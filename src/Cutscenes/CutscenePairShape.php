<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes;

use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;

/**
 * How one asset's two files become the one array the editor edits, and how
 * that array becomes the two files again.
 *
 * The editor holds a cinematic or a summon as a single record: the data
 * file's keys, plus the script's commands or the timeline's keys. That
 * merge is only safe while it is exactly reversible -- while splitting the
 * merged record returns the two arrays that were read, key for key and
 * value for value. Some authored shapes cannot survive it: a `commands`
 * value that is not a list would be renumbered by the command tree, a
 * script that is a bare map of settings would be read as data-file
 * metadata, and a key authored in both files can only appear once in the
 * merged record, so one copy would be lost on the next write.
 *
 * This object owns the merge, the split and the proof. `refusalFor()`
 * returns the precise reason a pair cannot make the round trip, and the
 * asset uses it to open the pair read-only rather than rewrite a file it
 * cannot reproduce. Validation may say a source is malformed; that is not
 * permission to reshape it.
 *
 * @package Ichiloto\Editor\Cutscenes
 */
final class CutscenePairShape
{
    /** The key a cinematic's commands are merged under. */
    private const string COMMANDS = CutsceneAsset::COMMANDS_KEY;

    /**
     * @param CutsceneType $type The asset form.
     * @param array<string, string> $keyOwners Which file each top-level key came from.
     * @param bool $scriptIsMap Whether a cinematic's script is `['commands' => ...]` rather than a bare list.
     * @param string[] $dataOrder The data file's key order as read.
     * @param string[] $partnerOrder The partner file's key order as read.
     */
    private function __construct(
        private readonly CutsceneType $type,
        private readonly array $keyOwners,
        private readonly bool $scriptIsMap,
        private readonly array $dataOrder,
        private readonly array $partnerOrder,
    ) {
    }

    /**
     * The shape of a pair as it was read.
     *
     * @param array<string, mixed> $data
     * @param array<int|string, mixed> $partner
     */
    public static function of(CutsceneType $type, array $data, array $partner): self
    {
        $scriptIsMap = $type === CutsceneType::CINEMATIC && ! array_is_list($partner) && $partner !== [];
        $owners = [];

        foreach (array_keys($data) as $key) {
            $owners[(string) $key] = 'data';
        }

        if ($type === CutsceneType::SUMMON || $scriptIsMap) {
            foreach (array_keys($partner) as $key) {
                if (! isset($owners[(string) $key])) {
                    $owners[(string) $key] = 'partner';
                }
            }
        }

        return new self(
            $type,
            $owners,
            $scriptIsMap,
            array_map(strval(...), array_keys($data)),
            array_map(strval(...), array_keys($partner)),
        );
    }

    /**
     * The shape of an asset that has no files yet.
     */
    public static function forNewAsset(CutsceneType $type): self
    {
        return new self($type, [], false, [], []);
    }

    /**
     * A cinematic's commands, as the command tree reads them.
     *
     * @param array<int|string, mixed> $partner
     * @return array<int, mixed>
     */
    public function commandsOf(array $partner): array
    {
        if ($this->type !== CutsceneType::CINEMATIC) {
            return [];
        }

        $commands = array_is_list($partner) ? $partner : ($partner[self::COMMANDS] ?? []);

        return is_array($commands) ? array_values($commands) : [];
    }

