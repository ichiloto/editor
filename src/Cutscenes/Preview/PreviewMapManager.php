<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Events\Triggers\EventTriggerFactory;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Throwable;

/**
 * A map manager that loads the author's map files for their tiles, collision
 * and NPCs, and nothing else.
 *
 * Map triggers, events, encounters, music and quest bookkeeping belong to the
 * field; a cinematic preview needs the ground the cast stands on, the walls a
 * route can run into, and the NPCs a command may address. The collision
 * dictionary and map readers are the Engine's own.
 */
final class PreviewMapManager extends MapManager
{
    private const string NO_MAP_DIAGNOSTIC = 'No map is selected: the preview shows undefined terrain.';

    /** @var array<string, mixed> The data array of the map last loaded. */
    public array $mapData = [];
    public private(set) ?string $mapFailure = null;
    /** @var list<string> The outcome of the current map load, never a retired map's failure. */
    private array $diagnostics = [self::NO_MAP_DIAGNOSTIC];

    public function __construct(Game $game, GameScene $gameScene)
    {
        parent::__construct($game, $gameScene);
    }

    /**
     * Loads a map by id from the project root the process is currently in.
     *
     * @return array<string, mixed> The map's data array.
     */
    public function loadForPreview(string $mapId): array
    {
        if ($mapId === '') {
            $this->unload();

            return [];
        }
        try {
            $map = $this->readMapDataFromFile($mapId);
            $this->mapData = $map;
            $this->calculateMapDimensions();
            $dictionaryPath = getcwd() . '/assets/Maps/collisions.php';
            $dictionary = is_file($dictionaryPath) ? $this->loadCollisionDictionary($dictionaryPath) : [];
            $this->collisionMap = $this->layers === null
                ? $this->generateCollisionMap($this->tileMap, $dictionary)
                : $this->generateLayerCollisionMap($this->layers, $dictionary);
            $this->gameScene->npcManager?->configure(is_array($map['npcs'] ?? null) ? $map['npcs'] : []);
            $this->installFieldEffects();
        } catch (Throwable $failure) {
            $this->unload();
            $this->mapFailure = $failure->getMessage();
            $this->diagnostics = [sprintf('Map %s could not be loaded: %s The preview shows undefined terrain.',
                $mapId, $this->mapFailure)];

            throw $failure;
        }
        $this->mapFailure = null;
        $this->diagnostics = [];

        return $map;
    }

    /** @return list<string> The current map's load diagnostics. */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * Gives the scene's field effects the loaded map's own, as the game's
     * field installs them when it loads a map: its declared effects, piece
     * effects and event cues.
     */
    public function installFieldEffects(): void
    {
        if ($this->mapData === [] || $this->gameScene->fieldEffects === null) {
            return;
        }
        // The map names itself by its data's id, as the game's field reads it.
        $mapId = strval($this->mapData['id'] ?? '');
        $triggers = array_map(static fn(array $event) => EventTriggerFactory::create($event, $mapId !== '' ? $mapId : null),
            array_values(array_filter((array) ($this->mapData['events'] ?? []), is_array(...))));
        $this->gameScene->fieldEffects->installMap($mapId, $this->mapData['fieldEffects'] ?? null, $this->graphics, $triggers);
    }

    /**
     * Clears the world so an unknown map reads as undefined terrain.
     */
    public function unload(): void
    {
        $this->mapData = [];
        $this->clearMapGeometry();
        $this->gameScene->npcManager?->configure([]);
        $this->mapFailure = null;
        $this->diagnostics = [self::NO_MAP_DIAGNOSTIC];
    }

    public function loadMap(string $filename, Player $player): self
    {
        $this->loadForPreview($filename);
        $this->camera->resetPosition($player);

        return $this;
    }

    public function render(?int $x = null, ?int $y = null): void
    {
        $this->camera->renderMap();
    }
}
