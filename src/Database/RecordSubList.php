<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Closure;

/**
 * A repeating list nested inside a record — quest objectives, skit beats,
 * troop members, event-script commands.
 *
 * Sub-list entries are flattened into the settings pane as
 * `<prefix><index><FieldKey>` rows (the idiom the Quests category proved),
 * and Shift+O / Shift+X append and remove entries.
 */
final readonly class RecordSubList
{
    /**
     * @param string $key The payload key holding the list.
     * @param string $prefix The settings-field id prefix (e.g. `beat`).
     * @param string $singular The noun used in status messages (e.g. `beat`).
     * @param RecordField[] $fields The per-entry fields.
     * @param array<string, mixed> $blank The payload of a freshly appended entry.
     * @param array<string, RecordField[]|Closure(array<string, mixed>): RecordField[]> $variants
     *   Per-`type` field overrides, keyed by type value. A closure is asked
     *   for the fields when a variant's own shape depends on the entry.
     * @param string|null $variantKey The entry key selecting a variant (e.g. `type`).
     * @param array<string, RecordSubList|\Closure> $nestedLists Per-variant nested
     * lists. Event movement routes use this to edit structured `steps`
     * without flattening them into free text.
     * @param array<string, string> $commandArms Event-command lists every
     * entry may carry, key => label. A dialogue variant's `script` is
     * opened as a frame this way, exactly like a branch arm.
     * @param array<string, array<string, string>> $variantArms Command lists
     * only some variants carry, variant => (key => label): a cinematic
     * `sequence` owns its `commands`, a `choice` its `cancel` arm.
     */
    public function __construct(
        public string $key,
        public string $prefix,
        public string $singular,
        public array $fields,
        public array $blank,
        public array $variants = [],
        public ?string $variantKey = null,
        public array $nestedLists = [],
        public array $commandArms = [],
        public array $variantArms = [],
    ) {
    }

    /**
     * Returns the command arms one entry carries: the arms every entry has,
     * and those its variant adds.
     *
     * @param array<string, mixed> $entry The entry payload.
     * @return array<string, string> Arm key => label.
     */
    public function armsFor(array $entry): array
    {
        $arms = $this->commandArms;

        if ($this->variantKey !== null) {
            $variant = strval($entry[$this->variantKey] ?? '');

            foreach ($this->variantArms[$variant] ?? [] as $key => $label) {
                $arms[$key] = $label;
            }
        }

        return $arms;
    }

    /**
     * Returns the fields for one entry, honouring any per-type variant.
     *
     * Event-script commands are the reason this exists: a `text` command and a
     * `transfer` command share a `type` row and nothing else.
     *
     * @param array<string, mixed> $entry The entry payload.
     * @return RecordField[]
     */
    public function fieldsFor(array $entry): array
    {
        if ($this->variantKey === null) {
            return $this->fields;
        }

        $variant = strval($entry[$this->variantKey] ?? '');
        $fields = $this->variants[$variant] ?? [];

        if ($fields instanceof Closure) {
            // A variant whose own shape depends on the entry: a knowledge
            // command asks for a report only when its operation is about
            // one, so an author is never shown a field the runtime will not
            // read for what they picked.
            /** @var RecordField[] $fields */
            $fields = $fields($entry);
        }

        return [...$this->fields, ...$fields];
    }

    /**
     * Returns the nested list exposed by this entry's current variant.
     */
    public function nestedListFor(array $entry): ?self
    {
        // A list every entry carries regardless of variant -- a dialogue
        // variant's lines -- is keyed '*'.
        if (isset($this->nestedLists['*'])) {
            return $this->nestedLists['*'];
        }

        if ($this->variantKey === null) {
            return null;
        }

        $nested = $this->nestedLists[strval($entry[$this->variantKey] ?? '')] ?? null;

        // A list some shapes of a variant carry and others do not -- a
        // camera route's points, but not a camera detach's -- is a closure
        // over the entry.
        if ($nested instanceof \Closure) {
            $resolved = $nested($entry);

            return $resolved instanceof self ? $resolved : null;
        }

        return $nested;
    }
}
