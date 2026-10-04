<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\IO\AtomicFile;

use BackedEnum;
use Throwable;

use Ichiloto\Editor\History\TracksPersistedState;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\ProjectConfig;
use Ichiloto\Editor\Database\Projections\WholeFileProjection;
use Ichiloto\Editor\Inspector\InputControl;
use Ichiloto\Editor\Inspector\InputControlType;
use RuntimeException;

/**
 * A schema-driven Database category.
 *
 * One class backs every category added in Phase 6 — states, troops, items,
 * weapons, armors, enemies, terms, tilesets, types, skits, and event scripts.
 * The behaviour an author sees (entry list, flattened settings pane, dirty
 * markers, Save All participation, identity-pinned undo, entry deletion)
 * comes from the `RecordSchema` rather than from bespoke code per category.
 *
 * Editability is *detected*, never assumed: the category is writable only
 * when its authored file round-trips losslessly (see `PhpDataFile`). A file
 * of `new Item(...)` calls is browsed, and `getReadOnlyReason()` says why.
 */
final class ProjectRecordDatabase
{
    use TracksPersistedState { isDirty as private isRecordStateDirty; }

    /**
     * Numeric identities of records removed this session, so a new record never
     * takes one something may still name.
     *
     * @var list<int>
     */
    private array $retiredIdentities = [];

    /**
     * @param RecordSchema $schema The category schema.
     * @param string $path The file or directory backing the category.
     * @param ProjectRecord[] $records The loaded records.
     * @param PhpDataFile|null $file The backing file, for single-file categories.
     * @param bool $isDirty Whether structural changes are unsaved.
     * @param string|null $readOnlyReason Why the category cannot be written.
     * @param string[] $stagedDeletions Record files to unlink on the next save.
     */
    private function __construct(
        public readonly RecordSchema $schema,
        public readonly string $path,
        private array $records = [],
        private ?PhpDataFile $file = null,
        bool $isDirty = false,
        private ?string $readOnlyReason = null,
        private array $stagedDeletions = [],
        private ?\Closure $writeBack = null,
        private ?string $projectRoot = null,
        private ?ProjectConfig $config = null,
        private ?\Closure $identityReferences = null,
    ) {
        if ($schema->storage === RecordStorage::LIST_FILE && $schema->projection === null) {
            // What the file declares for each record -- the identity it is
            // addressed by and the values it was authored with -- so a save
            // can patch only what an author actually changed, and can find
            // the entry to patch however the file has moved around it since.
            // Captured before the baseline, which is taken over the payload
            // these identities fold the records into.
            $this->captureAuthoredValues();
        }

        if (! $isDirty) {
            $this->captureBaseline();
        }
    }

    /**
     * @var array<int, array{record: ProjectRecord, identity: string|null, values: array<string, mixed>}>
     *   What the file held for each record when it was last read or adopted,
     *   by the record's object id: the durable identity the record's entry
     *   declares (an item's stable id, a troop's name) and the field values
     *   it was authored with. A record absent from here is one the file has
     *   never held under this category's watch: it is written as a new
     *   entry. Removing a record leaves it here -- the record itself is kept
     *   alongside, so its id stays its own -- and putting it back before the
     *   file is written finds its entry exactly where it was. Adopting a
     *   written file rebuilds this from the records the category then holds.
     */
    private array $authored = [];

    /**
     * Returns what the file declared for a record, or null when the file has
     * never held it.
     *
     * @param ProjectRecord $record The record.
     * @return array{identity: string|null, values: array<string, mixed>}|null The declaration.
     */
    private function authoredFor(ProjectRecord $record): ?array
    {
        $authored = $this->authored[spl_object_id($record)] ?? null;

        if ($authored === null || $authored['record'] !== $record) {
            return null;
        }

        return ['identity' => $authored['identity'], 'values' => $authored['values']];
    }

    /**
     * Returns the durable identity an entry declares, or null when it
     * declares none.
     *
     * The identity is whatever the schema names as the record's id -- read
     * the same way from an authored array, from an engine object the file
     * constructed, and from the record the editor holds -- so the record
     * and its entry in a fresh read of the file are recognised as one thing
     * by what they say, not by where they sit.
     *
     * @param mixed $entry The entry payload.
     * @param string|null $key The schema's identity key.
     * @return string|null The identity.
     */
    private static function identityOf(mixed $entry, ?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        $value = new ProjectRecord(is_array($entry) || is_object($entry) ? $entry : [], true)->get($key);

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $identity = strval($value);

        return $identity === '' ? null : $identity;
    }

    /**
     * Describes where a record sits in its source, for a diagnostic that has
     * to tell two records with one identity apart.
     *
     * Only for a category over one file, and only while the records still
     * stand in the order the file was read in -- after a record is added or
     * removed the file's places no longer line up with the list, and a
     * guess would point at the wrong entry, so nothing is said.
     *
     * @param int $index The record index.
     * @return string|null The source context, or null when it cannot be told.
     */
    public function sourceContextOf(int $index): ?string
    {
        if ($this->schema->storage !== RecordStorage::LIST_FILE || ! $this->file instanceof PhpDataFile || ! is_array($this->file->payload)) {
            return null;
        }

        $positions = [];

        foreach (array_values($this->file->payload) as $position => $entry) {
            if ($this->schema->recordFilter !== null && ! ($this->schema->recordFilter)($entry)) {
                continue;
            }

            if (is_array($entry) || is_object($entry)) {
                $positions[] = $position;
            }
        }

        if (count($positions) !== count($this->records) || ! isset($positions[$index])) {
            return null;
        }

        return sprintf('%s entry %d', $this->schema->relativePath, $positions[$index] + 1);
    }

    /**
     * Returns the position of every entry of this category in a file's
     * returned list, keyed by the identity each declares.
     *
     * A file holds several categories -- items, weapons and armors share
     * one -- so the third weapon may be the file's tenth entry, and an entry
     * of another category never appears here. An identity two entries
     * declare maps to both positions; an entry declaring none is listed
     * under `absent`, because it can be found but never told apart.
     *
     * @param PhpDataFile $file The file, read fresh.
     * @return array{positions: array<string, int[]>, absent: int[]} The map.
     */
    private function sourcePositionsIn(PhpDataFile $file): array
    {
        $positions = [];
        $absent = [];

        if (! is_array($file->payload)) {
            return ['positions' => [], 'absent' => []];
        }

        foreach (array_values($file->payload) as $position => $entry) {
            if ($this->schema->recordFilter !== null && ! ($this->schema->recordFilter)($entry)) {
                continue;
            }

            if (! is_array($entry) && ! is_object($entry)) {
                continue;
            }

            $identity = self::identityOf($entry, $this->schema->identityKey);

            if ($identity === null) {
                $absent[] = $position;

                continue;
            }

            $positions[$identity][] = $position;
        }

        return ['positions' => $positions, 'absent' => $absent];
    }

    /**
     * Builds a category over records another asset owns.
     *
     * The list is a map's `npcs`: this category edits the entries with the
     * same panes, pickers, frames and undo identity as any file-backed
     * category, and `save()` hands the rewritten list back through the
     * writer instead of touching a file. The owning map persists it and
     * fingerprints it; this category's own dirt is derived from its records.
     *
     * @param RecordSchema $schema The category schema (MAP_OWNED storage).
     * @param string $ownerPath The owner's path, for messages and identity.
     * @param array<int, mixed> $entries The current entries.
     * @param \Closure(array<int, mixed>): void $writeBack Receives the rewritten list.
     * @return self The category.
     */
    public static function overOwnedList(RecordSchema $schema, string $ownerPath, array $entries, \Closure $writeBack): self
    {
        $records = [];

        foreach (array_values($entries) as $index => $entry) {
            if (is_array($entry)) {
                $records[] = new ProjectRecord($entry, false, null, strval($entry['id'] ?? $index));
            }
        }

        $database = new self($schema, $ownerPath, $records);
        $database->writeBack = $writeBack;

        return $database;
    }

    /**
     * Loads a category from a project root.
     *
     * @param string $projectRoot The project root.
     * @param RecordSchema $schema The category schema.
     * @return self
     */
    public static function fromProject(string $projectRoot, RecordSchema $schema, ?ProjectConfig $config = null): self
    {
        // The categories evaluate authored files that construct engine
        // objects, and creating an entry constructs them too. The runtime
        // they need is this layer's to guarantee, not something every
        // embedder has to know to arrange.
        EngineDataBootstrap::ensure($projectRoot);

        $path = $schema->resolvePath($projectRoot);

        return match ($schema->storage) {
            RecordStorage::LIST_FILE => self::loadListFile($path, $schema, $projectRoot),
            RecordStorage::DIRECTORY => self::loadDirectory($path, $schema, $projectRoot),
            RecordStorage::CONFIG_SUBTREE => self::loadConfigSubtree($path, $schema, $projectRoot, $config),
            RecordStorage::MAP_OWNED => throw new RuntimeException('Map-owned records are built over their owner, not loaded from a path.'),
        };
    }

    /**
     * @return ProjectRecord[]
     */
    public function getRecords(): array
    {
        return array_values($this->records);
    }

    public function getRecordByIndex(int $index): ?ProjectRecord
    {
        return $this->records[$index] ?? null;
    }

    /**
     * Returns the entry-list labels for this category.
     *
     * @return string[]
     */
    public function getEntryLabels(): array
    {
        return array_map($this->getEntryLabel(...), $this->getRecords());
    }

    /** The entry-list label of one record. */
    public function getEntryLabel(ProjectRecord $record): string
    {
        if ($this->schema->labelFor !== null) {
            // A record whose name is made of its parts -- the scope a
            // weight vector applies at, what an exclusion excludes --
            // rather than stored under a key of its own.
            return strval(($this->schema->labelFor)((array) $record->toArray()));
        }

        $label = $record->getDisplayValue($this->schema->labelKey);

        if ($label === '' && $this->schema->identityKey !== null) {
            $label = $record->getDisplayValue($this->schema->identityKey);
        }

        return $label === '' ? ($record->recordId ?: '(unnamed)') : $label;
    }

    /**
     * Returns whether this category can be written at all.
     *
     * @return bool
     */
    public function isEditable(): bool
    {
        return $this->readOnlyReason === null;
    }

    public function isDirty(): bool
    {
        if ($this->config !== null) {
            foreach ($this->records as $record) {
                if ($record->isDirty()) { return true; }
            }
            return false;
        }
        return $this->isRecordStateDirty();
    }

    /**
     * Returns the honest explanation for a read-only category.
     *
     * @return string|null
     */
    public function getReadOnlyReason(): ?string
    {
        return $this->readOnlyReason;
    }

    /**
     * Explains why this category's projection cannot preserve a freshly
     * loaded payload, or null when it can.
     */
    public function projectionPreservationIssue(mixed $payload): ?string
    {
        $projection = $this->schema->projection;

        return $projection === null
            ? null
            : self::projectionPreservationIssueFor($projection, $payload);
    }

    /**
     * @inheritDoc
     */
    protected function dependencyVersion(): string
    {
        $versions = [];

        foreach ($this->records as $record) {
            $versions[] = $record->stateVersion();
        }

        return count($this->records) . ':' . implode(',', $versions)
            . '|' . implode(';', array_values($this->stagedDeletions));
    }

    /**
     * @inheritDoc
     */
    protected function buildPersistedPayload(): string
    {
        if (! $this->isEditable()) {
            // A category that will never write has nothing to diverge from:
            // it fingerprints as a constant and is never dirty. Building a
            // payload here would also mean exporting the unexportable.
            return '';
        }

        return match ($this->schema->storage) {
            // The exact form each save would persist, so dirty means the
            // save would change something and nothing else. A category that
            // owns one part of a shared file fingerprints only its own part:
            // a sibling saving its own list is not a change to this one.
            RecordStorage::LIST_FILE => PhpValueExporter::export(
                $this->schema->projection !== null
                    ? array_map(static fn(ProjectRecord $record): array => (array) $record->toArray(), $this->getRecords())
                    : $this->mergeIntoFilePayload()
            ),
            RecordStorage::DIRECTORY => implode("\0", array_map(
                static fn(ProjectRecord $record): string => $record->recordId . '=' . PhpValueExporter::export($record->toArray()),
                $this->records,
            )) . '|' . implode(';', array_values($this->stagedDeletions)),
            RecordStorage::CONFIG_SUBTREE, RecordStorage::MAP_OWNED => serialize([
                array_map(static fn(ProjectRecord $record): array|object => $record->toArray(), $this->records),
                array_values($this->stagedDeletions),
            ]),
        };
    }

    /**
     * Returns the files a save of this category would overwrite.
     *
     * @return string[]
     */
    public function getBackupPaths(): array
    {
        if ($this->schema->storage !== RecordStorage::DIRECTORY) {
            return is_file($this->path) ? [$this->path] : [];
        }

        $paths = [];

        foreach ($this->records as $record) {
            if ($record->sourcePath !== null && is_file($record->sourcePath)) {
                $paths[] = $record->sourcePath;
            }
        }

        return $paths;
    }

    /**
     * Builds the settings-pane field descriptors for one record.
     *
     * Descriptors match the editor's existing contract: a `control` makes a
     * row typed/steppable, an `options` list makes it cycled, and a row with
     * neither renders as a fixed `Label · value` line.
     *
     * @param int $index The record index.
     * @return array<int, array<string, mixed>>
     */
    public function getSettingsFields(int $index): array
    {
        $record = $this->getRecordByIndex($index);

        if (! $record instanceof ProjectRecord) {
            return [];
        }

        $isEditable = $this->isEditable() && $record->isEditable();
        $configIssue = $this->config?->getFieldIssue($record->getDisplayValue('path'));
        $isEditable = $isEditable && $configIssue === null;
        $fields = [];

        foreach ($this->schema->fieldsFor($record->toArray()) as $field) {
            $fields[] = self::describeField($field, self::displayValue($field, $record->get($field->key)), $field->key, $isEditable);
        }
        if ($configIssue !== null) { $fields[] = ['label' => 'Read-only', 'value' => $configIssue, 'editable' => false]; }

        foreach ($this->schema->commandLists as $listKey => $commandList) {
            // A record-level command list (an NPC's inline script) is a
            // frame to open, like a branch arm, never a wall of rows here.
            $fields[] = [
                // Named for what it is to the record (its Script), with the
                // count of commands it holds.
                'label' => ucfirst($listKey),
                'value' => sprintf('%d', count($record->getSubList($listKey))),
                'field' => 'commandList' . ucfirst($listKey),
                'frame' => [$listKey],
            ];
        }

        foreach ($this->schema->getInlineSubLists() as $subList) {
            if ($subList !== $this->schema->subList) {
                // A list beside the record's own is headed by its name and
                // count; its heading is where an entry is added to it.
                $fields[] = [
                    'label' => $subList->heading,
                    'value' => sprintf('%d', count($record->getEntries($subList))),
                    'field' => $subList->prefix . 'List',
                    'listHeading' => true,
                ];
            }

            foreach ($record->getEntries($subList) as $entryIndex => $entry) {
                $fields = [...$fields, ...$this->describeSubEntryFields($subList, $entryIndex, $entry, $isEditable, [])];
            }
        }

        return $fields;
    }

