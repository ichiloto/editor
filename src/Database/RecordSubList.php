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
     * @param list<list<string>> $exclusiveFields Field groups where authoring one removes the others.
     * @param string|null $scalarKey The field an entry authored as a bare value is (a
     * reward item's `item`): read as that field, and written back bare while it holds nothing else.
     * @param bool $removeWhenEmpty Whether the list's key is dropped when its last entry goes,
     * as files that omit an empty list author it (a quest's reward items).
     * @param string $heading What a list beside the record's own is headed as ("Reward Items"): its
     * heading row names it, counts it, and is where an entry is added to it. A record-level command
     * list is named by it on the row that opens it ("Stage Camera"), rather than by its key.
     * @param string|null $keyField For a list stored as a map (a tileset's pieces, keyed by piece
     * id): the field each entry carries its key as. Keys are unique and never empty.
     * @param string|null $valueField For a keyed list whose values are not entries of their own (a
     * piece's tiles: a layer name keyed to its rows): the field each entry carries its value as.
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
        public array $exclusiveFields = [],
        public string $heading = '',
        public ?string $scalarKey = null,
        public bool $removeWhenEmpty = false,
        public ?string $keyField = null,
        public ?string $valueField = null,
    ) {
    }

    /** Where a keyed list's entry keeps a value the editor does not read, to write it back as it was. */
    public const string RAW_VALUE = '__value';

    /**
     * The entries a list holds, as the editor edits them. An entry authored
     * as a bare value is its scalar field's value; in a list keyed by
     * `keyField` each entry carries its key as that field. A value an
     * unkeyed list holds that is neither stays as it is; a keyed list keeps
     * it under its key, so writing the list back keeps it.
     *
     * @param mixed $stored The list as the file holds it.
     * @return list<mixed>
     */
    public function readEntries(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $entries = [];

        foreach ($stored as $key => $value) {
            $entry = match (true) {
                $this->valueField !== null => [$this->valueField => $value],
                is_array($value) => $value,
                $this->scalarKey !== null && is_scalar($value) => [$this->scalarKey => $value],
                default => null,
            };

            $entries[] = $this->keyField === null
                ? $entry ?? $value
                : [$this->keyField => (string) $key] + ($entry ?? [self::RAW_VALUE => $value]);
        }

        return $entries;
    }

    /**
     * The form a list's entries are stored in: a map keyed by `keyField`
     * where the list is one, and an entry holding nothing but its scalar
     * field written bare.
     *
     * @param list<mixed> $entries The entries, as the editor edits them.
     * @return array<array-key, mixed>
     */
    public function writeEntries(array $entries): array
    {
        $stored = [];

        foreach (array_values($entries) as $entry) {
            if (! is_array($entry)) {
                $stored[] = $entry;
                continue;
            }

            $key = null;

            if ($this->keyField !== null) {
                $key = (string) ($entry[$this->keyField] ?? '');
                unset($entry[$this->keyField]);
            }

            $value = match (true) {
                $this->valueField !== null => $entry[$this->valueField] ?? null,
                array_key_exists(self::RAW_VALUE, $entry) => $entry[self::RAW_VALUE],
                $this->scalarKey !== null && array_keys($entry) === [$this->scalarKey] => $entry[$this->scalarKey],
                default => $entry,
            };

            if ($key === null) {
                $stored[] = $value;
            } else {
                $stored[$key] = $value;
            }
        }

        return $stored;
    }

    /** @param array<string, mixed> $entry @return array<string, mixed> */
    public function removeConflictingFields(array $entry, string $editedField): array
    {
        if (! array_key_exists($editedField, $entry)) { return $entry; }
        foreach ($this->exclusiveFields as $fields) {
            if (! in_array($editedField, $fields, true)) { continue; }
            foreach ($fields as $field) {
                if ($field !== $editedField) { unset($entry[$field]); }
            }
        }
        return $entry;
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
