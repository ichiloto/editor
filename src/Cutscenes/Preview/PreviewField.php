<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Closure;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Progression\WorldAbilityCatalog;
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\SceneFrameComposer;
use Ichiloto\Engine\Rendering\Presentation\SceneFrameComposition;
use Ichiloto\Engine\Rendering\Presentation\ScenePresentationContext;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Text\TextPreparationProviderInterface;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
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
 *
 * Before each picture the scene resolves its viewport and, in a cinematic,
 * its dialogue framing, as the game's field does before it draws
 * ({@see \Ichiloto\Engine\Scenes\Game\GameScene::synchronizeFieldViewport()}),
 * so the camera stands where the game's would for the dialogue on screen.
 *
 * It also owns what the scene presents for its whole life. A preview scene
 * has no runtime UI or renderer to report its dialogue and modals, so the
 * field declares them: its Terminal screen, with the field's own dialogue
 * and clock, from the moment the scene exists, and a graphical presentation
 * only while a still is composed or the window's view is attached, each of
 * which gives back the presentation it replaced. A capture holds a graphical
 * presentation for the field's whole run instead ({@see beginCapture()}), so
 * everything the run starts is presented graphically from its first command.
 *
 * The Engine configuration it installs is the project's, as the game
 * installs it before a campaign: the preview's play and project settings,
 * the project's world abilities, which world conditions and power
 * showcases read, and the project's control bindings, which the control
 * hints of a dialogue or notice name. A field with a player on it plays in
 * a campaign, as the game's field does: a save's ({@see openSavedCampaign()})
 * or the one a new game starts with ({@see open()}). It installs the
 * project's actors and items for that campaign, restores it for its own
 * Game and releases it when it is disposed.
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
    /** @var list<string> Why project configuration the field installs could not be read. */
    private array $configurationDiagnostics = [];
    /** The graphical presentation a capture holds, while one does. */
    private ?ScenePresentationContext $capture = null;
    /** The presentation the capture replaced. */
    private ?ScenePresentationContext $captureReplaced = null;
    private ?SceneFrameComposer $captureComposer = null;
    /** @var (Closure(string): bool)|null The capture's renderer capabilities. */
    private ?Closure $captureSupports = null;
    private bool $configured = false;
    /** @var array<string, array<string, mixed>> The Engine's input bindings as the field found them. */
    private array $previousBindings = [];
    /** The campaign the field plays in: a save's or a new game's. */
    private ?PreviewCampaign $campaign = null;
    /** Why a new game's campaign could not be started, when the field was opened on one and it could not. */
    public private(set) ?string $campaignFailure = null;
    /** Every Engine configuration the field may install; each is given back as it was found. */
    private const array INSTALLED_CONFIGURATION = [ProjectConfig::class, PlaySettings::class, WorldAbilityCatalog::class,
        ActorStore::class, ItemStore::class];
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
     * Opens the field on a map in the campaign a new game starts with
     * ({@see PreviewCampaign::startNewGame()}): the project's starting party,
     * inventory and funds, with the player standing on a cell, shown and
     * facing as a new game shows them, and the camera following. A project a
     * new game cannot be started in leaves the field without a campaign, an
     * empty party on it, and says why ({@see $campaignFailure}). A map that
     * cannot be loaded leaves undefined terrain and says why
     * ({@see $mapFailure}).
     *
     * @param Closure(): float $readTime The owner's clock, in seconds.
     */
    public static function open(string $projectRoot, string $mapId, Vector2 $spawn, int $width, int $height, Closure $readTime): self
    {
        $field = new self($projectRoot, $width, $height, $readTime);
        try {
            $field->run(function () use ($field, $mapId, $spawn): void {
                $manager = new PreviewSceneManager();
                $field->startNewGame($manager);
                $field->stage($manager, $mapId, $spawn);
            });
        } catch (Throwable $failure) {
            $field->dispose();
            throw $failure;
        }

        return $field;
    }

    /**
     * Opens the field on a save: its campaign restored for the field's own
     * Game through the Engine's save pipeline ({@see PreviewCampaign}), on the
     * save's map, with the player where the save left them, facing as they
     * faced and shown as the game shows them, and the camera following. The
     * save file is only read. A save that cannot be read or restored is
     * refused with the Engine's reason, and nothing stays installed.
     *
     * @param Closure(): float $readTime The owner's clock, in seconds.
     */
    public static function openSavedCampaign(string $projectRoot, string $savePath, int $width, int $height, Closure $readTime): self
    {
        $field = new self($projectRoot, $width, $height, $readTime);
        try {
            $field->run(function () use ($field, $savePath): void {
                $field->installProjectStores();
                $manager = new PreviewSceneManager();
                $field->campaign = PreviewCampaign::restoreFromSave(self::requirePreviewGame($manager), $field->projectRoot, $savePath);
                $config = $field->campaign->config;
                $field->stage($manager, $config->mapId, new Vector2($config->playerPosition->x, $config->playerPosition->y));
            });
        } catch (Throwable $failure) {
            $field->dispose();
            throw $failure;
        }

        return $field;
    }

    /**
     * Starts a new game's campaign for the field's Game. A project a new game
     * cannot be started in keeps the actors and items the field found
     * installed and has no campaign; the Engine's reason is kept.
     */
    private function startNewGame(PreviewSceneManager $manager): void
    {
        try {
            $this->installProjectStores();
            $this->campaign = PreviewCampaign::startNewGame(self::requirePreviewGame($manager), $this->projectRoot);
        } catch (Throwable $failure) {
            $this->giveBackConfiguration([ActorStore::class, ItemStore::class]);
            $this->campaignFailure = 'A new game could not be started, so the field has no party: ' . $failure->getMessage();
        }
    }

    /** Installs the project's actors and items, read from its files, as the game does before a campaign. */
    private function installProjectStores(): void
    {
        ConfigStore::put(ActorStore::class, new ActorStore());
        ConfigStore::put(ItemStore::class, new ItemStore());
    }

    private static function requirePreviewGame(PreviewSceneManager $manager): PreviewGame
    {
        return $manager->game instanceof PreviewGame ? $manager->game
            : throw new \LogicException('The preview scene manager lost its preview game.');
    }

    /**
     * Builds the field's scene on a map in the field's campaign, with the
     * player on a cell and the camera following them. In a campaign the
     * player has its sprite, heading and party, as the game's field gives
     * them; without one they are the plain marker of an empty party.
     */
    private function stage(PreviewSceneManager $manager, string $mapId, Vector2 $position): void
    {
        $config = $this->campaign?->config;
        $this->scene = new PreviewGameScene($manager, $this->screenWidth, $this->screenHeight, $mapId, $this->campaign?->session);
        $this->scene->installFieldEffects(EffectPresentation::TERMINAL);
        $camera = $this->scene->previewCamera();
        $this->presentation = new PreviewPresentation($this->screenWidth, $this->screenHeight, $this->projectRoot . '/assets');
        $this->scene->getPreviewGame()->notices->presentOn($this->presentation);
        $this->presentTerminal();
        $player = $config === null ? new PreviewPlayer($this->scene, $position)
            : new PreviewPlayer($this->scene, $position, $config->playerSprite, $config->playerHeading, party: $config->party);
        $this->scene->installPlayer($player);
        try {
            $this->scene->previewMap->loadForPreview($mapId);
        } catch (Throwable) {
            // The map manager owns the undefined terrain and its diagnostic.
        }
        $camera->attach($player);
    }

    /** The map the field's saved campaign was saved on; null for a field opened without a save. */
    public function getCampaignMapId(): ?string
    {
        return $this->campaign?->savedMapId;
    }

    /**
     * Opens the field on a map as the editor holds it, unsaved edits included
     * ({@see PreviewMapManager::loadProjectMapForPreview()}), with the player
     * standing on a cell facing a heading, or with nobody on it. A character
     * shown in the player's place gives their graphical art. The camera
     * follows the player, or holds the map's top-left corner without one.
     * A map that cannot be loaded leaves undefined terrain and says why.
     *
     * @param Closure(): float $readTime The owner's clock, in seconds.
     */
    public static function openProjectMap(string $projectRoot, ProjectMap $map, ?Vector2 $spawn, int $width, int $height,
        Closure $readTime, MovementHeading $heading = MovementHeading::NONE, ?CharacterSheet $appearance = null): self
    {
        $field = new self($projectRoot, $width, $height, $readTime);
        $field->run(function () use ($field, $map, $spawn, $heading, $appearance): void {
            $field->scene = new PreviewGameScene(new PreviewSceneManager(), $field->screenWidth, $field->screenHeight, $map->mapId);
            $field->scene->installFieldEffects(EffectPresentation::TERMINAL);
            $camera = $field->scene->previewCamera();
            $field->presentation = new PreviewPresentation($field->screenWidth, $field->screenHeight, $field->projectRoot . '/assets');
            $field->scene->getPreviewGame()->notices->presentOn($field->presentation);
            $field->presentTerminal();
            $player = $spawn === null ? null : new PreviewPlayer($field->scene, $spawn, heading: $heading, appearance: $appearance);
            if ($player !== null) {
                $field->scene->installPlayer($player);
            }
            try {
                $field->scene->previewMap->loadProjectMapForPreview($map);
            } catch (Throwable) {
                // The map manager owns the undefined terrain and its diagnostic.
            }
            if ($player !== null) {
                $camera->attach($player);
            } else {
                $camera->detach();
                $camera->moveTo(0, 0);
            }
        });

        return $field;
    }

    /**
     * Holds the camera still with its top-left as near a map cell as the map
     * allows, instead of following the player.
     */
    public function holdCameraAt(int $x, int $y): void
    {
        $this->run(function () use ($x, $y): void {
            $camera = $this->scene->previewCamera();
            $camera->detach();
            $camera->moveTo($x, $y);
        });
    }

    /**
     * The map cell the screen's top-left shows, as the camera projects the
     * world: negative where a map smaller than the screen sits centred on it.
     *
     * @return array{x: int, y: int}
     */
    public function getCameraOrigin(): array
    {
        return $this->scene->previewCamera()->getWorldOrigin();
    }

    /**
     * The game's graphical field as it stands, composed for a still picture
     * by a renderer of the given capabilities that has no window to answer:
     * the map's world, its sprites at this moment and the camera's viewport,
     * as the editor window's graphical view composes them
     * ({@see PreviewSceneHost}). The field cell is the graphical field's, so
     * the camera keeps the screen it has; the Terminal picture is unchanged
     * once the composition is taken, and the presentation the scene had --
     * the field's Terminal one, or the window's view -- is given back.
     *
     * @param Closure(string): bool $supports The still renderer's capabilities.
     * @param TextPreparationProviderInterface|null $textPreparation Where project labels, such as a sign's, are
     *     measured; without one the field composes no labels.
     * @param (Closure(SceneFrameComposition): bool)|null $composeAgain Asked after each composition whether to
     *     compose again in the same presentation, as a caller waiting for the labels it asked for does: the labels
     *     a field has asked for are kept only while its presentation stays. The last composition is returned.
     */
    public function composeStill(Closure $supports, ?TextPreparationProviderInterface $textPreparation = null,
        ?Closure $composeAgain = null): SceneFrameComposition
    {
        $supports = new PreviewPresentationCapabilities($supports)->supportsFeature(...);
        $assetRoot = $this->projectRoot . '/assets';
        $grid = new RendererGridConfig($this->screenWidth, $this->screenHeight, FieldViewport::TILE_SIZE, FieldViewport::TILE_SIZE);
        $composition = null;
        $this->run(function () use ($assetRoot, $grid, $supports, $textPreparation, $composeAgain, &$composition): void {
            $replaced = $this->scene->getPresentationContext();
            $this->scene->setPresentationContext(new ScenePresentationContext($grid, $supports,
                collectPresentations: fn(): array => $this->presentation->getActivePresentations(), readTime: $this->readTime,
                assetRoot: $assetRoot, textPreparation: $textPreparation));
            $this->scene->installFieldEffects(EffectPresentation::GRAPHICAL);
            $composer = new SceneFrameComposer($assetRoot);
            try {
                do {
                    $composition = $composer->composeFrame($this->scene, $grid, $supports,
                        fn(array $excluded, array $worldLayers) => Console::capturePresentation($grid->columns, $grid->rows,
                            $this->renderScene(...), $excluded, $worldLayers, $this->scene->getPresentationWorld() !== null));
                } while ($composeAgain !== null && $composeAgain($composition));
            } finally {
                $this->scene->restorePresentation($replaced);
            }
        });

        return $composition ?? throw new \LogicException('The field composed no graphical picture.');
    }

    /**
     * Presents the field graphically from now until the capture ends
     * ({@see endCapture()}), as the editor window's graphical view does while
     * attached, for a renderer of the given capabilities with no window: the
     * graphical field's grid, its effects drawn as sprites, dialogue and
     * notices paged for that screen. Everything the field runs meanwhile is
     * started graphically, as it is in the game's graphical window, and each
     * composed frame ({@see composeCapturedFrame()}) is the scene at the
     * owner's clock. The window's view cannot be attached while a capture
     * holds the field.
     *
     * @param Closure(string): bool $supports The capture renderer's capabilities.
     * @param TextPreparationProviderInterface|null $textPreparation Where project labels are measured; without one
     *     the field composes no labels.
     * @throws \LogicException When the field is already captured or the window's view is attached.
     */
    public function beginCapture(Closure $supports, ?TextPreparationProviderInterface $textPreparation = null): void
    {
        if ($this->capture !== null || $this->sceneHost !== null) {
            throw new \LogicException('The field is already presented graphically; end that presentation first.');
        }
        $this->captureSupports = new PreviewPresentationCapabilities($supports)->supportsFeature(...);
        $assetRoot = $this->projectRoot . '/assets';
        $grid = $this->getSceneGrid();
        $this->run(function () use ($assetRoot, $grid, $textPreparation): void {
            $this->captureReplaced = $this->scene->getPresentationContext();
            $this->scene->setPresentationContext($this->capture = new ScenePresentationContext($grid, $this->captureSupports,
                collectPresentations: fn(): array => $this->presentation->getActivePresentations(), readTime: $this->readTime,
                assetRoot: $assetRoot, textPreparation: $textPreparation));
            $this->scene->installFieldEffects(EffectPresentation::GRAPHICAL);
            $composer = $this->captureComposer = new SceneFrameComposer($assetRoot);
            $width = $grid->columns * $grid->cellWidth;
            $this->presentation->pageLayout = static fn(string $speaker, $context, string $help) =>
                $composer->getDialoguePageLayout($speaker, $context, $help, $width);
            $this->presentation->graphicalGrid = $grid;
        });
    }

    /** Whether a capture holds the field's presentation. */
    public function isCaptured(): bool
    {
        return $this->capture !== null;
    }

    /**
     * The scene as the captured graphical field shows it now: the retained
     * world, its sprites, the camera's viewport, the field's Terminal text,
     * the screen's dialogue and notices and the cinematic overlays, the
     * stage plane and what is lifted above its cover included, composed by
     * the Engine as for the game's graphical window.
     *
     * @throws \LogicException When no capture holds the field.
     */
    public function composeCapturedFrame(): SceneFrameComposition
    {
        $composer = $this->captureComposer;
        $supports = $this->captureSupports;
        if ($this->capture === null || $composer === null || $supports === null) {
            throw new \LogicException('Begin a capture before composing its frames.');
        }
        $grid = $this->getSceneGrid();
        $composition = null;
        $this->run(function () use ($composer, $supports, $grid, &$composition): void {
            // As the game's field does before it draws: the viewport, and the cinematic's dialogue framing for what is on screen now.
            $this->scene->synchronizeFieldViewport();
            $composition = $composer->composeFrame($this->scene, $grid, $supports,
                fn(array $excluded, array $worldLayers) => Console::capturePresentation($grid->columns, $grid->rows,
                    $this->renderScene(...), $excluded, $worldLayers, $this->scene->getPresentationWorld() !== null));
        });

        return $composition ?? throw new \LogicException('The captured field composed no picture.');
    }

    /** Gives the field back the presentation the capture replaced, with its Terminal dialogue and notice pages. */
    public function endCapture(): void
    {
        if ($this->capture === null) {
            return;
        }
        $this->run(function (): void {
            if ($this->scene->getPresentationContext() === $this->capture) {
                $this->scene->restorePresentation($this->captureReplaced);
            }
            $this->presentation->pageLayout = null;
            $this->presentation->graphicalGrid = null;
        });
        $this->capture = $this->captureReplaced = $this->captureComposer = null;
        $this->captureSupports = null;
    }

    /**
     * The field's signs: each world object whose resource is a sign board,
     * by the graphical id its world canvas carries.
     *
     * @return array<string, array{id: string, x: int, y: int}>
     */
    public function findSigns(): array
    {
        $signs = [];
        foreach ($this->scene->mapManager?->getWorldObjects() ?? [] as $object) {
            if ($object->definition->resource?->sign !== null) {
                $signs[$object->getGraphicalSpriteId()] = ['id' => $object->definition->id, 'x' => $object->definition->x,
                    'y' => $object->definition->y];
            }
        }

        return $signs;
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
            // As the game's field does before it draws: the viewport, and the cinematic's dialogue framing for what is on screen now.
            $this->scene->synchronizeFieldViewport();
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
        // Check ownership before allocating a host or decoding old READY/ACK/error lines; a capture owns the presentation.
        if ($sessionId !== $this->sessionId || $this->capture !== null) {
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

    /**
     * @return list<string> Why the current field has no usable map, project configuration it could not read, why
     *     it has no new game's campaign, and the casts a cinematic could not frame clear of its dialogue
     *     ({@see PreviewGameScene::getDialogueFramingDiagnostics()}).
     */
    public function getDiagnostics(): array
    {
        return [...$this->configurationDiagnostics, ...($this->campaignFailure === null ? [] : [$this->campaignFailure]),
            ...$this->scene->previewMap->getDiagnostics(),
            ...$this->scene->getDialogueFramingDiagnostics()];
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
        if ($this->capture !== null) {
            throw new \LogicException('A captured field keeps its screen until the capture ends.');
        }
        // The graphical view's grid is the screen's, so a new size starts it again.
        $previousSessionId = $this->sessionId;
        $this->detachScene();
        if ($this->sessionId === $previousSessionId) {
            $this->sessionId = bin2hex(random_bytes(16));
        }
        $this->screenWidth = max(20, $width);
        $this->screenHeight = max(8, $height);
        $this->scene->previewCamera()->resize($this->screenWidth, $this->screenHeight);
        $this->presentation->resizeScreen($this->screenWidth, $this->screenHeight);
        $this->run(function (): void {
            // The Engine places its windows, a dialogue box among them, on the screen it is told of.
            $this->installScreenSize();
            // The Terminal presentation is the screen's, so it takes the new size.
            $this->presentTerminal();
        });
    }

    /**
     * Ends the graphical view and releases the Engine configuration the field installed.
     */
    public function dispose(): void
    {
        $this->endCapture();
        $this->detachScene();
        // The project state the campaign replaced goes back before the configuration it was restored under.
        $this->campaign?->release();
        $this->campaign = null;
        if (! $this->configured) {
            return;
        }

        $this->giveBackConfiguration(self::INSTALLED_CONFIGURATION);
        InputManager::setBindings($this->previousBindings);

        $this->configured = false;
    }

    /**
     * Puts Engine configuration back as the field found it: what was installed before, or nothing.
     *
     * @param list<class-string<ConfigInterface>> $classes
     */
    private function giveBackConfiguration(array $classes): void
    {
        foreach ($classes as $class) {
            if (isset($this->previousConfigs[$class])) {
                ConfigStore::put($class, $this->previousConfigs[$class]);
            } else {
                ConfigStore::remove($class);
            }
        }
    }

    /**
     * Declares the scene's Terminal presentation: the field's screen in
     * Terminal cells, no graphical capability, the field's own dialogue and
     * choices as its presentation owners and the owner's clock as its time.
     * The runtime UI and renderer belong to a running game, never to a
     * preview, so they are neither initialized nor borrowed.
     */
    private function presentTerminal(): void
    {
        $this->scene->setPresentationContext(new ScenePresentationContext(
            new RendererGridConfig($this->screenWidth, $this->screenHeight, 1, 1),
            static fn(string $capability): bool => false,
            graphical: false,
            collectPresentations: fn(): array => $this->presentation->getActivePresentations(),
            readTime: $this->readTime,
            assetRoot: $this->projectRoot . '/assets',
        ));
    }

    /** Draws everything the field draws, in the game's order. */
    private function renderScene(): void
    {
        $this->scene->previewMap->render();
        $this->scene->renderEventCues($this->scene->previewMap->eventTriggers);
        $this->scene->npcManager?->render();
        $this->scene->player?->render();
        $this->scene->cinematicStage?->render();
        $this->scene->cinematicPresentation?->render();
        $this->presentation->render();
    }

    /**
     * Tells the Engine the size of the field's text screen, which it places
     * its windows on: where a dialogue box stands, and so what a cinematic's
     * cast is framed clear of.
     */
    private function installScreenSize(): void
    {
        ConfigStore::put(PlaySettings::class, new PreviewConfig([
            'screen' => ['width' => $this->screenWidth, 'height' => $this->screenHeight],
            'width' => $this->screenWidth,
            'height' => $this->screenHeight,
        ]));
    }

    private function installConfiguration(): void
    {
        if ($this->configured) {
            return;
        }

        foreach (self::INSTALLED_CONFIGURATION as $class) {
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
        $this->installScreenSize();
        // The project's world abilities, as the game installs them before a campaign; a source that cannot be read
        // leaves none installed and says why, so a condition or showcase that needs them reports it in its own words.
        ConfigStore::remove(WorldAbilityCatalog::class);
        try {
            ConfigStore::put(WorldAbilityCatalog::class, WorldAbilityCatalog::fromProject($this->projectRoot . '/assets'));
        } catch (Throwable $failure) {
            $this->configurationDiagnostics[] = 'The project\'s world abilities could not be read: ' . $failure->getMessage();
        }
        // The project's control bindings, as the game installs them, so the hints a dialogue or notice shows name the
        // project's keys. Only the names are read: the preview takes no input through the Engine.
        $this->previousBindings = InputManager::getBindings();
        try {
            // A project that authors no bindings has the Engine's own.
            InputManager::setBindings(is_file($this->projectRoot . '/input.php')
                ? InputManager::getProjectBindings($this->projectRoot) : []);
        } catch (Throwable $failure) {
            $this->configurationDiagnostics[] = 'The project\'s control bindings could not be read: ' . $failure->getMessage();
        }
        // Where the Engine writes its diagnostics belongs to the process
        // running the Editor, so the field leaves it as it found it.
        $this->configured = true;
    }
}
