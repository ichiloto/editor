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
     * @var array<string, array{x: int, y: int, old: string, new: string, oldStyle: array, newStyle: array}>
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
     * Records one painted cell. The old cell survives repeated appends so
     * undo restores the pre-stroke state.
     *
     * A cell also carries its raw styling bytes, as ProjectMap reads them,
     * so undo restores authored formatter tags byte-for-byte, a separate
     * style per character included. The event layer has no styling; its
     * styles stay empty.
     *
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @param string $oldSymbol The cell before the stroke touched it.
     * @param string $newSymbol The cell painted.
     * @param array{prefix?: string, suffix?: string, styles?: list<array{prefix: string, suffix: string}>} $oldStyle The styling before the stroke.
     * @param array{prefix?: string, suffix?: string, styles?: list<array{prefix: string, suffix: string}>} $newStyle The styling painted.
     * @return void
     */
    public function appendCell(
        int $x,
        int $y,
        string $oldSymbol,
        string $newSymbol,
        array $oldStyle = [],
        array $newStyle = [],
    ): void {
        $key = $x . ':' . $y;
        $newStyle = self::normalizeStyle($newStyle);

        if (isset($this->cells[$key])) {
            $this->cells[$key]['new'] = $newSymbol;
            $this->cells[$key]['newStyle'] = $newStyle;
            return;
        }

        $this->cells[$key] = [
            'x' => $x,
            'y' => $y,
            'old' => $oldSymbol,
            'new' => $newSymbol,
            'oldStyle' => self::normalizeStyle($oldStyle),
            'newStyle' => $newStyle,
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
            if ($cell['old'] !== $cell['new'] || $cell['oldStyle'] !== $cell['newStyle']) {
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
            $this->applyCell($cell['x'], $cell['y'], $cell['new'], $cell['newStyle']);
        }
    }

    /**
     * @inheritDoc
     */
    public function undo(): void
    {
        foreach ($this->cells as $cell) {
            $this->applyCell($cell['x'], $cell['y'], $cell['old'], $cell['oldStyle']);
        }
    }

    /**
     * Writes one cell back onto the stroke's layer.
     *
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @param string $symbol The cell to apply.
     * @param array{prefix: string, suffix: string, styles?: list<array{prefix: string, suffix: string}>} $style The styling to apply.
     * @return void
     */
    private function applyCell(int $x, int $y, string $symbol, array $style): void
    {
        $this->map->setStyledLayerCell($this->layer, $x, $y, $symbol, $style);
    }

    /**
     * @param array{prefix?: string, suffix?: string, styles?: list<array{prefix: string, suffix: string}>} $style
     * @return array{prefix: string, suffix: string, styles?: list<array{prefix: string, suffix: string}>}
     */
    private static function normalizeStyle(array $style): array
    {
        return ['prefix' => $style['prefix'] ?? '', 'suffix' => $style['suffix'] ?? ''] + $style;
    }
}
