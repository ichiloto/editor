<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes;

use Closure;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use RuntimeException;
use Throwable;

/**
 * Every cutscene a project has, of both forms, found by folder.
 *
 * `assets/Cutscenes/Cinematics/<id>/` and `assets/Cutscenes/Summons/<id>/`
 * each hold one pair of files per stable id. The library discovers the
 * complete pairs as assets, and reports what is not one: a folder holding
 * one file without the other, a file whose name does not match its folder,
 * a data file declaring another id than its folder. Nothing is written by
 * looking.
 *
 * Each form is also offered as a record category -- one record per asset,
 * its payload the asset's merged arrays -- so the settings pane, pickers,
 * sub-lists and command frames that edit every other category edit
 * cutscenes the same way, while this library keeps what those records
 * cannot: the folder, the bytes, the dirty state and the paired save.
 *
 * @package Ichiloto\Editor\Cutscenes
 */
final class CutsceneLibrary
{
    /**
     * @var array<string, CutsceneAsset[]> Assets by type value, in list order.
     */
    private array $assets = [];

    /**
     * @var array<string, array<int, array{folder: string, message: string}>> Discovery findings by type value.
     */
    private array $issues = [];

    /**
     * @var array<string, ProjectRecordDatabase> Record categories by type value.
     */
    private array $databases = [];

    private function __construct(public readonly string $projectRoot)
    {
    }

    /**
     * Discovers both forms under a project root.
     */
    public static function fromProject(string $projectRoot): self
    {
        $library = new self(rtrim($projectRoot, '/'));

        foreach (CutsceneType::cases() as $type) {
            $library->discover($type);
        }

        return $library;
    }

    /**
     * Returns the absolute folder holding a type's assets.
     */
    public function rootFor(CutsceneType $type): string
    {
        return $this->projectRoot . '/' . $type->relativeRoot();
    }