    /**
     * Describes one sub-list entry's rows, structural rows included.
     *
     * The same rows serve the top-level command list and any nested frame,
     * so a command reads identically at every depth.
     *
     * @param RecordSubList $subList The sub-list schema.
     * @param int $entryIndex The entry's index in its own list.
     * @param array<string, mixed> $entry The entry payload.
     * @param bool $isEditable Whether edits are accepted.
     * @param array<int, int|string> $basePath The frame path this entry's list lives at.
     * @return array<int, array<string, mixed>> The field descriptors.
     */
    private function describeSubEntryFields(RecordSubList $subList, int $entryIndex, array $entry, bool $isEditable, array $basePath): array
    {
        $fields = [];

        foreach ($subList->fieldsFor($entry) as $field) {
            $fields[] = self::describeField(
                $field,
                self::displayValue($field, self::readNested($entry, $field->key)),
                self::subFieldId($subList->prefix, $entryIndex, $field->key),
                $isEditable,
                sprintf('%s %d %s', ucfirst($subList->singular), $entryIndex + 1, $field->label),
            );
        }

        $variant = $subList->variantKey !== null ? strval($entry[$subList->variantKey] ?? '') : '';

        if ($variant === 'choice') {
            // Each option is a row to rename and a frame to open. The arm's
            // commands are edited inside the frame, exactly as the runtime
            // executes them.
            foreach (array_values((array) ($entry['options'] ?? [])) as $optionIndex => $option) {
                if (! is_array($option)) {
                    continue;
                }

                $armCount = count((array) ($option['then'] ?? []));
                $label = sprintf('%s %d Option %d', ucfirst($subList->singular), $entryIndex + 1, $optionIndex + 1);
                $text = strval($option['text'] ?? '');
                $textField = [
                    'label' => $label . ' Text',
                    'value' => $text,
                    'field' => sprintf('%s%dOption%dText', $subList->prefix, $entryIndex, $optionIndex),
                ];

                if ($isEditable) {
                    $textField['control'] = new InputControl(InputControlType::TEXT, $text);
                }

                $fields[] = $textField;
                $fields[] = [
                    'label' => $label . ' Commands',
                    'value' => sprintf('%d', $armCount),
                    'field' => sprintf('%s%dOption%dThen', $subList->prefix, $entryIndex, $optionIndex),
                    'frame' => [...$basePath, $entryIndex, 'options', $optionIndex, 'then'],
                ];
            }
        }

        $arms = $variant === 'branch' ? ['then' => 'Then', 'else' => 'Else'] : [];

        foreach ($subList->armsFor($entry) as $armKey => $armLabel) {
            // Arms every entry of this list may carry -- a dialogue variant's
            // own script -- and arms its variant adds -- a sequence's
            // commands, a choice's cancel arm -- edited in frames like a
            // branch arm.
            $arms[$armKey] = $armLabel;
        }

        foreach ($arms as $armKey => $armLabel) {
            $fields[] = [
                'label' => sprintf('%s %d %s Commands', ucfirst($subList->singular), $entryIndex + 1, $armLabel),
                'value' => sprintf('%d', count((array) ($entry[$armKey] ?? []))),
                'field' => sprintf('%s%d%s', $subList->prefix, $entryIndex, ucfirst($armKey)),
                'frame' => [...$basePath, $entryIndex, $armKey],
            ];
        }

        $nestedList = $subList->nestedListFor($entry);

        if ($nestedList === null) {
            return $fields;
        }

        foreach ($nestedList->readEntries($entry[$nestedList->key] ?? []) as $nestedIndex => $nestedEntry) {
            if (! is_array($nestedEntry)) {
                continue;
            }

            foreach ($nestedList->fieldsFor($nestedEntry) as $field) {
                $fields[] = self::describeField(
                    $field,
                    self::displayValue($field, self::readNested($nestedEntry, $field->key)),
                    self::nestedSubFieldId(
                        $subList->prefix,
                        $entryIndex,
                        $nestedList->prefix,
                        $nestedIndex,
                        $field->key,
                    ),
                    $isEditable,
                    sprintf(
                        '%s %d %s %d %s',
                        ucfirst($subList->singular),
                        $entryIndex + 1,
                        ucfirst($nestedList->singular),
                        $nestedIndex + 1,
                        $field->label,
                    ),
                );
            }

            foreach ($nestedList->armsFor($nestedEntry) as $armKey => $armLabel) {
                // A nested entry owning commands -- a parallel block's lane --
                // opens them as a frame, like an option's arm.
                $fields[] = [
                    'label' => sprintf(
                        '%s %d %s %d %s Commands',
                        ucfirst($subList->singular),
                        $entryIndex + 1,
                        ucfirst($nestedList->singular),
                        $nestedIndex + 1,
                        $armLabel,
                    ),
                    'value' => sprintf('%d', count((array) ($nestedEntry[$armKey] ?? []))),
                    'field' => self::nestedSubFieldId($subList->prefix, $entryIndex, $nestedList->prefix, $nestedIndex, ucfirst($armKey)),
                    'frame' => [...$basePath, $entryIndex, $nestedList->key, $nestedIndex, $armKey],
                ];
            }
        }

        return $fields;
    }

    /**
     * Applies one flattened settings-field edit.
     *
     * @param int $index The record index.
     * @param string $fieldId The settings-field identifier.
     * @param string $rawValue The raw edited value.
     * @return void
     */
    public function setField(int $index, string $fieldId, string $rawValue): void
    {
        $record = $this->getRecordByIndex($index);

        if (! $record instanceof ProjectRecord || ! $this->isEditable() || ! $record->isEditable()) {
            return;
        }
        if ($this->config !== null && ($issue = $this->config->getFieldIssue($record->getDisplayValue('path'))) !== null) {
            throw new \Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal($issue);
        }

        $subList = $this->schema->subList;

        if ($subList !== null) {
            $nestedPattern = '/^'
                . preg_quote($subList->prefix, '/')
                . '(\d+)([A-Za-z][A-Za-z0-9]*?)(\d+)([A-Za-z][A-Za-z0-9]*)$/';

            if (preg_match($nestedPattern, $fieldId, $matches) === 1) {
                $this->setNestedSubField(
                    $record,
                    $subList,
                    intval($matches[1]),
                    $matches[2],
                    intval($matches[3]),
                    $matches[4],
                    $rawValue,
                );
                return;
            }
        }

        foreach ($this->schema->getInlineSubLists() as $inlineList) {
            if (preg_match('/^' . preg_quote($inlineList->prefix, '/') . '(\d+)([A-Za-z][A-Za-z0-9]*)$/', $fieldId, $matches) === 1) {
                $this->setSubField($record, $inlineList, intval($matches[1]), $matches[2], $rawValue);
                return;
            }
        }

        foreach ($this->schema->fieldsFor($record->toArray()) as $field) {
            if ($field->key !== $fieldId || $field->isReadOnly) {
                continue;
            }

            if ($field->codec === RecordFieldCodec::KEY_VALUES) {
                // Decoded before anything is written, so a line this cannot
                // read leaves the record exactly as it was.
                $authored = ParameterMapCodec::decode($rawValue);
                $existing = $record->get($field->key);
                $merged = ParameterMapCodec::merge(
                    is_array($existing) ? $existing : [],
                    $authored,
                );
                $record->set($field->key, $merged === [] && $field->removeWhenEmpty ? null : $merged);
                $this->touchState();

                return;
            }

            $value = self::coerce($field, $rawValue);
            if ($this->schema->storage === RecordStorage::CONFIG_SUBTREE) {
                $this->config?->assertAcceptableValue(strval($record->get('path')), $value);
                // A setting the file leaves out already reads as its default:
                // choosing that default writes nothing.
                if ($record->get('value') === null && ! $this->config?->holds(strval($record->get('path'))) && $value == $record->get('default')) {
                    return;
                }
            }
            if ($field->uniqueAcrossRecords) {
                is_array($value)
                    ? $this->assertMembersUnclaimed($record, $field, $value)
                    : $this->assertValueUnclaimed($record, $field, $value);
            }
            $previousIdentity = $this->schema->identityKey === null ? null : $record->get($this->schema->identityKey);
            $before = $record->toArray();
            $record->set($field->key, $value);
            if ($field->key === $this->schema->labelKey && $this->schema->identityFollowsLabel) {
                $this->followLabelWithIdentity($record, strval($previousIdentity));
            }
            $this->dropStaleShapeFields($record, $before);
            $this->touchState();

            return;
        }
    }

    /**
     * A new entry of a keyed list under a key no other entry holds: its
     * blank's, or the list's noun, numbered when taken.
     *
     * @param list<mixed> $entries The list's entries.
     * @param array<string, mixed> $entry The entry to add.
     * @return array<string, mixed>
     */
    private static function withUniqueKey(RecordSubList $list, array $entries, array $entry): array
    {
        if ($list->keyField === null) {
            return $entry;
        }

        $taken = array_map(static fn(mixed $other): string => is_array($other) ? strval($other[$list->keyField] ?? '') : '', $entries);
        $base = trim(strval($entry[$list->keyField] ?? '')) ?: Slug::of('new ' . $list->singular);
        $key = $base;

        for ($number = 2; in_array($key, $taken, true); $number++) {
            $key = $base . '-' . $number;
        }

        $entry[$list->keyField] = $key;

        return $entry;
    }

    /**
     * Refuses a keyed list's key edit that would leave an entry without a key,
     * or give it another entry's: either would lose an entry on save.
     *
     * @param list<mixed> $entries The list's entries.
     * @throws \InvalidArgumentException
     */
    private static function assertKeyAvailable(RecordSubList $list, array $entries, int $at, RecordField $field, mixed $value): void
    {
        if ($list->keyField === null || $field->key !== $list->keyField) {
            return;
        }

        $key = trim(strval($value ?? ''));

        if ($key === '') {
            throw new \InvalidArgumentException(sprintf('A %s needs %s.', $list->singular, strtolower($field->label)));
        }

        foreach ($entries as $index => $other) {
            if ($index !== $at && is_array($other) && strval($other[$list->keyField] ?? '') === $key) {
                throw new \InvalidArgumentException(sprintf('Another %s already has %s "%s".', $list->singular, strtolower($field->label), $key));
            }
        }
    }

    /**
     * Drops the values only a record's old shape offered, when an edit
     * changes which fields it offers: a spell turned into an ability keeps
     * no spell effect type for the Engine to refuse. As an entry's variant
     * does ({@see withoutStaleVariantFields()}).
     *
     * @param array<string, mixed>|object $before The record before the edit.
     */
    private function dropStaleShapeFields(ProjectRecord $record, array|object $before): void
    {
        if ($this->schema->fieldsFor === null || ! is_array($before)) {
            return;
        }

        $keys = static fn(array $fields): array => array_map(static fn(RecordField $field): string => $field->key, $fields);
        $offered = $keys($this->schema->fieldsFor($record->toArray()));

        foreach (array_diff($keys($this->schema->fieldsFor($before)), $offered) as $stale) {
            $record->set($stale, null);
        }
    }

    /**
     * A new record's payload without the list members other records already
     * hold, for every field held by one record at most.
     *
     * @param array<string, mixed> $payload The record about to be added.
     * @return array<string, mixed>
     */
    private function withoutClaimedMembers(array $payload): array
    {
        foreach ($this->schema->fieldsFor($payload) as $field) {
            $members = $field->uniqueAcrossRecords ? ($payload[$field->key] ?? null) : null;
            if (! is_array($members)) {
                continue;
            }
            $held = [];
            foreach ($this->getRecords() as $record) {
                $theirs = $record->get($field->key);
                $held = [...$held, ...(is_array($theirs) ? $theirs : [])];
            }
            $free = array_values(array_diff($members, $held));
            if ($free === [] && $field->removeWhenEmpty) {
                unset($payload[$field->key]);
            } else {
                $payload[$field->key] = $free;
            }
        }

        return $payload;
    }

    /**
     * Refuses a value another record of the category already has, ignoring
     * case, before anything changes: two classes or enemies by one name.
     *
     * @throws \InvalidArgumentException When another record has it.
     */
    private function assertValueUnclaimed(ProjectRecord $record, RecordField $field, mixed $value): void
    {
        if (! is_scalar($value) || trim(strval($value)) === '') {
            return;
        }

        foreach ($this->getRecords() as $other) {
            $theirs = $other === $record ? null : $other->get($field->key);

            if (is_scalar($theirs) && mb_strtolower(trim(strval($theirs))) === mb_strtolower(trim(strval($value)))) {
                throw new \InvalidArgumentException(sprintf(
                    'Another %s already has %s %s.',
                    $this->schema->entryNoun,
                    mb_strtolower($field->label),
                    trim(strval($value)),
                ));
            }
        }
    }

    /**
     * Keeps a record's identity in step with its label, as the identity's
     * slug, while nothing refers to the identity it has; once something does,
     * the identity stays, so the reference keeps resolving.
     */
    private function followLabelWithIdentity(ProjectRecord $record, string $previousIdentity): void
    {
        $identityKey = (string) $this->schema->identityKey;

        if ($previousIdentity !== '' && $this->identityReferences !== null && ($this->identityReferences)($previousIdentity)) {
            return;
        }

        $taken = [];
        foreach ($this->getRecords() as $other) {
            if ($other !== $record) {
                $taken[] = $other->getDisplayValue($identityKey);
            }
        }

        $record->set($identityKey, Slug::unique(strval($record->get($this->schema->labelKey)), $taken, Slug::of($this->schema->entryNoun)));
    }

    /**
     * Says whether something in the project refers to a record by an
     * identity, for a category whose identities follow their labels. The
     * workspace supplies it: what refers to a record lives across the project.
     *
     * @param \Closure(string): bool $isReferenced
     */
    public function useIdentityReferences(\Closure $isReferenced): void
    {
        $this->identityReferences = $isReferenced;
    }

    /**
     * Refuses a list member another record of the category already holds,
     * naming that record, before anything changes.
     *
     * @param list<mixed> $members The list this record would hold.
     * @throws \InvalidArgumentException When another record holds one of them.
     */
    private function assertMembersUnclaimed(ProjectRecord $record, RecordField $field, array $members): void
    {
        $held = $record->get($field->key);
        $added = array_diff($members, is_array($held) ? $held : []);

        foreach ($this->getRecords() as $other) {
            $theirs = $other === $record ? null : $other->get($field->key);
            $taken = is_array($theirs) ? array_values(array_intersect($added, $theirs)) : [];

            if ($taken !== []) {
                throw new \InvalidArgumentException(sprintf(
                    '%s already has %s %s; take it off there first.',
                    $this->getEntryLabel($other),
                    mb_strtolower($field->label),
                    implode(', ', array_map(strval(...), $taken)),
                ));
            }
        }
    }

    /**
     * Returns whether this category can take a new record at all. Terms are
     * the leaves of an existing config tree and listings are informational:
     * there is no meaningful blank entry to append to either.
     *
     * @return bool True when creating a record can do something here.
     */
    public function supportsRecordCreation(): bool
    {
        return $this->isEditable()
            && ! $this->holdsOneRecord()
            && $this->schema->storage !== RecordStorage::CONFIG_SUBTREE;
    }

    /** Whether the file is the category's one record (system.php), only ever edited. */
    private function holdsOneRecord(): bool
    {
        return $this->schema->projection instanceof WholeFileProjection;
    }

    /**
     * Returns whether this category can let a record go. A term is a leaf of
     * the config tree it belongs to, not an entry of its own.
     *
     * @return bool True when deleting a record can do something here.
     */
    public function supportsRecordDeletion(): bool
    {
        return $this->isEditable() && ! $this->holdsOneRecord() && $this->schema->storage !== RecordStorage::CONFIG_SUBTREE;
    }

    /**
     * Appends a blank record.
     *
     * @return int|null The new record index, or null when the category is read-only.
     */
    public function addRecord(): ?int
    {
        if (! $this->supportsRecordCreation()) {
            return null;
        }

        $payload = $this->schema->blank;
        $identityKey = $this->schema->identityKey;
        $recordId = '';

        if ($this->schema->makeBlank !== null) {
            // Object-backed categories: the blank is a real engine object, so
            // it round-trips through the same constructor-call export as every
            // other entry. An array here would save to nothing. Engine
            // constructors may load assets, so the factory runs from the
            // project the way the authored file itself is evaluated.
            $projectRoot = $this->resolveProjectRoot();

            try {
                // The display name is what an author reads, so it is made
                // distinct from the other records' names; the stable id is
                // what everything else resolves, so it is made distinct
                // across the whole file rather than this category alone.
                $name = $this->makeUniqueName('New ' . ucwords($this->schema->entryNoun));
                $payload = $projectRoot === null
                    ? ($this->schema->makeBlank)($name, '')
                    : ProjectDirectoryContext::run(
                        $projectRoot,
                        fn(string $root): mixed => ($this->schema->makeBlank)($name, $root),
                    );
            } catch (Throwable) {
                return null;
            }

            // A data record's blank is its data; a constructor list's, the object.
            if ($this->schema->recordClass !== null ? ! is_array($payload) : ! is_object($payload)) {
                return null;
            }
        }

        if (is_array($payload) && $identityKey !== null && array_key_exists($identityKey, $payload)) {
            $payload[$identityKey] = $this->makeUniqueIdentity($payload[$identityKey]);
            $recordId = strval($payload[$identityKey]);
        }
        if (is_array($payload)) {
            $payload = $this->withoutClaimedMembers($payload);
        }

        if ($this->schema->recordFilter !== null && ! ($this->schema->recordFilter)($payload)) {
            // Never append what the save merge would drop: a blank that is not
            // a member of its own category vanishes on save, after the editor
            // said it was created.
            return null;
        }

        $sourcePath = null;
        $file = null;

        if ($this->schema->storage === RecordStorage::DIRECTORY) {
            [$recordId, $sourcePath, $file, $payload] = $this->prepareRecordFile($recordId, $payload);
        }

        // A record the file has never held: it has no authored identity or
        // values here, which is what tells the source writer to insert it.
        $this->records[] = new ProjectRecord($payload, true, $sourcePath, $recordId, $file);
        $this->touchState();

        return count($this->records) - 1;
    }

    /**
     * Removes the record at an index.
     *
     * @param int $index The record index.
     * @return ProjectRecord|null The removed record.
     */
    public function removeRecord(int $index): ?ProjectRecord
    {
        if (! $this->supportsRecordDeletion()) {
            return null;
        }

        $records = array_values($this->records);
        $record = $records[$index] ?? null;

        if (! $record instanceof ProjectRecord) {
            return null;
        }

        // What the file holds for the record stays known: undoing the delete
        // before a save finds the entry exactly where it was, and a save
        // finds the entry to remove by the identity it declares.
        array_splice($records, $index, 1);
        $this->records = $records;
        $this->touchState();
        $identity = $record->get($this->schema->identityKey ?? 'id');
        if (is_int($identity)) {
            $this->retiredIdentities[] = $identity;
        }

        if ($record->sourcePath !== null && is_file($record->sourcePath)) {
            $this->stagedDeletions[$record->sourcePath] = $record->sourcePath;
        }

        return $record;
    }

