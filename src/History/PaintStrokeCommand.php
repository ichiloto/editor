<?php

declare(strict_types=1);

namespace Ichiloto\Editor\History;

use Ichiloto\Editor\ProjectMap;

/**
 * Undoable canvas painting for both the tile and event layers.
 *
 * A keyboard paint records a single-cell stroke; a mouse drag appends every
 * changed cell into one stroke so the whole gesture undoes as a unit. Each
 * cell keeps its first-seen old symbol and last-seen new symbol, so painting
 * back and forth over the same cell still collapses to one change.
 */
final class PaintStrokeCommand implements Command
{
    public const string LAYER_TILE = 'tile';
    public const string LAYER_EVENT = 'event';

    /**
     * @var array<string, array{x: int, y: int, old: string, new: string, oldPrefix: string, oldSuffix: string, newPrefix: string, newSuffix: string}>
     */
    private array $cells = [];

    /**
     * @param ProjectMap $map The map receiving the stroke.
     * @param string $layer One of the LAYER_* constants.
     * @param string $label The status-line action label.
     */
    public function __construct(
        private readonly ProjectMap $map,
        private readonly string $layer,
        public readonly string $label = 'Paint stroke',
    ) {
    }

    /**
     * Records one painted cell. The old symbol survives repeated appends so
     * undo restores the pre-stroke state.
     *
     * On the tile layer a cell also carries its raw styling bytes, so undo
     * restores authored formatter tags byte-for-byte. The event layer has no
     * styling; its style arguments stay empty.
     *
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @param string $oldSymbol The symbol before the stroke touched the cell.
     * @param string $newSymbol The symbol painted onto the cell.
     * @param string $oldPrefix The styling prefix before the stroke.
     * @param string $oldSuffix The styling suffix before the stroke.
     * @param string $newPrefix The styling prefix painted onto the cell.
     * @param string $newSuffix The styling suffix painted onto the cell.
     * @return void
     */
    public function appendCell(
        int $x,
        int $y,
        string $oldSymbol,
        string $newSymbol,
        string $oldPrefix = '',
        string $oldSuffix = '',
        string $newPrefix = '',
        string $newSuffix = '',
    ): void {
        $key = $x . ':' . $y;

        if (isset($this->cells[$key])) {
            $this->cells[$key]['new'] = $newSymbol;
            $this->cells[$key]['newPrefix'] = $newPrefix;
            $this->cells[$key]['newSuffix'] = $newSuffix;
            return;
        }

        $this->cells[$key] = [
            'x' => $x,
            'y' => $y,
            'old' => $oldSymbol,
            'new' => $newSymbol,
            'oldPrefix' => $oldPrefix,
            'oldSuffix' => $oldSuffix,
            'newPrefix' => $newPrefix,
            'newSuffix' => $newSuffix,
        ];
    }

    /**
     * Returns whether the stroke actually changed any cell.
     *
     * @return bool
     */
    public function hasChanges(): bool
    {
        foreach ($this->cells as $cell) {
            if (
                $cell['old'] !== $cell['new']
                || $cell['oldPrefix'] !== $cell['newPrefix']
                || $cell['oldSuffix'] !== $cell['newSuffix']
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the number of recorded cells.
     *
     * @return int
     */
    public function getCellCount(): int
    {
        return count($this->cells);
    }

    /**
     * Returns whether the stroke targets the given map.
     *
     * @param ProjectMap $map The map to compare.
     * @return bool
     */
    public function targets(ProjectMap $map): bool
    {
        return $this->map === $map;
    }

    /**
     * @inheritDoc
     */
    public function execute(): void
    {
        foreach ($this->cells as $cell) {
            $this->applyCell($cell['x'], $cell['y'], $cell['new'], $cell['newPrefix'], $cell['newSuffix']);
        }
    }

    /**
     * @inheritDoc
     */
    public function undo(): void
    {
        foreach ($this->cells as $cell) {
            $this->applyCell($cell['x'], $cell['y'], $cell['old'], $cell['oldPrefix'], $cell['oldSuffix']);
        }
    }

    /**
     * Writes one cell back onto the stroke's layer.
     *
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @param string $symbol The symbol to apply.
     * @param string $prefix The styling prefix bytes (tile layer only).
     * @param string $suffix The styling suffix bytes (tile layer only).
     * @return void
     */
    private function applyCell(int $x, int $y, string $symbol, string $prefix, string $suffix): void
    {
        $this->map->setLayerCell($this->layer, $x, $y, $symbol, $prefix, $suffix);
    }
}
