<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Events;

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\IO\Console\TerminalText;

/**
 * What an event marker may be, and which one a new event takes.
 *
 * The engine keys a map's events by the glyph painted on its event layer,
 * and refuses a marker that is not exactly one terminal cell wide
 * (`MapSourceReader`). A marker is in use while it is painted or defined:
 * a definition without cells and cells without a definition both still
 * claim it, so a new event never adopts either.
 *
 * A digit cannot mark an event: PHP stores the key `'5'` as the integer 5,
 * and the engine reads only string keys as markers.
 */
final class EventMarkers
{
    /** The markers a new event is given, in the order they are offered. */
    public const string CANDIDATES = '@!$%&*+=?^{}|~<>ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /** Why a glyph cannot mark an event, or null when it can. */
    public static function findInvalidReason(string $marker): ?string
    {
        return match (true) {
            trim($marker) === '' => 'An event marker cannot be blank.',
            TerminalText::symbolCount($marker) !== 1 || TerminalText::displayWidth($marker) !== 1
                => sprintf('Event marker %s is not one terminal cell wide.', $marker),
            ctype_digit($marker) => sprintf('Event marker %s is a digit, which the map data stores as a number the engine cannot read as a marker.', $marker),
            default => null,
        };
    }

    /**
     * Every marker the map claims: painted on its event layer or defined in
     * its data.
     *
     * @return list<string>
     */
    public static function findUsedMarkers(ProjectMap $map): array
    {
        return array_values(array_unique([...$map->getPlacedEventMarkers(), ...$map->getEventMarkers()]));
    }

    /**
     * The first candidate none of the used markers claims, or null when
     * every one is taken.
     *
     * @param iterable<string> $used
     */
    public static function findFreeMarker(iterable $used): ?string
    {
        $taken = [];
        foreach ($used as $marker) {
            $taken[$marker] = true;
        }

        foreach (str_split(self::CANDIDATES) as $candidate) {
            if (! isset($taken[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }
}