    /**
     * Re-inserts a previously removed record (the undo of removeRecord()).
     *
     * @param int $index The index to restore at.
     * @param ProjectRecord $record The record to restore.
     * @return void
     */
    public function insertRecord(int $index, ProjectRecord $record): void
    {
        $records = array_values($this->records);
        $index = max(0, min(count($records), $index));

        // A record the file still holds is recognised again by its identity
        // and needs nothing written; one the file has since let go of is
        // written back as a new entry, placed ahead of the neighbour that
        // follows it here.
        array_splice($records, $index, 0, [$record]);
        $this->records = $records;
        $this->touchState();

        if ($record->sourcePath !== null) {
            unset($this->stagedDeletions[$record->sourcePath]);
        }
    }

    /**
     * Returns whether this category's record order survives saving and
     * reopening, so a reorder is a real edit rather than a display trick.
     *
     * Only a projection that stores its rows in order gives that guarantee:
     * its save folds the records back in record order. A plain or
     * constructor-authored list file is written surgically, addressed by
     * durable identity — a pure move changes no field, so the source plan
     * emits nothing and the file keeps its authored order. A directory
     * category has no order document at all, and config subtrees, file
     * listings and map-owned records never persist a list order of their
     * own. Pretending otherwise let a save report clean while the reopened
     * project reverted, which is the lie this answer exists to prevent.
     *
     * @return bool True when reordering is durably authorable.
     */
    public function supportsDurableReorder(): bool
    {
        return $this->isEditable()
            && $this->schema->storage === RecordStorage::LIST_FILE
            && $this->schema->projection?->ordersRecords() === true;
    }

    /**
     * Returns why a reorder is refused here, for the status line.
     *
     * @return string|null The reason, or null when reordering is supported.
     */
    public function reorderRefusalReason(): ?string
    {
        if ($this->supportsDurableReorder()) {
            return null;
        }

        if (! $this->isEditable()) {
            return $this->readOnlyReason;
        }

        return match ($this->schema->storage) {
            RecordStorage::DIRECTORY => sprintf(
                'Each %s is its own file; the list shows them in file order, which a move cannot change.',
                $this->schema->entryNoun,
            ),
            RecordStorage::LIST_FILE => $this->schema->projection !== null
                ? sprintf('%s entries are stored by key, not by order; a move would not survive reopening.', ucfirst($this->schema->entryNoun))
                : 'This file keeps its authored entry order; the editor writes entries in place and a move would not survive reopening.',
            default => sprintf('%s entries do not store a list order of their own.', ucfirst($this->schema->entryNoun)),
        };
    }

    /**
     * Moves a record to another position in its list.
     *
     * Declaration order is part of some categories' meaning — battle-entry
     * rules break priority ties by it — so reordering is a real edit, not a
     * view preference, and it is only permitted where the save path stores
     * record order (see supportsDurableReorder()).
     *
     * @param int $from The record's current index.
     * @param int $to The index to occupy.
     * @return bool True when the order changed.
     */
    public function moveRecord(int $from, int $to): bool
    {
        if (! $this->supportsDurableReorder()) {
            return false;
        }

        $records = array_values($this->records);

        if ($from === $to || ! isset($records[$from]) || $to < 0 || $to >= count($records)) {
            return false;
        }

        [$record] = array_splice($records, $from, 1);
        array_splice($records, $to, 0, [$record]);
        $this->records = $records;
        $this->touchState();

        return true;
    }

    /**
     * Returns whether this category can duplicate a record at all, for the
     * help overlay. Only editable list files of array records can: a
     * per-file or object-backed record's copy would need its own source
     * decisions, which nothing requires yet.
     *
     * @return bool True when Shift+D can do something here.
     */
    public function duplicateRecordSupported(): bool
    {
        return $this->isEditable()
            && ! $this->holdsOneRecord()
            && in_array($this->schema->storage, [RecordStorage::LIST_FILE, RecordStorage::DIRECTORY], true)
            && ! $this->isConstructorAuthored();
    }

    /**
     * Duplicates a record below itself, with a fresh unique identity.
     *
     * List-file and per-file categories duplicate; a per-file copy gets a
     * file of its own. Object-backed records' copies would need their own
     * source decisions, which nothing requires yet.
     *
     * @param int $index The record to copy.
     * @return int|null The copy's index, or null when nothing was copied.
     */
    public function duplicateRecord(int $index): ?int
    {
        if (! $this->duplicateRecordSupported()) {
            return null;
        }

        $records = array_values($this->records);
        $source = $records[$index] ?? null;
        $payload = $source?->toArray();

        if (! $source instanceof ProjectRecord || ! $source->isEditable() || ! is_array($payload)) {
            return null;
        }
        $identityKey = $this->schema->identityKey;
        $recordId = '';

        if ($identityKey !== null && array_key_exists($identityKey, $payload)) {
            $payload[$identityKey] = $this->makeUniqueIdentity($payload[$identityKey]);
            $recordId = strval($payload[$identityKey]);
        }
        // A copy cannot hold what one record holds alone (an animation's
        // roles): it starts without them, and the original keeps them.
        $payload = $this->withoutClaimedMembers($payload);

        if ($this->schema->recordFilter !== null && ! ($this->schema->recordFilter)($payload)) {
            return null;
        }

        // A record the file has never held: no authored identity or values,
        // which is what tells the source writer to insert it.
        $sourcePath = null;
        $file = null;

        if ($this->schema->storage === RecordStorage::DIRECTORY) {
            [$recordId, $sourcePath, $file, $payload] = $this->prepareRecordFile($recordId !== '' ? $recordId : $source->recordId, $payload);
        }

        $copy = new ProjectRecord($payload, true, $sourcePath, $recordId, $file);
        // A numbered copy takes the next number, so it goes last, where
        // reopening the folder lists it.
        $at = $this->schema->numberedFiles ? count($records) : $index + 1;
        array_splice($records, $at, 0, [$copy]);
        $this->records = $records;
        $this->touchState();

        return $at;
    }

    /**
     * Adds a sub-list entry (an objective, beat, member, or command), at the
     * end or at a position.
     *
     * @param int $index The record index.
     * @param array<string, mixed>|null $entry The entry payload; the schema blank when null.
     * @param int|null $at Where to insert it, or null for the end.
     * @return int|null The new entry index.
     */
    public function addSubItem(int $index, ?array $entry = null, ?int $at = null, ?string $listKey = null): ?int
    {
        $record = $this->getRecordByIndex($index);
        $subList = $this->schema->findInlineSubList($listKey);

        if ($subList === null || ! $record instanceof ProjectRecord || ! $this->isEditable() || ! $record->isEditable()) {
            return null;
        }

        $entries = $record->getEntries($subList);
        $position = $at === null ? count($entries) : max(0, min(count($entries), $at));
        array_splice($entries, $position, 0, [self::withUniqueKey($subList, $entries, $entry ?? $subList->blank)]);
        $record->setEntries($subList, $entries);
        $this->touchState();

        return $position;
    }

    /**
     * Removes a sub-list entry.
     *
     * @param int $index The record index.
     * @param int $entryIndex The entry index.
     * @return array<string, mixed>|null The removed entry payload.
     */
    public function removeSubItem(int $index, int $entryIndex, ?string $listKey = null): ?array
    {
        $record = $this->getRecordByIndex($index);
        $subList = $this->schema->findInlineSubList($listKey);

        if ($subList === null || ! $record instanceof ProjectRecord || ! $this->isEditable() || ! $record->isEditable()) {
            return null;
        }

        $entries = $record->getEntries($subList);

        if (! array_key_exists($entryIndex, $entries)) {
            return null;
        }

        [$removed] = array_splice($entries, $entryIndex, 1);
        $record->setEntries($subList, $entries);
        $this->touchState();

        return $removed;
    }

    /**
     * Re-inserts a sub-list entry at an index (undo support).
     *
     * @param int $index The record index.
     * @param int $entryIndex The target entry index.
     * @param array<string, mixed> $entry The entry payload.
     * @return void
     */
    public function insertSubItem(int $index, int $entryIndex, array $entry, ?string $listKey = null): void
    {
        $record = $this->getRecordByIndex($index);
        $subList = $this->schema->findInlineSubList($listKey);

        if ($subList === null || ! $record instanceof ProjectRecord) {
            return;
        }

        $entries = $record->getEntries($subList);
        $entryIndex = max(0, min(count($entries), $entryIndex));
        array_splice($entries, $entryIndex, 0, [$entry]);
        $record->setEntries($subList, $entries);
        $this->touchState();
    }

    /**
     * Returns the number of sub-list entries on a record.
     *
     * @param int $index The record index.
     * @return int
     */
    public function countSubItems(int $index, ?string $listKey = null): int
    {
        $subList = $this->schema->findInlineSubList($listKey);

        if ($subList === null) {
            return 0;
        }

        return count($this->getRecordByIndex($index)?->getEntries($subList) ?? []);
    }

    /**
     * Returns the entries of one of the lists a record shows inline.
     *
     * @param int $index The record index.
     * @param string|null $listKey The list; the record's own when null.
     * @return array<int, array<string, mixed>>
     */
    public function getSubItems(int $index, ?string $listKey = null): array
    {
        $subList = $this->schema->findInlineSubList($listKey);

        return $subList === null ? [] : ($this->getRecordByIndex($index)?->getEntries($subList) ?? []);
    }

    /**
     * Resolves a settings field to the nested list owned by its parent entry.
     *
     * @return array{parentIndex: int, nestedIndex: int|null, list: RecordSubList}|null
     */
    public function nestedSubListContext(int $index, string $fieldId): ?array
    {
        $record = $this->getRecordByIndex($index);
        $subList = $this->schema->subList;

        if (! $record instanceof ProjectRecord || $subList === null) {
            return null;
        }

        if (preg_match('/^' . preg_quote($subList->prefix, '/') . '(\d+)/', $fieldId, $parentMatch) !== 1) {
            return null;
        }

        $parentIndex = intval($parentMatch[1]);
        $entry = $record->getEntries($subList)[$parentIndex] ?? null;
        $nestedList = is_array($entry) ? $subList->nestedListFor($entry) : null;

        if ($nestedList === null) {
            return null;
        }

        $nestedIndex = null;
        $pattern = '/^'
            . preg_quote($subList->prefix, '/')
            . preg_quote((string) $parentIndex, '/')
            . preg_quote(ucfirst($nestedList->prefix), '/')
            . '(\d+)/';

        if (preg_match($pattern, $fieldId, $nestedMatch) === 1) {
            $nestedIndex = intval($nestedMatch[1]);
        }

        return ['parentIndex' => $parentIndex, 'nestedIndex' => $nestedIndex, 'list' => $nestedList];
    }

    /** Adds an entry to a variant-owned nested list, at the end or at a position. */
    public function addNestedSubItem(int $index, int $parentIndex, ?array $entry = null, ?int $at = null): ?int
    {
        $context = $this->nestedListForParent($index, $parentIndex);

        if ($context === null || ! $this->isEditable() || ! $context['record']->isEditable()) {
            return null;
        }

        $entries = $context['list']->readEntries($context['parent'][$context['list']->key] ?? []);
        $position = $at === null ? count($entries) : max(0, min(count($entries), $at));
        array_splice($entries, $position, 0, [self::withUniqueKey($context['list'], $entries, $entry ?? $context['list']->blank)]);
        $this->writeNestedSubList($context, $entries);

        return $position;
    }

    /** Removes an entry from a variant-owned nested list. */
    public function removeNestedSubItem(int $index, int $parentIndex, int $nestedIndex): ?array
    {
        $context = $this->nestedListForParent($index, $parentIndex);

        if ($context === null || ! $this->isEditable() || ! $context['record']->isEditable()) {
            return null;
        }

        $entries = $context['list']->readEntries($context['parent'][$context['list']->key] ?? []);

        if (! array_key_exists($nestedIndex, $entries) || ! is_array($entries[$nestedIndex])) {
            return null;
        }

        [$removed] = array_splice($entries, $nestedIndex, 1);
        $this->writeNestedSubList($context, $entries);

        return $removed;
    }

    /** Re-inserts an entry into a variant-owned nested list for undo. */
    public function insertNestedSubItem(int $index, int $parentIndex, int $nestedIndex, array $entry): void
    {
        $context = $this->nestedListForParent($index, $parentIndex);

        if ($context === null) {
            return;
        }

        $entries = $context['list']->readEntries($context['parent'][$context['list']->key] ?? []);
        $nestedIndex = max(0, min(count($entries), $nestedIndex));
        array_splice($entries, $nestedIndex, 0, [$entry]);
        $this->writeNestedSubList($context, $entries);
    }

    /** Returns the current number of nested entries for one parent. */
    public function countNestedSubItems(int $index, int $parentIndex): int
    {
        $context = $this->nestedListForParent($index, $parentIndex);

        return $context === null
            ? 0
            : count((array) ($context['parent'][$context['list']->key] ?? []));
    }

    /**
     * Writes the category back to disk.
     *
     * @return void
     */
    public function save(): void
    {
        if (! $this->isEditable()) {
            throw new RuntimeException(sprintf('%s is read-only: %s.', $this->schema->entryNoun, $this->readOnlyReason));
        }
        if ($this->config !== null) {
            $this->config->save();
            return;
        }

        if (! $this->isDirty()) {
            // Nothing diverges from the last save; writing would only
            // canonicalize authored formatting.
            return;
        }

        if ($this->schema->storage === RecordStorage::LIST_FILE) {
            // The transaction adopts the written file and cleans every
            // category it wrote for, this one included.
            SharedFileTransaction::commit([$this]);

            return;
        }

        match ($this->schema->storage) {
            RecordStorage::DIRECTORY => $this->saveDirectory(),
            RecordStorage::CONFIG_SUBTREE => null,
            RecordStorage::MAP_OWNED => $this->writeBack !== null
                ? ($this->writeBack)(array_map(static fn(ProjectRecord $record): array|object => $record->toArray(), $this->getRecords()))
                : null,
            RecordStorage::LIST_FILE => null,
        };

        foreach ($this->records as $record) {
            $record->markClean();
        }

        $this->captureBaseline();
    }

    /**
     * Loads a category stored as one file returning a list of records.
     *
     * @param string $path The data file path.
     * @param RecordSchema $schema The category schema.
     * @return self
     */
    private static function loadListFile(string $path, RecordSchema $schema, ?string $projectRoot = null): self
    {
        $file = PhpDataFile::load($path, $projectRoot);
        $payload = is_array($file->payload) ? $file->payload : [];

        if ($schema->projection !== null) {
            // A file shaped for the runtime rather than for an editor: the
            // projection knows which of it is this category's.
            $payload = $schema->projection->read($payload);
        }

        $records = [];

        foreach (array_values($payload) as $entry) {
            if ($schema->recordFilter !== null && ! ($schema->recordFilter)($entry)) {
                continue;
            }

            if (is_array($entry) || is_object($entry)) {
                $records[] = new ProjectRecord($entry);
            }
        }

        return new self(
            $schema,
            $path,
            $records,
            $file,
            readOnlyReason: self::resolveReadOnlyReason($schema, $file, $records),
            projectRoot: $projectRoot,
        );
    }

    /**
     * Loads a category stored as one file per record.
     *
     * @param string $path The directory path.
     * @param RecordSchema $schema The category schema.
     * @return self
     */
    private static function loadDirectory(string $path, RecordSchema $schema, ?string $projectRoot = null): self
    {
        $records = [];
        $files = is_dir($path) ? (glob($path . DIRECTORY_SEPARATOR . '*.php') ?: []) : [];
        sort($files);

        foreach ($files as $filename) {
            $file = PhpDataFile::load($filename, $projectRoot);
            $stem = basename($filename, '.php');
            $payload = $file->payload;

            if ($schema->listPayloadKey !== null) {
                // Event scripts return a bare command list; wrap it so the
                // record model stays uniform. `__scriptId` rides along for the
                // entry label and is dropped on save, because only the wrapped
                // list is ever written back.
                $payload = [
                    '__scriptId' => $stem,
                    $schema->listPayloadKey => is_array($payload) ? array_values($payload) : [],
                ];
            }

            $problem = $payload === null ? $file->readOnlyReason : null;

            if ($problem === null && $schema->recordClass !== null) {
                if (is_array($payload) && is_array($payload['data'] ?? null)
                    && ltrim(strval($payload['class'] ?? ''), '\\') === $schema->recordClass) {
                    $payload = $payload['data'];
                } else {
                    $problem = sprintf(
                        "%s does not return ['class' => %s::class, 'data' => [...]]",
                        basename($filename),
                        substr(strrchr('\\' . $schema->recordClass, '\\') ?: '', 1),
                    );
                }
            }

            if ($problem !== null) {
                // A file that cannot be read as a record stays in the list,
                // read-only with the reason, rather than vanishing from it.
                $records[] = new ProjectRecord([$schema->labelKey => $stem], false, $filename, $stem, $file, $problem);
                continue;
            }

            if (! is_array($payload)) {
                continue;
            }

            $records[] = new ProjectRecord($payload, false, $filename, $stem, $file);
        }

        return new self($schema, $path, $records, null);
    }

