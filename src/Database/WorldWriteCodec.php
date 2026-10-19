<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * Encodes and decodes the engine's world-state write arrays (`sets`) as a
 * single editable line.
 *
 * The engine's `WorldStateWriter` is the one implementation of this
 * vocabulary — event triggers on completion, NPCs after a conversation,
 * dialogue variants when spoken. This is the editor's one representation of
 * it, so every surface that edits `sets` behaves identically, and it stays
 * in step with the shapes the writer applies:
 *
 *   switch:name[:true|false]     -> ['type' => 'switch', 'name', 'value']
 *   event:name                   -> ['type' => 'event', 'name']
 *   variable:name[:set|add][:v]  -> ['type' => 'variable', 'name', 'op', 'value']
 *   quest:id[:confirm|grant]     -> ['type' => 'quest', 'name', 'confirm']
 *
 * Entries are separated by `;` ({@see WorldStateLine}); a name or value
 * escapes its backslash, `:` and `;` with a backslash, so any name the Engine
 * accepts round-trips. A line is read only as written: a part the type does
 * not take, or a value it cannot be, makes the entry unreadable, never
 * rewritten.
 *
 * @package Ichiloto\Editor\Database
 */
final class WorldWriteCodec
{
    /** The write types the engine's writer applies. */
    public const array TYPES = ['switch', 'event', 'variable', 'quest'];

    /** The keys the line carries per type, beyond type and name. */
    private const array CARRIED_KEYS = [
        'switch' => ['value'],
        'event' => [],
        'variable' => ['op', 'value'],
        'quest' => ['confirm'],
    ];

    /**
     * Why an authored write list cannot go through the write line, or null
     * when the line carries it faithfully: a list that is not a list, a write
     * type or variable operation the line cannot express, a switch or quest
     * flag that is not a boolean, or an authored key outside its vocabulary
     * would be lost on commit, so such a list is refused by name instead.
     *
     * @param mixed $sets The list as authored.
     */
    public static function findCarryIssue(mixed $sets): ?string
    {
        if (! is_array($sets) || ! array_is_list($sets)) {
            return sprintf('the writes are %s, not a list', is_array($sets) ? 'a keyed array' : get_debug_type($sets));
        }

        foreach ($sets as $index => $set) {
            if (! is_array($set)) {
                return sprintf('write %d is %s, not an array', $index + 1, get_debug_type($set));
            }

            $type = strval($set['type'] ?? '');
            $flag = ['switch' => 'value', 'quest' => 'confirm'][$type] ?? null;
            $lossy = match (true) {
                self::decode(self::encode($set)) === null => 'holds a shape the write editor cannot carry',
                $type === 'variable' && ! in_array($set['op'] ?? 'set', ['set', 'add'], true) => 'has a variable operation the write editor would rewrite',
                $flag !== null && array_key_exists($flag, $set) && ! is_bool($set[$flag]) => sprintf('has a "%s" that is not true or false', $flag),
                default => null,
            };
            if ($lossy !== null) {
                return sprintf('write %d %s', $index + 1, $lossy);
            }

            $extra = array_diff(array_keys($set), ['type', 'name', ...(self::CARRIED_KEYS[$type] ?? [])]);
            if ($extra !== []) {
                return sprintf('write %d carries "%s", which the write editor would drop', $index + 1, implode('", "', array_map(strval(...), $extra)));
            }
        }

        return null;
    }

    /**
     * Encodes a list of writes into the one-line editable form.
     *
     * @param array<int, mixed> $sets The write payloads.
     * @return string The line.
     */
    public static function encodeAll(array $sets): string
    {
        return implode('; ', array_map(
            static fn(array $set): string => self::encode($set),
            array_values(array_filter($sets, is_array(...))),
        ));
    }

    /**
     * Encodes one write.
     *
     * @param array<string, mixed> $set The write payload.
     * @return string The segment.
     */
    public static function encode(array $set): string
    {
        $type = strval($set['type'] ?? '');
        $name = strval($set['name'] ?? '');
        $parts = [$type, $name];

        switch ($type) {
            case 'switch':
                // An authored value is written back as authored, so a file
                // that said `true` explicitly still says it after an edit.
                if (array_key_exists('value', $set)) {
                    $parts[] = ($set['value'] ?? true) === false ? 'false' : 'true';
                }

                break;
            case 'variable':
                $parts[] = strval($set['op'] ?? 'set');
                $parts[] = strval($set['value'] ?? 0);
                break;
            case 'quest':
                if (array_key_exists('confirm', $set)) {
                    $parts[] = ($set['confirm'] ?? true) === false ? 'grant' : 'confirm';
                }

                break;
        }

        return WorldStateLine::joinParts($parts);
    }

