<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Closure;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Maps\MapLayers;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Status\StatusLevel;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use InvalidArgumentException;
use RuntimeException;

/**
 * Tiles follow glyphs on the terminal canvas: every glyph edit on a gameplay
 * layer, whether typed, painted, drawn, filled, cut or pasted, also writes
 * the tiles that keep that layer's pieces drawn where their glyphs are (see
 * {@see GlyphTilePlanner}), in the same undo step. When a glyph could be
 * several pieces and its neighbours do not show which, the edit waits while
 * the author chooses; the brush remembers the answer for its glyph until a
 * glyph is typed again.
 */
trait GlyphTileCanvas
{
    /** @var array{symbol: string, role: ?string}|null The piece role the brush's glyph draws, or null tiles for none, as the author chose. */
    private ?array $paintPieceRole = null;

    /** @var array{map: ProjectMap, sources: array<string, string>}|null The tile layers before the mouse stroke in progress changed them. */
    private ?array $activeStrokeTiles = null;

    /**
     * Writes cells onto the active layer and the tiles that follow them as
     * one undo step. Tiles another source sets, such as a pasted block's,
     * win on their layers. A glyph that needs the author's choice opens the
     * choice and changes nothing yet; choosing runs the edit again through
     * `$retry` with the answer among its choices.
     *
     * @param array<int, array{x: int, y: int, symbol: string, color?: string|null, style?: array{prefix: string, suffix: string}}> $writes The cells to write.
     * @param array<string, list<array{x: int, y: int, entry: string}>> $tiles Tile cells set by the edit itself, keyed by tile layer name.
     * @param (Closure(array<string, ?string>): void)|null $retry Runs the edit again with the author's choices.
     * @param array<string, ?string> $choices The role key chosen for a glyph, or null for no tiles.
     * @param bool $repaint Whether a glyph painted over itself may take another role.
     * @return int|null The number of glyph cells that changed, or null when nothing changed: refused, or waiting on a choice.
     */
    private function commitCanvasWrites(ProjectMap $map, array $writes, string $label, array $tiles = [], ?Closure $retry = null,
        array $choices = [], bool $repaint = false): ?int
    {
        if ($writes === [] && $tiles === []) {
            return 0;
        }
        $layer = $this->getActiveCanvasLayer();
        try {
            $plan = $this->planGlyphTiles($map, $layer, $writes, $choices, $repaint, array_keys($tiles));
        } catch (MapSourceRefusal $refusal) {
            $this->setStatus($refusal->getMessage(), StatusLevel::WARN);
            return null;
        }
        if ($plan !== null && $plan['unresolved'] !== [] && $retry !== null) {
            $glyph = (string) array_key_first($plan['unresolved']);
            $this->askForGlyphPiece($glyph, $plan['unresolved'][$glyph], $choices, $retry);
            return null;
        }
        $tiles = [...($plan['tiles'] ?? []), ...$tiles];

        $this->finalizeActiveStroke();
        if ($tiles === []) {
            [$stroke, $changed] = $this->writeCanvasCells($map, $layer, $writes, $label);
            if ($stroke->hasChanges()) {
                $this->recordCommand($stroke);
            }
            return $changed;
        }
        $tilesBefore = $map->getTileLayerSources();
        try {
            $map->writeTileCells($tiles);
        } catch (MapSourceRefusal $refusal) {
            $this->setStatus($refusal->getMessage(), StatusLevel::WARN);
            return null;
        }
        $tilesAfter = $map->getTileLayerSources();
        [$stroke, $changed] = $this->writeCanvasCells($map, $layer, $writes, $label);
        if ($stroke->hasChanges() || $tilesAfter !== $tilesBefore) {
            $this->recordStrokeWithTiles($label, $map, $stroke, $tilesBefore, $tilesAfter);
        }

        return $changed;
    }

    /**
     * Writes the tiles that follow one step of a mouse stroke, before its
     * glyphs are written, so the stroke records them with its glyphs when it
     * ends ({@see finalizeActiveStroke()}).
     *
     * @param array<int, array{x: int, y: int, symbol: string}> $writes
     * @param Closure(array<string, ?string>): void $retry Paints this step again with the author's choices.
     * @return bool Whether the glyphs may be written: false when refused or waiting on a choice.
     */
    private function followStrokeWithTiles(ProjectMap $map, array $writes, Closure $retry): bool
    {
        try {
            $plan = $this->planGlyphTiles($map, $this->getActiveCanvasLayer(), $writes,
                $this->getPaintPieceChoices((string) ($writes[0]['symbol'] ?? '')), true);
        } catch (MapSourceRefusal $refusal) {
            $this->setStatus($refusal->getMessage(), StatusLevel::WARN);
            return false;
        }
        if ($plan === null) {
            return true;
        }
        if ($plan['unresolved'] !== []) {
            $this->finalizeActiveStroke();
            $glyph = (string) array_key_first($plan['unresolved']);
            $this->askForGlyphPiece($glyph, $plan['unresolved'][$glyph], [], $retry);
            return false;
        }
        if ($plan['tiles'] === []) {
            return true;
        }
        if ($this->activeStrokeTiles === null || $this->activeStrokeTiles['map'] !== $map) {
            $this->activeStrokeTiles = ['map' => $map, 'sources' => $map->getTileLayerSources()];
        }
        try {
            $map->writeTileCells($plan['tiles']);
        } catch (MapSourceRefusal $refusal) {
            $this->setStatus($refusal->getMessage(), StatusLevel::WARN);
            return false;
        }

        return true;
    }