    /**
     * Loads a category stored as a flattened config.php subtree.
     *
     * @param string $path The config.php path.
     * @param RecordSchema $schema The category schema.
     * @return self
     */
    private static function loadConfigSubtree(string $path, RecordSchema $schema, ?string $projectRoot = null, ?ProjectConfig $config = null): self
    {
        $config ??= new ProjectConfig($projectRoot ?? dirname($path));
        return new self($schema, $path, $config->getTermRecords($schema->configPath),
            readOnlyReason: $config->getReadOnlyReason(), config: $config);
    }

    /**
     * Decides whether a loaded category may be written.
     *
     * @param RecordSchema $schema The category schema.
     * @param PhpDataFile $file The backing file.
     * @param ProjectRecord[] $records The loaded records.
     * @return string|null
     */
    private static function resolveReadOnlyReason(RecordSchema $schema, PhpDataFile $file, array $records): ?string
    {
        if ($file->readOnlyReason !== null) {
            return $file->readOnlyReason;
        }

        if ($schema->projection !== null && $file->exists) {
            $projectionIssue = self::projectionPreservationIssueFor($schema->projection, $file->payload);

            if ($projectionIssue !== null) {
                return sprintf('%s %s', basename($file->path), $projectionIssue);
            }
        }

        foreach ($records as $record) {
            $reason = $record->getReadOnlyReason();

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Checks both projection-specific malformed shapes and the exact
     * read/write round trip. The latter is the final guard against a new
     * projection silently coercing or dropping an authored value that its
     * structural checks did not anticipate.
     */
    private static function projectionPreservationIssueFor(RecordProjection $projection, mixed $payload): ?string
    {
        $issue = $projection->preservationIssue($payload);

        if ($issue !== null) {
            return $issue;
        }

        if (! is_array($payload)) {
            return sprintf('returns %s, not an array', get_debug_type($payload));
        }

        $roundTrip = $projection->write($payload, $projection->read($payload));

        if (self::withoutEmptyFields($roundTrip) !== self::withoutEmptyFields($payload)) {
            return 'contains values or structure that the editor cannot preserve exactly';
        }

        return null;
    }

    /**
     * Removes empty named fields before comparing a projection round trip.
     * An omitted optional category and that category written as an empty
     * array carry the same runtime data; list entries themselves remain
     * significant and are never removed here.
     *
     * @param array<mixed> $payload The value to normalize for comparison.
     * @return array<mixed> The comparison value.
     */
    private static function withoutEmptyFields(array $payload): array
    {
        $normalized = [];

        foreach ($payload as $key => $value) {
            $value = is_array($value) ? self::withoutEmptyFields($value) : $value;

            if (is_string($key) && $value === []) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Re-reads the backing file so this category composes against what is
     * actually there, not what it held when this category loaded.
     *
     * @return void
     */
    private function rereadBackingFile(): void
    {
        if (! $this->file instanceof PhpDataFile || ! is_file($this->file->path)) {
            return;
        }

        $this->file = PhpDataFile::load($this->file->path, $this->projectRoot);
    }

    /**
     * Returns whether this category owns one part of a file others also
     * write.
     *
     * @return bool True when it does.
     */
    public function sharesBackingFile(): bool
    {
        // Every category backed by one file belongs to that file's group,
        // whether it owns a key of it (a projection) or a subset of its
        // entries (a record filter). Items, weapons and armors are three
        // categories over one list, and a save that forgets that is a save
        // that can delete the wrong entry.
        return $this->schema->storage === RecordStorage::LIST_FILE;
    }

    /**
     * Returns the file this category writes through, resolved so two
     * categories naming one file agree that it is one file.
     *
     * @return string The canonical path.
     */
    public function backingFilePath(): string
    {
        return realpath($this->path) ?: $this->path;
    }

    /**
     * Returns the project this category belongs to, for a caller that has to
     * read the backing file the same way this category does.
     *
     * @return string|null The project root.
     */
    public function projectRoot(): ?string
    {
        return $this->projectRoot;
    }

    /**
     * Folds this category's records into a payload without writing.
     *
     * This is how several categories combine into one write: each is asked
     * for its own part, in turn, against the payload the last one produced,
     * and the result is written once. A projected category folds its list
     * or map back into the file's keyed payload. A category over the entries
     * of a returned list finds each of its entries by the identity it
     * declares: a record the list holds takes its entry's place, an entry
     * this category held and no longer does is left out, a record the list
     * has no entry for yet goes ahead of the next record of this category it
     * does hold -- or after the last entry -- and an entry this category
     * never loaded is kept where it is. Whatever belongs to another category
     * keeps its place. Only when the identities cannot tell the entries
     * apart does the category fall back to taking its entries' places in
     * order.
     *
     * @param array<array-key, mixed> $whole The payload so far.
     * @return array<array-key, mixed> The payload with this category folded in.
     */
    public function foldInto(array $whole): array
    {
        if ($this->schema->projection !== null) {
            return $this->schema->projection->write($whole, array_map(
                static fn(ProjectRecord $record): array => (array) $record->toArray(),
                $this->getRecords(),
            ));
        }

        $records = $this->getRecords();
        $entries = array_values($whole);
        $filter = $this->schema->recordFilter;
        $identityKey = $this->schema->identityKey;
        $mine = static fn(mixed $entry): bool => (is_array($entry) || is_object($entry))
            && ($filter === null || $filter($entry));

        // Which record each of the list's identities belongs to, and which
        // of the list's places each of them holds -- when both are one each.
        $recordByIdentity = [];
        $identityAtPosition = [];
        $addressable = $identityKey !== null;

        if ($addressable) {
            foreach ($records as $index => $record) {
                $authored = $this->authoredFor($record);

                if ($authored === null || $authored['identity'] === null) {
                    continue;
                }

                if (isset($recordByIdentity[$authored['identity']])) {
                    $addressable = false;

                    break;
                }

                $recordByIdentity[$authored['identity']] = $index;
            }
        }

        if ($addressable) {
            foreach ($entries as $position => $entry) {
                if (! $mine($entry)) {
                    continue;
                }

                $identity = self::identityOf($entry, $identityKey);

                if ($identity === null || in_array($identity, $identityAtPosition, true)) {
                    $addressable = false;

                    break;
                }

                $identityAtPosition[$position] = $identity;
            }
        }

        if (! $addressable) {
            return $this->foldIntoByPlace($entries, $records, $mine);
        }

        $positionOfRecord = [];

        foreach ($identityAtPosition as $position => $identity) {
            if (isset($recordByIdentity[$identity])) {
                $positionOfRecord[$recordByIdentity[$identity]] = $position;
            }
        }

        // Records the list has no entry for: ahead of the next record of this
        // category the list does hold, or after everything.
        $ahead = [];
        $appended = [];

        foreach ($records as $index => $record) {
            if (isset($positionOfRecord[$index])) {
                continue;
            }

            $anchor = null;

            for ($next = $index + 1, $count = count($records); $next < $count; $next++) {
                if (isset($positionOfRecord[$next])) {
                    $anchor = $positionOfRecord[$next];

                    break;
                }
            }

            if ($anchor === null) {
                $appended[] = $index;
            } else {
                $ahead[$anchor][] = $index;
            }
        }

        $known = $this->authoredIdentities();
        $payload = [];

        foreach ($entries as $position => $entry) {
            foreach ($ahead[$position] ?? [] as $index) {
                $payload[] = $records[$index]->toArray();
            }

            if (! isset($identityAtPosition[$position])) {
                // Another category's entry. It keeps its place untouched.
                $payload[] = $entry;

                continue;
            }

            $identity = $identityAtPosition[$position];

            if (isset($recordByIdentity[$identity])) {
                $payload[] = $records[$recordByIdentity[$identity]]->toArray();
            } elseif (! isset($known[$identity])) {
                // Never loaded by this category: not this category's to drop.
                $payload[] = $entry;
            }
            // Held by this category once and no longer: left out.
        }

        foreach ($appended as $index) {
            $payload[] = $records[$index]->toArray();
        }

        return $payload;
    }

    /**
     * Folds this category's records into a list by place: each record takes
     * the place of one of this category's entries, in order, and the rest go
     * after the last entry.
     *
     * The fallback for a list whose entries declare no identity, or declare
     * one twice: the category's records replace the category's entries as
     * a whole, so no record can land on the wrong entry.
     *
     * @param array<int, mixed> $entries The list, in order.
     * @param ProjectRecord[] $records This category's records.
     * @param \Closure(mixed): bool $mine Whether an entry is this category's.
     * @return array<int, mixed> The list with this category's records in it.
     */
    private function foldIntoByPlace(array $entries, array $records, \Closure $mine): array
    {
        $payload = [];
        $position = 0;

        foreach ($entries as $entry) {
            if (! $mine($entry)) {
                // Another category's entry. It keeps its place untouched.
                $payload[] = $entry;

                continue;
            }

            if (isset($records[$position])) {
                $payload[] = $records[$position]->toArray();
                $position++;
            }
        }

        // Records the list has no place for yet go after the ones it had.
        for ($count = count($records); $position < $count; $position++) {
            $payload[] = $records[$position]->toArray();
        }

        return $payload;
    }

    /**
     * Accepts a write another party made to this category's backing file.
     *
     * Called only after that write succeeded, so a refused write advances no
     * baseline and cleans no category. The file now holds exactly what the
     * records say, so what the file declares for each record is captured
     * again from the records themselves.
     *
     * @return void
     */
    public function adoptSavedFile(): void
    {
        $this->rereadBackingFile();
        $this->captureAuthoredValues();

        foreach ($this->records as $record) {
            $record->markClean();
        }

        $this->captureBaseline();
    }

    /**
     * Returns the source changes this category wants made, addressed by
     * position in one shared snapshot of the file.
     *
     * Nothing is written here. A file several categories own is changed once,
     * with every category's wants composed first, so this says what it wants
     * and the transaction decides where that lands.
     *
     * Every entry is found by the durable identity it declares, looked up in
     * the snapshot every sibling is being composed against: a record the
     * file still holds is patched where its entry now sits, an entry this
     * category held and no longer does is removed by that identity, and a
     * record the file does not hold is inserted ahead of the neighbour that
     * follows it in this category, or after the last entry when nothing
     * does. Where that identity cannot prove the address -- an entry that
     * declares none, two that declare one, a write that would leave two
     * declaring one -- nothing is guessed: the plan is refused with the
     * reason.
     *
     * @param PhpSourceDocument $document The file's source, parsed once.
     * @param PhpDataFile $file The file's payload, read once.
     * @return array{patches: array<int, array<string, mixed>>, removals: int[], insertions: array<int, array{before: int|null, class: string, arguments: array<string, string>}>}|null
     *   The plan, or null when this category cannot be written surgically.
     * @throws SourceIdentityConflict When an entry cannot be addressed with certainty.
     */
    public function sourcePlan(PhpSourceDocument $document, PhpDataFile $file): ?array
    {
        if ($this->schema->storage !== RecordStorage::LIST_FILE || $this->schema->projection !== null) {
            return null;
        }

        $payloadCount = is_array($file->payload) ? count($file->payload) : 0;

        if ($document->entryCount() === 0 || $document->entryCount() !== $payloadCount) {
            return null;
        }

        if (array_filter($document->entryClasses(), static fn(string $class): bool => trim($class) !== '') === []) {
            // Not a list of constructor calls at all: a file that is data,
            // written by regenerating its returned expression.
            return null;
        }

        $noun = $this->schema->entryNoun;
        $identityKey = $this->schema->identityKey;
        $filename = basename($this->path);

        if ($identityKey === null) {
            throw new SourceIdentityConflict(sprintf(
                'Refusing to write %s: %s entries declare no identity to be addressed by. Edit the file directly.',
                $filename,
                $noun,
            ));
        }

        $source = $this->sourcePositionsIn($file);

        if ($source['absent'] !== []) {
            throw new SourceIdentityConflict(sprintf(
                'Refusing to write %s: %s has no %s, so the %ss cannot be told apart. Give every entry a %s and reload.',
                $filename,
                $document->describeEntry($source['absent'][0]),
                $identityKey,
                $noun,
                $identityKey,
            ));
        }

        // Records the file holds, grouped by the identity they were loaded
        // under; the file may hold that identity once, more than once, or --
        // after a saved removal was undone -- not at all.
        $held = [];
        $claimed = [];

        foreach ($this->getRecords() as $index => $record) {
            $authored = $this->authoredFor($record);

            if ($authored === null || $authored['identity'] === null) {
                continue;
            }

            $held[$authored['identity']][] = $index;
        }

        // What the file will hold for this category once written is what
        // the records say, so no identity may be written twice: two entries
        // declaring one identity could never be told apart again. Two
        // records that merely mirror entries the file already holds twice
        // are left exactly as they are, below, rather than written.
        $current = [];

        foreach ($this->getRecords() as $index => $record) {
            $identity = self::identityOf($record->toArray(), $identityKey);

            if ($identity === null) {
                throw new SourceIdentityConflict(sprintf(
                    'Refusing to write %s: %s %d has no %s. Give it one before saving.',
                    $filename,
                    $noun,
                    $index + 1,
                    $identityKey,
                ));
            }

            $current[$identity][] = $index;
        }

        foreach ($current as $identity => $indexes) {
            if (count($indexes) === 1) {
                continue;
            }

            $mirrored = $held[$identity] ?? [];

            if ($indexes !== $mirrored) {
                throw new SourceIdentityConflict(sprintf(
                    'Refusing to write %s: two %ss would share the %s %s. Make each one distinct before saving.',
                    $filename,
                    $noun,
                    $identityKey,
                    var_export($identity, true),
                ));
            }
        }

        $patches = [];
        $positionOf = [];

        foreach ($held as $identity => $indexes) {
            $positions = $source['positions'][$identity] ?? [];

            if ($positions === []) {
                // The file has let go of it since -- a removal that was
                // saved and then undone. It is written back as a new entry.
                continue;
            }

            if (count($positions) !== 1 || count($indexes) !== 1) {
                // Either side holds this identity more than once, so no
                // record can be matched to one entry. Nothing may change
                // among them, and every one must still be there.
                foreach ($indexes as $index) {
                    if ($this->changedFields($this->getRecords()[$index]) !== []) {
                        throw new SourceIdentityConflict(sprintf(
                            'Refusing to write %s: %d entries share the %s %s, so the edited %s cannot be found among them. Make each one distinct in the file and reload.',
                            $filename,
                            max(count($positions), count($indexes)),
                            $identityKey,
                            var_export($identity, true),
                            $noun,
                        ));
                    }
                }

                if (count($positions) !== count($indexes)) {
                    throw new SourceIdentityConflict(sprintf(
                        'Refusing to write %s: %d entries share the %s %s, so the %s to remove cannot be found among them. Make each one distinct in the file and reload.',
                        $filename,
                        count($positions),
                        $identityKey,
                        var_export($identity, true),
                        $noun,
                    ));
                }

                foreach ($positions as $position) {
                    $claimed[$position] = true;
                }

                continue;
            }

            $index = $indexes[0];
            $position = $positions[0];
            $record = $this->getRecords()[$index];
            $claimed[$position] = true;
            $positionOf[$index] = $position;

            foreach ($this->changedFields($record) as $key => $value) {
                if (! $document->isEditable($position)) {
                    return null;
                }

                $patches[] = [
                    'entry' => $position,
                    ...$this->shallowestWritablePath($document, $position, $key, $record, $value),
                ];
            }
        }

        // Entries the file holds under an identity this category held and
        // no longer does. An entry this category never held stays: it is
        // not this category's to remove, and a record about to be written
        // under its identity is a collision, not a replacement.
        $removals = [];
        $known = $this->authoredIdentities();

        foreach ($source['positions'] as $identity => $positions) {
            if (isset($held[$identity])) {
                continue;
            }

            if (! isset($known[$identity])) {
                if (isset($current[$identity])) {
                    throw new SourceIdentityConflict(sprintf(
                        'Refusing to write %s: it already holds a %s with the %s %s that this category did not load. Reload before saving.',
                        $filename,
                        $noun,
                        $identityKey,
                        var_export($identity, true),
                    ));
                }

                continue;
            }

            foreach ($positions as $position) {
                if (! $document->isConstructorEntry($position)) {
                    return null;
                }

                $removals[] = $position;
            }
        }

        // Records the file does not hold, each placed ahead of the next
        // record of this category the file does hold, so the list on disk
        // reads in the order the list in the editor does.
        $insertions = [];
        $records = $this->getRecords();

        foreach ($records as $index => $record) {
            if (isset($positionOf[$index]) || $this->heldAmbiguously($record, $held)) {
                continue;
            }

            $entry = $this->newEntrySource($record);

            if ($entry === null) {
                return null;
            }

            $before = null;

            for ($next = $index + 1, $count = count($records); $next < $count; $next++) {
                if (isset($positionOf[$next])) {
                    $before = $positionOf[$next];

                    break;
                }
            }

            if ($before !== null && ! $document->isConstructorEntry($before)) {
                return null;
            }

            $insertions[] = ['before' => $before, ...$entry];
        }

        return ['patches' => $patches, 'removals' => $removals, 'insertions' => $insertions];
    }

    /**
     * Returns whether a record is one of several the file holds under one
     * identity -- kept in place, neither patched nor rewritten.
     *
     * @param ProjectRecord $record The record.
     * @param array<string, int[]> $held Record indexes by authored identity.
     * @return bool True when it is.
     */
    private function heldAmbiguously(ProjectRecord $record, array $held): bool
    {
        $authored = $this->authoredFor($record);

        if ($authored === null || $authored['identity'] === null) {
            return false;
        }

        return count($held[$authored['identity']] ?? []) > 1;
    }

    /**
     * Returns the fields whose value no longer matches what the file
     * declared for a record, keyed by field.
     *
     * @param ProjectRecord $record The record.
     * @return array<string, mixed> The changed values.
     */
    private function changedFields(ProjectRecord $record): array
    {
        $authored = $this->authoredFor($record);

        if ($authored === null) {
            return [];
        }

        $changed = [];

        foreach ($authored['values'] as $key => $value) {
            if ($record->get($key) !== $value) {
                $changed[$key] = $record->get($key);
            }
        }

        return $changed;
    }

    /**
     * Returns every identity this category has known the file to hold: those
     * of the records it holds, and of the records it held and removed.
     *
     * @return array<string, true> The identities.
     */
    private function authoredIdentities(): array
    {
        $identities = [];

        foreach ($this->authored as $authored) {
            if ($authored['identity'] !== null) {
                $identities[$authored['identity']] = true;
            }
        }

        return $identities;
    }

    /**
     * Returns whether this category has added or removed a record, or holds
     * one the file has since let go of -- the changes that alter the list
     * itself rather than a value in it.
     *
     * @param PhpDataFile $file The file, read fresh.
     * @return bool True when it has.
     */
    public function hasStructuralChange(PhpDataFile $file): bool
    {
        if ($this->schema->storage !== RecordStorage::LIST_FILE || $this->schema->projection !== null) {
            return false;
        }

        $source = $this->sourcePositionsIn($file);
        $held = [];

        foreach ($this->getRecords() as $record) {
            $authored = $this->authoredFor($record);

            if ($authored === null) {
                // Never held by the file.
                return true;
            }

            if ($authored['identity'] === null) {
                // Held, under no identity it could be looked for by.
                continue;
            }

            if (! isset($source['positions'][$authored['identity']])) {
                // Held once, let go of since.
                return true;
            }

            $held[$authored['identity']] = true;
        }

        foreach (array_keys($this->authoredIdentities()) as $identity) {
            if (! isset($held[$identity]) && isset($source['positions'][$identity])) {
                // Held by the file, no longer by this category.
                return true;
            }
        }

        return false;
    }

    /**
     * Returns why this category must not be written by rebuilding the file's
     * returned expression, or null when it may be.
     *
     * A file whose entries are constructor calls the author wrote is edited
     * entry by entry. When the list itself has changed and that edit could
     * not be expressed in the source, regenerating every entry to add or
     * remove one is exactly what the surgical writer exists to prevent, so
     * the write is refused with the reason instead.
     *
     * @param PhpDataFile $file The file, read fresh.
     * @return string|null The refusal.
     */
    public function regenerationRefusal(PhpDataFile $file): ?string
    {
        if (! $this->isConstructorAuthored() || ! $this->hasStructuralChange($file)) {
            return null;
        }

        return sprintf(
            'Refusing to add or remove a %s in %s: its entries are constructor calls this editor cannot rewrite safely. Edit the file directly.',
            $this->schema->entryNoun,
            basename($this->path),
        );
    }

    /**
     * Returns whether the backing file builds its entries with constructor
     * calls the author wrote.
     *
     * @return bool True when it does.
     */
    private function isConstructorAuthored(): bool
    {
        if (! $this->file instanceof PhpDataFile || ! is_file($this->file->path)) {
            return false;
        }

        $classes = array_filter(
            PhpSourceDocument::parse((string) file_get_contents($this->file->path))->entryClasses(),
            static fn(string $class): bool => trim($class) !== '',
        );

        return $classes !== [];
    }

    /**
     * Returns the source a new record should be written as, or null when it
     * cannot be written as one.
     *
     * Only the new entry is rendered. Every entry already in the file keeps
     * its own bytes, which is the difference between inserting a record and
     * rebuilding the list to hold it.
     *
     * @param ProjectRecord $record The new record.
     * @return array{class: string, arguments: array<string, string>}|null The entry source.
     */
    private function newEntrySource(ProjectRecord $record): ?array
    {
        $payload = $record->toArray();

        if (! is_object($payload)) {
            return null;
        }

        $arguments = PhpValueExporter::constructorArguments($payload);

        if ($arguments === null) {
            return null;
        }

        $literals = [];

        foreach ($arguments as $name => $value) {
            if (! PhpValueExporter::isExportable($value)) {
                return null;
            }

            $literals[$name] = PhpValueExporter::export($value, 2);
        }

        return ['class' => '\\' . $payload::class, 'arguments' => $literals];
    }

    /**
     * Returns the path and value to patch: the field itself when the source
     * declares its holder, otherwise the outermost part of the path the
     * source can be given whole.
     *
     * @param PhpSourceDocument $document The parsed file.
     * @param int $position The entry position.
     * @param string $key The field key, possibly dotted.
     * @param ProjectRecord $record The record.
     * @param mixed $value The field's current value.
     * @return array{path: string, value: mixed} The patch.
     */
    private function shallowestWritablePath(
        PhpSourceDocument $document,
        int $position,
        string $key,
        ProjectRecord $record,
        mixed $value,
    ): array {
        if (! str_contains($key, '.')) {
            return ['path' => $key, 'value' => $value];
        }

        $segments = explode('.', $key);
        array_pop($segments);
        $parent = implode('.', $segments);

        $parentSource = $document->argumentSource($position, $parent);

        // A leaf can be written on its own only when the source declares its
        // holder *and* holds it as a constructor call, which is the only
        // shape a named argument can be placed inside. A holder written as
        // an array literal -- a special property, a parameter map -- is
        // rewritten whole, which is still only that argument.
        if ($parentSource !== null && str_starts_with(ltrim($parentSource), 'new ')) {
            return ['path' => $key, 'value' => $value];
        }

        return ['path' => $parent, 'value' => $record->get($parent)];
    }

    /**
     * Records what the file declares for each record -- the identity its
     * entry is addressed by and the values it was authored with -- so a save
     * can tell which fields an author actually changed and find the entry
     * to change them in.
     *
     * Called when the file is read and again when a written file is
     * adopted, when the records are exactly what the file holds.
     *
     * @return void
     */
    private function captureAuthoredValues(): void
    {
        $this->authored = [];

        foreach ($this->getRecords() as $record) {
            $values = [];

            foreach ($this->schema->fieldsFor($record->toArray()) as $field) {
                $values[$field->key] = $record->get($field->key);
            }

            $this->authored[spl_object_id($record)] = [
                'record' => $record,
                'identity' => self::identityOf($record->toArray(), $this->schema->identityKey),
                'values' => $values,
            ];
        }
    }

    /**
     * Rebuilds the whole file around this category's records, against the
     * payload this category last read.
     *
     * This is what the category's dirty fingerprint is taken over: the exact
     * bytes a regenerating save of this category alone would write. A save
     * that shares the file with siblings folds into a fresh read instead;
     * see `foldInto()`.
     *
     * @return array<array-key, mixed> The payload.
     */
    private function mergeIntoFilePayload(): array
    {
        return $this->foldInto(is_array($this->file?->payload) ? $this->file->payload : []);
    }

    /**
     * Writes a one-file-per-record category, unlinking staged deletions.
     *
     * @return void
     */
    /**
     * What a one-file-per-record category writes for a record: its command
     * list bare for an event script, its data in its envelope for a record
     * class, the record itself otherwise.
     */
    private function composeRecordFilePayload(ProjectRecord $record): mixed
    {
        $payload = $record->toArray();

        if ($this->schema->listPayloadKey !== null && is_array($payload)) {
            $payload = array_values((array) ($payload[$this->schema->listPayloadKey] ?? []));
        }

        if ($this->schema->recordClass !== null) {
            $payload = ['class' => $this->schema->recordClass, 'data' => $payload];
        }

        return $payload;
    }

    /**
     * Refuses an edit the record's file could not be saved with - a value
     * the author wrote as an expression the editor cannot rewrite - by working
     * out exactly what a save would write, and writing nothing.
     *
     * @throws \Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal|SourceIdentityConflict|RuntimeException When a save would be refused.
     */
    public function assertSourceAccepts(int $index): void
    {
        $record = $this->getRecordByIndex($index);

        if (! $record instanceof ProjectRecord || ! $record->isDirty()) {
            return;
        }

        match ($this->schema->storage) {
            RecordStorage::LIST_FILE => SharedFileTransaction::preview([$this]),
            RecordStorage::DIRECTORY => $record->file?->composeContents($this->composeRecordFilePayload($record)),
            default => null,
        };
    }

    private function saveDirectory(): void
    {
        if (! is_dir($this->path) && ! mkdir($this->path, 0777, true) && ! is_dir($this->path)) {
            throw new RuntimeException("Unable to create {$this->path}.");
        }

        foreach ($this->records as $record) {
            $file = $record->file;

            if (! $file instanceof PhpDataFile || ! $record->isDirty()) {
                // A clean record's file already holds this content; skipping
                // it keeps authored formatting and mtimes untouched.
                continue;
            }

            $file->save($this->composeRecordFilePayload($record));
        }

        foreach ($this->stagedDeletions as $path) {
            @unlink($path);
        }

        $this->stagedDeletions = [];
    }

    /**
     * Applies one sub-list field edit.
     *
     * @param ProjectRecord $record The owning record.
     * @param RecordSubList $subList The sub-list schema.
     * @param int $entryIndex The entry index.
     * @param string $token The sanitized field token.
     * @param string $rawValue The raw edited value.
     * @return void
     */
    private function setSubField(ProjectRecord $record, RecordSubList $subList, int $entryIndex, string $token, string $rawValue): void
    {
        $entries = $record->getEntries($subList);

        if (! array_key_exists($entryIndex, $entries)) {
            return;
        }

        foreach ($subList->fieldsFor($entries[$entryIndex]) as $field) {
            if (self::fieldToken($field->key) !== $token || $field->isReadOnly) {
                continue;
            }

            $before = $entries[$entryIndex];
            $value = self::coerce($field, $rawValue);
            self::assertKeyAvailable($subList, $entries, $entryIndex, $field, $value);
            $entries[$entryIndex] = self::writeNested(
                $entries[$entryIndex],
                explode('.', $field->key),
                $value,
            );
            $entries[$entryIndex] = $subList->removeConflictingFields($entries[$entryIndex], $field->key);

            if ($field->key === $subList->variantKey) {
                // A new type keeps nothing only the old one read.
                $entries[$entryIndex] = self::withoutStaleVariantFields($subList, $before, $entries[$entryIndex]);
            }

            $record->setEntries($subList, $entries);
            $this->touchState();

            return;
        }
    }

    /** Applies one field edit inside a variant-owned nested list. */
    private function setNestedSubField(
        ProjectRecord $record,
        RecordSubList $subList,
        int $entryIndex,
        string $nestedPrefixToken,
        int $nestedIndex,
        string $fieldToken,
        string $rawValue,
    ): void {
        $entries = $record->getEntries($subList);
        $entry = $entries[$entryIndex] ?? null;

        if (! is_array($entry)) {
            return;
        }

        $written = self::withNestedFieldWritten($subList, $entry, $nestedPrefixToken, $nestedIndex, $fieldToken, $rawValue);

        if ($written !== null) {
            $entries[$entryIndex] = $written;
            $record->setEntries($subList, $entries);
            $this->touchState();
        }
    }

    /**
     * Returns an entry with one field of one of its nested entries written,
     * or null when the token names nothing writable.
     *
     * The one nested write, at any depth: the root list's entries and a
     * frame's commands both own their nested lists this way.
     *
     * @param RecordSubList $subList The list the entry belongs to.
     * @param array<string, mixed> $entry The entry.
     * @param string $nestedPrefixToken The nested list's prefix as it appears in the field id.
     * @param int $nestedIndex The nested entry.
     * @param string $fieldToken The field token.
     * @param string $rawValue The raw edited value.
     * @return array<string, mixed>|null The rewritten entry.
     */
    private static function withNestedFieldWritten(
        RecordSubList $subList,
        array $entry,
        string $nestedPrefixToken,
        int $nestedIndex,
        string $fieldToken,
        string $rawValue,
    ): ?array {
        $nestedList = $subList->nestedListFor($entry);

        if (
            $nestedList === null
            || mb_strtolower($nestedPrefixToken) !== mb_strtolower(ucfirst($nestedList->prefix))
        ) {
            return null;
        }

        $nestedEntries = $nestedList->readEntries($entry[$nestedList->key] ?? []);

        if (! is_array($nestedEntries[$nestedIndex] ?? null)) {
            return null;
        }

        foreach ($nestedList->fieldsFor($nestedEntries[$nestedIndex]) as $field) {
            if (self::fieldToken($field->key) !== $fieldToken || $field->isReadOnly) {
                continue;
            }

            $value = self::coerce($field, $rawValue);
            self::assertKeyAvailable($nestedList, $nestedEntries, $nestedIndex, $field, $value);
            $nestedEntries[$nestedIndex] = self::writeNested(
                $nestedEntries[$nestedIndex],
                explode('.', $field->key),
                $value,
            );
            $entry[$nestedList->key] = $nestedList->writeEntries($nestedEntries);

            return $entry;
        }

        return null;
    }

    /**
     * Resolves the nested list a settings row belongs to, inside a frame.
     *
     * At the root this is nestedSubListContext; inside a frame the parent
     * is a command of that frame, and the field ids carry the frame list's
     * prefix.
     *
     * @param int $recordIndex The record.
     * @param array<int, int|string> $framePath The frame.
     * @param string $fieldId The selected settings row.
     * @return array{parentIndex: int, nestedIndex: int|null, list: RecordSubList}|null The context.
     */
    public function frameNestedContext(int $recordIndex, array $framePath, string $fieldId): ?array
    {
        if ($framePath === []) {
            return $this->nestedSubListContext($recordIndex, $fieldId);
        }

        $commands = $this->getFrameCommands($recordIndex, $framePath);
        $frameList = $this->getFrameSubList($framePath);

        if ($commands === null || $frameList === null) {
            return null;
        }

        if (preg_match('/^' . preg_quote($frameList->prefix, '/') . '(\d+)/', $fieldId, $parentMatch) !== 1) {
            return null;
        }

        $parentIndex = intval($parentMatch[1]);
        $parent = $commands[$parentIndex] ?? null;
        $nestedList = is_array($parent) ? $frameList->nestedListFor($parent) : null;

        if ($nestedList === null) {
            return null;
        }

        $nestedIndex = null;
        $pattern = '/^'
            . preg_quote($frameList->prefix, '/')
            . preg_quote((string) $parentIndex, '/')
            . preg_quote(ucfirst($nestedList->prefix), '/')
            . '(\d+)/';

        if (preg_match($pattern, $fieldId, $nestedMatch) === 1) {
            $nestedIndex = intval($nestedMatch[1]);
        }

        return ['parentIndex' => $parentIndex, 'nestedIndex' => $nestedIndex, 'list' => $nestedList];
    }

    /**
     * Returns the item a settings row belongs to, to add after or remove:
     * the nested entry (a route step, a dialogue line, a lane) the row
     * edits, the choice option it names, or else the entry or command it is
     * part of. The field ids carry the frame list's prefix, as the rows were
     * made with it. The record's own rows, headings and an empty frame's
     * placeholder belong to none.
     *
     * @param int $recordIndex The record.
     * @param array<int, int|string> $framePath The frame the row is in; [] for the record's own list.
     * @param string $fieldId The row's field id.
     * @return RecordItem|null The item, or null when the row belongs to none.
     */
    public function locateItem(int $recordIndex, array $framePath, string $fieldId): ?RecordItem
    {
        if ($framePath === []) {
            foreach ($this->schema->subLists as $inlineList) {
                if ($fieldId === $inlineList->prefix . 'List') {
                    return new RecordItem(RecordItem::LIST, [], -1, null, $inlineList->heading, RecordItem::ENTRY, $inlineList->singular, $inlineList->key);
                }

                if (preg_match('/^' . preg_quote($inlineList->prefix, '/') . '(\d+)[A-Za-z]/', $fieldId, $matches) === 1) {
                    return new RecordItem(RecordItem::ENTRY, [], intval($matches[1]), null, $inlineList->singular, listKey: $inlineList->key);
                }
            }
        }

        $list = $this->getFrameSubList($framePath);
        $entries = $this->getFrameCommands($recordIndex, $framePath);

        if ($list === null || $entries === null || preg_match('/^' . preg_quote($list->prefix, '/') . '(\d+)/', $fieldId, $matches) !== 1) {
            return null;
        }

        $entryIndex = intval($matches[1]);
        $entry = $entries[$entryIndex] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        $nested = $this->frameNestedContext($recordIndex, $framePath, $fieldId);

        if ($nested !== null && $nested['nestedIndex'] !== null) {
            return new RecordItem(RecordItem::NESTED, $framePath, $entryIndex, $nested['nestedIndex'], $nested['list']->singular);
        }

        if (preg_match('/^' . preg_quote($list->prefix . $entryIndex, '/') . 'Option(\d+)/', $fieldId, $option) === 1) {
            return new RecordItem(RecordItem::OPTION, $framePath, $entryIndex, intval($option[1]), 'option');
        }

        $nestedList = $list->nestedListFor($entry);
        $isChoice = $list->variantKey !== null && strval($entry[$list->variantKey] ?? '') === 'choice';

        return new RecordItem(
            RecordItem::ENTRY,
            $framePath,
            $entryIndex,
            null,
            $list->singular,
            match (true) {
                $nestedList !== null => RecordItem::NESTED,
                $isChoice => RecordItem::OPTION,
                default => null,
            },
            $nestedList?->singular ?? ($isChoice ? 'option' : null),
        );
    }

    /**
     * Returns how many nested entries a frame command owns.
     *
     * @param int $recordIndex The record.
     * @param array<int, int|string> $framePath The frame.
     * @param int $parentIndex The command.
     * @return int The count.
     */
    public function countFrameNestedItems(int $recordIndex, array $framePath, int $parentIndex): int
    {
        if ($framePath === []) {
            return $this->countNestedSubItems($recordIndex, $parentIndex);
        }

        $commands = $this->getFrameCommands($recordIndex, $framePath) ?? [];
        $frameList = $this->getFrameSubList($framePath);
        $parent = $commands[$parentIndex] ?? null;
        $nestedList = is_array($parent) && $frameList !== null ? $frameList->nestedListFor($parent) : null;

        return $nestedList === null ? 0 : count($nestedList->readEntries($parent[$nestedList->key] ?? []));
    }

    /**
     * Appends (or inserts) a nested entry under a frame command.
     *
     * @param int $recordIndex The record.
     * @param array<int, int|string> $framePath The frame.
     * @param int $parentIndex The command.
     * @param array<string, mixed>|null $entry The entry, or null for the list's blank.
     * @param int|null $at Where to insert, or null for the end.
     * @return int|null The nested index, or null when nothing was added.
     */
    public function addFrameNestedItem(int $recordIndex, array $framePath, int $parentIndex, ?array $entry = null, ?int $at = null): ?int
    {
        if ($framePath === []) {
            return $this->addNestedSubItem($recordIndex, $parentIndex, $entry, $at);
        }

        $record = $this->getRecordByIndex($recordIndex);
        $commands = $this->getFrameCommands($recordIndex, $framePath);
        $frameList = $this->getFrameSubList($framePath);
        // Without a root sub-list (a summon's tracks), the frame's list
        // is the one the write goes through.
        $rootList = $this->schema->subList ?? $frameList;
        $parent = $commands[$parentIndex] ?? null;
        $nestedList = is_array($parent) && $frameList !== null ? $frameList->nestedListFor($parent) : null;

        if (! $record instanceof ProjectRecord || $commands === null || $rootList === null || $nestedList === null || ! $this->isEditable()) {
            return null;
        }

        $nestedEntries = $nestedList->readEntries($parent[$nestedList->key] ?? []);
        $position = $at === null ? count($nestedEntries) : max(0, min(count($nestedEntries), $at));
        array_splice($nestedEntries, $position, 0, [self::withUniqueKey($nestedList, $nestedEntries, $entry ?? $nestedList->blank)]);
        $commands[$parentIndex][$nestedList->key] = $nestedList->writeEntries($nestedEntries);
        $this->writeFrameCommands($record, $rootList, $framePath, $commands);

        return $position;
    }

    /**
     * Removes a nested entry from a frame command.
     *
     * @param int $recordIndex The record.
     * @param array<int, int|string> $framePath The frame.
     * @param int $parentIndex The command.
     * @param int $nestedIndex The nested entry.
     * @return array<string, mixed>|null The removed entry.
     */
    public function removeFrameNestedItem(int $recordIndex, array $framePath, int $parentIndex, int $nestedIndex): ?array
    {
        if ($framePath === []) {
            return $this->removeNestedSubItem($recordIndex, $parentIndex, $nestedIndex);
        }

        $record = $this->getRecordByIndex($recordIndex);
        $commands = $this->getFrameCommands($recordIndex, $framePath);
        $frameList = $this->getFrameSubList($framePath);
        $rootList = $this->schema->subList ?? $frameList;
        $parent = $commands[$parentIndex] ?? null;
        $nestedList = is_array($parent) && $frameList !== null ? $frameList->nestedListFor($parent) : null;

        if (! $record instanceof ProjectRecord || $commands === null || $rootList === null || $nestedList === null || ! $this->isEditable()) {
            return null;
        }

        $nestedEntries = $nestedList->readEntries($parent[$nestedList->key] ?? []);

        if (! is_array($nestedEntries[$nestedIndex] ?? null)) {
            return null;
        }

        [$removed] = array_splice($nestedEntries, $nestedIndex, 1);

        if ($nestedEntries === []) {
            // A command's blank carries no nested list, so removing the last
            // entry leaves the command as it was created rather than with an
            // empty list the runtime reads the same and the fingerprint does
            // not.
            unset($commands[$parentIndex][$nestedList->key]);
        } else {
            $commands[$parentIndex][$nestedList->key] = $nestedList->writeEntries($nestedEntries);
        }

        $this->writeFrameCommands($record, $rootList, $framePath, $commands);

        return $removed;
    }

    /**
     * Resolves one parent entry and its schema-owned nested list.
     *
     * @return array{record: ProjectRecord, entries: array<int, array<string, mixed>>, parentIndex: int, parent: array<string, mixed>, list: RecordSubList}|null
     */
    private function nestedListForParent(int $index, int $parentIndex): ?array
    {
        $record = $this->getRecordByIndex($index);
        $subList = $this->schema->subList;

        if (! $record instanceof ProjectRecord || $subList === null) {
            return null;
        }

        $entries = $record->getEntries($subList);
        $parent = $entries[$parentIndex] ?? null;
        $nestedList = is_array($parent) ? $subList->nestedListFor($parent) : null;

        if (! is_array($parent) || $nestedList === null) {
            return null;
        }

        return [
            'record' => $record,
            'entries' => $entries,
            'parentIndex' => $parentIndex,
            'parent' => $parent,
            'list' => $nestedList,
        ];
    }

    /**
     * Stores a changed nested list back through its owning ProjectRecord.
     *
     * @param array{record: ProjectRecord, entries: array<int, array<string, mixed>>, parentIndex: int, parent: array<string, mixed>, list: RecordSubList} $context
     * @param array<int, array<string, mixed>> $nestedEntries
     */
    private function writeNestedSubList(array $context, array $nestedEntries): void
    {
        $subList = $this->schema->subList;

        if ($subList === null) {
            return;
        }

        $entries = $context['entries'];
        $entries[$context['parentIndex']][$context['list']->key] = $context['list']->writeEntries($nestedEntries);
        $context['record']->setEntries($subList, $entries);
        $this->touchState();
    }

    /**
     * Builds one settings-field descriptor.
     *
     * @param RecordField $field The field schema.
     * @param string $value The display value.
     * @param string $fieldId The settings-field identifier.
     * @param bool $isEditable Whether the category and record accept edits.
     * @param string|null $label An override label (used for sub-list rows).
     * @return array<string, mixed>
     */
    private static function describeField(RecordField $field, string $value, string $fieldId, bool $isEditable, ?string $label = null): array
    {
        $descriptor = [
            'label' => $label ?? $field->label,
            'value' => $value,
            'field' => $fieldId,
        ];

        if (! $isEditable || $field->isReadOnly) {
            // Said outright, so the pane never opens an editor on a value the
            // record would refuse to take.
            $descriptor['editable'] = false;

            return $descriptor;
        }

        if ($field->options !== []) {
            $descriptor['options'] = $field->options;

            return $descriptor;
        }

        if ($field->reference !== null) {
            // Chosen, never typed: no control means the pane opens a picker
            // instead of a text cursor.
            $descriptor['reference'] = $field->reference;

            if ($field->allowsNone) {
                $descriptor['allowsNone'] = true;
                $descriptor['noneLabel'] = $field->noneLabel();
            }

            if ($field->blankLabel !== null) {
                $descriptor['blankLabel'] = $field->blankLabel;
            }

            if ($field->codec === RecordFieldCodec::CSV_LIST) {
                // A list picked from a catalogue: each pick toggles one
                // member in or out, so the picker is used several times.
                $descriptor['multi'] = true;
            }

            return $descriptor;
        }

        if ($field->type === InputControlType::MULTILINE) {
            // Shown as its first line with a count of the rest; edited in
            // the multiline editor, which holds the exact text.
            $descriptor['control'] = new InputControl(InputControlType::MULTILINE, $value, $field->step);
            $descriptor['value'] = self::summarizeLines($value);

            return $descriptor;
        }

        if ($field->codec === RecordFieldCodec::AFFINITIES) {
            // Rows in a dedicated editor, like a condition list: the element
            // picked, the effect cycled, the wire form written by the codec.
            $descriptor['affinities'] = true;

            return $descriptor;
        }

        if ($field->codec === RecordFieldCodec::WORLD_WRITES) {
            $descriptor['worldWrites'] = true;

            if ($field->writeTypes !== null) {
                // A surface the runtime restricts (a transactional boundary
                // rejects quest acceptance) offers only what it may author.
                $descriptor['writeTypes'] = $field->writeTypes;
            }

            return $descriptor;
        }

        if ($field->codec === RecordFieldCodec::ACTOR_PREDICATES) {
            // A predicate list is built a part at a time in its own editor:
            // the actor picked by durable identity, the presence cycled.
            $descriptor['actorPredicates'] = true;

            return $descriptor;
        }

        if ($field->codec === RecordFieldCodec::CONDITIONS) {
            // A condition list is built a part at a time. The encoded line is
            // still what gets stored, but nobody has to write it.
            $descriptor['conditions'] = true;

            return $descriptor;
        }

        $descriptor['control'] = new InputControl($field->type, $value, $field->step);

        return $descriptor;
    }

    /**
     * Coerces a raw edited string into the field's storage type.
     *
     * Returning null tells `ProjectRecord::set()` to drop the key entirely,
     * which is how optional keys stay absent instead of being written as
     * empty strings or zeroes.
     *
     * @param RecordField $field The field schema.
     * @param string $rawValue The raw edited value.
     * @return mixed
     */
    /**
     * Returns a multi-line value as one settings line: its first line and
     * how many more it holds.
     */
    public static function summarizeLines(string $value): string
    {
        if (! str_contains($value, "\n")) {
            return $value;
        }

        $lines = explode("\n", $value);
        $first = array_shift($lines);

        return sprintf('%s ⏎ %d more line%s', $first, count($lines), count($lines) === 1 ? '' : 's');
    }

    private static function coerce(RecordField $field, string $rawValue): mixed
    {
        if ($field->type === InputControlType::MULTILINE || $field->codec === RecordFieldCodec::LINES) {
            // Exact text: every space, backslash and blank line is the
            // author's. Only a wholly empty block is nothing.
            $text = str_replace("\r\n", "\n", $rawValue);

            if ($text === '' && $field->removeWhenEmpty) {
                return null;
            }

            return $field->codec === RecordFieldCodec::LINES ? explode("\n", $text) : $text;
        }

        $trimmed = trim($rawValue);

        if ($field->codec === RecordFieldCodec::POINT) {
            if ($trimmed === '' && $field->removeWhenEmpty) {
                return null;
            }

            $parts = array_map(trim(...), explode(',', $trimmed));

            if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
                throw new \InvalidArgumentException(sprintf('%s must be two whole numbers separated by a comma.', $field->label));
            }

            return [intval($parts[0]), intval($parts[1])];
        }

        if ($field->codec === RecordFieldCodec::RECT) {
            if ($trimmed === '' && $field->removeWhenEmpty) {
                return null;
            }

            $parts = array_map(trim(...), explode(',', $trimmed));

            if (count($parts) !== 4 || array_filter($parts, static fn(string $part): bool => ! is_numeric($part)) !== []) {
                throw new \InvalidArgumentException(sprintf('%s must be four numbers: x, y, width and height.', $field->label));
            }

            // Whole numbers stay integers, as an author writes them.
            $numbers = array_map(static fn(string $part): int|float => floor((float) $part) === (float) $part ? (int) $part : (float) $part, $parts);

            return array_combine(['x', 'y', 'width', 'height'], $numbers);
        }

        if ($field->codec === RecordFieldCodec::NORMALIZED_POINT) {
            if ($trimmed === '' && $field->removeWhenEmpty) {
                return null;
            }

            $parts = array_map(trim(...), explode(',', $trimmed));

            if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])
                || min(floatval($parts[0]), floatval($parts[1])) < 0.0 || max(floatval($parts[0]), floatval($parts[1])) > 1.0) {
                throw new \InvalidArgumentException(sprintf('%s must be two numbers from 0 to 1, x and y, separated by a comma.', $field->label));
            }

            return ['x' => floatval($parts[0]), 'y' => floatval($parts[1])];
        }

        if ($field->codec === RecordFieldCodec::CONDITIONS) {
            $conditions = ConditionCodec::decodeAllStrictly($trimmed);

            return $conditions === [] && $field->removeWhenEmpty ? null : $conditions;
        }

        if ($field->codec === RecordFieldCodec::AFFINITIES) {
            // Empty decodes to [], which the exporter's default-trimming
            // drops from a rebuilt constructor call.
            return ElementAffinityCodec::decodeAll($trimmed);
        }

        if ($field->codec === RecordFieldCodec::WORLD_WRITES) {
            $sets = WorldWriteCodec::decodeAllStrictly($trimmed);

            return $sets === [] && $field->removeWhenEmpty ? null : $sets;
        }

        if ($field->codec === RecordFieldCodec::ACTOR_PREDICATES) {
            $predicates = BattleEntryPredicateCodec::decodeAll($trimmed);

            return $predicates === [] && $field->removeWhenEmpty ? null : $predicates;
        }

        if ($field->reference !== null && $field->blankLabel !== null && $trimmed === $field->blankLabel) {
            // The picked "empty" row: stored as '', which the runtime reads
            // as its own value rather than as unset.
            return '';
        }

        if (
            $field->reference !== null
            && $field->allowsNone
            && in_array(mb_strtolower($trimmed), ['', '(none)', 'none', mb_strtolower($field->noneLabel())], true)
        ) {
            return null;
        }

        if (in_array($field->codec, [RecordFieldCodec::CSV_LIST, RecordFieldCodec::CSV_INTEGERS, RecordFieldCodec::CSV_TOKENS], true)) {
            $items = array_values(array_filter(
                array_map(trim(...), explode(',', $trimmed)),
                static fn(string $item): bool => $item !== '',
            ));
            $isWhole = static fn(string $item): bool => preg_match('/\A-?\d+\z/', $item) === 1;

            if ($field->codec === RecordFieldCodec::CSV_INTEGERS) {
                if (array_filter($items, static fn(string $item): bool => ! $isWhole($item)) !== []) {
                    throw new \InvalidArgumentException(sprintf('%s lists whole numbers separated by commas.', $field->label));
                }

                $items = array_map(intval(...), $items);
            } elseif ($field->codec === RecordFieldCodec::CSV_TOKENS) {
                $items = array_map(static fn(string $item): int|string => $isWhole($item) ? intval($item) : $item, $items);
            }

            return $items === [] && $field->removeWhenEmpty ? null : $items;
        }

        if ($field->codec === RecordFieldCodec::SHAPE_TILES) {
            if ($trimmed === '') {
                return $field->removeWhenEmpty ? null : '';
            }

            if (! str_contains($trimmed, ':')) {
                return $trimmed;
            }

            $shapes = [];

            foreach (explode(',', $trimmed) as $pair) {
                [$shape, $tile] = array_map(trim(...), explode(':', $pair, 2)) + [1 => ''];
                $shapes[$shape] = $tile;
            }

            if (array_keys($shapes) !== ['horizontal', 'vertical', 'corner'] || in_array('', $shapes, true)) {
                throw new \InvalidArgumentException(sprintf('%s is one tile, or one for each shape: horizontal: 5888, vertical: 5890, corner: 5892.', $field->label));
            }

            return $shapes;
        }

        if ($field->enumClass !== null && enum_exists($field->enumClass)) {
            // The stored value is the enum case, not its string: an object
            // rebuild hands it straight back to a typed constructor argument.
            // A case is chosen by its value or by its name, whichever the
            // field offers.
            foreach ($field->enumClass::cases() as $case) {
                if (($case instanceof BackedEnum && mb_strtolower(strval($case->value)) === mb_strtolower($trimmed))
                    || mb_strtolower($case->name) === mb_strtolower($trimmed)) {
                    return $case;
                }
            }

            return null;
        }

        if ($field->options !== []) {
            // An empty choice of a field that is removed when empty is its
            // absence, whether or not '' is offered as an option.
            if ($trimmed === '' && $field->removeWhenEmpty) {
                return null;
            }

            foreach ($field->options as $option) {
                if (mb_strtolower(strval($option)) === mb_strtolower($trimmed)) {
                    return self::coerceScalar($field, strval($option));
                }
            }

            return self::coerceScalar($field, $trimmed);
        }

        if ($trimmed === '' && $field->removeWhenEmpty) {
            return null;
        }

        return self::coerceScalar($field, $trimmed);
    }

