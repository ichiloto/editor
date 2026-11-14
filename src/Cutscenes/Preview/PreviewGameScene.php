<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Closure;
use Ichiloto\Engine\Animations\Field\FieldEffectManager;
use Ichiloto\Engine\Animations\Field\FieldEffectAnchor;
use Ichiloto\Engine\Animations\Field\FieldEffectSession;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Core\Campaign\CampaignSession;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicController;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicPresentationManager;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicSubjectResolver;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Field\Location;
use Ichiloto\Engine\Field\NpcManager;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationProviderInterface;
use Ichiloto\Engine\Progress\Bestiary;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeCatalog;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeProgressService;
use Ichiloto\Engine\Rendering\Presentation\ScenePresentationContext;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * The isolated game scene a cinematic preview plays inside.
 *
 * The scene owns the facts of the campaign it plays in, a save's or a new
 * game's ({@see PreviewCampaign}), as the game's field adopts them, or a
 * fresh `GameState` and an empty party where it has none, with the Engine's own cinematic stage, presentation and
 * controller, and an interpreter whose dialogue goes to the preview pane. The author's play settings, save slots
 * and project files are never written; transfers load the destination map's
 * tiles and NPCs from disk and carry the session across exactly as the
 * Engine does.
 */
final class PreviewGameScene extends GameScene
{
    /** @var array<int, array{id: int, completed: bool}> */
    public array $finishedSessions = [];

    /** @var array<int, array{map: string, x: int, y: int, cover: bool}> */
    public array $transfers = [];

    public PreviewMapManager $previewMap;

    /** The id the effect an author previews plays under on the field. */
    private const string SHOWN_EFFECT_ID = 'editor-preview';

    /** @var array{0: Closure(EffectPresentation): CompiledEffectTimeline, 1: int}|null The effect an author previews, at its frame. */
    private ?array $shownEffect = null;

    /** @var array<string, non-empty-list<float>> The host's playhead, in seconds, each time the Engine began reporting a dialogue framing diagnostic, by its text. */
    private array $dialogueFramingReports = [];

    /** Whether the Engine reported a dialogue framing diagnostic at the last refresh. */
    private bool $dialogueFramingFailed = false;

    /** The dialogue line the last report was made under, so one line is reported once. */
    private ?DialoguePresentationProviderInterface $reportedDialogueLine = null;

    public function __construct(
        public readonly PreviewSceneManager $previewSceneManager,
        int $screenWidth,
        int $screenHeight,
        string $initialMapId,
        ?CampaignSession $campaign = null,
    ) {
        $this->sceneManager = $previewSceneManager;
        // The preview's scene manager owns this one scene, so it is the current, running scene that stage
        // presentations and other scene-owned work require, as the game's field is in its scene manager.
        $previewSceneManager->currentScene = $this;
        $this->hasDeferredAutoSave = false;
        $this->currentMapId = $initialMapId;
        if ($campaign !== null) {
            // Before anything of the scene's reads them, as the game's field adopts a restored campaign.
            $this->adoptCampaign($campaign);
            $campaign->setCurrentMapId($initialMapId);
        } else {
            $this->gameState = new GameState();
            $this->party = new Party();
            $this->knowledge = new KnowledgeProgressService(KnowledgeCatalog::fromProject());
            $this->bestiary = new Bestiary($this->knowledge);
        }
        $this->camera = new PreviewCamera($this, $screenWidth, $screenHeight);
        $this->npcManager = new NpcManager($this);
        $this->previewMap = new PreviewMapManager($previewSceneManager->game, $this);
        $this->mapManager = $this->previewMap;
        $this->cinematicStage = new CinematicStageManager($this);
        $this->cinematicPresentation = new CinematicPresentationManager($this);
        $this->cinematicController = new CinematicController($this);
    }

