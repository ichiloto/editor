<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Closure;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\History\PaintStrokeCommand;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Maps\MapLayers;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use InvalidArgumentException;
use RuntimeException;

/**
 * Edits a map's canvas the one way every interface does: glyphs written onto
 * a layer, and the tiles that keep that layer's pieces drawn where its glyphs
 * are (see {@see GlyphTilePlanner}), as one undo step.
 *
 * An edit is two calls. {@see plan()} works out the tiles and reports a glyph
 * that could be several pieces, which the caller asks the author about and
 * plans again with the answer. {@see apply()} writes the glyphs and tiles and
 * returns the command that undoes and redoes them, applied and unrecorded, for
 * the caller's history. Neither call knows a cursor, a focused pane, a status
 * line or a dialog; refusals are returned or thrown, never shown.
 */
final class CanvasEditor
{
    /**
     * Works out the tiles an edit's glyphs need, or why it cannot go ahead.
     *
     * @param array<int, array{x: int, y: int, symbol: string}> $writes The glyphs the edit writes.
     * @param array<string, ?string> $choices The role key chosen for a glyph, or null for no tiles.
     * @param bool $repaint Whether a glyph painted over itself may take another role.
     * @param list<string> $excludedLayers Tile layers the edit sets itself.
     * @param array<string, string> $assigned The role key a written cell's glyph plays, by `x,y`, when the edit knows it.
     * @return array{tiles: array<string, list<array{x: int, y: int, entry: string}>>, unresolved: array<string, list<PieceRole>>}|null
     *     The tiles and the glyphs still waiting on a choice, or null when the layer has no pieces to follow.
     * @throws MapSourceRefusal When a tile layer the pieces draw on cannot be read.
     */
    public static function plan(ProjectMap $map, string $layerId, array $writes, array $choices = [], bool $repaint = false,
        array $excludedLayers = [], array $assigned = []): ?array
    {
        $planner = self::loadGlyphTilePlanner($map, $layerId, $excludedLayers);
        if ($planner === null) {
            return null;
        }
        $changes = [];
        foreach ($writes as $write) {
            if ($map->hasLayerCell($layerId, $write['x'], $write['y'])) {
                $changes[] = ['x' => $write['x'], 'y' => $write['y'],
                    'old' => $map->getLayerSymbol($layerId, $write['x'], $write['y']), 'new' => $write['symbol']];
            }
        }

        return $planner->plan(
            $changes,
            static fn(int $x, int $y): ?string => $map->hasLayerCell($layerId, $x, $y) ? $map->getLayerSymbol($layerId, $x, $y) : null,
            self::createTileReader($map),
            $choices,
            $repaint,
            $assigned,
        );
    }

    /**
     * Writes glyphs onto a layer and tiles onto the tile layers, and returns
     * the command that undoes and redoes both, already applied. The command
     * is null when nothing changed.
     *
     * @param array<int, array{x: int, y: int, symbol: string, color?: string|null, style?: array{prefix: string, suffix: string}}> $writes
     * @param array<string, list<array{x: int, y: int, entry: string}>> $tiles Tile cells keyed by tile layer name.
     * @return array{command: ?Command, changed: int} The command and the number of glyph cells that changed.
     * @throws MapSourceRefusal When the tile layers cannot take the tiles; nothing is written then.
     */
    public static function apply(ProjectMap $map, string $layerId, array $writes, string $label, array $tiles = []): array
    {
        if ($tiles === []) {
            [$stroke, $changed] = self::writeCells($map, $layerId, $writes, $label);

            return ['command' => $stroke->hasChanges() ? $stroke : null, 'changed' => $changed];
        }
        $tilesBefore = $map->getTileLayerSources();
        $map->writeTileCells($tiles);
        $tilesAfter = $map->getTileLayerSources();
        [$stroke, $changed] = self::writeCells($map, $layerId, $writes, $label);
        $command = $stroke->hasChanges() || $tilesAfter !== $tilesBefore
            ? self::combineStrokeWithTiles($label, $map, $stroke, $tilesBefore, $tilesAfter)
            : null;

        return ['command' => $command, 'changed' => $changed];
    }