    /**
     * Renders a stored value onto the field's single editable line.
     *
     * @param RecordField $field The field schema.
     * @param mixed $value The stored value.
     * @return string
     */
    private static function displayValue(RecordField $field, mixed $value): string
    {
        // An enum case reads as the field offers it: by value, or by name
        // where the field offers names (a value that is not readable text).
        if ($value instanceof \UnitEnum && $field->enumClass !== null
            && (! $value instanceof BackedEnum || ! in_array(strval($value->value), array_map(strval(...), $field->options), true))) {
            return $value->name;
        }

        // An absent key reads as what the runtime will do with it, and an
        // empty string as what the runtime means by it, when the schema says
        // so -- neither is left as a blank for the author to decode.
        if ($value === null && $field->displayDefault !== null && $field->codec === RecordFieldCodec::NONE) {
            return $field->displayDefault;
        }

        if ($value === '' && $field->blankLabel !== null) {
            return $field->blankLabel;
        }

        return match ($field->codec) {
            RecordFieldCodec::CONDITIONS => ConditionCodec::encodeAll(is_array($value) ? $value : []),
            RecordFieldCodec::AFFINITIES => ElementAffinityCodec::encodeAll(is_array($value) ? $value : []),
            RecordFieldCodec::WORLD_WRITES => WorldWriteCodec::encodeAll(is_array($value) ? $value : []),
            RecordFieldCodec::ACTOR_PREDICATES => BattleEntryPredicateCodec::encodeAll(is_array($value) ? $value : []),
            RecordFieldCodec::CSV_LIST, RecordFieldCodec::CSV_INTEGERS, RecordFieldCodec::CSV_TOKENS => implode(', ', array_map(strval(...), is_array($value) ? $value : [])),
            RecordFieldCodec::SHAPE_TILES => is_array($value)
                ? implode(', ', array_map(static fn(string $shape): string => sprintf('%s: %s', $shape, ProjectRecord::stringify($value[$shape] ?? '')), array_keys($value)))
                : ProjectRecord::stringify($value),
            RecordFieldCodec::KEY_VALUES => ParameterMapCodec::encode(is_array($value) ? $value : []),
            // A sprite authored as one string is one row; as a list, its rows.
            RecordFieldCodec::LINES => is_array($value)
                ? implode("\n", array_map(strval(...), $value))
                : ProjectRecord::stringify($value),
            RecordFieldCodec::POINT => is_array($value)
                ? implode(', ', array_map(strval(...), array_values($value)))
                : ProjectRecord::stringify($value),
            RecordFieldCodec::RECT => is_array($value)
                ? implode(', ', array_map(static fn(string $key): string => ProjectRecord::stringify($value[$key] ?? ''), ['x', 'y', 'width', 'height']))
                : ProjectRecord::stringify($value),
            RecordFieldCodec::NORMALIZED_POINT => is_array($value)
                ? sprintf('%s, %s', ProjectRecord::stringify($value['x'] ?? ''), ProjectRecord::stringify($value['y'] ?? ''))
                : ProjectRecord::stringify($value),
            RecordFieldCodec::NONE => ProjectRecord::stringify($value),
        };
    }

