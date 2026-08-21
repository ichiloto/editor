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
     * @var array<string, array{x: int, y: int, old: string, new: string}>
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
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @param string $oldSymbol The symbol before the stroke touched the cell.
     * @param string $newSymbol The symbol painted onto the cell.
     * @return void
     */
    public function appendCell(int $x, int $y, string $oldSymbol, string $newSymbol): void
    {
        $key = $x . ':' . $y;

        if (isset($this->cells[$key])) {
            $this->cells[$key]['new'] = $newSymbol;
            return;
        }

        $this->cells[$key] = ['x' => $x, 'y' => $y, 'old' => $oldSymbol, 'new' => $newSymbol];
    }

    /**
     * Returns whether the stroke actually changed any cell.
     *
     * @return bool
     */
    public function hasChanges(): bool
    {
        foreach ($this->cells as $cell) {
            if ($cell['old'] !== $cell['new']) {
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
            $this->applySymbol($cell['x'], $cell['y'], $cell['new']);
        }
    }

    /**
     * @inheritDoc
     */
    public function undo(): void
    {
        foreach ($this->cells as $cell) {
            $this->applySymbol($cell['x'], $cell['y'], $cell['old']);
        }
    }

    /**
     * Writes one symbol back onto the stroke's layer.
     *
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @param string $symbol The symbol to apply.
     * @return void
     */
    private function applySymbol(int $x, int $y, string $symbol): void
    {
        if ($this->layer === self::LAYER_EVENT) {
            $this->map->setEventSymbol($x, $y, $symbol);
            return;
        }

        $this->map->setTileSymbol($x, $y, $symbol);
    }
}