    /**
     * Writes cells onto one layer and returns the stroke that undoes them,
     * unrecorded, so a caller can record it alone or as part of a larger
     * step. Cells outside the layer are skipped.
     *
     * @param array<int, array{x: int, y: int, symbol: string, color?: string|null, style?: array{prefix: string, suffix: string}}> $writes The cells to write.
     * @return array{0: PaintStrokeCommand, 1: int} The stroke and the number of cells that actually changed.
     */
    public static function writeCells(ProjectMap $map, string $layer, array $writes, string $label): array
    {
        $stroke = new PaintStrokeCommand($map, $layer, $label);
        $changed = 0;

        foreach ($writes as $write) {
            if (! $map->hasLayerCell($layer, $write['x'], $write['y'])) {
                continue;
            }

            $oldSymbol = $map->getLayerSymbol($layer, $write['x'], $write['y']);
            $oldStyle = $map->getLayerCellStyle($layer, $write['x'], $write['y']);
            [$newPrefix, $newSuffix] = isset($write['style'])
                ? [$write['style']['prefix'], $write['style']['suffix']]
                : self::resolvePaintStyle($write['symbol'], $write['color'] ?? null, $oldStyle);
            $map->setLayerCell($layer, $write['x'], $write['y'], $write['symbol'], $newPrefix, $newSuffix);
            $newSymbol = $map->getLayerSymbol($layer, $write['x'], $write['y']);
            $stroke->appendCell($write['x'], $write['y'], $oldSymbol, $newSymbol, $oldStyle['prefix'], $oldStyle['suffix'],
                $newPrefix, $newSuffix);

            if ($oldSymbol !== $newSymbol || $oldStyle['prefix'] !== $newPrefix || $oldStyle['suffix'] !== $newSuffix) {
                $changed++;
            }
        }

        return [$stroke, $changed];
    }

    /**
     * Resolves the styling bytes a paint writes.
     *
     * The colour directive follows the brush contract: null keeps the
     * cell's existing styling byte-for-byte, an empty string paints without
     * colour, and any other value becomes an `fg=` tag. A space is always
     * uncoloured, so erasing never leaves invisible styling behind.
     *
     * @param array{prefix: string, suffix: string} $oldStyle The cell's current styling.
     * @return array{0: string, 1: string} The prefix and suffix to write.
     */
    public static function resolvePaintStyle(string $symbol, ?string $colorDirective, array $oldStyle): array
    {
        if ($symbol === ' ') {
            return ['', ''];
        }

        if ($colorDirective === null) {
            return [$oldStyle['prefix'], $oldStyle['suffix']];
        }

        if ($colorDirective === '') {
            return ['', ''];
        }

        return [sprintf('<fg=%s>', $colorDirective), '</>'];
    }

    /**
     * Sets one tile in each cell of a tile layer, `0` erasing it, as one undo
     * step, by {@see stampTiles()}: a tile that stands for a glyph brings or
     * takes that glyph with it.
     *
     * @param list<array{0: int, 1: int}> $cells The cells, as [x, y].
     * @param array<string, string> $choices The role key chosen for a tile that could stand for several glyphs, by tile entry.
     * @return array{command: ?Command, changed: int, glyphs: int, unresolved: array<string, list<PieceRole>>}
     * @throws MapSourceRefusal When the tile is not a tile identity, a cell is outside the map or the layer cannot be written; nothing is changed.
     */
    public static function setTiles(ProjectMap $map, string $layerName, array $cells, int $tile, string $label, array $choices = []): array
    {
        return self::stampTiles($map, $layerName, array_map(static fn(array $cell): array => [$cell[0], $cell[1], $tile], $cells), $label, $choices);
    }

