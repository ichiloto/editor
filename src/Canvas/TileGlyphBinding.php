<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Editor\Maps\MapLayers;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;
use InvalidArgumentException;
use RuntimeException;

/**
 * Which tiles of a map stand for a glyph, read from its tileset's pieces:
 * every tile a piece role draws stands for that role's glyph on the piece's
 * gameplay layer, such as a bed's tiles for its `O` and `U` on fixtures or a
 * wall's tile for `-`, `|` and `+` on buildings. Those tiles and their glyph
 * must change together, so the glyph, its collision and its tiles never
 * disagree. A tile no role draws, such as a rug or a picture, stands for
 * nothing in the terminal and is free to place anywhere.
 */
final readonly class TileGlyphBinding
{
    /**
     * @param array<string, array<string, list<TileBinding>>> $bindings By tile layer name, then tile entry.
     * @param array<string, GlyphTilePlanner> $planners Each gameplay layer's planner, by layer id.
     */
    private function __construct(private array $bindings, private array $planners) {}

    /** The bindings of a map's tileset pieces, over the gameplay layers the map has. */
    public static function fromMap(ProjectMap $map): self
    {
        try {
            $pieces = $map->loadTileset()?->pieces ?? [];
        } catch (InvalidArgumentException | RuntimeException) {
            $pieces = [];
        }
        $bindings = $planners = [];
        foreach ($map->getLayers() as $layer) {
            if ($pieces === [] || $layer['id'] === MapLayers::EVENT || $layer['decoration']) {
                continue;
            }
            $planner = GlyphTilePlanner::fromPieces($pieces, $layer['name']);
            $planners[$layer['id']] = $planner;
            foreach ($planner->listRoles() as $glyph => $roles) {
                foreach ($roles as $role) {
                    $piece = $pieces[$role->pieceId];
                    $companions = self::findCompanions($piece, $role);
                    foreach ($role->tiles as $tileLayer => $cells) {
                        foreach ($cells as $cell) {
                            $bindings[$tileLayer][$cell['entry']][] = new TileBinding($layer['id'], (string) $glyph, $role,
                                $cell['dx'], $cell['dy'], $piece, $companions);
                        }
                    }
                }
            }
        }

        return new self($bindings, $planners);
    }

    /** @return list<TileBinding> The glyphs a tile on a tile layer can stand for; none for a free tile. */
    public function findBindings(string $tileLayer, string $entry): array
    {
        return $this->bindings[$tileLayer][$entry] ?? [];
    }

    /** Whether a tile on a tile layer stands for a glyph. */
    public function isBound(string $tileLayer, string $entry): bool
    {
        return $this->findBindings($tileLayer, $entry) !== [];
    }

    /** The planner that keeps a gameplay layer's tiles with its glyphs, or null when the layer has no pieces. */
    public function getPlanner(string $layerId): ?GlyphTilePlanner
    {
        return $this->planners[$layerId] ?? null;
    }

    /**
     * An item piece's glyph cells that draw no tile, relative to a role's
     * glyph cell. A connected piece has none: each of its cells is its own.
     *
     * @return list<array{dx: int, dy: int, glyph: string, key: string}>
     */
    private static function findCompanions(TilesetPiece $piece, PieceRole $role): array
    {
        if ($role->cell === null) {
            return [];
        }
        $companions = [];
        foreach (PieceRole::readPiece($piece) as $glyph => $roles) {
            foreach ($roles as $other) {
                if ($other->tiles === [] && $other->cell !== null && $other->key !== $role->key) {
                    $companions[] = ['dx' => $other->cell[1] - $role->cell[1], 'dy' => $other->cell[0] - $role->cell[0],
                        'glyph' => (string) $glyph, 'key' => $other->key];
                }
            }
        }

        return $companions;
    }
}
