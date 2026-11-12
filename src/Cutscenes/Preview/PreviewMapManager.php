<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Closure;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Events\Triggers\EventTrigger;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapSourceReader;
use Ichiloto\Engine\Field\MapTerrainKinds;
use Ichiloto\Engine\Field\MapPhysicalOccupancy;
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
    /** @var list<EventTrigger> The loaded map's events, bound to the preview's world state; their cues show, they never run. */
    public private(set) array $eventTriggers = [];
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

        return $this->load($mapId, function () use ($mapId): array {
            $map = $this->readMapDataFromFile($mapId);
            $kinds = MapTerrainKinds::loadForMap(getcwd() . '/assets', $map);
            if (array_key_exists(MapPhysicalOccupancy::DATA_KEY, $map)) {
                return [$map, MapCollisionResolver::resolveMap($this->layers, $map, kinds: $kinds)->collisionGrid];
            }
            $dictionaryPath = getcwd() . '/assets/Maps/collisions.php';
            $dictionary = is_file($dictionaryPath) ? $this->loadCollisionDictionary($dictionaryPath) : [];
            $dictionary = $kinds?->composeDictionary($dictionary) ?? $dictionary;

            return [$map, $this->layers === null
                ? $this->generateCollisionMap($this->tileMap, $dictionary)
                : $this->generateLayerCollisionMap($this->layers, $dictionary)];
        });
    }

    /**
     * Loads a map as the editor holds it, unsaved edits included, for its
     * tiles, collision and NPCs, as {@see loadForPreview()} loads its files:
     * the Engine prepares the map's source as it prepares one read from disk,
     * with the graphics, tile layers, terrain kinds and collision the editor
     * reads from the same map.
     *
     * @return array<string, mixed> The map's data array.
     */
    public function loadProjectMapForPreview(ProjectMap $projectMap): array
    {
        return $this->load($projectMap->mapId, function () use ($projectMap): array {
            $source = MapSourceReader::readDeclarations($projectMap->mapId, $projectMap->getEditableData(),
                $projectMap->getLayerSet(), $projectMap->getEventLayerCells());
            $map = $this->installMapSource($projectMap->mapId, $source, $projectMap->loadTerrainKinds(), $projectMap->loadGraphics(...),
                static fn(): array => MapGraphics::readLayersFromSources($projectMap->getTileLayerSources(), $projectMap->mapId,
                    $source['layers']), $projectMap->getFieldCatalog(...));

            return [$map, $projectMap->getResolvedCollisionMap()];
        });
    }

    /**
     * Loads a map read by `$read`, which installs its geometry and answers its
     * data and collision; a failure leaves undefined terrain and says why.
     *
     * @param Closure(): array{0: array<string, mixed>, 1: int[][]} $read
     * @return array<string, mixed> The map's data array.
     */
    private function load(string $mapId, Closure $read): array
    {
        try {
            [$map, $collisions] = $read();
            $this->mapData = $map;
            $this->calculateMapDimensions();
            $this->collisionMap = $collisions;
            // Bound to the preview's world state for their cues alone; the preview never runs them.
            $this->eventTriggers = $this->createEventTriggers(array_values(array_filter((array) ($map['events'] ?? []), is_array(...))),
                strval($map['id'] ?? ''));
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
        $this->gameScene->fieldEffects->installMap(strval($this->mapData['id'] ?? ''), $this->mapData['fieldEffects'] ?? null,
            $this->graphics, $this->eventTriggers);
    }

    /**
     * Clears the world so an unknown map reads as undefined terrain.
     */
    public function unload(): void
    {
        $this->mapData = [];
        $this->eventTriggers = [];
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

    /** Draws the map as the field does, with its world objects' Terminal cells. */
    public function render(?int $x = null, ?int $y = null): void
    {
        $this->refreshTerminalPresentation();
        $this->camera->renderMap();
    }
}
