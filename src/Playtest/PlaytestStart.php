<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Playtest;

/**
 * Where a playtest begins: on the open map at the chosen cell, or at the
 * game's own title screen with the project's real starting position, to test
 * the opening as a player first meets it.
 */
enum PlaytestStart: string
{
    case CELL = 'cell';
    case TITLE = 'title';

    /** What an author reads for the choice. */
    public function getLabel(): string
    {
        return match ($this) {
            self::CELL => 'From the selected cell',
            self::TITLE => 'From the title',
        };
    }
}
