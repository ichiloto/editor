<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\ConditionCodec;
use Ichiloto\Editor\ProjectMap;

/**
 * A map's conditional music, as the engine's `MapManager` reads it.
 *
 * The authored shape is one ordered list beside the static `bgm`:
 *
 * ```php
 * 'bgm' => 'town-in-danger',
 * 'bgmVariants' => [
 *     [
 *         'track' => 'quiet-town',
 *         'conditions' => [['type' => 'event', 'name' => 'crisis_resolved']],
 *     ],
 * ],
 * ```
 *
 * Variants are evaluated in declaration order and the first whose
 * conditions all hold selects the track; an empty conditions list is an
 * unconditional match, exactly as the engine evaluates it. When none
 * matches, the static `bgm` plays, and when neither resolves to a track the
 * current music simply continues. The engine skips a variant it cannot
 * read — no track, conditions that are not a list — which is why the
 * editor keeps such variants visible and diagnosable instead of dropping
 * or repairing them.
 *
 * This model holds exactly what was authored. Keys inside a variant it
 * does not own ride every mutation untouched, and a shape it cannot hold
 * as rows is refused by name rather than rewritten.
 *
 * @package Ichiloto\Editor\Field
 */
final class MapBgmVariants
{
    /** The map key holding the ordered list. */
    public const string KEY = 'bgmVariants';

    /**
     * @param array<int, mixed>|null $block The authored list, or null when the map declares none.
     * @param array<int, array<string, mixed>> $rows The variants, in authored order.
     * @param string|null $unsupported Why this list cannot be edited, or null.
     */
    private function __construct(
        private readonly ?array $block,
        private readonly array $rows,
        private readonly ?string $unsupported,
    ) {
    }

    /**
     * Reads the list a map currently holds, unsaved edits included.
     */
    public static function fromMap(ProjectMap $map): self
    {
        return self::of($map->getMapDataField([self::KEY]));
    }

    /**
     * Reads one authored value as a variant list.
     */
    public static function of(mixed $block): self
    {
        if ($block === null) {
            return new self(null, [], null);
        }

        if (! is_array($block) || ! array_is_list($block)) {
            return new self(null, [], sprintf('the bgmVariants block is %s, not an ordered list', is_array($block) ? 'a keyed array' : get_debug_type($block)));
        }

        foreach ($block as $index => $variant) {
            if (! is_array($variant)) {
                // The engine skips it; the editor cannot show it as a row
                // without pretending it has a track and conditions.
                return new self($block, [], sprintf('variant %d is %s, not an array', $index + 1, get_debug_type($variant)));
            }
        }

        return new self($block, array_values($block), null);
    }

    /**
     * Whether the map declares a bgmVariants key at all.
     */
    public function isDeclared(): bool
    {
        return $this->block !== null || $this->unsupported !== null;
    }

    /**
     * Whether the list can be edited as rows.
     */
    public function isSupported(): bool
    {
        return $this->unsupported === null;
    }

    /**
     * Why the list is read-only, when it is.
     */
    public function unsupportedReason(): ?string
    {
        return $this->unsupported;
    }

    /**
     * The variants, in authored order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * The authored track of one variant: null when absent or not a string.
     */
    public function trackAt(int $index): ?string
    {
        $track = $this->rows[$index]['track'] ?? null;

        return is_string($track) ? trim($track) : null;
    }

    /**
     * The raw authored track value, for showing a shape honestly.
     */
    public function rawTrackAt(int $index): mixed
    {
        return $this->rows[$index]['track'] ?? null;
    }

    /**
     * The authored conditions of one variant, empty when absent.
     *
     * @return array<int, mixed>
     */
    public function conditionsAt(int $index): array
    {
        $conditions = $this->rows[$index]['conditions'] ?? [];

        return is_array($conditions) ? array_values($conditions) : [];
    }

