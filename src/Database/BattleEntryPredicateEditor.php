<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * Building a battle-entry actor-predicate list a part at a time.
 *
 * The wire form is one line -- `hero:active; medic:reserve` -- compact to
 * store and unforgiving to type. This holds the same list as rows an author
 * moves through: the actor picked from the project's durable actor
 * identities, never typed, and the presence cycled through what the engine's
 * entry roster matching accepts. The line is only ever written by the codec.
 *
 * Nothing here draws, so the behaviour can be exercised without a terminal.
 *
 * @package Ichiloto\Editor\Database
 */
final class BattleEntryPredicateEditor
{
    /**
     * @var array<int, array<string, mixed>> The predicates being edited.
     */
    private array $predicates = [];
    private bool $isOpen = false;
    private string $fieldId = '';
    private string $label = '';
    private int $selectedIndex = 0;

    /**
     * Opens the editor on a field's predicates.
     *
     * @param string $fieldId The field being edited.
     * @param string $label What to call it.
     * @param array<int, mixed> $predicates The predicates as stored.
     * @return void
     */
    public function open(string $fieldId, string $label, array $predicates): void
    {
        $this->isOpen = true;
        $this->fieldId = $fieldId;
        $this->label = $label;
        $this->predicates = array_values(array_filter($predicates, is_array(...)));
        $this->selectedIndex = 0;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->fieldId = '';
        $this->label = '';
        $this->predicates = [];
        $this->selectedIndex = 0;
    }

    public function isOpen(): bool
    {
        return $this->isOpen;
    }

    public function fieldId(): string
    {
        return $this->fieldId;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * @return array<int, array<string, mixed>> The predicates as they stand.
     */
    public function predicates(): array
    {
        return $this->predicates;
    }

    public function encoded(): string
    {
        return BattleEntryPredicateCodec::encodeAll($this->predicates);
    }

    public function count(): int
    {
        return count($this->predicates);
    }

    public function selectedIndex(): int
    {
        return min($this->selectedIndex, max(0, $this->count() - 1));
    }

    /**
     * @return array<string, mixed>|null The predicate the cursor is on.
     */
    public function selected(): ?array
    {
        return $this->predicates[$this->selectedIndex()] ?? null;
    }

    public function move(int $delta): void
    {
        if ($this->count() === 0) {
            $this->selectedIndex = 0;

            return;
        }

        $this->selectedIndex = (($this->selectedIndex() + $delta) % $this->count() + $this->count()) % $this->count();
    }

    /**
     * Adds a predicate below the cursor and moves onto it.
     */
    public function add(): void
    {
        $position = $this->count() === 0 ? 0 : $this->selectedIndex() + 1;
        array_splice($this->predicates, $position, 0, [['actor' => '', 'presence' => 'active']]);
        $this->selectedIndex = $position;
    }

    public function remove(): void
    {
        if ($this->count() === 0) {
            return;
        }

        array_splice($this->predicates, $this->selectedIndex(), 1);
        $this->selectedIndex = max(0, min($this->selectedIndex, $this->count() - 1));
    }

    /**
     * Cycles the predicate's presence through the engine's entry rosters.
     */
    public function cyclePresence(int $step): void
    {
        $predicate = $this->selected();

        if ($predicate === null) {
            return;
        }

        $presences = BattleEntryPredicateCodec::PRESENCES;
        $index = array_search(strval($predicate['presence'] ?? ''), $presences, true);
        $index = is_int($index) ? $index : 0;
        $predicate['presence'] = $presences[(($index + $step) % count($presences) + count($presences)) % count($presences)];
        $this->predicates[$this->selectedIndex()] = $predicate;
    }

    /**
     * Sets the selected predicate's actor identity.
     */
    public function setActor(string $actorId): void
    {
        $predicate = $this->selected();

        if ($predicate === null) {
            return;
        }

        $predicate['actor'] = $actorId;
        $this->predicates[$this->selectedIndex()] = $predicate;
    }

    /**
     * @param array<string, string> $actorLabels Display names by durable id.
     * @return string[] One readable row per predicate.
     */
    public function rows(array $actorLabels = []): array
    {
        return array_map(
            static fn(array $predicate): string => BattleEntryPredicateCodec::describe(
                $predicate,
                $actorLabels[strval($predicate['actor'] ?? '')] ?? null,
            ),
            $this->predicates,
        );
    }
}