    /**
     * The one array the editor edits.
     *
     * @param array<string, mixed> $data
     * @param array<int|string, mixed> $partner
     * @return array<string, mixed>
     */
    public function merge(array $data, array $partner): array
    {
        $payload = $data;

        if ($this->type === CutsceneType::CINEMATIC) {
            $payload[self::COMMANDS] = $this->commandsOf($partner);

            if (! array_is_list($partner)) {
                foreach ($partner as $key => $value) {
                    if ($key !== self::COMMANDS && ! array_key_exists($key, $payload)) {
                        $payload[$key] = $value;
                    }
                }
            }

            return $payload;
        }

        foreach ($partner as $key => $value) {
            if (! array_key_exists($key, $payload)) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * The two files' arrays again.
     *
     * @param array<string, mixed> $payload
     * @return array{0: array<string, mixed>, 1: array<int|string, mixed>}
     */
    public function split(array $payload): array
    {
        unset($payload[CutsceneAsset::ORIGIN_KEY]);
        $data = [];
        $partner = [];

        if ($this->type === CutsceneType::CINEMATIC) {
            $commands = $payload[self::COMMANDS] ?? [];
            unset($payload[self::COMMANDS]);
            $commands = is_array($commands) ? array_values($commands) : [];

            foreach ($payload as $key => $value) {
                if (($this->keyOwners[$key] ?? 'data') === 'partner') {
                    $partner[$key] = $value;
                } else {
                    $data[$key] = $value;
                }
            }

            $partner = $this->scriptIsMap || $partner !== []
                ? $this->inReadOrder([self::COMMANDS => $commands, ...$partner], $this->partnerOrder)
                : $commands;

            return [$this->inReadOrder($data, $this->dataOrder), $partner];
        }

        foreach ($payload as $key => $value) {
            $owner = $this->keyOwners[$key]
                ?? (in_array($key, CinematicCommandSchema::SUMMON_TIMELINE_FIELDS, true) ? 'partner' : 'data');

            if ($owner === 'partner') {
                $partner[$key] = $value;
            } else {
                $data[$key] = $value;
            }
        }

        return [$this->inReadOrder($data, $this->dataOrder), $this->inReadOrder($partner, $this->partnerOrder)];
    }

    /**
     * Why this pair cannot be merged and split without loss, or null when it
     * can.
     *
     * @param array<string, mixed> $data
     * @param array<int|string, mixed> $partner
     */
    public function refusalFor(array $data, array $partner): ?string
    {
        $reasons = $this->type === CutsceneType::CINEMATIC
            ? $this->cinematicReasons($data, $partner)
            : [];
        $reasons = [...$reasons, ...$this->collisionReasons($data, $partner)];

        if ($reasons !== []) {
            return implode('; ', $reasons);
        }

        // Whatever else an authored pair does, it must come back exactly.
        [$splitData, $splitPartner] = $this->split($this->merge($data, $partner));

        if ($splitData === $data && $splitPartner === $partner) {
            return null;
        }

        return $this->describeDifference($data, $partner, $splitData, $splitPartner);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int|string, mixed> $partner
     * @return string[]
     */
    private function cinematicReasons(array $data, array $partner): array
    {
        $reasons = [];

        if (array_key_exists(self::COMMANDS, $data)) {
            $reasons[] = 'the data file also declares "commands", which the editor reads from the script';
        }

        if (array_is_list($partner)) {
            return $reasons;
        }

        if ($partner === []) {
            return $reasons;
        }

        if (! array_key_exists(self::COMMANDS, $partner)) {
            return [...$reasons, 'the script is a keyed array with no "commands" list, so its keys would be read as data-file settings'];
        }

        $commands = $partner[self::COMMANDS];

        if (! is_array($commands)) {
            return [...$reasons, sprintf('the script\'s "commands" is %s, not a command list', get_debug_type($commands))];
        }

        if (! array_is_list($commands)) {
            return [...$reasons, sprintf(
                'the script\'s "commands" is keyed (%s), and the command tree would renumber it',
                implode(', ', array_map(static fn(mixed $key): string => '"' . $key . '"', array_slice(array_keys($commands), 0, 3))),
            )];
        }

        return $reasons;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int|string, mixed> $partner
     * @return string[]
     */
    private function collisionReasons(array $data, array $partner): array
    {
        if ($this->type === CutsceneType::CINEMATIC && array_is_list($partner)) {
            return [];
        }

        $shared = array_values(array_filter(
            array_keys($partner),
            static fn(int|string $key): bool => $key !== self::COMMANDS && array_key_exists($key, $data),
        ));

        if ($shared === []) {
            return [];
        }

        return [sprintf(
            'both files declare the top-level key%s %s, and the editor can hold only one value for each',
            count($shared) === 1 ? '' : 's',
            implode(', ', array_map(static fn(int|string $key): string => '"' . $key . '"', $shared)),
        )];
    }

    /**
     * Names what a merge and split would change, for a shape no specific
     * rule caught.
     *
     * @param array<string, mixed> $data
     * @param array<int|string, mixed> $partner
     * @param array<string, mixed> $splitData
     * @param array<int|string, mixed> $splitPartner
     */
    private function describeDifference(array $data, array $partner, array $splitData, array $splitPartner): string
    {
        $differences = [];

        foreach ([['the data file', $data, $splitData], ['the ' . $this->type->partnerNoun(), $partner, $splitPartner]] as [$noun, $before, $after]) {
            if ($before === $after) {
                continue;
            }

            $lost = array_values(array_diff(array_map(strval(...), array_keys($before)), array_map(strval(...), array_keys($after))));
            $gained = array_values(array_diff(array_map(strval(...), array_keys($after)), array_map(strval(...), array_keys($before))));

            $differences[] = match (true) {
                $lost !== [] => sprintf('%s would lose %s', $noun, self::quoteKeys($lost)),
                $gained !== [] => sprintf('%s would gain %s', $noun, self::quoteKeys($gained)),
                array_keys($before) !== array_keys($after) => sprintf('%s would have its keys reordered', $noun),
                default => sprintf('%s would come back with different values', $noun),
            };
        }

        return implode('; ', $differences === [] ? ['the pair cannot be merged and split without changing it'] : $differences);
    }

    /**
     * @param string[] $keys
     */
    private static function quoteKeys(array $keys): string
    {
        return implode(', ', array_map(static fn(string $key): string => '"' . $key . '"', array_slice($keys, 0, 4)));
    }

    /**
     * Puts the keys back in the order the file had them, with anything new
     * after them.
     *
     * @param array<int|string, mixed> $values
     * @param string[] $order
     * @return array<int|string, mixed>
     */
    private function inReadOrder(array $values, array $order): array
    {
        if ($order === []) {
            return $values;
        }

        $ordered = [];

        foreach ($order as $key) {
            if (array_key_exists($key, $values)) {
                $ordered[$key] = $values[$key];
            }
        }

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $ordered)) {
                $ordered[$key] = $value;
            }
        }

        return $ordered;
    }
}
