<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Review;

/**
 * One view of a map rendered both ways: its Terminal picture and the still of
 * its graphical field, for the same rectangle of cells, with what either
 * could not show.
 */
final readonly class MapRendering
{
    /**
     * @param array{x: int, y: int, width: int, height: int} $region The rectangle rendered, in map cells.
     * @param list<string> $diagnostics
     */
    public function __construct(
        public MapRenderView $view,
        public array $region,
        public TerminalPicture $terminal,
        public FieldStill $still,
        public array $diagnostics = [],
    ) {
    }
}
