<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Storage;

use Closure;
use Ichiloto\Editor\Backup\BackupWriter;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\SharedFileTransaction;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\Maps\SaveRelocationPlanner;
use Ichiloto\Editor\ProjectActorDatabase;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectSettingsFile;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\MapValidator;
use Ichiloto\Editor\Validation\Severity;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Saves every unsaved document of a workspace in one pass: its maps, its
 * databases and its cutscenes, each file backed up first when backups are
 * on. One document's failure never stops the others. A map whose save would
 * move its folder is skipped, because that move needs its own confirmation
 * on the map. Both editors' Save All runs this.
 *
 * A change set is the opposite case: everything it changed is written
 * together or not at all. {@see prepareAll()} works out every document's
 * save first, and {@see SaveSet} installs them as one transaction.
 */
final class WorkspaceSave
{
    /**
     * Works out the save of every unsaved document of a workspace, writing
     * nothing, for a change set to install as one {@see SaveSet}: its
     * databases, then its cutscenes, then its maps, each map after the save
     * relocations it needs. What the maps name is declared before them, as
     * Save All writes it.
     *
     * @param (Closure(Command): void)|null $recordRelocation Records the undo step of a map's save relocations once
     *     they are installed; null refuses a map whose save would move existing saves.
     * @param list<string> $unrelocatedMaps Maps whose existing saves this write plans no relocations for: maps made
     *     since the last save, such as in an open change set, on which no save stands whatever their blank files on
     *     disk hold, and maps the change set retires, whose saves the retirement already moves.
     * @return list<PreparedSave>
     * @throws RuntimeException When a document cannot take its edits, a map would move its folder, or a map needs
     *     save relocations that were not allowed. Nothing is written.
     */
    public static function prepareAll(ProjectWorkspace $workspace, ?Closure $recordRelocation = null, array $unrelocatedMaps = []): array
    {
        $saves = [];
        // Categories editing through one settings file (Terms and Configuration write `config.php`
        // through the project configuration) are prepared once, by that file, wherever they are listed.
        $writers = [];

        foreach (SharedFileTransaction::groupByPath($workspace->listSaveableDatabases()) as $group) {
            $dirty = array_filter($group, static fn(object $database): bool => $database->isDirty());

            if ($dirty === []) {
                continue;
            }

            $shared = reset($dirty);

            if ($shared instanceof ProjectRecordDatabase && $shared->sharesBackingFile()) {
                $saves[] = SharedFileTransaction::prepare(array_values($dirty));
                continue;
            }

            foreach ($dirty as $database) {
                $writer = $database instanceof ProjectRecordDatabase ? $database->getFileWriter() : $database;
                if (isset($writers[spl_object_id($writer)])) {
                    continue;
                }
                $writers[spl_object_id($writer)] = true;
                $saves = [...$saves, ...match (true) {
                    $database instanceof ProjectActorDatabase => $database->prepareSaves(),
                    $database instanceof ProjectRecordDatabase, $database instanceof ProjectSettingsFile => [$database->prepareSave()],
                    default => throw new LogicException(sprintf('%s cannot be saved with a change set.', get_debug_type($database))),
                }];
            }
        }

        $saves = [...$saves, ...($workspace->cutscenes?->prepareSaves() ?? [])];

        $dirtyMaps = array_values(array_filter($workspace->maps, static fn(ProjectMap $map): bool => $map->isDirty()));

        foreach ($dirtyMaps as $map) {
            if ($map->willMoveOnSave()) {
                throw new RuntimeException(sprintf('%s would move its folder when saved; save it on its own.', $map->mapId));
            }
        }

        // One manifest step holds every map's relocations: the manifest is written once. A map made since the last
        // save holds no save, whatever stands on its blank files, and a retired map's saves move with its retirement.
        $relocation = SaveRelocationPlanner::planAll(array_values(array_filter($dirtyMaps,
            static fn(ProjectMap $map): bool => ! in_array($map->mapId, $unrelocatedMaps, true))), $workspace->projectRoot);

        if ($relocation !== null) {
            if ($recordRelocation === null) {
                throw new RuntimeException(sprintf('Saving %s moves existing saves; allow the relocations first.', $relocation->mapId));
            }

            $saves[] = $relocation->prepareApply($recordRelocation);
        }

        foreach ($dirtyMaps as $map) {
            $saves[] = $map->prepareSave();
        }

        return array_values(array_filter($saves));
    }

