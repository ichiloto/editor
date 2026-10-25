<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Review;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use InvalidArgumentException;

/**
 * Who stands on a rendered map: the player on a cell, facing a heading, as a
 * new game shows them, or an actor of the project in the player's place
 * (their graphical art; the Terminal shows the player's own sprite).
 */
final readonly class MapRenderSubject
{
    public function __construct(
        public int $x,
        public int $y,
        public MovementHeading $heading = MovementHeading::SOUTH,
        public ?string $actorId = null,
    ) {
        if ($x < 0 || $y < 0) {
            throw new InvalidArgumentException('A subject stands on a map cell: x and y are 0 or more.');
        }
        if ($actorId !== null && trim($actorId) === '') {
            throw new InvalidArgumentException('Name the actor by its id, or leave the actor out for the player.');
        }
    }

    /**
     * Reads a heading by name: north, east, south or west, in any case.
     *
     * @throws InvalidArgumentException When it names no heading.
     */
    public static function readHeading(string $name): MovementHeading
    {
        foreach ([MovementHeading::NORTH, MovementHeading::EAST, MovementHeading::SOUTH, MovementHeading::WEST] as $heading) {
            if (strcasecmp($heading->value, trim($name)) === 0) {
                return $heading;
            }
        }

        throw new InvalidArgumentException(sprintf('"%s" is no heading: use north, east, south or west.', $name));
    }
}
