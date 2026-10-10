<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Closure;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use Throwable;

/**
 * The game's field inside the editor, isolated from the author's project:
 * one map with the player on it, drawn by the field's own camera into a
 * buffer, with silent audio and the preview's own configuration, and the
 * editor window's graphical view of it while one is attached.
 *
 * It is what every field preview stands on. A cinematic preview plays a
 * cinematic in it; an effect preview shows an effect on it. The field owns
 * the Engine configuration it installs, the project directory every step
 * runs in, its screen and its graphical view; its clock is its owner's.
 */
final class PreviewField
{
    public private(set) PreviewGameScene $scene;
    /** The field's dialogue and choices, drawn on its camera. */
    public private(set) PreviewPresentation $presentation;
    /** Why the current map could not be loaded, when it could not. */
    public ?string $mapFailure {
        get => $this->scene->previewMap->mapFailure;
    }
    /** Opaque graphical-host epoch, reserved without opening a graphical view. */
    public private(set) string $sessionId;
    /** The editor window's graphical view of the field, while one is attached. */
    private ?PreviewSceneHost $sceneHost = null;
    /** @var array<string, ConfigInterface> */
    private array $previousConfigs = [];
    private bool $configured = false;
    /** The field's screen in Terminal cells, which the graphical view's camera viewport never changes. */
    private int $screenWidth;
    private int $screenHeight;

    /**
     * @param Closure(): float $readTime The owner's clock, in seconds.
     */
    private function __construct(
        private readonly string $projectRoot,
        int $width,
        int $height,
        private readonly Closure $readTime,
    ) {
        $this->screenWidth = max(20, $width);
        $this->screenHeight = max(8, $height);
        $this->sessionId = bin2hex(random_bytes(16));
    }

    /**
     * Opens the field on a map, with the player standing on a cell and the
     * camera following them. A map that cannot be loaded leaves undefined
     * terrain and says why ({@see $mapFailure}).
     *
     * @param Closure(): float $readTime The owner's clock, in seconds.
     */
    public static function open(string $projectRoot, string $mapId, Vector2 $spawn, int $width, int $height, Closure $readTime): self
    {
        $field = new self($projectRoot, $width, $height, $readTime);
        $field->run(function () use ($field, $mapId, $spawn): void {
            $field->scene = new PreviewGameScene(new PreviewSceneManager(), $field->screenWidth, $field->screenHeight, $mapId);
            $field->scene->installFieldEffects(EffectPresentation::TERMINAL);
            $camera = $field->scene->previewCamera();
            $field->presentation = new PreviewPresentation($camera);
            $player = new PreviewPlayer($field->scene, $spawn);
            $field->scene->installPlayer($player);
            try {
                $field->scene->previewMap->loadForPreview($mapId);
            } catch (Throwable) {
                // The map manager owns the undefined terrain and its diagnostic.
            }
            $camera->attach($player);
        });

        return $field;
    }

    /**
     * Runs Engine code inside the project root with the preview
     * configuration installed and any stray terminal output discarded.
     */
    public function run(callable $operation): void
    {
        $this->installConfiguration();

        ProjectDirectoryContext::run($this->projectRoot, function () use ($operation): void {
            ob_start();

            try {
                $operation();
            } finally {
                ob_end_clean();
            }
        });
    }

    /**
     * Advances what the field shows by itself on the owner's clock: walking
     * frames, NPC and stage animation and the map's effects, as a game frame
     * advances them ({@see \Ichiloto\Engine\Scenes\Game\GameScene::advanceFieldPresentation()}).
     */
    public function advance(float $seconds): void
    {
        $this->run(fn() => $this->scene->advanceFieldPresentation(max(0.0, $seconds)));
    }

    /**
     * Shows an effect on the field at one of its frames, at the player
     * ({@see PreviewGameScene::showFieldEffect()}).
     *
     * @param Closure(EffectPresentation): CompiledEffectTimeline $compile The effect, compiled for a presentation.
     */
    public function showFieldEffect(Closure $compile, int $frame): void
    {
        $this->run(fn() => $this->scene->showFieldEffect($compile, $frame));
    }

    /**
     * The composed picture of the field at this moment.
     *
     * @return string[]
     */
    public function frame(): array
    {
        $rows = [];
        $this->run(function () use (&$rows): void {
            // The same isolated capture the graphical view composes from, with nothing left out.
            $rows = Console::capturePresentation($this->screenWidth, $this->screenHeight, $this->renderScene(...))->rows ?? [];
        });

        return $rows;
    }

