<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes;

use Closure;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
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

            if (! $hasData || ! $hasPartner) {
                $this->reportIncomplete($type, $folder, $entry, $hasData, $hasPartner);

                continue;
            }

            if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $entry) !== 1) {
                $this->issues[$type->value][] = ['folder' => $entry, 'message' => sprintf('The folder name "%s" is not a stable id (lowercase letters, digits, ".", "_" and "-").', $entry)];
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

    /**
     * Reports a folder that holds part of a pair, and says which part.
     */
    private function reportIncomplete(CutsceneType $type, string $folder, string $entry, bool $hasData, bool $hasPartner): void
    {
        $files = array_values(array_filter(scandir($folder) ?: [], static fn(string $file): bool => str_ends_with($file, '.php')));
        $missing = ! $hasData ? $entry . '.data.php' : $entry . $type->partnerSuffix();
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

        $this->issues[$type->value][] = ['folder' => $entry, 'message' => sprintf(
            'Folder "%s" is missing %s; a %s is a data file and a %s together.',
            $entry,
            $missing,
            $type->noun(),
            $type->partnerNoun(),
        )];
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

        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $newId) !== 1) {
            throw new RuntimeException(sprintf('"%s" is not a stable id (lowercase letters, digits, ".", "_" and "-").', $newId));
        }

        $payload = $source->payload();
        $payload['id'] = $newId;
        unset($payload[CutsceneAsset::ORIGIN_KEY]);
        $renamed = CutsceneAsset::create($type, $newId, $this->rootFor($type), $payload, $this->projectRoot);

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

        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $newId) !== 1) {
            throw new RuntimeException(sprintf('"%s" is not a stable id (lowercase letters, digits, ".", "_" and "-").', $newId));
        }

        $payload = $source->payload();
        $payload['id'] = $newId;
        unset($payload[CutsceneAsset::ORIGIN_KEY]);
        $copy = CutsceneAsset::create($type, $newId, $this->rootFor($type), $payload, $this->projectRoot);
        $this->assets[$type->value][] = $copy;
        $this->refreshRecords($type);

        return $copy;
    }

    /**
     * Returns an id no asset of a type uses, from a preferred stem.
     */
    public function freeId(CutsceneType $type, string $preferred): string
    {
        $stem = strtolower(trim(preg_replace('/[^a-z0-9._-]+/i', '-', $preferred) ?? '', '-.'));

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