    /**
     * Reads a type's folder into assets and findings.
     */
    private function discover(CutsceneType $type): void
    {
        $this->assets[$type->value] = [];
        $this->issues[$type->value] = [];
        $root = $this->rootFor($type);

        if (! is_dir($root)) {
            return;
        }

        $entries = scandir($root) ?: [];
        sort($entries, SORT_STRING);
        $seenIds = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $folder = $root . '/' . $entry;

            if (! is_dir($folder)) {
                if (str_ends_with($entry, '.php')) {
                    $this->issues[$type->value][] = ['folder' => $entry, 'message' => sprintf('%s sits outside any %s folder; every %s lives in its own folder named by its id.', $entry, $type->noun(), $type->noun())];
                }

                continue;
            }

            $dataPath = $folder . '/' . $entry . '.data.php';
            $partnerPath = $folder . '/' . $entry . $type->partnerSuffix();
            $hasData = is_file($dataPath);
            $hasPartner = is_file($partnerPath);

            if (($type->hasDataFile() && ! $hasData) || ! $hasPartner) {
                // A folder of resources with no PHP at all, such as artwork several cutscenes share, is not a
                // cutscene; the game passes over it as well. An empty folder or half a pair is reported.
                if (! $hasData && ! $hasPartner && self::holdsOnlyResources($folder)) {
                    continue;
                }
                $this->reportIncomplete($type, $folder, $entry, $hasData, $hasPartner);

                continue;
            }

            if (preg_match($type->getIdPattern(), $entry) !== 1) {
                $this->issues[$type->value][] = ['folder' => $entry, 'message' => sprintf('The folder name "%s" is not a stable id (%s).', $entry, $type->describeIdCharacters())];
            }

            $lower = strtolower($entry);

            if (isset($seenIds[$lower])) {
                $this->issues[$type->value][] = ['folder' => $entry, 'message' => sprintf('"%s" and "%s" are the same id in different case; the game cannot tell them apart.', $seenIds[$lower], $entry)];
            }

            $seenIds[$lower] = $entry;

            try {
                $asset = CutsceneAsset::load($type, $folder, $this->projectRoot);
            } catch (Throwable $throwable) {
                $this->issues[$type->value][] = ['folder' => $entry, 'message' => $throwable->getMessage()];

                continue;
            }

            if ($asset->readOnlyReason() !== null) {
                $this->issues[$type->value][] = ['folder' => $entry, 'message' => sprintf('%s is read-only: %s.', $entry, $asset->readOnlyReason())];
            }

            $this->assets[$type->value][] = $asset;
        }
    }

    /** Whether a folder holds something, none of it a PHP file, at any depth. */
    private static function holdsOnlyResources(string $folder): bool
    {
        $found = false;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.php')) {
                return false;
            }
            $found = true;
        }

        return $found;
    }

    /**
     * Reports a folder that holds part of a pair, and says which part.
     */
    private function reportIncomplete(CutsceneType $type, string $folder, string $entry, bool $hasData, bool $hasPartner): void
    {
        $files = array_values(array_filter(scandir($folder) ?: [], static fn(string $file): bool => str_ends_with($file, '.php')));
        $missing = $type->hasDataFile() && ! $hasData ? $entry . '.data.php' : $entry . $type->partnerSuffix();
        $misnamed = array_values(array_filter(
            $files,
            static fn(string $file): bool => (str_ends_with($file, '.data.php') || str_ends_with($file, $type->partnerSuffix()))
                && $file !== $entry . '.data.php'
                && $file !== $entry . $type->partnerSuffix(),
        ));

        if ($misnamed !== []) {
            $this->issues[$type->value][] = ['folder' => $entry, 'message' => sprintf(
                'Folder "%s" holds %s, which does not match the folder name; the game looks for %s.',
                $entry,
                implode(' and ', $misnamed),
                $missing,
            )];

            return;
        }

        $this->issues[$type->value][] = ['folder' => $entry, 'message' => $type->hasDataFile()
            ? sprintf('Folder "%s" is missing %s; a %s is a data file and a %s together.', $entry, $missing, $type->noun(), $type->partnerNoun())
            : sprintf('Folder "%s" is missing %s; an %s is its %s file.', $entry, $missing, $type->noun(), $type->partnerNoun())];
    }

    /**
     * Returns a type's assets, in list order.
     *
     * @return CutsceneAsset[]
     */
    public function assets(CutsceneType $type): array
    {
        return array_values($this->assets[$type->value] ?? []);
    }

    /**
     * Returns an asset by id.
     */
    public function find(CutsceneType $type, string $id): ?CutsceneAsset
    {
        foreach ($this->assets($type) as $asset) {
            if ($asset->id === $id) {
                return $asset;
            }
        }

        return null;
    }

    /**
     * Returns the ids of a type's assets that are not marked for deletion.
     *
     * @return string[]
     */
    public function ids(CutsceneType $type): array
    {
        return array_values(array_map(
            static fn(CutsceneAsset $asset): string => $asset->id,
            array_filter($this->assets($type), static fn(CutsceneAsset $asset): bool => ! $asset->isDeleted()),
        ));
    }

    /**
     * Returns a type's discovery findings.
     *
     * @return array<int, array{folder: string, message: string}>
     */
    public function issues(CutsceneType $type): array
    {
        return $this->issues[$type->value] ?? [];
    }

    /**
     * Returns whether any asset of either form holds unsaved work.
     */
    public function hasUnsavedChanges(): bool
    {
        foreach (CutsceneType::cases() as $type) {
            foreach ($this->assets($type) as $asset) {
                if ($asset->isDirty()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Returns every dirty asset of either form.
     *
     * @return CutsceneAsset[]
     */
    public function dirtyAssets(): array
    {
        $dirty = [];

        foreach (CutsceneType::cases() as $type) {
            foreach ($this->assets($type) as $asset) {
                if ($asset->isDirty()) {
                    $dirty[] = $asset;
                }
            }
        }

        return $dirty;
    }

    /**
     * Returns the record category editing a type's assets, built over the
     * library and written back into it on every change.
     */
    public function records(CutsceneType $type): ProjectRecordDatabase
    {
        return $this->databases[$type->value] ??= $this->buildRecords($type);
    }

    /**
     * Rebuilds a type's record category from the assets, after the assets
     * changed outside the records -- an undo swapping an asset's arrays, a
     * save, a reload.
     */
    public function refreshRecords(CutsceneType $type): void
    {
        $this->databases[$type->value] = $this->buildRecords($type);
    }

    /**
     * Changes one asset through its type's records, the one way every
     * interface does: the change runs against the records, is taken into
     * the asset at once, and comes back as the command that undoes and redoes
     * it by restoring the asset's whole edit state, pinned to that asset
     * whatever is selected when the history fires. Null when nothing changed.
     *
     * @template T
     * @param int $index The asset's place among its type's records.
     * @param callable(ProjectRecordDatabase, int): T $change The change, against the records and the record index.
     * @param (Closure(CutsceneAsset): void)|null $restored Told when an undo or redo puts the asset back, for an interface to follow.
     * @return array{command: ?Command, result: T}
     * @throws RuntimeException When the asset is missing or read-only; nothing is changed.
     * @throws Throwable Whatever the change throws; the records are rebuilt from the untouched asset.
     */
    public function changeAsset(CutsceneType $type, int $index, string $label, callable $change, ?Closure $restored = null): array
    {
        $id = $this->ids($type)[$index] ?? throw new RuntimeException(sprintf('There is no %s %d.', $type->noun(), $index));
        $asset = $this->find($type, $id) ?? throw new RuntimeException(sprintf('There is no %s %s.', $type->noun(), $id));
        if (! $asset->isEditable()) {
            throw new RuntimeException(sprintf('%s is read-only: %s.', ucfirst($type->noun()), $asset->readOnlyReason()));
        }
        $records = $this->records($type);
        $before = $asset->captureEditState();
        try {
            $result = $change($records, $index);
            $records->save();
        } finally {
            $this->refreshRecords($type);
        }
        $after = $asset->captureEditState();
        if ($after === $before) {
            return ['command' => null, 'result' => $result];
        }
        return ['command' => $this->createRestoringCommand($label, $type, $id, $before, $after, $restored), 'result' => $result];
    }

    /**
     * Creates a new asset of a type from its schema's blank, under the
     * preferred id made free and safe (or the blank's own), and returns it
     * with the command that undoes and redoes its creation. It reaches disk
     * on save.
     *
     * @param (Closure(CutsceneAsset): void)|null $restored Told when an undo or redo puts the asset back.
     * @return array{asset: CutsceneAsset, command: Command}
     */
    public function createAsset(CutsceneType $type, ?string $preferredId = null, ?Closure $restored = null): array
    {
        $blank = $this->records($type)->schema->blank;
        $id = $this->freeId($type, $preferredId ?? strval($blank['id'] ?? ('new-' . $type->noun())));
        $created = CutsceneAsset::create($type, $id, $this->rootFor($type), [...$blank, 'id' => $id], $this->projectRoot);
        $this->adopt($created);

        return ['asset' => $created, 'command' => $this->createPresenceCommand(sprintf('Create %s', $type->noun()), $created, $restored)];
    }

    /**
     * Duplicates an asset under the preferred id made free and safe (or the
     * original's with `-copy`), and returns the copy with the command that
     * undoes and redoes the duplication. It reaches disk on save.
     *
     * @param (Closure(CutsceneAsset): void)|null $restored Told when an undo or redo puts the copy back.
     * @return array{asset: CutsceneAsset, command: Command}
     * @throws RuntimeException When the asset is missing.
     */
    public function duplicateAsset(CutsceneType $type, string $id, ?string $preferredId = null, ?Closure $restored = null): array
    {
        $copy = $this->duplicate($type, $id, $this->freeId($type, $preferredId ?? $id . '-copy'));

        return ['asset' => $copy, 'command' => $this->createPresenceCommand(sprintf('Duplicate %s', $type->noun()), $copy, $restored)];
    }

    /** The command that takes a just-made asset away on undo and brings it back on redo. */
    private function createPresenceCommand(string $label, CutsceneAsset $asset, ?Closure $restored): Command
    {
        $state = $asset->captureEditState();

        return $this->createRestoringCommand($label, $asset->type, $asset->id, [...$state, 'deleted' => true], [...$state, 'deleted' => false], $restored);
    }

    /**
     * The command that puts an asset into one edit state on redo and another
     * on undo, pinned to the asset whatever is selected when it fires.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param (Closure(CutsceneAsset): void)|null $restored
     */
    private function createRestoringCommand(string $label, CutsceneType $type, string $id, array $before, array $after, ?Closure $restored): Command
    {
        $restore = function (array $state) use ($type, $id, $restored): void {
            $asset = $this->find($type, $id);
            if ($asset === null) {
                return;
            }
            $asset->restoreEditState($state);
            $this->refreshRecords($type);
            if ($restored !== null) {
                $restored($asset);
            }
        };

        return new GenericCommand($label, static fn() => $restore($after), static fn() => $restore($before));
    }

    private function buildRecords(CutsceneType $type): ProjectRecordDatabase
    {
        $schema = $this->schemaFor($type);
        $entries = array_map(
            static fn(CutsceneAsset $asset): array => $asset->payload(),
            array_values(array_filter($this->assets($type), static fn(CutsceneAsset $asset): bool => ! $asset->isDeleted())),
        );

        return ProjectRecordDatabase::overOwnedList(
            $schema,
            $this->rootFor($type),
            $entries,
            function (array $written) use ($type): void {
                $this->writeBack($type, $written);
            },
        );
    }

    /**
     * Returns the schema a type's records are edited with.
     */
    public function schemaFor(CutsceneType $type): RecordSchema
    {
        return match ($type) {
            CutsceneType::CINEMATIC => RecordSchemaCatalog::cinematics(),
            CutsceneType::SUMMON => RecordSchemaCatalog::summons(),
            CutsceneType::EFFECT => RecordSchemaCatalog::effects(),
        };
    }

    /**
     * Takes the records' payloads back into the assets: each record names
     * the asset it was read from; one that names none is new; an asset no
     * record names any more is marked for deletion; the order is the
     * records' order.
     *
     * @param array<int, mixed> $written
     */
    private function writeBack(CutsceneType $type, array $written): void
    {
        $current = $this->assets($type);
        $byId = [];

        foreach ($current as $asset) {
            $byId[$asset->id] = $asset;
        }

        $next = [];
        $seen = [];

        foreach ($written as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $origin = isset($entry[CutsceneAsset::ORIGIN_KEY]) ? strval($entry[CutsceneAsset::ORIGIN_KEY]) : '';
            $asset = $origin !== '' ? ($byId[$origin] ?? null) : null;

            if ($asset === null) {
                $id = trim(strval($entry['id'] ?? ''));

                if ($id === '' || isset($byId[$id]) || isset($seen[$id])) {
                    throw new RuntimeException(sprintf('A new %s needs a stable id no other %s has.', $type->noun(), $type->noun()));
                }

                $asset = CutsceneAsset::create($type, $id, $this->rootFor($type), $entry, $this->projectRoot);
                $byId[$id] = $asset;
            } else {
                $asset->markDeleted(false);
                $asset->apply($entry);
            }

            $seen[$asset->id] = true;
            $next[] = $asset;
        }

        foreach ($current as $asset) {
            if (! isset($seen[$asset->id])) {
                if ($asset->isNew()) {
                    // Created and removed before it ever reached disk.
                    continue;
                }

                $asset->markDeleted(true);
                $next[] = $asset;
            }
        }

        $this->assets[$type->value] = $next;
    }

    /**
     * Adds a created asset to its type's list, as a new dirty asset, and
     * rebuilds the records over it.
     */
    public function adopt(CutsceneAsset $asset): void
    {
        if ($this->find($asset->type, $asset->id) !== null) {
            throw new RuntimeException(sprintf('A %s "%s" already exists.', $asset->type->noun(), $asset->id));
        }

        $this->assets[$asset->type->value][] = $asset;
        $this->refreshRecords($asset->type);
    }

    /**
     * Gives a never-saved asset its identity: the folder does not exist yet,
     * so the id is free to choose until the first save fixes it.
     */
    public function renameNew(CutsceneType $type, string $id, string $newId): CutsceneAsset
    {
        $source = $this->find($type, $id);

        if ($source === null) {
            throw new RuntimeException(sprintf('No %s "%s" to rename.', $type->noun(), $id));
        }

        if (! $source->isNew()) {
            throw new RuntimeException(sprintf('%s "%s" is saved; its id is its folder. Duplicate it under the new id and delete this one.', ucfirst($type->noun()), $id));
        }

        if ($newId === $id) {
            return $source;
        }

        if ($this->find($type, $newId) !== null || is_dir($this->rootFor($type) . '/' . $newId)) {
            throw new RuntimeException(sprintf('A %s "%s" already exists.', $type->noun(), $newId));
        }

        if (preg_match($type->getIdPattern(), $newId) !== 1) {
            throw new RuntimeException(sprintf('"%s" is not a stable id (%s).', $newId, $type->describeIdCharacters()));
        }

        $renamed = $source->copyAs($newId, $this->rootFor($type));

        foreach ($this->assets[$type->value] as $position => $asset) {
            if ($asset === $source) {
                $this->assets[$type->value][$position] = $renamed;
            }
        }

        $this->refreshRecords($type);

        return $renamed;
    }

    /**
     * Duplicates an asset under a new id, as a new dirty asset.
     */
    public function duplicate(CutsceneType $type, string $id, string $newId): CutsceneAsset
    {
        $source = $this->find($type, $id);

        if ($source === null) {
            throw new RuntimeException(sprintf('No %s "%s" to duplicate.', $type->noun(), $id));
        }

        if ($this->find($type, $newId) !== null || is_dir($this->rootFor($type) . '/' . $newId)) {
            throw new RuntimeException(sprintf('A %s "%s" already exists.', $type->noun(), $newId));
        }

        if (preg_match($type->getIdPattern(), $newId) !== 1) {
            throw new RuntimeException(sprintf('"%s" is not a stable id (%s).', $newId, $type->describeIdCharacters()));
        }

        // Both files as they stand: every sequence, not only the one shown.
        $copy = $source->copyAs($newId, $this->rootFor($type));
        $this->assets[$type->value][] = $copy;
        $this->refreshRecords($type);

        return $copy;
    }

    /**
     * Returns an id no asset of a type uses, from a preferred stem.
     */
    public function freeId(CutsceneType $type, string $preferred): string
    {
        // An effect id holds no dots, as the Engine requires.
        $allowed = $type === CutsceneType::EFFECT ? '/[^a-z0-9_-]+/i' : '/[^a-z0-9._-]+/i';
        $stem = strtolower(trim(preg_replace($allowed, '-', $preferred) ?? '', '-.'));

        if ($stem === '' || preg_match('/^[a-z0-9]/', $stem) !== 1) {
            $stem = 'new-' . $type->noun();
        }

        $candidate = $stem;
        $suffix = 2;

        while ($this->find($type, $candidate) !== null || is_dir($this->rootFor($type) . '/' . $candidate)) {
            $candidate = $stem . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Saves every dirty asset of both forms, each as its own paired
     * transaction, and reports failures by asset rather than stopping at
     * the first.
     *
     * @param Closure(string ...$paths): void|null $backup
     * @return array{saved: string[], failed: array<string, string>}
     */
    public function saveAll(?Closure $backup = null): array
    {
        $saved = [];
        $failed = [];

        foreach (CutsceneType::cases() as $type) {
            foreach ($this->assets($type) as $asset) {
                if (! $asset->isDirty()) {
                    continue;
                }

                try {
                    $asset->save($backup);
                    $saved[] = $type->noun() . ' ' . $asset->id;
                } catch (Throwable $throwable) {
                    $failed[$type->noun() . ' ' . $asset->id] = $throwable->getMessage();
                }
            }

            $this->forgetDeleted($type);
        }

        return ['saved' => $saved, 'failed' => $failed];
    }

    /**
     * Saves one asset.
     *
     * @param Closure(string ...$paths): void|null $backup
     */
    public function save(CutsceneType $type, string $id, ?Closure $backup = null): bool
    {
        $asset = $this->find($type, $id);

        if ($asset === null) {
            throw new RuntimeException(sprintf('No %s "%s" to save.', $type->noun(), $id));
        }

        $written = $asset->save($backup);
        $this->forgetDeleted($type);

        return $written;
    }

    /**
     * Drops assets whose deletion has reached disk.
     */
    private function forgetDeleted(CutsceneType $type): void
    {
        $remaining = array_values(array_filter(
            $this->assets($type),
            static fn(CutsceneAsset $asset): bool => ! ($asset->isDeleted() && ! $asset->isDirty()),
        ));

        if (count($remaining) !== count($this->assets($type))) {
            $this->assets[$type->value] = $remaining;
            $this->refreshRecords($type);
        }
    }
}
