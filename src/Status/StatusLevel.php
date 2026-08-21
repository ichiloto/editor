<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Status;

use Atatusoft\Termutil\IO\Enumerations\Color;

/**
 * Classifies footer status messages so feedback severities are visually
 * distinct and expire on their own schedule.
 */
enum StatusLevel: string
{
    case INFO = 'info';
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
            self::INFO => Color::WHITE,
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