    /**
     * The tiles that keep a layer's pieces drawn where its glyphs will be,
     * or null when the layer has no pieces to follow: the event layer, a
     * decoration layer, or a map whose tileset cannot load, which validation
     * reports.
     *
     * @param array<int, array{x: int, y: int, symbol: string}> $writes
     * @param array<string, ?string> $choices
     * @param list<string> $excludedLayers Tile layers the edit sets itself.
     * @return array{tiles: array<string, list<array{x: int, y: int, entry: string}>>, unresolved: array<string, list<PieceRole>>}|null
     * @throws MapSourceRefusal When a tile layer the pieces draw on cannot be read.
     */
    private function planGlyphTiles(ProjectMap $map, string $layerId, array $writes, array $choices, bool $repaint,
        array $excludedLayers = []): ?array
    {
        $planner = $this->loadGlyphTilePlanner($map, $layerId, $excludedLayers);
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
            $this->createTileReader($map),
            $choices,
            $repaint,
        );
    }

    /**
     * Draws the tiles of the glyphs already on the layer being edited, as if
     * each were painted again: every glyph a piece draws gets that piece's
     * tiles, in one undo step, unsaved until the map is saved. A glyph that
     * could be several pieces asks first, as painting does, and the answer
     * applies wherever its neighbours do not decide. Glyphs no piece draws,
     * and the glyphs themselves, are left as they are.
     *
     * @param array<string, ?string> $choices The role key chosen for a glyph, or null for no tiles.
     */
    private function drawTilesForLayerGlyphs(array $choices = []): void
    {
        $map = $this->getSelectedMap();
        if (! $map instanceof ProjectMap || $map->getGridSourceIssue() !== null) {
            return;
        }
        $layerId = $this->getActiveCanvasLayer();
        $layer = array_find($map->getLayers(), static fn(array $candidate): bool => $candidate['id'] === $layerId);
        $label = MapLayers::formatLabel((string) ($layer['name'] ?? $layerId));
        $writes = [];
        for ($y = 0; $y < $map->getHeight(); $y++) {
            for ($x = 0; $x < $map->getWidth(); $x++) {
                if ($map->hasLayerCell($layerId, $x, $y) && ($symbol = $map->getLayerSymbol($layerId, $x, $y)) !== ' ') {
                    $writes[] = ['x' => $x, 'y' => $y, 'symbol' => $symbol];
                }
            }
        }
        try {
            $plan = $this->planGlyphTiles($map, $layerId, $writes, $choices, true);
        } catch (MapSourceRefusal | InvalidArgumentException | RuntimeException $refusal) {
            $this->setStatus('Tiles were not drawn: ' . $refusal->getMessage(), StatusLevel::WARN);
            $this->renderFooter();
            return;
        }
        if ($plan === null) {
            $this->setStatus(sprintf('The %s layer has no pieces in this map\'s kind to draw tiles for.', $label), StatusLevel::WARN);
            $this->renderFooter();
            return;
        }
        if ($plan['unresolved'] !== []) {
            $glyph = (string) array_key_first($plan['unresolved']);
            $this->askForGlyphPiece($glyph, $plan['unresolved'][$glyph], $choices,
                fn(array $chosen) => $this->drawTilesForLayerGlyphs($chosen));
            return;
        }
        $before = $map->getTileLayerSources();
        try {
            $map->writeTileCells($plan['tiles']);
        } catch (MapSourceRefusal $refusal) {
            $this->setStatus($refusal->getMessage(), StatusLevel::WARN);
            $this->renderFooter();
            return;
        }
        $after = $map->getTileLayerSources();
        if ($after === $before) {
            $this->setStatus(sprintf('Every glyph on the %s layer already has its tiles.', $label));
            $this->renderFooter();
            return;
        }
        $this->recordCommand(new GenericCommand(sprintf('Draw %s tiles', $label),
            static fn() => $map->restoreTileLayerSources($after),
            static fn() => $map->restoreTileLayerSources($before)));
        $cells = array_sum(array_map(count(...), $plan['tiles']));
        $this->setStatus(sprintf('Drew %d tile%s for the %s layer\'s glyphs; save to keep them.', $cells, $cells === 1 ? '' : 's', $label),
            StatusLevel::INFO);
        $this->renderCanvasArea();
    }

    /** The planner for a gameplay layer's pieces, or null when it has none to follow. */
    private function loadGlyphTilePlanner(ProjectMap $map, string $layerId, array $excludedLayers = []): ?GlyphTilePlanner
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
    private function createTileReader(ProjectMap $map): Closure
    {
        $layers = [];

        return static function (string $layer, int $x, int $y) use ($map, &$layers): string {
            $layers[$layer] ??= $map->readTileEntries([$layer], 0, 0, $map->getWidth(), $map->getHeight())[$layer] ?? [];

            return $layers[$layer][$y][$x] ?? (string) TileId::EMPTY;
        };
    }

    /**
     * Asks which piece a glyph is, listing its roles and No tiles, with the
     * brush's last answer for it first in line. The edit runs again on an
     * answer; Esc drops it.
     *
     * @param list<PieceRole> $roles
     * @param array<string, ?string> $choices The answers the edit already has.
     * @param Closure(array<string, ?string>): void $retry
     */
    private function askForGlyphPiece(string $glyph, array $roles, array $choices, Closure $retry): void
    {
        $this->finalizeActiveStroke();
        $this->optionDialogField = ['glyphPiece' => ['glyph' => $glyph, 'choices' => $choices, 'retry' => $retry]];
        $this->eventOptionDialogMarker = null;
        $this->eventOptionDialogPath = null;
        $this->eventOptionDialogTitle = "Piece for {$glyph}";
        $this->eventOptionDialogEntries = [
            ...array_map(static fn(PieceRole $role): array => [
                'label' => $role->label,
                'value' => $role->key,
                'description' => implode(' · ', array_map(
                    static fn(string $layer, array $cells): string => $layer . ' ' . implode(' ', array_column($cells, 'entry')),
                    array_keys($role->tiles),
                    $role->tiles,
                )) ?: 'no tiles',
            ], $roles),
            ['label' => 'No tiles', 'value' => '', 'description' => 'the glyph alone'],
        ];
        $remembered = ($this->paintPieceRole['symbol'] ?? null) === $glyph ? ($this->paintPieceRole['role'] ?? '') : '';
        $this->selectedEventOptionIndex = $this->resolveEventOptionSelectionIndex($remembered);
        $this->isEventOptionDialogOpen = true;
        $this->setStatus(sprintf('%s could be %d pieces. Choose the one it draws; Esc leaves the map as it was.', $glyph, count($roles)));
        $this->renderSelectionDependentArea();
    }

    /**
     * Runs the waiting edit again with the author's answer for its glyph.
     *
     * @param array{glyph: string, choices: array<string, ?string>, retry: Closure(array<string, ?string>): void} $pending
     */
    private function chooseGlyphPiece(array $pending, string $value): void
    {
        $this->closeEventOptionDialog();
        ($pending['retry'])([...$pending['choices'], $pending['glyph'] => $value === '' ? null : $value]);
    }

    /**
     * The brush's answer for its glyph, as edit choices.
     *
     * @return array<string, ?string>
     */
    private function getPaintPieceChoices(string $symbol): array
    {
        return ($this->paintPieceRole['symbol'] ?? null) === $symbol ? [$symbol => $this->paintPieceRole['role']] : [];
    }

    /**
     * Keeps the answer an edit got for the brush's glyph, so the brush draws
     * that piece until a glyph is typed again.
     *
     * @param array<string, ?string> $choices
     */
    private function rememberPaintPieceRole(string $symbol, array $choices): void
    {
        if (array_key_exists($symbol, $choices)) {
            $this->paintPieceRole = ['symbol' => $symbol, 'role' => $choices[$symbol]];
        }
    }

    /**
     * Makes the brush draw the piece role the glyph in a cell plays, as the
     * eyedropper picks it up, and names it; an empty string when its tiles
     * show no role.
     */
    private function pickPaintPieceRole(ProjectMap $map, int $x, int $y): string
    {
        $this->paintPieceRole = null;
        $layerId = $this->getActiveCanvasLayer();
        $planner = $this->loadGlyphTilePlanner($map, $layerId);
        if ($planner === null || ! $map->hasLayerCell($layerId, $x, $y)) {
            return '';
        }
        $symbol = $map->getLayerSymbol($layerId, $x, $y);
        try {
            $role = $planner->findPlayedRole($symbol, $x, $y, $this->createTileReader($map));
        } catch (MapSourceRefusal) {
            return '';
        }
        if ($role === null) {
            return '';
        }
        $this->paintPieceRole = ['symbol' => $symbol, 'role' => $role->key];

        return $role->label;
    }
}
