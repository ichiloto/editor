<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database\Projections;

use Ichiloto\Editor\Database\RecordProjection;

/**
 * One side of the battler bindings file, actors or enemies, as records.
 *
 * The Engine's battlers.php binds battle art to identities as a map
 * ({@see \Ichiloto\Engine\Battle\Presentation\BattlerBindings}), which is not
 * a list an editor can put rows on, so each identity becomes a record
 * carrying it as `identity`, and the map is rebuilt on the way out in row
 * order, which the file keeps. An entry's own poses, a map from role to
 * pose, are the record's keyed list. The other side and the scale reference
 * are written back exactly as read.
 *
 * @package Ichiloto\Editor\Database\Projections
 */
final readonly class BattlerBindingProjection implements RecordProjection
{
    /** The field a record names its battler by. */
    public const string IDENTITY = 'identity';


    /**
     * @param 'actors'|'enemies' $side The map this category edits.
     */
    public function __construct(public string $side)
    {
    }

    /**
     * @inheritDoc
     */
    public function read(array $whole): array
    {
        $rows = [];

        foreach (is_array($whole[$this->side] ?? null) ? $whole[$this->side] : [] as $identity => $entry) {
            if (is_array($entry)) {
                $rows[] = [self::IDENTITY => strval($identity), ...$entry];
            }
        }

        return $rows;
    }

    /**
     * @inheritDoc
     */
    public function write(array $whole, array $rows): array
    {
        $entries = [];

        foreach ($rows as $row) {
            $identity = trim(strval($row[self::IDENTITY] ?? ''));
            if ($identity === '') {
                continue;
            }
            unset($row[self::IDENTITY]);
            $entries[$identity] = $row;
        }

        if ($entries === []) {
            unset($whole[$this->side]);

            return $whole;
        }

        $whole[$this->side] = $entries;

        return $whole;
    }

    /** @inheritDoc */
    public function preservationIssue(mixed $whole): ?string
    {
        if (! is_array($whole)) {
            return sprintf('returns %s, not an array', get_debug_type($whole));
        }
        if (! array_key_exists($this->side, $whole)) {
            return null;
        }
        $entries = $whole[$this->side];
        if (! is_array($entries) || ($entries !== [] && array_is_list($entries))) {
            return sprintf('field "%s" is not a map of battler identities', $this->side);
        }
        foreach ($entries as $identity => $entry) {
            if (! is_array($entry)) {
                return sprintf('field "%s" entry "%s" is %s, not a binding', $this->side, strval($identity), get_debug_type($entry));
            }
            if (array_key_exists(self::IDENTITY, $entry)) {
                return sprintf('field "%s" entry "%s" holds "%s", which the editor uses for the identity', $this->side, strval($identity), self::IDENTITY);
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     *
     * The map is written in row order, which it keeps.
     */
    public function ordersRecords(): bool
    {
        return true;
    }
}
