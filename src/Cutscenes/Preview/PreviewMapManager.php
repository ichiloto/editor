<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Scenes\Game\GameScene;

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
    /** @var array<string, mixed> The data array of the map last loaded. */
    public array $mapData = [];

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
        $map = $this->readMapDataFromFile($mapId);
        $this->mapData = $map;
        $this->calculateMapDimensions();
        $dictionaryPath = getcwd() . '/assets/Maps/collisions.php';
        $dictionary = is_file($dictionaryPath) ? $this->loadCollisionDictionary($dictionaryPath) : [];
        $this->collisionMap = $this->generateCollisionMap($this->tileMap, $dictionary);
        $this->gameScene->npcManager?->configure(is_array($map['npcs'] ?? null) ? $map['npcs'] : []);

        return $map;
    }

    /**
     * Clears the world so an unknown map reads as undefined terrain.
     */
    public function unload(): void
    {
        $this->mapData = [];
        $this->tileMap = [];
        $this->collisionMap = [];
        $this->calculateMapDimensions();
        $this->camera->worldSpace = [];
        $this->gameScene->npcManager?->configure([]);
    }

    public function loadMap(string $filename, Player $player): self
    {
        $this->loadForPreview($filename);
        $this->camera->resetPosition($player);

        return $this;
    }

    public function scrollMap(Player $player, Vector2 $moveDirection): bool
    {
        return false;
    }

    public function render(?int $x = null, ?int $y = null): void
    {
        $this->camera->renderMap();
    }
}