    /**
     * The keys the condition line carries per type, beyond type/name/negate.
     */
    private const array CONDITION_KEYS = [
        'switch' => ['value'],
        'event' => [],
        'variable' => ['op', 'value'],
        'item' => ['quantity'],
        'key_item' => ['quantity'],
        'quest' => ['status'],
    ];

    /**
     * Why one variant's conditions cannot go through the condition editor,
     * or null when the editor can carry them faithfully.
     *
     * Committing the editor writes what the condition line carries; a
     * condition type the line cannot express, or an authored key outside
     * the line's vocabulary, would be silently dropped on commit, so such
     * a variant's conditions are refused by name instead. The track stays
     * editable either way.
     */
    public function conditionsIssueAt(int $index): ?string
    {
        $conditions = $this->rows[$index]['conditions'] ?? [];

        if (! is_array($conditions)) {
            return sprintf('the conditions are %s, not a list', get_debug_type($conditions));
        }

        foreach (array_values($conditions) as $conditionIndex => $condition) {
            if (! is_array($condition)) {
                return sprintf('condition %d is %s, not an array', $conditionIndex + 1, get_debug_type($condition));
            }

            if (ConditionCodec::decode(ConditionCodec::encode($condition)) === null) {
                return sprintf('condition %d holds a shape the condition editor cannot carry', $conditionIndex + 1);
            }

            $type = strval($condition['type'] ?? '');
            $carried = ['type', 'name', 'negate', ...(self::CONDITION_KEYS[$type] ?? [])];
            $extra = array_diff(array_keys($condition), $carried);

            if ($extra !== []) {
                return sprintf(
                    'condition %d carries "%s", which the condition editor would drop',
                    $conditionIndex + 1,
                    implode('", "', array_map(strval(...), $extra)),
                );
            }
        }

        return null;
    }

    /**
     * One line for the Inspector heading.
     */
    public function summary(): string
    {
        if (! $this->isSupported()) {
            return 'read-only';
        }

        if (! $this->isDeclared()) {
            return 'None';
        }

        return sprintf('%d · first match wins', count($this->rows));
    }

    /**
     * The list with a fresh, visibly incomplete variant appended.
     *
     * The empty track is the incomplete state — the engine skips it and
     * validation names it — and the empty conditions are an explicit
     * unconditional match, because that is what the engine evaluates.
     *
     * @return array<int, array<string, mixed>>
     */
    public function withVariantAdded(): array
    {
        return [...$this->rows, ['track' => '', 'conditions' => []]];
    }

    /**
     * The list without one variant, or null when the last is removed and
     * the key should leave the file with it.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function withVariantRemovedAt(int $index): ?array
    {
        $rows = $this->rows;
        array_splice($rows, $index, 1);

        return $rows === [] ? null : $rows;
    }

    /**
     * The list with one variant moved, or null when the move is out of range.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function withVariantMovedTo(int $from, int $to): ?array
    {
        $rows = $this->rows;

        if (! isset($rows[$from]) || $to < 0 || $to >= count($rows) || $from === $to) {
            return null;
        }

        [$variant] = array_splice($rows, $from, 1);
        array_splice($rows, $to, 0, [$variant]);

        return $rows;
    }

    /**
     * The list with one variant's track replaced, other keys untouched.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function withTrackAt(int $index, string $track): ?array
    {
        $rows = $this->rows;

        if (! isset($rows[$index])) {
            return null;
        }

        $rows[$index]['track'] = $track;

        return $rows;
    }

    /**
     * The list with one variant's conditions replaced, other keys untouched.
     *
     * @param array<int, array<string, mixed>> $conditions
     * @return array<int, array<string, mixed>>|null
     */
    public function withConditionsAt(int $index, array $conditions): ?array
    {
        $rows = $this->rows;

        if (! isset($rows[$index])) {
            return null;
        }

        $rows[$index]['conditions'] = array_values($conditions);

        return $rows;
    }
}