    /**
     * Casts a trimmed string to the field's declared type.
     *
     * @param RecordField $field The field schema.
     * @param string $value The trimmed value.
     * @return mixed
     */
    private static function coerceScalar(RecordField $field, string $value): mixed
    {
        return match ($field->type) {
            InputControlType::INTEGER => self::coerceInteger($field, $value),
            InputControlType::FLOAT => floatval($value),
            InputControlType::BOOLEAN => self::coerceBoolean($field, $value),
            default => $value,
        };
    }

    /**
     * Casts an integer field, dropping the key when the value is zero and the
     * schema treats zero as "unset".
     *
     * @param RecordField $field The field schema.
     * @param string $value The trimmed value.
     * @return int|null
     */
    private static function coerceInteger(RecordField $field, string $value): ?int
    {
        $number = intval($value);

        return $number === 0 && $field->removeWhenEmpty ? null : $number;
    }

    /**
     * Casts a boolean field, dropping the key when false and the schema
     * treats false as "unset" (which is how the engine's defaults work).
     *
     * @param RecordField $field The field schema.
     * @param string $value The trimmed value.
     * @return bool|null
     */
    private static function coerceBoolean(RecordField $field, string $value): ?bool
    {
        $flag = InputControl::parseBoolean($value);

        return $flag === false && $field->removeWhenEmpty ? null : $flag;
    }