    public static function saveAll(ProjectWorkspace $workspace, BackupWriter $backups): WorkspaceSaveResult
    {
        $backupFailures = [];
        $backup = static function (string ...$paths) use ($backups, &$backupFailures): void {
            if ($backups->isEnabled() && $paths !== []) {
                array_push($backupFailures, ...$backups->backup(...$paths)['failed']);
            }
        };
        $savedMaps = 0;
        $savedDatabases = 0;
        $skippedRenames = [];
        $skippedRelocations = [];
        $failures = [];
        $warnings = [];
        $errors = [];
        $mapsById = [];

        foreach ($workspace->maps as $map) {
            $mapsById[$map->mapId] = $map;
        }

        $inventory = MapValidator::readInventoryCatalog($workspace);

        // Categories sharing one file are saved together, so the file is
        // written once with every dirty part folded in rather than once per
        // category, each rewriting what the last just wrote.
        foreach (SharedFileTransaction::groupByPath($workspace->listSaveableDatabases()) as $group) {
            $dirty = array_filter($group, static fn(object $database): bool => $database->isDirty());

            if ($dirty === []) {
                continue;
            }

            try {
                // One file, one backup, however many categories of it are being written.
                $backup(...array_values(array_unique(array_merge(...array_map(
                    static fn(object $database): array => $database->getBackupPaths(),
                    array_values($dirty),
                )))));
                $shared = reset($dirty);

                if ($shared instanceof ProjectRecordDatabase && $shared->sharesBackingFile()) {
                    // One write for the file, with every dirty category's
                    // edits composed against one snapshot of it first.
                    SharedFileTransaction::commit(array_values($dirty));
                } else {
                    foreach ($dirty as $database) {
                        $database->save();
                    }
                }

                $savedDatabases += count($dirty);
            } catch (Throwable $throwable) {
                $failures[] = sprintf('%s: %s', implode(', ', array_keys($dirty)), $throwable->getMessage());
            }
        }

        // Cutscenes: each dirty asset as its own paired transaction.
        $cutscenes = $workspace->cutscenes?->saveAll($backup) ?? ['saved' => [], 'failed' => []];

        foreach ($cutscenes['failed'] as $asset => $reason) {
            $failures[] = sprintf('%s: %s', $asset, $reason);
        }

        // Maps last: what they name (a field resource, a cinematic, an item) is on disk before they are.
        foreach ($workspace->maps as $map) {
            if (! $map->isDirty()) {
                continue;
            }

            if ($map->willMoveOnSave()) {
                $skippedRenames[] = $map->mapId;
                continue;
            }

            // Saves that would load inside what the map now makes solid are moved only once the author has seen where.
            try {
                $relocation = SaveRelocationPlanner::plan($map, $workspace->projectRoot);
            } catch (Throwable $throwable) {
                $failures[] = sprintf('%s: %s', $map->mapId, $throwable->getMessage());
                continue;
            }
            if ($relocation !== null) {
                $skippedRelocations[] = $map->mapId;
                continue;
            }

            $issues = MapValidator::validate($map, $mapsById, $inventory);
            foreach (MapValidator::describeIssues(MapValidator::selectSeverity($issues, Severity::WARNING)) as $warning) {
                $warnings[] = sprintf('%s: %s', $map->mapId, $warning);
            }
            foreach (MapValidator::describeIssues(MapValidator::selectSeverity($issues, Severity::ERROR)) as $error) {
                $errors[] = sprintf('%s: %s', $map->mapId, $error);
            }

            try {
                $map->save($backup);
                $savedMaps++;
            } catch (Throwable $throwable) {
                $failures[] = sprintf('%s: %s', $map->mapId, $throwable->getMessage());
            }
        }

        return new WorkspaceSaveResult(
            savedMaps: $savedMaps,
            savedDatabases: $savedDatabases,
            savedCutscenes: count($cutscenes['saved']),
            skippedRenames: $skippedRenames,
            skippedRelocations: $skippedRelocations,
            failures: $failures,
            warnings: $warnings,
            backupFailures: array_values(array_unique($backupFailures)),
            errors: $errors,
        );
    }
}