    /**
     * One exchange with the editor window's graphical view of the field
     * ({@see PreviewSceneHost::exchange()}): its renderer event lines in, what
     * it should apply out. The view's grid is the field's screen in the
     * game's graphical text cells, so it shows what the game's graphical
     * field would. While attached, the Engine sizes the field's camera to
     * the graphical field's viewport, as the game does, so the Terminal
     * picture is the game's Terminal view only while detached.
     *
     * @param list<string> $events
     * @param string|null $sessionId The epoch the feedback answers; null discovers the current epoch without applying events.
     * @return list<array{type: string, payload: array<string, mixed>}>
     */
    public function exchangeScene(array $events, ?string $sessionId = null): array
    {
        // Check ownership before allocating a host or decoding old READY/ACK/error lines.
        if ($sessionId !== $this->sessionId) {
            return [];
        }
        $grid = $this->getSceneGrid();
        $messages = [];
        $this->run(function () use ($grid, $events, &$messages): void {
            $this->sceneHost ??= new PreviewSceneHost($this->scene, $this->presentation,
                $this->projectRoot . '/assets', $grid, $this->renderScene(...), $this->readTime, $this->sessionId);
            $messages = $this->sceneHost->exchange($events);
        });

        return $messages;
    }

    /** The graphical view's grid: the field's screen in the game's graphical text cells. */
    public function getSceneGrid(): RendererGridConfig
    {
        return new RendererGridConfig($this->screenWidth, $this->screenHeight,
            RendererRuntimeConfig::GPUI_CELL_WIDTH, RendererRuntimeConfig::GPUI_CELL_HEIGHT);
    }

    /** @return list<string> Why the current field has no usable map, if it does not. */
    public function getDiagnostics(): array
    {
        return $this->scene->previewMap->getDiagnostics();
    }

    /** Ends the editor window's graphical view of the field, if one is attached. */
    public function detachScene(): void
    {
        if ($this->sceneHost === null) {
            return;
        }
        // In the project, as every step of the field runs, so the Terminal effects it restores find their files.
        $this->run(fn() => $this->sceneHost->dispose());
        $this->sceneHost = null;
        $this->sessionId = bin2hex(random_bytes(16));
    }

    /**
     * The captured screen's size, in columns and rows.
     *
     * @return array{int, int}
     */
    public function getScreenSize(): array
    {
        return [$this->screenWidth, $this->screenHeight];
    }

    /**
     * Resizes the captured screen.
     */
    public function resize(int $width, int $height): void
    {
        // The graphical view's grid is the screen's, so a new size starts it again.
        $previousSessionId = $this->sessionId;
        $this->detachScene();
        if ($this->sessionId === $previousSessionId) {
            $this->sessionId = bin2hex(random_bytes(16));
        }
        $this->screenWidth = max(20, $width);
        $this->screenHeight = max(8, $height);
        $this->scene->previewCamera()->resize($this->screenWidth, $this->screenHeight);
    }

    /**
     * Ends the graphical view and releases the Engine configuration the field installed.
     */
    public function dispose(): void
    {
        $this->detachScene();
        if (! $this->configured) {
            return;
        }

        foreach ([ProjectConfig::class, PlaySettings::class] as $class) {
            if (isset($this->previousConfigs[$class])) {
                ConfigStore::put($class, $this->previousConfigs[$class]);
            } else {
                ConfigStore::remove($class);
            }
        }

        $this->configured = false;
    }

    /** Draws everything the field draws, in the game's order. */
    private function renderScene(): void
    {
        $this->scene->previewMap->render();
        $this->scene->npcManager?->render();
        $this->scene->player?->render();
        $this->scene->cinematicStage?->render();
        $this->scene->cinematicPresentation?->render();
        $this->presentation->render();
    }

    private function installConfiguration(): void
    {
        if ($this->configured) {
            return;
        }

        foreach ([ProjectConfig::class, PlaySettings::class] as $class) {
            if (ConfigStore::has($class)) {
                $this->previousConfigs[$class] = ConfigStore::get($class);
            }
        }

        ConfigStore::put(ProjectConfig::class, new PreviewConfig([
            'accessibility' => ['reducedMotion' => false],
            'save' => ['autosave' => false],
            'ui' => ['hud' => ['location' => false]],
            // The engine records what the field asks for either way; with
            // music off it never starts a player process for it.
            'audio' => ['music' => false, 'sfx' => false, 'voice' => false],
        ]));
        ConfigStore::put(PlaySettings::class, new PreviewConfig([
            'screen' => ['width' => $this->screenWidth, 'height' => $this->screenHeight],
            'width' => $this->screenWidth,
            'height' => $this->screenHeight,
        ]));

        $logDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ichiloto-editor-preview-logs';
        Debug::configure(['log_level' => Debug::ERROR, 'log_directory' => $logDirectory]);
        $this->configured = true;
    }
}
