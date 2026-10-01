<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Closure;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;

/**
 * Keeps a gameplay layer's tiles consistent with its glyphs, however they
 * are edited. A glyph leaving a cell takes the tiles of the piece role it
 * played there; a glyph arriving brings the tiles of its role. A glyph that
 * several roles share (`-` for a chair facing north or south) takes the
 * role its neighbours prove, such as the right half of a table beside its
 * left half, or else the author's choice. Tiles no role accounts for, such
 * as a floor under a wall, are left alone.
 */
final readonly class GlyphTilePlanner
{
    /** @param array<string, list<PieceRole>> $roles Roles by glyph, distinct by the tiles they draw. */
    private function __construct(private array $roles) {}

    /**
     * @param iterable<TilesetPiece> $pieces The map's tileset pieces.
     * @param string $layerName The gameplay layer the glyphs are on.
     * @param list<string> $excludedLayers Tile layers another source already sets.
     */
    public static function fromPieces(iterable $pieces, string $layerName, array $excludedLayers = []): self
    {
        $roles = [];
        foreach ($pieces as $piece) {
            if ($piece->layer !== $layerName) {
                continue;
            }
            foreach (PieceRole::readPiece($piece, $excludedLayers) as $glyph => $pieceRoles) {
                foreach ($pieceRoles as $role) {
                    $roles[(string) $glyph][self::getSignature($role)] ??= $role;
                }
            }
        }

        return new self(array_map(array_values(...), $roles));
    }

    /** @return list<PieceRole> The roles a glyph can play, one for each set of tiles. */
    public function getRoles(string $glyph): array
    {
        return $this->roles[$glyph] ?? [];
    }

    /** The role with this key that a glyph can play, or null when it has none. */
    public function findRole(string $glyph, string $key): ?PieceRole
    {
        foreach ($this->getRoles($glyph) as $role) {
            if ($role->key === $key) {
                return $role;
            }
        }

        return null;
    }

    /**
     * The role the glyph in a cell plays now, read from the tiles there: the
     * one whose own tiles are all in place and the most of whose tiles are,
     * or null when its tiles do not show one.
     *
     * @param Closure(string, int, int): string $tileAt The entry at a cell of a tile layer.
     */
    public function findPlayedRole(string $glyph, int $x, int $y, Closure $tileAt): ?PieceRole
    {
        $best = null;
        foreach ($this->getRoles($glyph) as $role) {
            $own = $role->getOwnEntries();
            if ($own === [] || array_filter($own, static fn(string $entry, string $layer): bool =>
                $tileAt($layer, $x, $y) !== $entry, ARRAY_FILTER_USE_BOTH) !== []) {
                continue;
            }
            $matched = 0;
            foreach ($role->tiles as $layer => $cells) {
                foreach ($cells as $cell) {
                    $matched += $tileAt($layer, $x + $cell['dx'], $y + $cell['dy']) === $cell['entry'] ? 1 : 0;
                }
            }
            if ($best === null || $matched > $best[1]) {
                $best = [$role, $matched];
            }
        }

        return $best[0] ?? null;
    }

    /**
     * Plans the tile writes that keep the glyphs' tiles consistent with a set
     * of glyph changes. A change whose glyph stays the same is planned only
     * when `$repaint` says the author painted it again on purpose, which can
     * give it another role.
     *
     * @param list<array{x: int, y: int, old: string, new: string}> $changes Glyph changes, by cell.
     * @param Closure(int, int): ?string $glyphAt The layer's glyph at a cell before the changes, or null off the map.
     * @param Closure(string, int, int): string $tileAt The entry at a cell of a tile layer before the changes.
     * @param array<string, ?string> $choices The role key the author chose for a glyph, or null for no tiles.
     * @return array{tiles: array<string, list<array{x: int, y: int, entry: string}>>, unresolved: array<string, list<PieceRole>>}
     *     The tile cells to write, keyed by tile layer name, and the roles of each glyph that still needs a choice.
     */
    public function plan(array $changes, Closure $glyphAt, Closure $tileAt, array $choices = [], bool $repaint = false): array
    {
        $changes = array_values(array_filter($changes, static fn(array $change): bool => $repaint || $change['old'] !== $change['new']));
        $changed = [];
        foreach ($changes as $change) {
            $changed["{$change['x']},{$change['y']}"] = $change['new'];
        }
        $overlay = [];
        $resolved = [];
        $unresolved = [];

        foreach ($changes as $index => $change) {
            $resolved[$index] = $change['new'] === ' ' ? null : $this->resolveRole($change, $glyphAt, $tileAt, $changed, $choices, $unresolved);
        }
        // Every leaving role takes its tiles before any arriving role draws,
        // so a glyph moved within one edit keeps the tiles it brings.
        foreach ($changes as $index => $change) {
            $leaving = $this->findPlayedRole($change['old'], $change['x'], $change['y'], $tileAt);
            if ($leaving === null || ($resolved[$index] ?? null) === $leaving) {
                continue;
            }
            foreach ($leaving->tiles as $layer => $cells) {
                foreach ($cells as $cell) {
                    [$x, $y] = [$change['x'] + $cell['dx'], $change['y'] + $cell['dy']];
                    if ($glyphAt($x, $y) !== null && ($overlay[$layer]["{$x},{$y}"] ?? $tileAt($layer, $x, $y)) === $cell['entry']) {
                        $overlay[$layer]["{$x},{$y}"] = (string) TileId::EMPTY;
                    }
                }
            }
        }
        foreach ($changes as $index => $change) {
            foreach (($resolved[$index] ?? null)?->tiles ?? [] as $layer => $cells) {
                foreach ($cells as $cell) {
                    [$x, $y] = [$change['x'] + $cell['dx'], $change['y'] + $cell['dy']];
                    if ($glyphAt($x, $y) !== null) {
                        $overlay[$layer]["{$x},{$y}"] = $cell['entry'];
                    }
                }
            }
        }

        $tiles = [];
        foreach ($overlay as $layer => $cells) {
            foreach ($cells as $position => $entry) {
                [$x, $y] = array_map(intval(...), explode(',', (string) $position));
                if ($tileAt((string) $layer, $x, $y) !== $entry) {
                    $tiles[$layer][] = ['x' => $x, 'y' => $y, 'entry' => $entry];
                }
            }
        }

        return ['tiles' => $tiles, 'unresolved' => $unresolved];
    }

    /**
     * The role an arriving glyph plays: its only one; the one its neighbours
     * outside this edit prove, by holding the rest of that role's piece; or
     * the author's choice. Null draws no tiles, and a glyph with neither
     * proof nor choice is noted as unresolved.
     *
     * @param array{x: int, y: int, old: string, new: string} $change
     * @param array<string, string> $changed The glyph each cell of the edit gets, by `x,y`.
     * @param array<string, ?string> $choices
     * @param array<string, list<PieceRole>> $unresolved
     */
    private function resolveRole(array $change, Closure $glyphAt, Closure $tileAt, array $changed, array $choices, array &$unresolved): ?PieceRole
    {
        $roles = $this->getRoles($change['new']);
        if (count($roles) < 2) {
            return $roles[0] ?? null;
        }
        $proven = [];
        foreach ($roles as $role) {
            $proof = $this->countNeighbourProof($role, $change['x'], $change['y'], $glyphAt, $tileAt, $changed);
            if ($proof > 0) {
                $proven[$proof][] = $role;
            }
        }
        if ($proven !== []) {
            krsort($proven);
            $strongest = reset($proven);
            if (count($strongest) === 1) {
                return $strongest[0];
            }
        }
        if (array_key_exists($change['new'], $choices)) {
            return $choices[$change['new']] === null ? null : $this->findRole($change['new'], $choices[$change['new']]);
        }
        $unresolved[$change['new']] = $roles;

        return null;
    }

    /**
     * How many of the other glyph cells of a role's piece its neighbours
     * outside the edit hold, each with its glyph and its own tiles.
     *
     * @param array<string, string> $changed
     */
    private function countNeighbourProof(PieceRole $role, int $x, int $y, Closure $glyphAt, Closure $tileAt, array $changed): int
    {
        $proof = 0;
        foreach ($this->roles as $glyph => $roles) {
            foreach ($roles as $other) {
                $offset = self::getOffset($role, $other);
                if ($offset === null) {
                    continue;
                }
                [$otherX, $otherY] = [$x + $offset[0], $y + $offset[1]];
                if (isset($changed["{$otherX},{$otherY}"]) || $glyphAt($otherX, $otherY) !== (string) $glyph) {
                    continue;
                }
                $own = $other->getOwnEntries();
                if ($own !== [] && array_filter($own, static fn(string $entry, string $layer): bool =>
                    $tileAt($layer, $otherX, $otherY) !== $entry, ARRAY_FILTER_USE_BOTH) === []) {
                    $proof++;
                }
            }
        }

        return $proof;
    }

    /**
     * Where another cell of the same item piece lies from this role's cell,
     * or null when the two roles are not cells of one item piece.
     *
     * @return array{int, int}|null Columns across and rows down.
     */
    private static function getOffset(PieceRole $role, PieceRole $other): ?array
    {
        if ($role->cell === null || $other->cell === null || $role->pieceId !== $other->pieceId || $role->key === $other->key) {
            return null;
        }

        return [$other->cell[1] - $role->cell[1], $other->cell[0] - $role->cell[0]];
    }

    /** What a role draws, so roles that draw the same tiles count once. */
    private static function getSignature(PieceRole $role): string
    {
        $tiles = $role->tiles;
        ksort($tiles);

        return json_encode($tiles, JSON_THROW_ON_ERROR);
    }
}