    /**
     * Decodes the one-line form back into write arrays, leaving out an entry
     * it cannot read: for showing a line this codec wrote. A line an author or
     * a client submits is read by {@see decodeAllStrictly()}.
     *
     * @param string $value The encoded writes.
     * @return array<int, array<string, mixed>> The writes.
     */
    public static function decodeAll(string $value): array
    {
        $sets = [];

        foreach (WorldStateLine::splitEntries($value) as $segment) {
            $set = self::decode($segment);

            if ($set !== null) {
                $sets[] = $set;
            }
        }

        return $sets;
    }

    /**
     * Decodes the one-line form, refusing it whole when any entry cannot be
     * read as written, so a typed write is never dropped or reinterpreted
     * without a word.
     *
     * @param string $value The encoded writes.
     * @return array<int, array<string, mixed>> The writes.
     * @throws \InvalidArgumentException Naming the first unreadable entry and why.
     */
    public static function decodeAllStrictly(string $value): array
    {
        $sets = [];

        foreach (WorldStateLine::splitEntries($value) as $segment) {
            if (trim($segment) === '') {
                continue;
            }

            $set = self::parse($segment);
            if (is_string($set)) {
                throw new \InvalidArgumentException(sprintf('Write "%s" cannot be read: %s Nothing was changed.', trim($segment), $set));
            }
            $sets[] = $set;
        }

        return $sets;
    }

    /**
     * Decodes one entry.
     *
     * @param string $segment The entry.
     * @return array<string, mixed>|null The write, or null when it cannot be read as written.
     */
    public static function decode(string $segment): ?array
    {
        $set = self::parse($segment);

        return is_array($set) ? $set : null;
    }

    /**
     * Reads one entry exactly as written.
     *
     * @return array<string, mixed>|string The write, or why it cannot be read.
     */
    private static function parse(string $segment): array|string
    {
        if (trim($segment) === '') {
            return 'it is empty.';
        }
        try {
            $parts = WorldStateLine::splitParts(trim($segment));
        } catch (\InvalidArgumentException $error) {
            return $error->getMessage();
        }
        $type = strtolower($parts[0]);
        $name = $parts[1] ?? '';

        if (! in_array($type, self::TYPES, true)) {
            return sprintf('write type:name[:extras] with a type of %s.', implode(', ', self::TYPES));
        }
        if ($name === '') {
            return sprintf('the %s write needs a name.', $type);
        }
        $extras = array_slice($parts, 2);
        $allowed = ['switch' => 1, 'event' => 0, 'variable' => 2, 'quest' => 1][$type];
        if (count($extras) > $allowed) {
            return sprintf('the %s write takes %s after its name, not "%s"; write a colon or semicolon inside a name as \\: or \\;.',
                $type, ['switch' => 'at most true or false', 'event' => 'nothing', 'variable' => 'at most set or add and a value',
                    'quest' => 'at most confirm or grant'][$type], implode(':', array_slice($extras, $allowed)));
        }

        $set = ['type' => $type, 'name' => $name];

        switch ($type) {
            case 'switch':
                if (isset($extras[0])) {
                    $state = strtolower($extras[0]);
                    if (! in_array($state, ['true', 'false'], true)) {
                        return sprintf('a switch is set true or false, not "%s".', $extras[0]);
                    }
                    $set['value'] = $state === 'true';
                }

                break;
            case 'variable':
                $op = strtolower($extras[0] ?? 'set');
                if (! in_array($op, ['set', 'add'], true)) {
                    return sprintf('a variable write is set or add, not "%s".', $extras[0]);
                }
                $set['op'] = $op;
                $rawValue = $extras[1] ?? ($op === 'add' ? '1' : '0');
                $set['value'] = is_numeric($rawValue) ? $rawValue + 0 : $rawValue;
                break;
            case 'quest':
                if (isset($extras[0])) {
                    $mode = strtolower($extras[0]);
                    if (! in_array($mode, ['confirm', 'grant'], true)) {
                        return sprintf('a quest write is confirm or grant, not "%s".', $extras[0]);
                    }
                    $set['confirm'] = $mode === 'confirm';
                }

                break;
        }

        return $set;
    }

    /**
     * Describes one write in words.
     *
     * @param array<string, mixed> $set The write.
     * @return string The description.
     */
    public static function describe(array $set): string
    {
        $name = strval($set['name'] ?? '');
        $name = $name === '' ? '(unnamed)' : $name;

        return match (strval($set['type'] ?? '')) {
            'switch' => sprintf('Switch %s %s', $name, ($set['value'] ?? true) === false ? 'off' : 'on'),
            'event' => sprintf('Record event %s', $name),
            'variable' => sprintf(
                'Variable %s %s %s',
                $name,
                strval($set['op'] ?? 'set') === 'add' ? '+=' : '=',
                strval($set['value'] ?? 0),
            ),
            'quest' => sprintf('%s quest %s', ($set['confirm'] ?? true) === false ? 'Grant' : 'Offer', $name),
            default => sprintf('%s %s', strval($set['type'] ?? '?'), $name),
        };
    }
}