    /**
     * Sets each listed cell of a tile layer to its own tile, `0` erasing, as
     * one undo step: a block of tiles chosen together and stamped, as RPG
     * Maker places a multi-tile selection. The layer is created when the map
     * does not have it yet. An autotile is placed by its kind; the Engine
     * shapes it from its neighbours.
     *
     * A tile that stands for a glyph ({@see TileGlyphBinding}) never moves
     * without it. Placing one writes its glyph where its role puts it, with
     * the rest of that role's tiles and the piece's glyph cells that draw no
     * tile; erasing or covering one removes the glyph it stood for, with its
     * other tiles. A wall's glyphs take the shapes their neighbours give them.
     * Glyphs, their collision and every tile change as one undo step. A tile
     * that stands for nothing, such as a rug, is written as given. An erase
     * and a stamp in one call move a piece.
     *
     * @param list<array{0: int, 1: int, 2: int}> $cells Each cell as [x, y, tile].
     * @param array<string, string> $choices The role key chosen for a tile that could stand for several glyphs, by tile entry.
     * @return array{command: ?Command, changed: int, glyphs: int, unresolved: array<string, list<PieceRole>>}
     *     No command when nothing changed, or when a tile still waits on a choice ({@see PieceRole} by tile entry).
     * @throws MapSourceRefusal When a tile is not a tile identity, a cell is outside the map, a tile's glyph would fall
     *     outside its layer or the layer cannot be written; nothing is changed.
     */
    public static function stampTiles(ProjectMap $map, string $layerName, array $cells, string $label, array $choices = []): array
    {
        $stamp = [];
        foreach ($cells as [$x, $y, $tile]) {
            $stamp["{$x},{$y}"] = (string) $tile;
        }
        $binding = TileGlyphBinding::fromMap($map);
        $tileAt = self::createTileReader($map);
        $glyphPlan = self::planStampGlyphs($map, $binding, $layerName, $stamp, $tileAt, $choices);
        if ($glyphPlan['unresolved'] !== []) {
            return ['command' => null, 'changed' => 0, 'glyphs' => 0, 'unresolved' => $glyphPlan['unresolved']];
        }

        // The tiles the glyphs' roles take and bring, then the stamp's own
        // tiles, except where an arriving role draws: its tiles stay whole.
        $tiles = [];
        foreach ($glyphPlan['writes'] as $layerId => $writes) {
            $plan = self::plan($map, $layerId, $writes, assigned: $glyphPlan['assigned'][$layerId] ?? []);
            foreach ($plan['tiles'] ?? [] as $tileLayer => $entries) {
                foreach ($entries as $entry) {
                    $tiles[$tileLayer]["{$entry['x']},{$entry['y']}"] = $entry['entry'];
                }
            }
        }
        foreach ($stamp as $position => $entry) {
            $planned = $tiles[$layerName][$position] ?? null;
            if ($planned === null || $planned === (string) TileId::EMPTY) {
                $tiles[$layerName][$position] = $entry;
            }
        }

        $before = $map->getTileLayerSources();
        $previous = [];
        foreach (array_keys($tiles) as $tileLayer) {
            $previous[$tileLayer] = $map->readTileEntries([$tileLayer], 0, 0, $map->getWidth(), $map->getHeight())[$tileLayer] ?? [];
        }
        $map->writeTileCells(array_map(static fn(array $entries): array => array_map(static function (string $entry, string $position): array {
            [$x, $y] = array_map(intval(...), explode(',', $position));

            return ['x' => $x, 'y' => $y, 'entry' => $entry];
        }, $entries, array_map(strval(...), array_keys($entries))), $tiles));
        $after = $map->getTileLayerSources();
        $strokes = [];
        $glyphs = 0;
        foreach ($glyphPlan['writes'] as $layerId => $writes) {
            [$stroke, $changed] = self::writeCells($map, $layerId, $writes, $label);
            $glyphs += $changed;
            if ($stroke->hasChanges()) {
                $strokes[] = $stroke;
            }
        }
        if ($strokes === [] && $after === $before) {
            return ['command' => null, 'changed' => 0, 'glyphs' => 0, 'unresolved' => []];
        }
        $changed = 0;
        foreach ($tiles as $tileLayer => $entries) {
            foreach ($entries as $position => $entry) {
                [$x, $y] = array_map(intval(...), explode(',', (string) $position));
                $changed += ($previous[$tileLayer][$y][$x] ?? (string) TileId::EMPTY) !== $entry ? 1 : 0;
            }
        }

        return ['command' => new GenericCommand($label,
            static function () use ($strokes, $map, $after): void {
                foreach ($strokes as $stroke) {
                    $stroke->execute();
                }
                $map->restoreTileLayerSources($after);
            },
            static function () use ($strokes, $map, $before): void {
                foreach (array_reverse($strokes) as $stroke) {
                    $stroke->undo();
                }
                $map->restoreTileLayerSources($before);
            }), 'changed' => $changed, 'glyphs' => $glyphs, 'unresolved' => []];
    }

