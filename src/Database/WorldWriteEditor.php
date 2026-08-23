<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * Building a world-state write list a part at a time.
 *
 * The wire form is one line -- `switch:gate_open; event:met_the_king` --
 * compact to store and unforgiving to type. This holds the same list as
 * rows an author moves through: the type cycled through what the engine's
 * writer applies, the name picked where it names a record (a quest) and
 * typed where it is invented (a switch, an event, a variable), the extras
 * cycled per type. The line is only ever written by the codec.
 *
 * Nothing here draws, so the behaviour can be exercised without a terminal.
 *
 * @package Ichiloto\Editor\Database
 */
final class WorldWriteEditor
{
    /**
     * What each write type calls the thing it names, and which kind of
     * reference that is. Null is a name the author invents.
     */
    private const array NAMES = [
        'switch' => ['Switch', null],
        'event' => ['Story Event', null],
        'variable' => ['Variable', null],
        'quest' => ['Quest', 'quests'],
    ];

    /**
     * @var array<int, array<string, mixed>> The writes being edited.
     */
    private array $sets = [];
    private bool $isOpen = false;
    private string $fieldId = '';
    private string $label = '';
    private int $selectedIndex = 0;

    /**
     * @var string[] The write types this surface may author.
     */
    private array $types = WorldWriteCodec::TYPES;

    /**
     * Opens the editor on a field's writes.
     *
     * @param string $fieldId The field being edited.
     * @param string $label What to call it.
     * @param array<int, mixed> $sets The writes as stored.
     * @param string[]|null $types The write types this surface may author,
     * when the runtime restricts it (a transactional boundary rejects quest
     * acceptance). Null offers the writer's full vocabulary. A write already
     * in the source keeps its type until the author changes it, so nothing
     * is silently rewritten; validation diagnoses it instead.
     * @return void
     */
    public function open(string $fieldId, string $label, array $sets, ?array $types = null): void
    {
        $this->isOpen = true;
        $this->fieldId = $fieldId;
        $this->label = $label;
        $this->sets = array_values(array_filter($sets, is_array(...)));
        $this->selectedIndex = 0;
        $types = array_values(array_intersect($types ?? WorldWriteCodec::TYPES, WorldWriteCodec::TYPES));
        $this->types = $types === [] ? WorldWriteCodec::TYPES : $types;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->fieldId = '';
        $this->label = '';
        $this->sets = [];
        $this->selectedIndex = 0;
        $this->types = WorldWriteCodec::TYPES;
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
     * @return array<int, array<string, mixed>> The writes as they stand.
     */
    public function sets(): array
    {
        return $this->sets;
    }

    public function encoded(): string
    {
        return WorldWriteCodec::encodeAll($this->sets);
    }

    public function count(): int
    {
        return count($this->sets);
    }

    public function selectedIndex(): int
    {
        return min($this->selectedIndex, max(0, $this->count() - 1));
    }

    /**
     * @return array<string, mixed>|null The write the cursor is on.
     */
    public function selected(): ?array
    {
        return $this->sets[$this->selectedIndex()] ?? null;
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
     * Adds a write below the cursor and moves onto it.
     */
    public function add(): void
    {
        $position = $this->count() === 0 ? 0 : $this->selectedIndex() + 1;
        array_splice($this->sets, $position, 0, [['type' => 'switch', 'name' => '', 'value' => true]]);
        $this->selectedIndex = $position;
    }

    public function remove(): void
    {
        if ($this->count() === 0) {
            return;
        }

        array_splice($this->sets, $this->selectedIndex(), 1);
        $this->selectedIndex = max(0, min($this->selectedIndex, $this->count() - 1));
    }

    /**
     * Cycles the write's type, rebuilding extras as the type's own defaults.
     *
     * A type outside this surface's vocabulary (a quest write already in the
     * source) cycles onto the first offered type: the author is never
     * offered what the runtime would reject here.
     */
    public function cycleType(int $step): void
    {
        $set = $this->selected();

        if ($set === null) {
            return;
        }

        $types = $this->types;
        $index = array_search(strval($set['type'] ?? ''), $types, true);
        $index = is_int($index) ? $index : -$step;
        $type = $types[(($index + $step) % count($types) + count($types)) % count($types)];
        $rebuilt = ['type' => $type, 'name' => strval($set['name'] ?? '')];

        if ($type === 'switch') {
            $rebuilt['value'] = true;
        } elseif ($type === 'variable') {
            $rebuilt['op'] = 'set';
            $rebuilt['value'] = 0;
        }

        $this->sets[$this->selectedIndex()] = $rebuilt;
    }

    /**
     * Cycles whatever the write's type carries beyond a name.
     */
    public function cycleExtra(int $step): void
    {
        $set = $this->selected();

        if ($set === null) {
            return;
        }

        switch (strval($set['type'] ?? '')) {
            case 'switch':
                $set['value'] = ($set['value'] ?? true) === false;
                break;
            case 'variable':
                $set['op'] = strval($set['op'] ?? 'set') === 'add' ? 'set' : 'add';
                break;
            case 'quest':
                $set['confirm'] = ($set['confirm'] ?? true) === false;
                break;
            default:
                return;
        }

        $this->sets[$this->selectedIndex()] = $set;
    }

    public function setName(string $name): void
    {
        $set = $this->selected();

        if ($set === null) {
            return;
        }

        $set['name'] = $name;
        $this->sets[$this->selectedIndex()] = $set;
    }

    /**
     * Sets a variable write's value.
     */
    public function setValue(string $value): void
    {
        $set = $this->selected();

        if ($set === null || strval($set['type'] ?? '') !== 'variable') {
            return;
        }

        $set['value'] = is_numeric($value) ? $value + 0 : $value;
        $this->sets[$this->selectedIndex()] = $set;
    }

    /**
     * @return array{label: string, category: string|null}|null What the selected write names.
     */
    public function nameReference(): ?array
    {
        $set = $this->selected();

        if ($set === null) {
            return null;
        }

        [$label, $category] = self::NAMES[strval($set['type'] ?? '')] ?? ['Name', null];

        return ['label' => $label, 'category' => $category];
    }

    /**
     * @return string[] One readable row per write.
     */
    public function rows(): array
    {
        return array_map(WorldWriteCodec::describe(...), $this->sets);
    }
}
