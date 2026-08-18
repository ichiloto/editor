<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\IO\AtomicFile;

use BackedEnum;
use Throwable;

use Ichiloto\Editor\History\TracksPersistedState;
use Ichiloto\Editor\ProjectDirectoryContext;
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
    use TracksPersistedState;

    /**
     * @param RecordSchema $schema The category schema.
     * @param string $path The file or directory backing the category.
     * @param ProjectRecord[] $records The loaded records.
     * @param PhpDataFile|null $file The backing file, for single-file categories.
     * @param bool $isDirty Whether structural changes are unsaved.
     * @param string|null $readOnlyReason Why the category cannot be written.
     * @param mixed $rootPayload The whole config payload, for CONFIG_SUBTREE storage.
     * @param string[] $stagedDeletions Record files to unlink on the next save.
     */
    private function __construct(
        public readonly RecordSchema $schema,
        public readonly string $path,
        private array $records = [],
        private ?PhpDataFile $file = null,
        bool $isDirty = false,
        private ?string $readOnlyReason = null,
        private mixed $rootPayload = null,
        private array $stagedDeletions = [],
        private ?\Closure $writeBack = null,
        private ?string $projectRoot = null,
    ) {
        if (! $isDirty) {
            $this->captureBaseline();
        }

        if ($schema->storage === RecordStorage::LIST_FILE && $schema->projection === null) {
            // What the source declares, so a save can patch only what an
            // author actually changed.
            // Which entry of *this category* each record came from. The
            // position that ordinal occupies in the whole file is resolved
            // from the file itself at the moment of writing, because a
            // sibling category sharing the file moves it.
            $this->payloadPositions = array_keys(array_values($this->records));
            $this->captureAuthoredValues();
        }
    }

    /**
     * @var array<int, array<string, mixed>> Field values as loaded, by record.
     */
    private array $authoredValues = [];
    /**
     * @var array<int, int> Where each record sits in the file's top-level list.
     */
    private array $payloadPositions = [];

    /**
     * @var bool Whether a record was added or removed since the file was
     * read. While that is pending, the mapping from records to authored
     * entries is the editor's to keep, not the file's to redefine.
     */
    private bool $structuralChange = false;

    /**
     * @var \SplObjectStorage<ProjectRecord, int>|null Where a removed record
     * used to sit in the file, so restoring it restores its source too.
     */
    private ?\SplObjectStorage $removedPositions = null;

    /**
     * Returns the position each of this category's records occupies in the
     * file's returned list. A file holds several categories -- items, weapons
     * and armors share one -- so the third weapon may be the file's tenth
     * entry.
     *
     * @param RecordSchema $schema The category schema.
     * @param PhpDataFile|null $file The backing file.
     * @return array<int, int> Payload positions by record index.
     */
    private static function payloadPositionsFor(RecordSchema $schema, ?PhpDataFile $file): array
    {
        if (! $file instanceof PhpDataFile || ! is_array($file->payload)) {
            return [];
        }

        $positions = [];
        $recordIndex = 0;

        foreach (array_values($file->payload) as $position => $entry) {
            if ($schema->recordFilter !== null && ! ($schema->recordFilter)($entry)) {
                continue;
            }

            if (is_array($entry) || is_object($entry)) {
                $positions[$recordIndex] = $position;
                $recordIndex++;
            }
        }

        return $positions;
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
    public static function fromProject(string $projectRoot, RecordSchema $schema): self
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
            RecordStorage::CONFIG_SUBTREE => self::loadConfigSubtree($path, $schema, $projectRoot),
            RecordStorage::FILE_LISTING => self::loadFileListing($path, $schema),
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
        return array_map(function (ProjectRecord $record): string {
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
        }, $this->getRecords());
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
            RecordStorage::CONFIG_SUBTREE, RecordStorage::FILE_LISTING, RecordStorage::MAP_OWNED => serialize([
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
        $fields = [];

        foreach ($this->schema->fieldsFor($record->toArray()) as $field) {
            $fields[] = self::describeField($field, self::displayValue($field, $record->get($field->key)), $field->key, $isEditable);
        }

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

        $subList = $this->schema->subList;

        if ($subList === null) {
            return $fields;
        }

        foreach ($record->getSubList($subList->key) as $entryIndex => $entry) {
            $fields = [...$fields, ...$this->describeSubEntryFields($subList, $entryIndex, $entry, $isEditable, [])];
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

        foreach ($subList->commandArms as $armKey => $armLabel) {
            // Arms every entry of this list may carry: a dialogue variant's
            // own script, edited in a frame like a branch arm.
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

        foreach (array_values((array) ($entry[$nestedList->key] ?? [])) as $nestedIndex => $nestedEntry) {
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

        if ($subList !== null && preg_match('/^' . preg_quote($subList->prefix, '/') . '(\d+)([A-Za-z][A-Za-z0-9]*)$/', $fieldId, $matches) === 1) {
            $this->setSubField($record, $subList, intval($matches[1]), $matches[2], $rawValue);
            return;
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

            $record->set($field->key, self::coerce($field, $rawValue));
            $this->touchState();

            return;
        }
    }

    /**
     * Appends a blank record.
     *
     * @return int|null The new record index, or null when the category is read-only.
     */
    public function addRecord(): ?int
    {
        if (! $this->isEditable() || $this->schema->storage === RecordStorage::CONFIG_SUBTREE || $this->schema->storage === RecordStorage::FILE_LISTING) {
            // Terms are the leaves of an existing config tree and listings are
            // informational: there is no meaningful blank entry to append.
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

            if (! is_object($payload)) {
                return null;
            }
        }

        if (is_array($payload) && $identityKey !== null && array_key_exists($identityKey, $payload)) {
            $payload[$identityKey] = $this->makeUniqueIdentity(strval($payload[$identityKey]));
            $recordId = strval($payload[$identityKey]);
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
            $recordId = $recordId !== '' ? $recordId : $this->makeUniqueFileStem('new-' . str_replace(' ', '-', $this->schema->entryNoun));
            $sourcePath = $this->path . DIRECTORY_SEPARATOR . $recordId . '.php';
            $file = PhpDataFile::load($sourcePath);

            if ($this->schema->listPayloadKey !== null) {
                $payload['__scriptId'] = $recordId;
            }
        }

        $this->records[] = new ProjectRecord($payload, true, $sourcePath, $recordId, $file);
        // A record with no position in the file is a record the file does not
        // have yet, which is what tells the source writer to insert it.
        $this->payloadPositions[] = null;
        $this->structuralChange = true;
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
        if (! $this->isEditable() || $this->schema->storage === RecordStorage::CONFIG_SUBTREE) {
            return null;
        }

        $records = array_values($this->records);
        $record = $records[$index] ?? null;

        if (! $record instanceof ProjectRecord) {
            return null;
        }

        $positions = array_values($this->payloadPositions);
        $position = $positions[$index] ?? null;
        array_splice($positions, $index, 1);
        $this->payloadPositions = $positions;

        if ($position !== null) {
            // Remembered against the record itself, so undoing the delete
            // puts the entry back where its source still is rather than
            // appending a rebuilt copy at the end.
            $this->removedPositions ??= new \SplObjectStorage();
            $this->removedPositions[$record] = $position;
        }

        $this->structuralChange = true;

        array_splice($records, $index, 1);
        $this->records = $records;
        $this->touchState();

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
        $positions = array_values($this->payloadPositions);
        $restored = $this->removedPositions?->offsetExists($record) === true
            ? $this->removedPositions[$record]
            : null;
        array_splice($positions, $index, 0, [$restored]);
        $this->payloadPositions = $positions;

        if ($restored !== null) {
            $this->removedPositions?->offsetUnset($record);
        }

        $this->structuralChange = true;

        array_splice($records, $index, 0, [$record]);
        $this->records = $records;
        $this->touchState();

        if ($record->sourcePath !== null) {
            unset($this->stagedDeletions[$record->sourcePath]);
        }
    }

    /**
     * Appends a sub-list entry (an objective, beat, member, or command).
     *
     * @param int $index The record index.
     * @param array<string, mixed>|null $entry The entry payload; the schema blank when null.
     * @return int|null The new entry index.
     */
    public function addSubItem(int $index, ?array $entry = null): ?int
    {
        $record = $this->getRecordByIndex($index);
        $subList = $this->schema->subList;

        if ($subList === null || ! $record instanceof ProjectRecord || ! $this->isEditable() || ! $record->isEditable()) {
            return null;
        }

        $entries = $record->getSubList($subList->key);
        $entries[] = $entry ?? $subList->blank;
        $record->setSubList($subList->key, $entries);
        $this->touchState();

        return count($entries) - 1;
    }

    /**
     * Removes a sub-list entry.
     *
     * @param int $index The record index.
     * @param int $entryIndex The entry index.
     * @return array<string, mixed>|null The removed entry payload.
     */
    public function removeSubItem(int $index, int $entryIndex): ?array
    {
        $record = $this->getRecordByIndex($index);
        $subList = $this->schema->subList;

        if ($subList === null || ! $record instanceof ProjectRecord || ! $this->isEditable() || ! $record->isEditable()) {
            return null;
        }

        $entries = $record->getSubList($subList->key);

        if (! array_key_exists($entryIndex, $entries)) {
            return null;
        }

        [$removed] = array_splice($entries, $entryIndex, 1);
        $record->setSubList($subList->key, $entries);
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
    public function insertSubItem(int $index, int $entryIndex, array $entry): void
    {
        $record = $this->getRecordByIndex($index);
        $subList = $this->schema->subList;

        if ($subList === null || ! $record instanceof ProjectRecord) {
            return;
        }

        $entries = $record->getSubList($subList->key);
        $entryIndex = max(0, min(count($entries), $entryIndex));
        array_splice($entries, $entryIndex, 0, [$entry]);
        $record->setSubList($subList->key, $entries);
        $this->touchState();
    }

    /**
     * Returns the number of sub-list entries on a record.
     *
     * @param int $index The record index.
     * @return int
     */
    public function countSubItems(int $index): int
    {
        $subList = $this->schema->subList;

        if ($subList === null) {
            return 0;
        }

        return count($this->getRecordByIndex($index)?->getSubList($subList->key) ?? []);
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
        $entry = $record->getSubList($subList->key)[$parentIndex] ?? null;
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

    /** Appends an entry to a variant-owned nested list. */
    public function addNestedSubItem(int $index, int $parentIndex, ?array $entry = null): ?int
    {
        $context = $this->nestedListForParent($index, $parentIndex);

        if ($context === null || ! $this->isEditable() || ! $context['record']->isEditable()) {
            return null;
        }

        $entries = array_values((array) ($context['parent'][$context['list']->key] ?? []));
        $entries[] = $entry ?? $context['list']->blank;
        $this->writeNestedSubList($context, $entries);

        return count($entries) - 1;
    }

    /** Removes an entry from a variant-owned nested list. */
    public function removeNestedSubItem(int $index, int $parentIndex, int $nestedIndex): ?array
    {
        $context = $this->nestedListForParent($index, $parentIndex);

        if ($context === null || ! $this->isEditable() || ! $context['record']->isEditable()) {
            return null;
        }

        $entries = array_values((array) ($context['parent'][$context['list']->key] ?? []));

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

        $entries = array_values((array) ($context['parent'][$context['list']->key] ?? []));
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
            RecordStorage::CONFIG_SUBTREE => $this->saveConfigSubtree(),
            RecordStorage::FILE_LISTING => null,
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

            if (! is_array($payload)) {
                continue;
            }

            $records[] = new ProjectRecord($payload, false, $filename, $stem, $file);
        }

        return new self($schema, $path, $records, null, readOnlyReason: $schema->isAlwaysReadOnly ? $schema->readOnlyNote : null);
    }

    /**
     * Loads a category stored as a flattened config.php subtree.
     *
     * @param string $path The config.php path.
     * @param RecordSchema $schema The category schema.
     * @return self
     */
    private static function loadConfigSubtree(string $path, RecordSchema $schema, ?string $projectRoot = null): self
    {
        $file = PhpDataFile::load($path, $projectRoot);
        $payload = is_array($file->payload) ? $file->payload : [];
        $records = [];

        foreach ($schema->configPath as $root) {
            if (! is_array($payload[$root] ?? null)) {
                continue;
            }

            foreach (self::flattenLeaves($payload[$root], $root) as $leafPath => $value) {
                $records[] = new ProjectRecord(['path' => $leafPath, 'value' => $value]);
            }
        }

        $reason = $schema->isAlwaysReadOnly ? $schema->readOnlyNote : $file->readOnlyReason;

        if ($reason === null && ! $file->exists) {
            $reason = sprintf('%s does not exist in this project', basename($path));
        }

        return new self($schema, $path, $records, $file, readOnlyReason: $reason, rootPayload: $payload);
    }

    /**
     * Lists the files in a directory without evaluating any of them.
     *
     * @param string $path The directory path.
     * @param RecordSchema $schema The category schema.
     * @return self
     */
    private static function loadFileListing(string $path, RecordSchema $schema): self
    {
        $records = [];
        $files = is_dir($path) ? (glob($path . DIRECTORY_SEPARATOR . '*.php') ?: []) : [];
        sort($files);

        foreach ($files as $filename) {
            $source = (string) file_get_contents($filename);
            $kind = match (true) {
                preg_match('/^\s*enum\s+/m', $source) === 1 => 'PHP enum',
                preg_match('/^\s*(final\s+)?(readonly\s+)?class\s+/m', $source) === 1 => 'PHP class',
                preg_match('/^\s*interface\s+/m', $source) === 1 => 'PHP interface',
                default => 'data file',
            };

            $records[] = new ProjectRecord(
                [
                    'file' => basename($filename),
                    'kind' => $kind,
                    'lines' => substr_count($source, "\n") + 1,
                ],
                false,
                $filename,
                basename($filename, '.php'),
            );
        }

        return new self($schema, $path, $records, null, readOnlyReason: $schema->readOnlyNote);
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
        if ($schema->isAlwaysReadOnly) {
            return $schema->readOnlyNote;
        }

        if ($file->readOnlyReason !== null) {
            return $file->readOnlyReason;
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
     * Flattens a nested config subtree into dotted leaf paths.
     *
     * @param array<string, mixed> $tree The subtree.
     * @param string $prefix The path prefix.
     * @return array<string, mixed> Leaf values keyed by dotted path.
     */
    private static function flattenLeaves(array $tree, string $prefix): array
    {
        $leaves = [];

        foreach ($tree as $key => $value) {
            $path = $prefix . '.' . $key;

            if (is_array($value) && $value !== []) {
                $leaves += self::flattenLeaves($value, $path);
                continue;
            }

            if (is_array($value) || is_object($value)) {
                continue;
            }

            $leaves[$path] = $value;
        }

        return $leaves;
    }

    /**
     * Writes a single-file list category.
     *
     * @return void
     */
    private function saveListFile(): void
    {
        if (! $this->file instanceof PhpDataFile) {
            throw new RuntimeException('No backing file to save.');
        }

        // What the file holds now, which is not always what it held when
        // this category loaded: several categories share one file, and a
        // sibling may have saved since. Folding into the stale snapshot is
        // how one category silently reverts another's saved work.
        $this->rereadBackingFile();

        if ($this->savedInPlace()) {
            return;
        }

        if ($this->structuralChange && $this->isConstructorAuthored()) {
            // Adding or removing a record here would go through the writer
            // that rebuilds the returned expression, and rebuilding an
            // author's constructor calls is exactly what the surgical writer
            // exists to prevent. Refusing with a reason beats silently
            // reformatting the file.
            throw new RuntimeException(sprintf(
                'Refusing to add or remove a %s in %s: its entries are constructor calls this editor cannot rewrite safely. Edit the file directly.',
                $this->schema->entryNoun,
                basename($this->path),
            ));
        }

        $this->file->save($this->mergeIntoFilePayload());
        $this->structuralChange = false;
        $this->rereadBackingFile();

        if ($this->schema->projection === null) {
            // The in-place patcher compares against what the file was found
            // holding, and the file has just stopped holding that. Left
            // stale, a later save that puts a value back to what the file
            // originally said would find nothing to patch and write nothing
            // -- so an undone edit would stay on disk.
            $this->captureAuthoredValues();
        }
    }

    /**
     * Re-reads the backing file so this category folds into what is actually
     * there.
     *
     * Entry positions are recomputed with it: a sibling category that added
     * or removed an entry moved this category's entries along the list, and
     * the in-place patcher writes by position.
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
     * for its own part, in turn, against the payload the last one produced.
     *
     * @param array<string, mixed> $whole The payload so far.
     * @return array<string, mixed> The payload with this category folded in.
     */
    public function foldInto(array $whole): array
    {
        if ($this->schema->projection === null) {
            return $whole;
        }

        return $this->schema->projection->write($whole, array_map(
            static fn(ProjectRecord $record): array => (array) $record->toArray(),
            $this->getRecords(),
        ));
    }

    /**
     * Accepts a write another party made to this category's backing file.
     *
     * Called only after that write succeeded, so a refused write advances no
     * baseline and cleans no category.
     *
     * @return void
     */
    public function adoptSavedFile(): void
    {
        $this->rereadBackingFile();
        // Every record now sits where the file says it does, in this
        // category's own order.
        $this->payloadPositions = array_keys(array_values($this->records));
        $this->structuralChange = false;
        $this->removedPositions = null;
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
     * @param PhpSourceDocument $document The file's source, parsed once.
     * @param PhpDataFile $file The file's payload, read once.
     * @return array{patches: array<int, array<string, mixed>>, removals: int[], additions: array<int, array{class: string, arguments: array<string, string>}>}|null
     *   The plan, or null when this category cannot be written surgically.
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

        // Ordinal within this category to position in the whole file, read
        // from the snapshot every sibling is being composed against.
        $absolute = self::payloadPositionsFor($this->schema, $file);
        $patches = [];
        $additions = [];
        $claimed = [];

        foreach ($this->getRecords() as $index => $record) {
            $ordinal = $this->payloadPositions[$index] ?? null;

            if ($ordinal === null) {
                $entry = $this->newEntrySource($record);

                if ($entry === null) {
                    return null;
                }

                $additions[] = $entry;

                continue;
            }

            $position = $absolute[$ordinal] ?? null;

            if ($position === null || ! isset($this->authoredValues[$ordinal])) {
                return null;
            }

            $claimed[$ordinal] = true;

            foreach ($this->authoredValues[$ordinal] as $key => $authored) {
                if ($record->get($key) === $authored) {
                    continue;
                }

                if (! $document->isEditable($position)) {
                    return null;
                }

                $patches[] = [
                    'entry' => $position,
                    ...$this->shallowestWritablePath($document, $position, $key, $record, $record->get($key)),
                ];
            }
        }

        $removals = [];

        foreach (array_keys($this->authoredValues) as $ordinal) {
            if (! isset($claimed[$ordinal]) && isset($absolute[$ordinal])) {
                $removals[] = $absolute[$ordinal];
            }
        }

        return ['patches' => $patches, 'removals' => $removals, 'additions' => $additions];
    }

    /**
     * Returns whether this category would write its file by rebuilding the
     * returned expression rather than by editing the author's source.
     *
     * @return bool True when it would.
     */
    public function ownsFileKey(): bool
    {
        return $this->schema->projection !== null;
    }

    /**
     * Returns whether this category would write its file by rebuilding the
     * returned expression rather than by editing the author's source.
     *
     * @return bool True when it would.
     */
    public function wouldRegenerate(): bool
    {
        return $this->structuralChange && $this->isConstructorAuthored();
    }

    /**
     * Returns the whole payload this category would write, for a file no
     * category can edit surgically.
     *
     * @return array<array-key, mixed> The payload.
     */
    public function wholeFilePayload(): array
    {
        return $this->mergeIntoFilePayload();
    }

    /**
     * Writes only the values that changed, straight into the authored PHP.
     *
     * A file whose entries are constructor calls with named arguments is the
     * author's own PHP, not data that happens to live in a PHP file.
     * Regenerating it reorders arguments, writes out defaults nobody wrote,
     * and rewrites every record to change one. So each changed field is
     * patched where it sits and everything else keeps its bytes.
     *
     * Returns false when this file is not that shape -- entries built some
     * other way, or a record added or removed, which changes the list itself
     * rather than a value in it -- and the ordinary writer takes over.
     *
     * @return bool True when the file was written in place.
     */
    private function savedInPlace(): bool
    {
        if (! $this->file instanceof PhpDataFile || ! is_file($this->file->path)) {
            return false;
        }

        if ($this->schema->projection !== null) {
            // A projected category owns part of a keyed file, not a list of
            // authored entries, so there is nothing to patch by position.
            return false;
        }

        $document = PhpSourceDocument::parse((string) file_get_contents($this->file->path));
        $payloadCount = is_array($this->file->payload) ? count($this->file->payload) : 0;

        if ($document->entryCount() === 0 || $document->entryCount() !== $payloadCount) {
            return false;
        }

        $patches = [];
        $additions = [];

        foreach ($this->getRecords() as $index => $record) {
            $position = $this->payloadPositions[$index] ?? null;

            if ($position === null) {
                // A record the file does not hold yet: written as a new
                // entry rather than by rebuilding the list around it.
                $additions[] = $record;

                continue;
            }

            if (! isset($this->authoredValues[$position])) {
                return false;
            }

            foreach ($this->authoredValues[$position] as $key => $authored) {
                $current = $record->get($key);

                if ($current === $authored) {
                    continue;
                }

                if (! $document->isEditable($position)) {
                    // The entry is not a named-argument call, so the value
                    // cannot be placed without rewriting it.
                    return false;
                }

                $patches[] = [
                    'entry' => $position,
                    // A value inside something the source does not declare at
                    // all is written as the whole thing: authoring the first
                    // special property writes specialProperty, not a leaf
                    // inside an argument that is not there.
                    ...$this->shallowestWritablePath($document, $position, $key, $record, $current),
                ];
            }
        }

        // Entries the file still holds that no record claims any more.
        $claimed = array_values(array_filter(
            $this->payloadPositions,
            static fn(?int $position): bool => $position !== null,
        ));
        $removals = array_values(array_diff(array_keys($this->authoredValues), $claimed));
        rsort($removals);

        foreach ($additions as $record) {
            if ($this->newEntrySource($record) === null) {
                // Nothing to write it as. Refusing is the whole point: the
                // alternative is regenerating every other entry to add one.
                return false;
            }
        }

        if ($patches === [] && $removals === [] && $additions === []) {
            return true;
        }

        // Values first, while every position still means what it meant when
        // the document was parsed; then removals from the back, so each one
        // leaves the positions before it alone; then the new entries.
        foreach ($patches as $patch) {
            $document = $patch['value'] === null
                ? $document->withoutArgument($patch['entry'], $patch['path'])
                : $document->withArgument($patch['entry'], $patch['path'], PhpValueExporter::export($patch['value']));
        }

        foreach ($removals as $position) {
            $document = $document->withoutEntry($position);
        }

        foreach ($additions as $record) {
            $entry = $this->newEntrySource($record);

            if ($entry === null) {
                return false;
            }

            $document = $document->withNewEntry($entry['class'], $entry['arguments']);
        }

        AtomicFile::write($this->file->path, $document->source);
        $this->adoptSavedFile();

        return true;
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
     * Records what each record's fields hold as loaded, so a save can tell
     * which ones an author actually changed.
     *
     * @return void
     */
    private function captureAuthoredValues(): void
    {
        $this->authoredValues = [];

        foreach ($this->getRecords() as $index => $record) {
            // Keyed by where the entry sits in the file, not by where the
            // record sits in the list: removing one shifts every record
            // after it, and what an entry was authored holding does not
            // move with them.
            $position = $this->payloadPositions[$index] ?? null;

            if ($position === null) {
                continue;
            }

            $values = [];

            foreach ($this->schema->fieldsFor($record->toArray()) as $field) {
                $values[$field->key] = $record->get($field->key);
            }

            $this->authoredValues[$position] = $values;
        }
    }

    /**
     * Rebuilds the whole file around this category's records.
     *
     * Items, weapons, armors, and accessories share `items.php`, and a
     * category only holds the entries its filter accepts. Writing just those
     * would delete the rest of the file, so the original payload is walked and
     * this category's entries are substituted where they sat.
     *
     * A category with a projection returns the file's own keyed payload; one
     * without returns the list the file is.
     *
     * @return array<array-key, mixed> The payload to write.
     */
    private function mergeIntoFilePayload(): array
    {
        if ($this->schema->projection !== null) {
            // Everything the file holds, with only this category's records
            // folded back in: a catalogue's other lists, a policy's other
            // maps, and the vocabularies they share are not this category's
            // to rewrite.
            return $this->schema->projection->write(
                is_array($this->file?->payload) ? $this->file->payload : [],
                array_map(
                    static fn(ProjectRecord $record): array => (array) $record->toArray(),
                    $this->getRecords(),
                ),
            );
        }

        $original = is_array($this->file?->payload) ? array_values($this->file->payload) : [];
        $records = $this->getRecords();
        $filter = $this->schema->recordFilter;
        $payload = [];
        $position = 0;

        foreach ($original as $entry) {
            if ($filter !== null && ! $filter($entry)) {
                // Another category's entry. It keeps its place untouched.
                $payload[] = $entry;

                continue;
            }

            if (isset($records[$position])) {
                $payload[] = $records[$position]->toArray();
                $position++;
            }
        }

        // Records added since the file was read go after the ones it had.
        for ($count = count($records); $position < $count; $position++) {
            $payload[] = $records[$position]->toArray();
        }

        return $payload;
    }

    /**
     * Writes a one-file-per-record category, unlinking staged deletions.
     *
     * @return void
     */
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

            $payload = $record->toArray();

            if ($this->schema->listPayloadKey !== null && is_array($payload)) {
                $payload = array_values((array) ($payload[$this->schema->listPayloadKey] ?? []));
            }

            $file->save($payload);
        }

        foreach ($this->stagedDeletions as $path) {
            @unlink($path);
        }

        $this->stagedDeletions = [];
    }

    /**
     * Writes edited terms back into the project config file.
     *
     * @return void
     */
    private function saveConfigSubtree(): void
    {
        if (! $this->file instanceof PhpDataFile) {
            throw new RuntimeException('No backing config file to save.');
        }

        $payload = is_array($this->rootPayload) ? $this->rootPayload : [];

        foreach ($this->records as $record) {
            $path = $record->getDisplayValue('path');

            if ($path === '') {
                continue;
            }

            $payload = self::writeNested($payload, explode('.', $path), $record->get('value'));
        }

        $this->file->save($payload);
        $this->rootPayload = $payload;
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
        $entries = $record->getSubList($subList->key);

        if (! array_key_exists($entryIndex, $entries)) {
            return;
        }

        foreach ($subList->fieldsFor($entries[$entryIndex]) as $field) {
            if (self::fieldToken($field->key) !== $token || $field->isReadOnly) {
                continue;
            }

            $entries[$entryIndex] = self::writeNested(
                $entries[$entryIndex],
                explode('.', $field->key),
                self::coerce($field, $rawValue),
            );
            $record->setSubList($subList->key, $entries);
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
        $entries = $record->getSubList($subList->key);
        $entry = $entries[$entryIndex] ?? null;

        if (! is_array($entry)) {
            return;
        }

        $written = self::withNestedFieldWritten($subList, $entry, $nestedPrefixToken, $nestedIndex, $fieldToken, $rawValue);

        if ($written !== null) {
            $entries[$entryIndex] = $written;
            $record->setSubList($subList->key, $entries);
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

        $nestedEntries = array_values((array) ($entry[$nestedList->key] ?? []));

        if (! is_array($nestedEntries[$nestedIndex] ?? null)) {
            return null;
        }

        foreach ($nestedList->fieldsFor($nestedEntries[$nestedIndex]) as $field) {
            if (self::fieldToken($field->key) !== $fieldToken || $field->isReadOnly) {
                continue;
            }

            $nestedEntries[$nestedIndex] = self::writeNested(
                $nestedEntries[$nestedIndex],
                explode('.', $field->key),
                self::coerce($field, $rawValue),
            );
            $entry[$nestedList->key] = $nestedEntries;

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
        $frameList = $this->frameSubList($framePath);

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
        $frameList = $this->frameSubList($framePath);
        $parent = $commands[$parentIndex] ?? null;
        $nestedList = is_array($parent) && $frameList !== null ? $frameList->nestedListFor($parent) : null;

        return $nestedList === null ? 0 : count(array_values((array) ($parent[$nestedList->key] ?? [])));
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
            if ($at === null) {
                return $this->addNestedSubItem($recordIndex, $parentIndex, $entry);
            }

            $blank = $entry ?? $this->nestedListForParent($recordIndex, $parentIndex)['list']->blank ?? [];
            $this->insertNestedSubItem($recordIndex, $parentIndex, $at, $blank);

            return $at;
        }

        $record = $this->getRecordByIndex($recordIndex);
        $commands = $this->getFrameCommands($recordIndex, $framePath);
        $frameList = $this->frameSubList($framePath);
        $rootList = $this->schema->subList;
        $parent = $commands[$parentIndex] ?? null;
        $nestedList = is_array($parent) && $frameList !== null ? $frameList->nestedListFor($parent) : null;

        if (! $record instanceof ProjectRecord || $commands === null || $rootList === null || $nestedList === null || ! $this->isEditable()) {
            return null;
        }

        $nestedEntries = array_values((array) ($parent[$nestedList->key] ?? []));
        $position = $at === null ? count($nestedEntries) : max(0, min(count($nestedEntries), $at));
        array_splice($nestedEntries, $position, 0, [$entry ?? $nestedList->blank]);
        $commands[$parentIndex][$nestedList->key] = $nestedEntries;
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
        $frameList = $this->frameSubList($framePath);
        $rootList = $this->schema->subList;
        $parent = $commands[$parentIndex] ?? null;
        $nestedList = is_array($parent) && $frameList !== null ? $frameList->nestedListFor($parent) : null;

        if (! $record instanceof ProjectRecord || $commands === null || $rootList === null || $nestedList === null || ! $this->isEditable()) {
            return null;
        }

        $nestedEntries = array_values((array) ($parent[$nestedList->key] ?? []));

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
            $commands[$parentIndex][$nestedList->key] = $nestedEntries;
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

        $entries = $record->getSubList($subList->key);
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
        $entries[$context['parentIndex']][$context['list']->key] = array_values($nestedEntries);
        $context['record']->setSubList($subList->key, $entries);
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
    private static function coerce(RecordField $field, string $rawValue): mixed
    {
        $trimmed = trim($rawValue);

        if ($field->codec === RecordFieldCodec::CONDITIONS) {
            $conditions = ConditionCodec::decodeAll($trimmed);

            return $conditions === [] && $field->removeWhenEmpty ? null : $conditions;
        }

        if ($field->codec === RecordFieldCodec::AFFINITIES) {
            // Empty decodes to [], which the exporter's default-trimming
            // drops from a rebuilt constructor call.
            return ElementAffinityCodec::decodeAll($trimmed);
        }

        if ($field->codec === RecordFieldCodec::WORLD_WRITES) {
            $sets = WorldWriteCodec::decodeAll($trimmed);

            return $sets === [] && $field->removeWhenEmpty ? null : $sets;
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

        if ($field->codec === RecordFieldCodec::CSV_LIST) {
            $items = array_values(array_filter(
                array_map(trim(...), explode(',', $trimmed)),
                static fn(string $item): bool => $item !== '',
            ));

            return $items === [] && $field->removeWhenEmpty ? null : $items;
        }

        if ($field->enumClass !== null && is_subclass_of($field->enumClass, BackedEnum::class)) {
            // The stored value is the enum case, not its string: an object
            // rebuild hands it straight back to a typed constructor argument.
            foreach ($field->enumClass::cases() as $case) {
                if (mb_strtolower(strval($case->value)) === mb_strtolower($trimmed)) {
                    return $case;
                }
            }

            return null;
        }

        if ($field->options !== []) {
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
            RecordFieldCodec::CSV_LIST => implode(', ', array_map(strval(...), is_array($value) ? $value : [])),
            RecordFieldCodec::KEY_VALUES => ParameterMapCodec::encode(is_array($value) ? $value : []),
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
    private function frameSubList(array $framePath): ?RecordSubList
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
        $subList = $this->frameSubList($framePath);

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
        $commands = $this->getFrameCommands($recordIndex, $framePath);

        if ($subList === null || ! $record instanceof ProjectRecord || $commands === null || ! $this->isEditable()) {
            return;
        }

        // Field ids inside a frame carry the frame's own list prefix (a
        // command's, however the frame was reached), not the schema's
        // sub-list prefix: an NPC's variants are "variantN…" at the root and
        // its script's commands "commandN…" inside.
        $frameList = $this->frameSubList($framePath) ?? $subList;
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

            $written = $this->writeEntryFieldToken($this->frameSubList($framePath) ?? $subList, $entry, $matches[2], $rawValue);

            if ($written === null) {
                return;
            }

            $commands[$entryIndex] = $written;
        } else {
            return;
        }

        $this->writeFrameCommands($record, $subList, $framePath, $commands);
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

        if ($subList === null || ! $record instanceof ProjectRecord || $commands === null || ! $this->isEditable()) {
            return null;
        }

        $position = $afterIndex === null ? count($commands) : min(count($commands), $afterIndex + 1);
        $frameList = $this->frameSubList($framePath) ?? $subList;
        array_splice($commands, $position, 0, [$frameList->blank]);
        $this->writeFrameCommands($record, $subList, $framePath, $commands);

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

        if ($subList === null || ! $record instanceof ProjectRecord || $commands === null) {
            return;
        }

        array_splice($commands, min($commandIndex, count($commands)), 0, [$command]);
        $this->writeFrameCommands($record, $subList, $framePath, $commands);
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

        if ($subList === null || ! $record instanceof ProjectRecord || $commands === null || ! isset($commands[$commandIndex])) {
            return null;
        }

        [$removed] = array_splice($commands, $commandIndex, 1);
        $this->writeFrameCommands($record, $subList, $framePath, $commands);

        return is_array($removed) ? $removed : null;
    }

    /**
     * Adds an option to a choice command.
     *
     * @param int $recordIndex The record.
     * @param array<int, int|string> $framePath The frame the choice sits in.
     * @param int $commandIndex The choice command.
     * @return int|null The new option's index.
     */
    public function addChoiceOption(int $recordIndex, array $framePath, int $commandIndex): ?int
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
        $options[] = ['text' => 'New option', 'then' => []];
        $commands[$commandIndex]['options'] = $options;
        $this->writeFrameCommands($record, $subList, $framePath, $commands);

        return count($options) - 1;
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

            if (($framePath[$position + 1] ?? null) === 'options') {
                $parts[] = sprintf('Choice %d › Option %d', $entryNumber, intval($framePath[$position + 2]) + 1);
                $position += 4;
            } else {
                $noun = $position === 0 && $firstLevelSingular !== null ? $firstLevelSingular : 'Branch';
                $parts[] = sprintf('%s %d › %s', $noun, $entryNumber, ucfirst(strval($framePath[$position + 1] ?? '')));
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

            if (($framePath[$position + 1] ?? null) === 'options') {
                $option = ((array) ($entry['options'] ?? []))[intval($framePath[$position + 2] ?? -1)] ?? null;

                if (! is_array($option) || ($framePath[$position + 3] ?? null) !== 'then') {
                    return null;
                }

                $list = array_values((array) ($option['then'] ?? []));
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

        if (($framePath[1] ?? null) === 'options') {
            $optionIndex = intval($framePath[2] ?? -1);
            $options = array_values((array) ($list[$entryIndex]['options'] ?? []));

            if (! is_array($options[$optionIndex] ?? null)) {
                return $list;
            }

            $options[$optionIndex]['then'] = self::withFrameList(
                (array) ($options[$optionIndex]['then'] ?? []),
                array_slice($framePath, 4),
                $frameList,
            );
            $list[$entryIndex]['options'] = $options;

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

            return self::writeNested($entry, explode('.', $field->key), self::coerce($field, $rawValue));
        }

        return null;
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

    private function makeUniqueIdentity(string $preferred): string
    {
        $identityKey = $this->schema->identityKey ?? 'id';
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
