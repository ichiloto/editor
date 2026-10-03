<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Storage;

use Ichiloto\Editor\Backup\BackupWriter;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\SharedFileTransaction;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\MapValidator;
use Throwable;

/**
 * Saves every unsaved document of a workspace in one pass: its maps, its
 * databases and its cutscenes, each file backed up first when backups are
 * on. One document's failure never stops the others. A map whose save would
 * move its folder is skipped, because that move needs its own confirmation
 * on the map. Both editors' Save All runs this.
 */
final class WorkspaceSave
{
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
        $failures = [];
        $warnings = [];
        $mapsById = [];

        foreach ($workspace->maps as $map) {
            $mapsById[$map->mapId] = $map;
        }

        foreach ($workspace->maps as $map) {
            if (! $map->isDirty()) {
                continue;
            }

            if ($map->willMoveOnSave()) {
                $skippedRenames[] = $map->mapId;
                continue;
            }

            foreach (MapValidator::validate($map, $mapsById) as $warning) {
                $warnings[] = sprintf('%s: %s', $map->mapId, $warning);
            }

            try {
                $map->save($backup);
                $savedMaps++;
            } catch (Throwable $throwable) {
                $failures[] = sprintf('%s: %s', $map->mapId, $throwable->getMessage());
            }
        }

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

        return new WorkspaceSaveResult(
            savedMaps: $savedMaps,
            savedDatabases: $savedDatabases,
            savedCutscenes: count($cutscenes['saved']),
            skippedRenames: $skippedRenames,
            failures: $failures,
            warnings: $warnings,
            backupFailures: array_values(array_unique($backupFailures)),
        );
    }
}