    /**
     * The glyphs a tile stamp writes so no tile that stands for a glyph is
     * left without it: the glyph a covered or erased tile stood for leaves,
     * and the glyph a placed tile stands for arrives, each with the piece's
     * glyph cells that draw no tile. A wall joins or leaves its connected
     * piece and every wall cell beside it takes its new shape.
     *
     * @param array<string, string> $stamp The stamp's tile entries, by `x,y`.
     * @param Closure(string, int, int): string $tileAt Tile entries before the stamp.
     * @param array<string, string> $choices The role key chosen for an ambiguous tile, by entry.
     * @return array{writes: array<string, list<array{x: int, y: int, symbol: string}>>, assigned: array<string, array<string, string>>,
     *     unresolved: array<string, list<PieceRole>>} Glyph writes and the role each written cell plays, by gameplay layer id.
     * @throws MapSourceRefusal When a tile's glyph would fall outside its layer.
     */
    private static function planStampGlyphs(ProjectMap $map, TileGlyphBinding $binding, string $tileLayer, array $stamp,
        Closure $tileAt, array $choices): array
    {
        $glyphAt = static fn(string $layerId, int $x, int $y): ?string
            => $map->hasLayerCell($layerId, $x, $y) ? $map->getLayerSymbol($layerId, $x, $y) : null;
        $symbols = $assigned = $unresolved = [];
        $connected = [];

        $leave = static function (TileBinding $bound, int $x, int $y) use (&$symbols, &$assigned, &$connected, $glyphAt): void {
            if ($bound->isConnected) {
                $connected[$bound->layerId][$bound->piece->id]['erased']["{$x},{$y}"] = ['x' => $x, 'y' => $y];
                return;
            }
            $symbols[$bound->layerId]["{$x},{$y}"] = ' ';
            unset($assigned[$bound->layerId]["{$x},{$y}"]);
            foreach ($bound->companions as $companion) {
                [$cx, $cy] = [$x + $companion['dx'], $y + $companion['dy']];
                if ($glyphAt($bound->layerId, $cx, $cy) === $companion['glyph']) {
                    $symbols[$bound->layerId]["{$cx},{$cy}"] = ' ';
                }
            }
        };
        $arrive = static function (TileBinding $bound, int $x, int $y) use (&$symbols, &$assigned, &$connected, $glyphAt): void {
            if ($glyphAt($bound->layerId, $x, $y) === null) {
                throw new MapSourceRefusal(sprintf('That tile stands for %s, whose glyph at (%d, %d) is outside the map. Nothing was changed.',
                    $bound->role->label, $x, $y));
            }
            if ($bound->isConnected) {
                $connected[$bound->layerId][$bound->piece->id]['drawn']["{$x},{$y}"] = ['x' => $x, 'y' => $y];
                return;
            }
            $symbols[$bound->layerId]["{$x},{$y}"] = $bound->glyph;
            $assigned[$bound->layerId]["{$x},{$y}"] = $bound->role->key;
            foreach ($bound->companions as $companion) {
                [$cx, $cy] = [$x + $companion['dx'], $y + $companion['dy']];
                if ($glyphAt($bound->layerId, $cx, $cy) !== null) {
                    $symbols[$bound->layerId]["{$cx},{$cy}"] = $companion['glyph'];
                    $assigned[$bound->layerId]["{$cx},{$cy}"] = $companion['key'];
                }
            }
        };

        // Leaving first: the glyph each covered or erased tile stood for.
        foreach ($stamp as $position => $entry) {
            [$x, $y] = array_map(intval(...), explode(',', (string) $position));
            $old = $tileAt($tileLayer, $x, $y);
            if ($old === $entry) {
                continue;
            }
            foreach ($binding->findBindings($tileLayer, $old) as $bound) {
                [$gx, $gy] = [$x - $bound->dx, $y - $bound->dy];
                if ($glyphAt($bound->layerId, $gx, $gy) === $bound->glyph
                    && $binding->getPlanner($bound->layerId)?->findPlayedRole($bound->glyph, $gx, $gy, $tileAt)?->key === $bound->role->key) {
                    $leave($bound, $gx, $gy);
                    break;
                }
            }
        }
        // Then arriving: the glyph each placed tile stands for.
        foreach ($stamp as $position => $entry) {
            [$x, $y] = array_map(intval(...), explode(',', (string) $position));
            $bound = self::chooseBinding($binding->findBindings($tileLayer, $entry), $tileLayer, $x, $y, $stamp, $glyphAt, $choices[$entry] ?? null);
            if ($bound instanceof TileBinding) {
                $arrive($bound, $x - $bound->dx, $y - $bound->dy);
            } elseif ($bound !== null) {
                $unresolved[$entry] = $bound;
            }
        }
        // Connected pieces: every drawn, erased and neighbouring wall cell takes its shape.
        foreach ($connected as $layerId => $pieces) {
            foreach ($pieces as $pieceId => $cells) {
                $piece = $map->loadTileset()?->pieces[$pieceId] ?? null;
                if ($piece === null) {
                    continue;
                }
                $isMember = static fn(int $x, int $y): bool => in_array($glyphAt($layerId, $x, $y), $piece->shapes, true);
                foreach (ConnectedPieceShaper::reshapeCells($piece, array_values($cells['drawn'] ?? []), array_values($cells['erased'] ?? []), $isMember) as $cell) {
                    if ($glyphAt($layerId, $cell['x'], $cell['y']) !== null) {
                        $symbols[$layerId]["{$cell['x']},{$cell['y']}"] = $cell['shape'] === null ? ' ' : $piece->shapes[$cell['shape']];
                    }
                }
            }
        }

        $writes = [];
        foreach ($symbols as $layerId => $cells) {
            foreach ($cells as $position => $symbol) {
                [$x, $y] = array_map(intval(...), explode(',', (string) $position));
                $writes[$layerId][] = ['x' => $x, 'y' => $y, 'symbol' => $symbol];
            }
        }

        return ['writes' => $writes, 'assigned' => $assigned, 'unresolved' => $unresolved];
    }