    /**
     * Resolves the field's viewport and the cinematic's dialogue framing, as
     * the game's scene does, and keeps what the Engine reports about a cast
     * it cannot frame clear of the dialogue. The Engine holds that
     * diagnostic only while the cast does not fit and drops it when the
     * cinematic ends; the preview keeps each report with the playhead it
     * began at, for as long as the scene lives.
     */
    #[\Override]
    public function synchronizeFieldViewport(): void
    {
        parent::synchronizeFieldViewport();
        $this->recordDialogueFramingDiagnostic();
    }

    /**
     * What the Engine reported about casts it could not frame clear of the
     * dialogue, each in the Engine's words with the scene time it first
     * occurred and the time of every later line it occurred under.
     *
     * @return list<string>
     */
    public function getDialogueFramingDiagnostics(): array
    {
        $diagnostics = [];
        foreach ($this->dialogueFramingReports as $message => $times) {
            $later = array_map(static fn(float $seconds): string => sprintf('%.2f s', $seconds), array_slice($times, 1));
            $diagnostics[] = sprintf('%s First at %.2f s of the scene%s.', $message, $times[0],
                $later === [] ? '' : '; again at ' . implode(', ', $later));
        }

        return $diagnostics;
    }

    /**
     * Gives the scene the field's effects for a presentation, with the loaded
     * map's installed, as the game's field has them: Terminal glyphs for the
     * Terminal picture, graphical sprites for the graphical view.
     */
    public function installFieldEffects(EffectPresentation $presentation): void
    {
        $this->fieldEffects?->clear();
        $this->fieldEffects = new FieldEffectManager(getcwd() . '/assets', $presentation);
        // A new manager learns what the attached renderer draws, as the scene's own did; without it, it draws nothing.
        if ($this->getPresentationContext() !== null) {
            $this->synchronizeFieldViewport();
        }
        $this->previewMap->installFieldEffects();
        if ($this->shownEffect !== null) {
            $this->showFieldEffect(...$this->shownEffect);
        }
    }

    /**
     * Gives the scene back a presentation a graphical one replaced, with the
     * field effects that presentation draws: sprites for a graphical view,
     * glyphs for the Terminal screen.
     */
    public function restorePresentation(?ScenePresentationContext $context): void
    {
        $this->setPresentationContext($context);
        $this->installFieldEffects($context?->graphical === true ? EffectPresentation::GRAPHICAL : EffectPresentation::TERMINAL);
    }

    /**
     * Shows an effect on the field at one of its frames, at the player the
     * camera follows, as a cinematic's field animation presents one: an
     * effect session this scene owns, compiled for the field's presentation
     * and held at the frame for inspection, so no cue plays and no clock
     * moves it. It stays shown, in whichever presentation the field is,
     * until another is shown.
     *
     * @param Closure(EffectPresentation): CompiledEffectTimeline $compile The effect, compiled for a presentation.
     */
    public function showFieldEffect(Closure $compile, int $frame): void
    {
        $this->shownEffect = [$compile, $frame];
        $presentation = $this->cinematicPresentation ?? throw new \LogicException('The preview scene has no cinematic presentation.');
        $presentation->removeEffect(self::SHOWN_EFFECT_ID);
        $session = new FieldEffectSession(self::SHOWN_EFFECT_ID, FieldEffectAnchor::createAtPosition(
            new CinematicSubjectResolver($this)->position(['kind' => 'player'])),
            $compile($this->isGraphicalFieldPresented() ? EffectPresentation::GRAPHICAL : EffectPresentation::TERMINAL));
        $session->playback->seek($frame);
        $session->playback->pause();
        $presentation->presentEffect($session);
    }

    /** The preview game this scene belongs to, with its silent audio and its notice queue. */
    public function getPreviewGame(): PreviewGame
    {
        $game = $this->previewSceneManager->game;

        return $game instanceof PreviewGame ? $game : throw new \LogicException('The preview scene lost its preview game.');
    }

    public function installPlayer(PreviewPlayer $player): void
    {
        $this->player = $player;
    }

    public function installInterpreter(EventInterpreter $interpreter): void
    {
        $this->eventInterpreter = $interpreter;
    }