    /**
     * Reads a dotted path out of a sub-list entry.
     *
     * @param array<string, mixed> $entry The entry payload.
     * @param string $key The key or dotted path.
     * @return mixed
     */
    private static function readNested(array $entry, string $key): mixed
    {
        $current = $entry;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Writes a dotted path into a nested array, removing the key on null.
     *
     * @param array<string, mixed> $target The array to write into.
     * @param string[] $segments The remaining path segments.
     * @param mixed $value The value; null removes the key.
     * @return array<string, mixed>
     */
    private static function writeNested(array $target, array $segments, mixed $value): array
    {
        $segment = array_shift($segments);

        if ($segment === null) {
            return $target;
        }

        $key = is_numeric($segment) ? intval($segment) : $segment;

        if ($segments === []) {
            if ($value === null) {
                unset($target[$key]);
            } else {
                $target[$key] = $value;
            }

            return $target;
        }

        $child = is_array($target[$key] ?? null) ? $target[$key] : [];
        $target[$key] = self::writeNested($child, $segments, $value);

        return $target;
    }

    /**
     * Determines whether this category's commands nest into frames.
     *
     * @return bool True when choice options or branch arms can occur.
     */
    public function hasCommandFrames(): bool
    {
        $subList = $this->schema->subList;
        $variants = $subList?->variants ?? [];

        return array_key_exists('choice', $variants)
            || array_key_exists('branch', $variants)
            || ($subList !== null && $subList->commandArms !== [])
            || $this->schema->commandLists !== [];
    }

    /**
     * Returns the command list a frame path addresses.
     *
     * A path is segments from the record's top list: `[]` is the script
     * itself, `[2, 'options', 0, 'then']` is the first option of the third
     * command, `[4, 'else']` is a branch's else arm -- the same shape the
     * runtime pushes as execution frames.
     *
     * @param int $recordIndex The record.
     * @param array<int, int|string> $framePath The path.
     * @return array<int, array<string, mixed>>|null The commands, or null when the path no longer resolves.
     */
    public function getFrameCommands(int $recordIndex, array $framePath): ?array
    {
        $record = $this->getRecordByIndex($recordIndex);

        if (! $record instanceof ProjectRecord) {
            return null;
        }

        [$rootKey, $relativePath] = $this->splitFramePath($framePath);

        if ($rootKey === null) {
            return null;
        }

        return self::frameListFrom($record->getSubList($rootKey), $relativePath);
    }

    /**
     * Returns the sub-list whose entries a frame holds.
     *
     * Inside any command frame the entries are event commands, whatever list
     * the frame descended from; at the root it is the schema's own sub-list.
     *
     * @param array<int, int|string> $framePath The frame.
     * @return RecordSubList|null The list.
     */
    public function getFrameSubList(array $framePath): ?RecordSubList
    {
        if ($framePath === []) {
            return $this->schema->subList;
        }

        [$rootKey] = $this->splitFramePath($framePath);

        if ($rootKey !== null && array_key_exists($rootKey, $this->schema->commandLists)) {
            return $this->schema->commandLists[$rootKey];
        }

        // Descended from the sub-list into a command arm: commands from here
        // down. The catalog's shared command list is what every arm holds.
        return RecordSchemaCatalog::eventCommandList('commands');
    }

    /**
     * Splits a frame path into the record key it starts from and the rest.
     *
     * A path beginning with a string names a record-level command list (an
     * NPC's `script`); otherwise the record's sub-list is the root.
     *
     * @param array<int, int|string> $framePath The frame.
     * @return array{0: string|null, 1: array<int, int|string>} Root key and remaining path.
     */
    private function splitFramePath(array $framePath): array
    {
        $first = $framePath[0] ?? null;

        if (is_string($first) && array_key_exists($first, $this->schema->commandLists)) {
            return [$first, array_slice($framePath, 1)];
        }

        return [$this->schema->subList?->key, $framePath];
    }

    /**
     * Returns the settings rows for one frame of a record's commands.
     *
     * The root frame includes the record's own fields, so the editor can use
     * this one call wherever it showed getSettingsFields.
     *
     * @param int $recordIndex The record.
     * @param array<int, int|string> $framePath The frame.
     * @return array<int, array<string, mixed>> The field descriptors.
     */
    public function getFrameSettingsFields(int $recordIndex, array $framePath): array
    {
        if ($framePath === []) {
            return $this->getSettingsFields($recordIndex);
        }

        $record = $this->getRecordByIndex($recordIndex);
        $commands = $this->getFrameCommands($recordIndex, $framePath);
        $subList = $this->getFrameSubList($framePath);

        if ($subList === null || ! $record instanceof ProjectRecord || $commands === null) {
            return [];
        }

        $isEditable = $this->isEditable() && $record->isEditable();
        $fields = [];

        foreach ($commands as $entryIndex => $entry) {
            if (is_array($entry)) {
                $fields = [...$fields, ...$this->describeSubEntryFields($subList, $entryIndex, $entry, $isEditable, $framePath)];
            }
        }

        if ($fields === []) {
            // A frame that resolves but holds nothing yet is still a place
            // to stand: one row says so, so the first command can be added
            // here rather than the pane falling back to the root. A frame
            // that no longer resolves returns null from getFrameCommands
            // and is the caller's cue to leave.
            return [self::emptyFrameRow($subList)];
        }

        return $fields;
    }

    /**
     * The one identifier a frame with no commands shows.
     */
    public const string EMPTY_FRAME_FIELD = 'frameEmpty';

    /**
     * Returns the placeholder row of an empty frame.
     *
     * @param RecordSubList $subList The list the frame holds.
     * @return array<string, mixed> The descriptor.
     */
    private static function emptyFrameRow(RecordSubList $subList): array
    {
        return [
            'label' => sprintf('No %ss yet', $subList->singular),
            'value' => sprintf('Shift+O adds the first %s', $subList->singular),
            'field' => self::EMPTY_FRAME_FIELD,
            'editable' => false,
        ];
    }

    /**
     * Applies one settings-field edit inside a frame.
     *
     * @param int $recordIndex The record.
     * @param array<int, int|string> $framePath The frame.
     * @param string $fieldId The frame-relative field id.
     * @param string $rawValue The raw edited value.
     * @return void
     */
    public function setFrameField(int $recordIndex, array $framePath, string $fieldId, string $rawValue): void
    {
        $subList = $this->schema->subList;
        $record = $this->getRecordByIndex($recordIndex);

        if (! $record instanceof ProjectRecord || ! $this->isEditable()) {
            return;
        }

        if ($framePath === [] && $subList === null) {
            // A record with command lists but no sub-list of its own -- a
            // summon's tracks and cues -- edits its root fields plainly.
            $this->setField($recordIndex, $fieldId, $rawValue);

            return;
        }

        $commands = $this->getFrameCommands($recordIndex, $framePath);

        // Field ids inside a frame carry the frame's own list prefix (a
        // command's, however the frame was reached), not the schema's
        // sub-list prefix: an NPC's variants are "variantN…" at the root and
        // its script's commands "commandN…" inside.
        $frameList = $this->getFrameSubList($framePath) ?? $subList;

        if ($frameList === null || $commands === null) {
            return;
        }

        $prefix = preg_quote($frameList->prefix, '/');

        if (preg_match('/^' . $prefix . '(\\d+)Option(\\d+)Text$/', $fieldId, $matches) === 1) {
            // Option rows exist at every depth, the root included, and the
            // plain setField has never heard of them.
            $commands = self::withOptionText($commands, intval($matches[1]), intval($matches[2]), $rawValue);
        } elseif (
            $framePath !== []
            && preg_match('/^' . $prefix . '(\\d+)([A-Za-z][A-Za-z0-9]*?)(\\d+)([A-Za-z][A-Za-z0-9]*)$/', $fieldId, $matches) === 1
            && is_array($commands[intval($matches[1])] ?? null)
        ) {
            // A nested entry of a command inside the frame (a route step):
            // the same write the root list gets, at this depth.
            $written = self::withNestedFieldWritten(
                $frameList,
                $commands[intval($matches[1])],
                $matches[2],
                intval($matches[3]),
                $matches[4],
                $rawValue,
            );

            if ($written === null) {
                return;
            }

            $commands[intval($matches[1])] = $written;
        } elseif ($framePath === []) {
            // The root list is what setField already edits, steps and all.
            $this->setField($recordIndex, $fieldId, $rawValue);

            return;
        } elseif (preg_match('/^' . $prefix . '(\\d+)([A-Za-z][A-Za-z0-9]*)$/', $fieldId, $matches) === 1) {
            $entryIndex = intval($matches[1]);
            $entry = $commands[$entryIndex] ?? null;

            if (! is_array($entry)) {
                return;
            }

            $written = $this->writeEntryFieldToken($frameList, $entry, $matches[2], $rawValue);

            if ($written === null) {
                return;
            }

            $commands[$entryIndex] = $written;
        } else {
            return;
        }

        $this->writeFrameCommands($record, $subList ?? $frameList, $framePath, $commands);
    }

    /**
     * Adds a blank command to a frame.
     *
     * @param int $recordIndex The record.
     * @param array<int, int|string> $framePath The frame.
     * @param int|null $afterIndex The command to insert after, or null for the end.
     * @return int|null The new command's index.
     */
    public function addFrameCommand(int $recordIndex, array $framePath, ?int $afterIndex = null): ?int
    {
        $subList = $this->schema->subList;
        $record = $this->getRecordByIndex($recordIndex);
        $commands = $this->getFrameCommands($recordIndex, $framePath);
        // A record whose lists are all frames (a summon's tracks and cues)
        // has no root sub-list; the frame's own list is the one to write.
        $frameList = $this->getFrameSubList($framePath) ?? $subList;

        if ($frameList === null || ! $record instanceof ProjectRecord || $commands === null || ! $this->isEditable()) {
            return null;
        }

        $position = $afterIndex === null ? count($commands) : min(count($commands), $afterIndex + 1);
        array_splice($commands, $position, 0, [$frameList->blank]);
        $this->writeFrameCommands($record, $subList ?? $frameList, $framePath, $commands);

        return $position;
    }

    /**
     * Puts a removed command back where it was.
     *
     * @param int $recordIndex The record.
     * @param array<int, int|string> $framePath The frame.
     * @param int $commandIndex The index it held.
     * @param array<string, mixed> $command The command payload.
     * @return void
     */
    public function insertFrameCommand(int $recordIndex, array $framePath, int $commandIndex, array $command): void
    {
        $subList = $this->schema->subList;
        $record = $this->getRecordByIndex($recordIndex);
        $commands = $this->getFrameCommands($recordIndex, $framePath);
        $frameList = $this->getFrameSubList($framePath) ?? $subList;

        if ($frameList === null || ! $record instanceof ProjectRecord || $commands === null) {
            return;
        }

        array_splice($commands, min($commandIndex, count($commands)), 0, [$command]);
        $this->writeFrameCommands($record, $subList ?? $frameList, $framePath, $commands);
    }

    /**
     * Removes a command from a frame.
     *
     * @param int $recordIndex The record.
     * @param array<int, int|string> $framePath The frame.
     * @param int $commandIndex The command.
     * @return array<string, mixed>|null The removed payload, for undo.
     */
    public function removeFrameCommand(int $recordIndex, array $framePath, int $commandIndex): ?array
    {
        $subList = $this->schema->subList;
        $record = $this->getRecordByIndex($recordIndex);
        $commands = $this->getFrameCommands($recordIndex, $framePath);
        $frameList = $this->getFrameSubList($framePath) ?? $subList;

        if ($frameList === null || ! $record instanceof ProjectRecord || $commands === null || ! isset($commands[$commandIndex])) {
            return null;
        }

        [$removed] = array_splice($commands, $commandIndex, 1);
        $this->writeFrameCommands($record, $subList ?? $frameList, $framePath, $commands);

        return is_array($removed) ? $removed : null;
    }

    /**
     * Adds an option to a choice command, at the end or at a position.
     *
     * @param int $recordIndex The record.
     * @param array<int, int|string> $framePath The frame the choice sits in.
     * @param int $commandIndex The choice command.
     * @param int|null $at Where to insert it, or null for the end.
     * @return int|null The new option's index.
     */
    public function addChoiceOption(int $recordIndex, array $framePath, int $commandIndex, ?int $at = null): ?int
    {
        $subList = $this->schema->subList;
        $record = $this->getRecordByIndex($recordIndex);
        $commands = $this->getFrameCommands($recordIndex, $framePath);
        $entry = $commands[$commandIndex] ?? null;

        if ($subList === null || ! $record instanceof ProjectRecord || ! is_array($entry) || ! $this->isEditable()) {
            return null;
        }

        if (strval($entry[$subList->variantKey ?? 'type'] ?? '') !== 'choice') {
            return null;
        }

        $options = array_values((array) ($entry['options'] ?? []));
        $position = $at === null ? count($options) : max(0, min(count($options), $at));
        array_splice($options, $position, 0, [['text' => 'New option', 'then' => []]]);
        $commands[$commandIndex]['options'] = $options;
        $this->writeFrameCommands($record, $subList, $framePath, $commands);

        return $position;
    }

    /**
     * Puts a removed option back where it was.
     *
     * @param int $recordIndex The record.
     * @param array<int, int|string> $framePath The frame the choice sits in.
     * @param int $commandIndex The choice command.
     * @param int $optionIndex The index it held.
     * @param array<string, mixed> $option The option payload.
     * @return void
     */
    public function insertChoiceOption(int $recordIndex, array $framePath, int $commandIndex, int $optionIndex, array $option): void
    {
        $subList = $this->schema->subList;
        $record = $this->getRecordByIndex($recordIndex);
        $commands = $this->getFrameCommands($recordIndex, $framePath);
        $entry = $commands[$commandIndex] ?? null;

        if ($subList === null || ! $record instanceof ProjectRecord || ! is_array($entry)) {
            return;
        }

        $options = array_values((array) ($entry['options'] ?? []));
        array_splice($options, min($optionIndex, count($options)), 0, [$option]);
        $commands[$commandIndex]['options'] = $options;
        $this->writeFrameCommands($record, $subList, $framePath, $commands);
    }

    /**
     * Removes an option from a choice command, its arm included.
     *
     * @param int $recordIndex The record.
     * @param array<int, int|string> $framePath The frame the choice sits in.
     * @param int $commandIndex The choice command.
     * @param int $optionIndex The option.
     * @return array<string, mixed>|null The removed payload, for undo.
     */
    public function removeChoiceOption(int $recordIndex, array $framePath, int $commandIndex, int $optionIndex): ?array
    {
        $subList = $this->schema->subList;
        $record = $this->getRecordByIndex($recordIndex);
        $commands = $this->getFrameCommands($recordIndex, $framePath);
        $entry = $commands[$commandIndex] ?? null;

        if ($subList === null || ! $record instanceof ProjectRecord || ! is_array($entry)) {
            return null;
        }

        $options = array_values((array) ($entry['options'] ?? []));

        if (! isset($options[$optionIndex])) {
            return null;
        }

        [$removed] = array_splice($options, $optionIndex, 1);
        $commands[$commandIndex]['options'] = $options;
        $this->writeFrameCommands($record, $subList, $framePath, $commands);

        return is_array($removed) ? $removed : null;
    }

    /**
     * Describes a frame path as a breadcrumb.
     *
     * @param array<int, int|string> $framePath The frame.
     * @return string The breadcrumb.
     */
    public static function describeFrame(array $framePath): string
    {
        return self::describeFrameFrom($framePath, 'Commands', null);
    }

    /**
     * Describes a frame in this schema's own words: a record-level list by
     * its key (`Script`), a sub-list entry by its singular (`Variant 2 ›
     * Script`), commands as choices and branches.
     *
     * @param array<int, int|string> $framePath The frame.
     * @return string The trail.
     */
    public function describeFramePath(array $framePath): string
    {
        [$rootKey, $relative] = $this->splitFramePath($framePath);

        if ($rootKey !== null && array_key_exists($rootKey, $this->schema->commandLists)) {
            return self::describeFrameFrom($relative, ucfirst($rootKey), null);
        }

        $subList = $this->schema->subList;
        $firstLevel = $subList !== null && $subList->prefix !== 'command' ? ucfirst($subList->singular) : null;

        return self::describeFrameFrom($relative, $firstLevel === null || $subList === null ? 'Commands' : ucfirst($subList->key), $firstLevel);
    }

    /**
     * Walks a frame path into a trail.
     *
     * @param array<int, int|string> $framePath The frame, relative to the root list.
     * @param string $root What the root list is called.
     * @param string|null $firstLevelSingular What a first-level entry is called
     *   when it is not a command (a dialogue variant), or null.
     * @return string The trail.
     */
    private static function describeFrameFrom(array $framePath, string $root, ?string $firstLevelSingular): string
    {
        if ($framePath === []) {
            return $root;
        }

        $parts = [$root];
        $position = 0;

        while ($position < count($framePath)) {
            $entryNumber = intval($framePath[$position]) + 1;

            if (self::isNestedArmPath($framePath, $position)) {
                $listKey = strval($framePath[$position + 1]);
                [$owner, $member] = match ($listKey) {
                    'options' => ['Choice', 'Option'],
                    'lanes' => ['Parallel', 'Lane'],
                    default => [ucfirst($listKey), 'Entry'],
                };
                $parts[] = sprintf('%s %d › %s %d', $owner, $entryNumber, $member, intval($framePath[$position + 2]) + 1);
                $position += 4;
            } else {
                $arm = strval($framePath[$position + 1] ?? '');
                $noun = match (true) {
                    $position === 0 && $firstLevelSingular !== null => $firstLevelSingular,
                    $arm === 'commands' => 'Sequence',
                    $arm === 'cancel' => 'Choice',
                    default => 'Branch',
                };
                $parts[] = sprintf('%s %d › %s', $noun, $entryNumber, ucfirst($arm));
                $position += 2;
            }
        }

        return implode(' › ', $parts);
    }

    /**
     * Walks a frame path down a command list.
     *
     * @param array<int, mixed> $rootList The record's top command list.
     * @param array<int, int|string> $framePath The path.
     * @return array<int, array<string, mixed>>|null The list, or null when the path breaks.
     */
    private static function frameListFrom(array $rootList, array $framePath): ?array
    {
        $list = array_values($rootList);
        $position = 0;

        while ($position < count($framePath)) {
            $entry = $list[intval($framePath[$position])] ?? null;

            if (! is_array($entry)) {
                return null;
            }

            if (self::isNestedArmPath($framePath, $position)) {
                // A list inside the entry, one of its members, and that
                // member's arm: an option's `then`, a lane's `commands`.
                $member = ((array) ($entry[$framePath[$position + 1]] ?? []))[intval($framePath[$position + 2])] ?? null;

                if (! is_array($member)) {
                    return null;
                }

                $list = array_values((array) ($member[$framePath[$position + 3]] ?? []));
                $position += 4;
                continue;
            }

            $arm = $framePath[$position + 1] ?? null;

            if (! is_string($arm) || $arm === '') {
                return null;
            }

            $list = array_values((array) ($entry[$arm] ?? []));
            $position += 2;
        }

        return $list;
    }

    /**
     * Returns whether a frame path, at a position, descends through a list
     * member's arm -- `[i, 'options', k, 'then']` or `[i, 'lanes', k,
     * 'commands']` -- rather than through the entry's own arm.
     *
     * @param array<int, int|string> $framePath The path.
     * @param int $position The index of the entry step.
     */
    private static function isNestedArmPath(array $framePath, int $position): bool
    {
        return is_string($framePath[$position + 1] ?? null)
            && is_int($framePath[$position + 2] ?? null)
            && is_string($framePath[$position + 3] ?? null);
    }

    /**
     * Returns the root list with one frame's commands replaced.
     *
     * @param array<int, mixed> $list The list at the current depth.
     * @param array<int, int|string> $framePath The remaining path.
     * @param array<int, mixed> $frameList The frame's new commands.
     * @return array<int, mixed> The rebuilt list.
     */
    private static function withFrameList(array $list, array $framePath, array $frameList): array
    {
        if ($framePath === []) {
            return array_values($frameList);
        }

        $list = array_values($list);
        $entryIndex = intval($framePath[0]);

        if (! is_array($list[$entryIndex] ?? null)) {
            return $list;
        }

        if (self::isNestedArmPath($framePath, 0)) {
            $listKey = strval($framePath[1]);
            $memberIndex = intval($framePath[2]);
            $armKey = strval($framePath[3]);
            $members = array_values((array) ($list[$entryIndex][$listKey] ?? []));

            if (! is_array($members[$memberIndex] ?? null)) {
                return $list;
            }

            $members[$memberIndex][$armKey] = self::withFrameList(
                (array) ($members[$memberIndex][$armKey] ?? []),
                array_slice($framePath, 4),
                $frameList,
            );
            $list[$entryIndex][$listKey] = $members;

            return $list;
        }

        $arm = strval($framePath[1] ?? 'then');
        $list[$entryIndex][$arm] = self::withFrameList(
            (array) ($list[$entryIndex][$arm] ?? []),
            array_slice($framePath, 2),
            $frameList,
        );

        return $list;
    }

    /**
     * Writes a frame's commands back through the record's top list.
     *
     * @param ProjectRecord $record The record.
     * @param RecordSubList $subList The sub-list schema.
     * @param array<int, int|string> $framePath The frame.
     * @param array<int, mixed> $commands The frame's new commands.
     * @return void
     */
    private function writeFrameCommands(ProjectRecord $record, RecordSubList $subList, array $framePath, array $commands): void
    {
        [$rootKey, $relativePath] = $this->splitFramePath($framePath);
        $rootKey ??= $subList->key;

        $record->setSubList(
            $rootKey,
            self::withFrameList($record->getSubList($rootKey), $relativePath, $commands),
        );
        $this->touchState();
    }

    /**
     * Writes one variant field into an entry by its flattened token.
     *
     * @param RecordSubList $subList The sub-list schema.
     * @param array<string, mixed> $entry The entry payload.
     * @param string $token The field token.
     * @param string $rawValue The raw edited value.
     * @return array<string, mixed>|null The rewritten entry, or null when no field matched.
     */
    private function writeEntryFieldToken(RecordSubList $subList, array $entry, string $token, string $rawValue): ?array
    {
        foreach ($subList->fieldsFor($entry) as $field) {
            if (self::fieldToken($field->key) !== $token || $field->isReadOnly) {
                continue;
            }

            $written = self::writeNested($entry, explode('.', $field->key), self::coerce($field, $rawValue));
            $written = $subList->removeConflictingFields($written, $field->key);

            if ($field->key === $subList->variantKey && $written !== null) {
                $written = self::withoutStaleVariantFields($subList, $entry, $written);
            }

            return $written;
        }

        return null;
    }

    /**
     * Drops the fields the old variant owned that the new one does not,
     * when an entry changes variant: a wait turned into a transfer keeps
     * nothing of its seconds, and a finalizer command turned from a switch
     * write into a player move carries no name or value for the Engine's
     * strict shapes to refuse. A value is dropped by its whole path, so two
     * variants that keep different shapes under one key (a stamped piece's
     * glyph rows, a connected piece's glyph per shape) never mix. Unknown
     * keys -- neither variant's -- stay.
     *
     * @param array<string, mixed> $before The entry before the change.
     * @param array<string, mixed> $after The entry with the new variant.
     * @return array<string, mixed>
     */
    private static function withoutStaleVariantFields(RecordSubList $subList, array $before, array $after): array
    {
        if (strval($before[$subList->variantKey] ?? '') === strval($after[$subList->variantKey] ?? '')) {
            // The same variant picked again changes nothing.
            return $after;
        }

        $keys = static fn(array $fields): array => array_map(static fn(RecordField $field): string => $field->key, $fields);
        $newKeys = $keys($subList->fieldsFor($after));
        $oldNested = $subList->nestedListFor($before);
        $newNested = $subList->nestedListFor($after);

        foreach ($keys($subList->fieldsFor($before)) as $key) {
            if ($key !== $subList->variantKey && ! in_array($key, $newKeys, true)) {
                $after = self::writeNested($after, explode('.', $key), null);
            }
        }

        if ($oldNested !== null && $newNested !== $oldNested) {
            // Another list, even under the same key, holds another shape.
            unset($after[$oldNested->key]);
        }

        return $after;
    }

    /**
     * Replaces one option's text on a choice command.
     *
     * @param array<int, mixed> $commands The frame's commands.
     * @param int $commandIndex The choice command.
     * @param int $optionIndex The option.
     * @param string $text The new text.
     * @return array<int, mixed> The rewritten commands.
     */
    private static function withOptionText(array $commands, int $commandIndex, int $optionIndex, string $text): array
    {
        $entry = $commands[$commandIndex] ?? null;

        if (! is_array($entry)) {
            return $commands;
        }

        $options = array_values((array) ($entry['options'] ?? []));

        if (! is_array($options[$optionIndex] ?? null)) {
            return $commands;
        }

        $options[$optionIndex]['text'] = $text;
        $commands[$commandIndex]['options'] = $options;

        return $commands;
    }

    /**
     * Returns the settings-field id for a sub-list field.
     *
     * @param string $prefix The sub-list prefix.
     * @param int $entryIndex The entry index.
     * @param string $key The field key.
     * @return string
     */
    public static function subFieldId(string $prefix, int $entryIndex, string $key): string
    {
        return $prefix . $entryIndex . self::fieldToken($key);
    }

    /** Returns the flattened id for a nested sub-list field. */
    public static function nestedSubFieldId(
        string $prefix,
        int $entryIndex,
        string $nestedPrefix,
        int $nestedIndex,
        string $key,
    ): string {
        return $prefix
            . $entryIndex
            . ucfirst($nestedPrefix)
            . $nestedIndex
            . self::fieldToken($key);
    }

    /**
     * Sanitizes a field key into an unambiguous identifier token.
     *
     * The token always starts with a letter, so the entry index preceding it
     * can never be mis-parsed.
     *
     * @param string $key The field key.
     * @return string
     */
    private static function fieldToken(string $key): string
    {
        return (string) preg_replace('/[^A-Za-z0-9]/', '', ucfirst($key));
    }

    /**
     * Returns an identity not already used by another record.
     *
     * @param string $preferred The preferred identity.
     * @return string
     */
    /**
     * Recovers the project root this database was loaded against.
     *
     * @return string|null The root, or null when the path does not carry it.
     */
    private function resolveProjectRoot(): ?string
    {
        $suffix = DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->schema->relativePath);

        if (str_ends_with($this->path, $suffix)) {
            return substr($this->path, 0, -strlen($suffix));
        }

        return null;
    }

