<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicController;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicPresentationManager;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Field\Location;
use Ichiloto\Engine\Field\NpcManager;
use Ichiloto\Engine\Progress\Bestiary;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeCatalog;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeProgressService;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * The isolated game scene a cinematic preview plays inside.
 *
 * The scene owns a fresh `GameState`, an empty party, the Engine's own
 * cinematic stage, presentation and controller, and an interpreter whose
 * dialogue goes to the preview pane. The author's play settings, save slots
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

    public function __construct(
        public readonly PreviewSceneManager $previewSceneManager,
        int $screenWidth,
        int $screenHeight,
        string $initialMapId,
    ) {
        $this->sceneManager = $previewSceneManager;
        $this->gameState = new GameState();
        $this->hasDeferredAutoSave = false;
        $this->currentMapId = $initialMapId;
        $this->camera = new PreviewCamera($screenWidth, $screenHeight);
        $this->party = new Party();
        $this->knowledge = new KnowledgeProgressService(KnowledgeCatalog::fromProject());
        $this->bestiary = new Bestiary($this->knowledge);
        $this->npcManager = new NpcManager($this);
        $this->previewMap = new PreviewMapManager($previewSceneManager->game, $this);
        $this->mapManager = $this->previewMap;
        $this->cinematicStage = new CinematicStageManager($this);
        $this->cinematicPresentation = new CinematicPresentationManager($this);
        $this->cinematicController = new CinematicController($this);
    }

    public function installPlayer(PreviewPlayer $player): void
    {
        $player->bindScene($this);
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

    public function transferPlayer(Location $location, bool $useConfiguredTransition = true): void
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

        try {
            $this->previewMap->loadForPreview($location->mapFilename);
        } catch (\Throwable) {
            // An unknown destination is a validation finding, not a preview
            // crash: the cinematic continues over undefined terrain and the
            // final state still reports the map it asked for.
            $this->previewMap->unload();
        }

        if ($this->player !== null && $this->camera->followsPlayer) {
            $this->camera->resetPosition($this->player);
        }

        $this->eventInterpreter?->resumeAfterTransfer();
        $this->autoSave();
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
}