    public function previewCamera(): PreviewCamera
    {
        $camera = $this->camera;

        if (! $camera instanceof PreviewCamera) {
            throw new \LogicException('The preview scene lost its camera.');
        }

        return $camera;
    }

    public function transferPlayer(Location $location, bool $useConfiguredTransition = true, ?\Closure $onCommitted = null): bool
    {
        $this->transfers[] = [
            'map' => $location->mapFilename,
            'x' => intval($location->playerPosition->x),
            'y' => intval($location->playerPosition->y),
            'cover' => $this->cinematicPresentation?->hasTransitionCover() ?? false,
        ];
        $this->cinematicStage?->clear();

        if ($this->player !== null) {
            $this->player->position->x = $location->playerPosition->x;
            $this->player->position->y = $location->playerPosition->y;

            if ($location->playerSprite) {
                $this->player->sprite = $location->playerSprite;
            }
        }

        $this->currentMapId = $location->mapFilename;
        $this->campaign?->setCurrentMapId($this->currentMapId);

        $loaded = false;
        try {
            $this->previewMap->loadForPreview($location->mapFilename);
            $loaded = $location->mapFilename !== '';
        } catch (\Throwable) {
            // The map manager clears failed geometry and records why; the
            // cinematic can continue over explicitly diagnosed undefined terrain.
        }

        if ($this->player !== null && $this->camera->followsPlayer) {
            $this->camera->resetPosition($this->player);
        }

        // Only a destination that actually loaded commits the transfer, so its owner's completion runs before
        // events resume or anything saves. A map that failed keeps its diagnosed undefined-terrain preview but
        // never completes the trigger that sent the player there.
        if ($loaded && $onCommitted !== null) {
            $onCommitted();
        }
        $this->eventInterpreter?->resumeAfterTransfer();
        $this->autoSave();
        return true;
    }

    public function onEventSessionStarted(EventExecutionSession $session): void
    {
    }

    public function onEventSessionFinished(EventExecutionSession $session, bool $completed): void
    {
        $this->finishedSessions[] = ['id' => $session->id, 'completed' => $completed];

        if (! $completed) {
            $this->hasDeferredAutoSave = false;

            return;
        }

        if ($this->hasDeferredAutoSave) {
            $this->hasDeferredAutoSave = false;
            $this->autoSave();
        }
    }

    // The game's field repaints its retained screen through the four methods below. A preview keeps no screen: it
    // draws a whole picture each time one is asked for, and whoever draws it first resolves the viewport and the
    // dialogue framing ({@see synchronizeFieldViewport()}), which is all of the game's repaint a preview needs.

    public function renderBackgroundTile(int $x, int $y): void
    {
    }

    public function requestFieldPresentationReconciliation(): void
    {
    }

    public function reconcileFieldPresentation(): void
    {
    }

    public function restoreFieldAfterOverlay(): void
    {
    }

    /**
     * Notes the Engine's dialogue framing diagnostic when it begins, and
     * again for each further dialogue line it stands under, at the host's
     * playhead. The diagnostic stays set between lines; a line already
     * reported is never reported twice.
     */
    private function recordDialogueFramingDiagnostic(): void
    {
        $diagnostic = $this->getDialogueFramingDiagnostic();
        if ($diagnostic === null) {
            $this->dialogueFramingFailed = false;

            return;
        }
        $line = array_find($this->getActiveFieldPresentations(),
            static fn(object $owner): bool => $owner instanceof DialoguePresentationProviderInterface);
        if ($line === null ? ! $this->dialogueFramingFailed : $line !== $this->reportedDialogueLine) {
            // The field's own clock, which a preview scene is always presented with.
            $this->dialogueFramingReports[$diagnostic][] = ($this->getPresentationContext()
                ?? throw new \LogicException('The preview scene frames dialogue without its field\'s presentation.'))->getPresentationTime();
            $this->reportedDialogueLine = $line ?? $this->reportedDialogueLine;
        }
        $this->dialogueFramingFailed = true;
    }
}