    /**
     * The glyph a placed tile stands for: its only binding; the one the rest
     * of the stamp proves, by holding more of that role's tiles; the author's
     * choice; or, still undecided, the roles to choose among. The cells of
     * one connected piece count as one binding, since their neighbours decide
     * the shape. Null for a tile that stands for nothing.
     *
     * @param list<TileBinding> $bindings
     * @param array<string, string> $stamp
     * @param Closure(string, int, int): ?string $glyphAt
     * @return TileBinding|list<PieceRole>|null
     */
    private static function chooseBinding(array $bindings, string $tileLayer, int $x, int $y, array $stamp, Closure $glyphAt,
        ?string $choice): TileBinding|array|null
    {
        $distinct = [];
        foreach ($bindings as $bound) {
            $distinct[$bound->isConnected ? "{$bound->layerId}:{$bound->piece->id}" : "{$bound->layerId}:{$bound->role->key}"] ??= $bound;
        }
        if (count($distinct) < 2) {
            return array_values($distinct)[0] ?? null;
        }
        if ($choice !== null) {
            foreach ($distinct as $bound) {
                if ($bound->role->key === $choice) {
                    return $bound;
                }
            }
        }
        $scored = [];
        foreach ($distinct as $bound) {
            [$gx, $gy] = [$x - $bound->dx, $y - $bound->dy];
            $score = 0;
            foreach ($bound->role->tiles[$tileLayer] ?? [] as $cell) {
                $score += ($stamp[($gx + $cell['dx']) . ',' . ($gy + $cell['dy'])] ?? null) === $cell['entry'] ? 2 : 0;
            }
            $score += $glyphAt($bound->layerId, $gx, $gy) === $bound->glyph ? 1 : 0;
            $scored[$score][] = $bound;
        }
        krsort($scored);
        $best = reset($scored);
        if (count($best) === 1) {
            return $best[0];
        }

        return array_values(array_map(static fn(TileBinding $bound): PieceRole => $bound->role, $distinct));
    }

    /**
     * One undo step for a glyph stroke and the tile layer change made with it,
     * such as a stamped piece or a pasted block.
     *
     * @param array<string, string> $tilesBefore Tile layer sources before the change.
     * @param array<string, string> $tilesAfter Tile layer sources after it.
     */
    public static function combineStrokeWithTiles(string $label, ProjectMap $map, PaintStrokeCommand $stroke,
        array $tilesBefore, array $tilesAfter): Command
    {
        return new GenericCommand($label,
            static function () use ($stroke, $map, $tilesAfter): void {
                $stroke->execute();
                $map->restoreTileLayerSources($tilesAfter);
            },
            static function () use ($stroke, $map, $tilesBefore): void {
                $stroke->undo();
                $map->restoreTileLayerSources($tilesBefore);
            },
        );
    }

    /** The planner for a gameplay layer's pieces, or null when it has none to follow. */
    public static function loadGlyphTilePlanner(ProjectMap $map, string $layerId, array $excludedLayers = []): ?GlyphTilePlanner
    {
        $layer = array_find($map->getLayers(), static fn(array $candidate): bool => $candidate['id'] === $layerId);
        if ($layer === null || $layer['id'] === MapLayers::EVENT || $layer['decoration']) {
            return null;
        }
        try {
            $pieces = $map->loadTileset()?->pieces ?? [];
        } catch (InvalidArgumentException | RuntimeException) {
            return null;
        }

        return $pieces === [] ? null : GlyphTilePlanner::fromPieces($pieces, $layer['name'], $excludedLayers);
    }

    /**
     * Reads tile entries cell by cell, each layer once: `0` where a layer
     * has no tile, or the map has no such layer.
     *
     * @return Closure(string, int, int): string
     */
    public static function createTileReader(ProjectMap $map): Closure
    {
        $layers = [];

        return static function (string $layer, int $x, int $y) use ($map, &$layers): string {
            $layers[$layer] ??= $map->readTileEntries([$layer], 0, 0, $map->getWidth(), $map->getHeight())[$layer] ?? [];

            return $layers[$layer][$y][$x] ?? (string) TileId::EMPTY;
        };
    }
}
