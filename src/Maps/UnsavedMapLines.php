<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

/**
 * The map rows and columns an open change set inserted, in the coordinates
 * its maps have now. A line insertion writes its files at once, blank cells
 * and all, but no save can stand on a line that did not exist when the
 * change set began: nothing outside the change set ever saw it. The save
 * planner reads these lines as holding no save, as it reads a map the change
 * set made ({@see WorkspaceSave::prepareAll()}).
 *
 * Later line edits in the same change set move the lines already recorded:
 * an insertion before them pushes them on, and a removal drops the ones it
 * takes and pulls the rest back.
 */
final class UnsavedMapLines
{
    /** @var array<string, array{x: array<int, true>, y: array<int, true>}> Inserted line indices, by map and axis. */
    private array $lines = [];

    /** Records `$count` lines inserted before line `$at` of a map's axis (`x` columns, `y` rows). */
    public function recordInsertion(string $mapId, string $axis, int $at, int $count): void
    {
        $moved = [];
        foreach (array_keys($this->lines[$mapId][$axis] ?? []) as $line) {
            $moved[$line >= $at ? $line + $count : $line] = true;
        }
        for ($line = $at; $line < $at + $count; $line++) {
            $moved[$line] = true;
        }
        $this->lines[$mapId] ??= ['x' => [], 'y' => []];
        $this->lines[$mapId][$axis] = $moved;
    }

    /** Records lines `$at` to `$at + $count - 1` of a map's axis removed. */
    public function recordRemoval(string $mapId, string $axis, int $at, int $count): void
    {
        if (! isset($this->lines[$mapId])) {
            return;
        }
        $moved = [];
        foreach (array_keys($this->lines[$mapId][$axis]) as $line) {
            if ($line < $at) {
                $moved[$line] = true;
            } elseif ($line >= $at + $count) {
                $moved[$line - $count] = true;
            }
        }
        $this->lines[$mapId][$axis] = $moved;
    }

    /** Whether a cell of a map lies on a line the change set inserted. */
    public function containsCell(string $mapId, int $x, int $y): bool
    {
        return isset($this->lines[$mapId]['x'][$x]) || isset($this->lines[$mapId]['y'][$y]);
    }

    /** Whether no line was inserted. */
    public bool $isEmpty {
        get => array_all($this->lines, static fn(array $axes): bool => $axes['x'] === [] && $axes['y'] === []);
    }
}