    /**
     * Returns a display name no other record in this category uses.
     *
     * @param string $preferred The preferred name.
     * @return string The name.
     */
    private function makeUniqueName(string $preferred): string
    {
        $existing = array_map(
            static fn(ProjectRecord $record): string => $record->getDisplayValue('name'),
            $this->getRecords(),
        );
        $candidate = $preferred;
        $suffix = 2;

        while (in_array($candidate, $existing, true)) {
            $candidate = $preferred . ' ' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function makeUniqueIdentity(mixed $preferred): int|string
    {
        $identityKey = $this->schema->identityKey ?? 'id';

        if (is_int($preferred)) {
            // A numeric identity (an animation's id) is the next number after
            // the largest any record holds or held this session, so it never
            // reuses one something may still name.
            $numbers = [...array_filter(
                array_map(static fn(ProjectRecord $record): mixed => $record->get($identityKey), $this->getRecords()),
                is_int(...),
            ), ...$this->retiredIdentities];

            return $numbers === [] ? $preferred : max($numbers) + 1;
        }

        $preferred = strval($preferred);
        $existing = array_map(
            static fn(ProjectRecord $record): string => $record->getDisplayValue($identityKey),
            $this->getRecords(),
        );
        $candidate = $preferred;
        $suffix = 2;

        while (in_array($candidate, $existing, true)) {
            $candidate = $preferred . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Chooses the file a new per-file record is written to: named after its
     * identity, or after the category when it has none, and never an existing
     * file.
     *
     * @param string $identity The record's identity, or an empty string.
     * @param array<string, mixed>|object $payload The record payload.
     * @return array{0: string, 1: string, 2: PhpDataFile, 3: array<string, mixed>|object} The file stem,
     *   path, (not yet written) file and payload.
     */
    private function prepareRecordFile(string $identity, array|object $payload): array
    {
        $slug = Slug::of($identity) ?: 'new-' . Slug::of($this->schema->entryNoun);
        $stem = $this->makeUniqueFileStem($this->schema->numberedFiles ? sprintf('%04d-%s', $this->findNextFileNumber(), $slug) : $slug);
        $sourcePath = $this->path . DIRECTORY_SEPARATOR . $stem . '.php';

        if ($this->schema->listPayloadKey !== null && is_array($payload)) {
            $payload['__scriptId'] = $stem;
        }

        return [$stem, $sourcePath, PhpDataFile::load($sourcePath), $payload];
    }

    /**
     * The number after the highest a record file of this category carries,
     * on disk or not yet written, so a new record is listed last.
     */
    private function findNextFileNumber(): int
    {
        $stems = [
            ...array_map(static fn(string $file): string => basename($file, '.php'), glob($this->path . DIRECTORY_SEPARATOR . '*.php') ?: []),
            ...array_map(static fn(ProjectRecord $record): string => $record->recordId, $this->records),
        ];
        $highest = 0;

        foreach ($stems as $stem) {
            if (preg_match('/\A(\d+)-/', $stem, $match) === 1) {
                $highest = max($highest, (int) $match[1]);
            }
        }

        return $highest + 1;
    }

    /**
     * Returns a file stem not already present in the category directory.
     *
     * @param string $preferred The preferred stem.
     * @return string
     */
    private function makeUniqueFileStem(string $preferred): string
    {
        $candidate = $preferred;
        $suffix = 2;

        while (is_file($this->path . DIRECTORY_SEPARATOR . $candidate . '.php')) {
            $candidate = $preferred . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }
}
