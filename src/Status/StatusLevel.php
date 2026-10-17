<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Status;

use Atatusoft\Termutil\IO\Enumerations\Color;

/**
 * Classifies footer status messages so feedback severities are visually
 * distinct and expire on their own schedule.
 *
 * Colour carries meaning as much as the words do. Green reads as saved, so
 * SUCCESS is only for something that reached disk (a save, or a command that
 * writes files at once) or a check that passed. An edit held in memory until
 * the author saves is INFO, however complete it is: the unsaved-changes
 * marker says it is not yet saved.
 */
enum StatusLevel: string
{
    /** Blue: edits held until saved, navigation, previews and other updates. */
    case INFO = 'info';
    /** Green: written to disk, or a check passed. */
    case SUCCESS = 'success';
    case WARN = 'warn';
    case ERROR = 'error';

    /**
     * Returns the terminal color for the level.
     *
     * @return Color
     */
    public function color(): Color
    {
        return match ($this) {
            self::INFO => Color::LIGHT_BLUE,
            self::SUCCESS => Color::LIGHT_GREEN,
            self::WARN => Color::YELLOW,
            self::ERROR => Color::LIGHT_RED,
        };
    }

    /**
     * Returns how long a message of this level stays on screen.
     *
     * @return float Seconds before the status reverts to the idle message.
     */
    public function timeToLiveSeconds(): float
    {
        return match ($this) {
            self::INFO, self::SUCCESS => 4.0,
            self::WARN => 6.0,
            self::ERROR => 10.0,
        };
    }
}
