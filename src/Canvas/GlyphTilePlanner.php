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
    /**
     * @param array<string, list<PieceRole>> $roles Roles by glyph, distinct by the tiles they draw and keep.
     * @param array<string, PieceRole> $twins The kept role for each key of a role that draws and keeps the same tiles with the same glyph.
     */
    private function __construct(private array $roles, private array $twins = []) {}

    /**
     * @param iterable<TilesetPiece> $pieces The map's tileset pieces.
     * @param string $layerName The gameplay layer the glyphs are on.
     * @param list<string> $excludedLayers Tile layers another source already sets.
     */
    public static function fromPieces(iterable $pieces, string $layerName, array $excludedLayers = []): self
    {
        $roles = $twins = [];
        foreach ($pieces as $piece) {
            if ($piece->layer !== $layerName) {
                continue;
            }
            foreach (PieceRole::readPiece($piece, $excludedLayers) as $glyph => $pieceRoles) {
                foreach ($pieceRoles as $role) {
                    $kept = $roles[(string) $glyph][self::getSignature($role)] ??= $role;
                    if ($kept !== $role) {
                        $twins[$role->key] = $kept;
                    }
                }
            }
        }

        return new self(array_map(array_values(...), $roles), $twins);
    }

    /** @return array<string, list<PieceRole>> Every role the layer's glyphs can play, keyed by glyph. */
    public function listRoles(): array
    {
        return $this->roles;
    }

    /** @return list<PieceRole> The roles a glyph can play, one for each drawing and keeping behaviour. */
    public function getRoles(string $glyph): array
    {
        return $this->roles[$glyph] ?? [];
    }

    /**
     * The role with this key that a glyph can play, or null when it has none.
     * A role kept for another with the same drawing and keeping behaviour answers for its key.
     */
    public function findRole(string $glyph, string $key): ?PieceRole
    {
        $twin = $this->twins[$key] ?? null;
        foreach ($this->getRoles($glyph) as $role) {
            if ($role->key === $key || $role === $twin) {
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
     * @param array<string, string> $assigned The role key a cell's glyph plays, by `x,y`, when the edit already knows
     *     it, such as a tile edit that placed that role's tile. It outranks proof and choice, and plans the cell even
     *     when its glyph stays the same, so a role whose tiles had gone missing gets them back.
     * @return array{tiles: array<string, list<array{x: int, y: int, entry: string}>>, unresolved: array<string, list<PieceRole>>, glyphs: list<array{x: int, y: int, symbol: string, role: PieceRole}>}
     *     The tile cells to write, keyed by tile layer name; the roles of each glyph that still needs a choice; and
     *     the cells an erase gives back to the role their kept underlay proves ({@see findUnderlayRole()}), which
     *     the edit writes instead of a blank.
     */
    public function plan(array $changes, Closure $glyphAt, Closure $tileAt, array $choices = [], bool $repaint = false,
        array $assigned = []): array
    {
        $changes = array_values(array_filter($changes, static fn(array $change): bool => $repaint || $change['old'] !== $change['new']
            || isset($assigned["{$change['x']},{$change['y']}"])));
        $unresolved = [];
        $glyphs = [];
        $underlay = [];
        foreach ($changes as $index => $change) {
            if ($change['new'] === ' ' && ($found = $this->findUnderlayRole($change, $tileAt, $choices, $unresolved)) !== null) {
                [$changes[$index]['new'], $underlay[$index]] = $found;
                $glyphs[] = ['x' => $change['x'], 'y' => $change['y'], 'symbol' => $found[0], 'role' => $found[1]];
            }
        }
        $changed = [];
        foreach ($changes as $change) {
            $changed["{$change['x']},{$change['y']}"] = $change['new'];
        }
        $overlay = [];
        $resolved = [];
        $kept = [];

        foreach ($changes as $index => $change) {
            $key = $assigned["{$change['x']},{$change['y']}"] ?? null;
            $resolved[$index] = match (true) {
                isset($underlay[$index]) => $underlay[$index],
                $change['new'] === ' ' => null,
                $key !== null => $this->findRole($change['new'], $key),
                default => $this->resolveRole($change, $glyphAt, $tileAt, $changed, $choices, $unresolved),
            };
            foreach ($resolved[$index]?->keeps ?? [] as $layer => $cells) {
                foreach ($cells as $cell) {
                    [$x, $y] = [$change['x'] + $cell['dx'], $change['y'] + $cell['dy']];
                    if ($glyphAt($x, $y) !== null) {
                        $kept[$layer]["{$x},{$y}"] = true;
                    }
                }
            }
        }
        // Every leaving role takes its tiles before any arriving role draws,
        // except existing underlay kept by any arriving role, including its blank cells.
        foreach ($changes as $index => $change) {
            $leaving = $this->findPlayedRole($change['old'], $change['x'], $change['y'], $tileAt);
            if ($leaving === null || ($resolved[$index] ?? null) === $leaving) {
                continue;
            }
            foreach ($leaving->tiles as $layer => $cells) {
                foreach ($cells as $cell) {
                    [$x, $y] = [$change['x'] + $cell['dx'], $change['y'] + $cell['dy']];
                    if (! isset($kept[$layer]["{$x},{$y}"]) && $glyphAt($x, $y) !== null
                        && ($overlay[$layer]["{$x},{$y}"] ?? $tileAt($layer, $x, $y)) === $cell['entry']) {
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

        return ['tiles' => $tiles, 'unresolved' => $unresolved, 'glyphs' => $glyphs];
    }

    /**
     * The role an erased cell goes back to: when the role leaving it keeps a
     * layer's existing tiles there, the role those kept tiles alone prove,
     * one whose own tiles at the cell are all present and all on kept layers
     * (the wall face a window was mounted on). Erasing gives the cell that
     * role's glyph, so its terminal and collision role returns with the tiles
     * that never left. Roles of another glyph proven the same way make the
     * erase wait for the author's choice, keyed by the blank glyph; choosing
     * none leaves the cell blank. Null when nothing is kept or nothing proven.
     *
     * @param array{x: int, y: int, old: string, new: string} $change
     * @param array<string, ?string> $choices
     * @param array<string, list<PieceRole>> $unresolved
     * @return array{0: string, 1: PieceRole}|null The glyph and role the cell takes.
     */
    private function findUnderlayRole(array $change, Closure $tileAt, array $choices, array &$unresolved): ?array
    {
        [$x, $y] = [$change['x'], $change['y']];
        $leaving = $this->findPlayedRole($change['old'], $x, $y, $tileAt);
        $keptLayers = [];
        foreach ($leaving?->keeps ?? [] as $layer => $cells) {
            if (array_any($cells, static fn(array $cell): bool => $cell['dx'] === 0 && $cell['dy'] === 0)) {
                $keptLayers[$layer] = true;
            }
        }
        if ($leaving === null || $keptLayers === []) {
            return null;
        }
        $proven = [];
        foreach (array_keys($this->roles) as $glyph) {
            $role = $this->findPlayedRole((string) $glyph, $x, $y, $tileAt);
            if ($role !== null && $role->pieceId !== $leaving->pieceId && array_diff_key($role->getOwnEntries(), $keptLayers) === []) {
                $proven[(string) $glyph] = $role;
            }
        }
        if (count($proven) < 2) {
            return $proven === [] ? null : [(string) array_key_first($proven), reset($proven)];
        }
        if (array_key_exists(' ', $choices)) {
            foreach ($proven as $glyph => $role) {
                if ($choices[' '] !== null && $this->findRole($glyph, $choices[' ']) === $role) {
                    return [$glyph, $role];
                }
            }

            return null;
        }
        $waiting = [];
        foreach ([...($unresolved[' '] ?? []), ...array_values($proven)] as $role) {
            $waiting[$role->key] = $role;
        }
        $unresolved[' '] = array_values($waiting);

        return null;
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

    /** What a role draws and keeps, so only behaviourally identical roles count once. */
    private static function getSignature(PieceRole $role): string
    {
        $tiles = $role->tiles;
        $keeps = $role->keeps;
        ksort($tiles);
        ksort($keeps);

        return json_encode([$tiles, $keeps], JSON_THROW_ON_ERROR);
    }
}
