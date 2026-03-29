<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\AnimationCell;
use Ichiloto\Engine\Animations\AnimationCue;
use Ichiloto\Engine\Animations\AnimationTargetPosition;

/**
 * Builds preview data for animation editing and playback.
 */
final class AnimationPreviewRenderer
{
    /**
     * Returns the preview cells to draw for the given frame.
     *
     * @param Animation $animation The animation being previewed.
     * @param int $frameIndex The selected frame.
     * @param int $width The preview width.
     * @param int $height The preview height.
     * @return array{cells: array<int, array{x: int, y: int, symbol: string, color: string|null}>, cue: AnimationCue|null}
     */
    public static function build(Animation $animation, int $frameIndex, int $width, int $height): array
    {
        $origin = self::resolveOrigin($animation->position, $width, $height);
        $cells = [];

        foreach (self::buildTargetCells($origin['x'], $origin['y'], $animation->position) as $cell) {
            $cells[] = $cell;
        }

        foreach ($animation->getFrame($frameIndex)->getCells() as $cell) {
            $cells[] = [
                'x' => $origin['x'] + $cell->x,
                'y' => $origin['y'] + $cell->y,
                'symbol' => $cell->symbol,
                'color' => $cell->color,
            ];
        }

        return [
            'cells' => array_values(array_filter(
                $cells,
                static fn(array $cell): bool => $cell['x'] >= 0
                    && $cell['x'] < $width
                    && $cell['y'] >= 0
                    && $cell['y'] < $height
            )),
            'cue' => $animation->getCue($frameIndex),
        ];
    }

    /**
     * Returns the anchor point for the preview target.
     *
     * @param AnimationTargetPosition $position The animation position.
     * @param int $width The preview width.
     * @param int $height The preview height.
     * @return array{x: int, y: int}
     */
    public static function resolveOrigin(AnimationTargetPosition $position, int $width, int $height): array
    {
        $x = intdiv(max(1, $width), 2);
        $y = intdiv(max(1, $height), 2);

        return match ($position) {
            AnimationTargetPosition::HEAD => ['x' => $x, 'y' => max(1, $y - 2)],
            AnimationTargetPosition::FEET => ['x' => $x, 'y' => min(max(1, $height - 2), $y + 2)],
            AnimationTargetPosition::SCREEN,
            AnimationTargetPosition::CENTER => ['x' => $x, 'y' => $y],
        };
    }

    /**
     * Returns the preview target silhouette.
     *
     * @param int $x The origin x coordinate.
     * @param int $y The origin y coordinate.
     * @param AnimationTargetPosition $position The target anchor.
     * @return array<int, array{x: int, y: int, symbol: string, color: string|null}>
     */
    private static function buildTargetCells(int $x, int $y, AnimationTargetPosition $position): array
    {
        if ($position === AnimationTargetPosition::SCREEN) {
            return [];
        }

        return [
            ['x' => $x, 'y' => $y - 1, 'symbol' => 'o', 'color' => null],
            ['x' => $x - 1, 'y' => $y, 'symbol' => '/', 'color' => null],
            ['x' => $x, 'y' => $y, 'symbol' => '|', 'color' => null],
            ['x' => $x + 1, 'y' => $y, 'symbol' => '\\', 'color' => null],
            ['x' => $x - 1, 'y' => $y + 1, 'symbol' => '/', 'color' => null],
            ['x' => $x + 1, 'y' => $y + 1, 'symbol' => '\\', 'color' => null],
        ];
    }
}
