<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourceNode;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Cutscenes\Source\SourceUnreadable;
use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Storage\SourceSetPlan;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayerSource;
use Ichiloto\Engine\Field\MapPhysicalOccupancy;
use RuntimeException;
use Throwable;

/**
 * Plans inserting blank rows or columns into a map so the whole project
 * stays consistent: the map's grids grow, and every coordinate in its space
 * at or beyond the line moves, in whichever file holds it
 * ({@see LineInsertionInventory}). Every changed file is rewritten through
 * the source-preserving writers; a coordinate the source cannot take as a
 * literal is reported for a hand edit instead of being flattened. When the
 * project has a save compatibility manifest, the plan raises its content
 * version with a declarative map shift step, so existing saves follow.
 *
 * Planning reads the project from disk and writes nothing.
 *
 * @package Ichiloto\Editor\Maps
 */
final class LineInsertionPlanner
{
    private const string MANIFEST = 'assets/Data/save-compatibility.php';
    private const string SYSTEM = 'assets/Data/system.php';

    private function __construct()
    {
    }

    /**
     * Plans the insertion; callers show the plan and obtain confirmation
     * before applying it.
     *
     * @throws RuntimeException When the map or a data file cannot be read; nothing is written.
     */
    public static function planProject(string $projectRoot, string $mapId, string $axis, int $at, int $count): LineInsertionPlan
    {
        $insertion = new LineInsertion($mapId, $axis, $at, $count);

        return ProjectDirectoryContext::run($projectRoot, static fn(string $root): LineInsertionPlan => self::buildPlan($root, $insertion));
    }

    private static function buildPlan(string $root, LineInsertion $insertion): LineInsertionPlan
    {
        $directory = $root . '/assets/Maps/' . $insertion->mapId;
        $map = ProjectMap::fromDirectory($root . '/assets/Maps', $directory);
        $map->insertLines($insertion->axis, $insertion->at, $insertion->count);

        $watched = $originals = $proposals = $before = $after = [];
        $gridPaths = self::findGridPaths($directory);
        foreach ($map->getGridSources() as $path => $source) {
            $original = (string) file_get_contents($path);
            $watched[$path] = $original;
            if ($source !== $original) {
                $originals[$path] = $original;
                $proposals[$path] = $source;
                $before[$path] = MapGridSource::parseSource($original, $path);
                $after[$path] = MapGridSource::parseSource($source, $path);
            }
        }

        $dataPaths = self::findDataPaths($root);
        $payloads = self::evaluate($dataPaths, $root);
        $relative = static fn(string $path): string => substr($path, strlen($root) + 1);
        foreach ($dataPaths as $path) {
            $watched[$path] = (string) file_get_contents($path);
        }

        // Physical geometry is edited by ProjectMap, not the coordinate inventory.
        // Keep its source-preserved proposal in this same reversible source set.
        $map->assertSourcesUnchanged();
        $mapData = $payloads[$map->dataPath];
        if ($map->hasMapDataField([MapPhysicalOccupancy::DATA_KEY])) {
            $mapData[MapPhysicalOccupancy::DATA_KEY] = $map->getMapDataField([MapPhysicalOccupancy::DATA_KEY]);
        }
        if ($map->getMapDataField([]) !== $map->data) {
            $originals[$map->dataPath] = $watched[$map->dataPath];
            $proposals[$map->dataPath] = $map->captureLayerSnapshot()['source'];
            $before[$map->dataPath] = PhpDataFile::getComparableValue($payloads[$map->dataPath]);
            $after[$map->dataPath] = PhpDataFile::getComparableValue($mapData);
        }

        $commonEvents = [];
        foreach ($dataPaths as $path) {
            if (dirname($path) === $root . '/assets/Events') {
                $commonEvents[basename($path, '.php')] = ['file' => $path, 'commands' => $payloads[$path]];
            }
        }
        $inventory = new LineInsertionInventory($insertion, $commonEvents);
        foreach ($dataPaths as $path) {
            if (str_starts_with($path, $root . '/assets/Maps/') && is_array($payloads[$path])) {
                $inventory->addMapData($path, substr(dirname($path), strlen($root . '/assets/Maps/')), $payloads[$path]);
            }
        }
        if (is_array($payloads[$root . '/' . self::SYSTEM] ?? null)) {
            $inventory->addSystemData($root . '/' . self::SYSTEM, $payloads[$root . '/' . self::SYSTEM]);
        }
        foreach ($dataPaths as $path) {
            if (str_ends_with($path, '.data.php') && str_starts_with($path, $root . '/assets/Cutscenes/Cinematics/')) {
                $scriptPath = substr($path, 0, -strlen('.data.php')) . '.script.php';
                if (is_array($payloads[$path]) && array_key_exists($scriptPath, $payloads)) {
                    $inventory->addCutscene($path, $scriptPath, basename(dirname($path)), $payloads[$path], $payloads[$scriptPath]);
                }
            }
        }
        $inventory->addCommonEvents();

        $handEdits = [];
        foreach ($inventory->getHandEdits() as $path => $edits) {
            foreach ($edits as $edit) {
                $handEdits[] = ['file' => $relative($path), 'where' => PhpArraySourceDocument::describePath($edit['path']), 'reason' => $edit['reason']];
            }
        }
        foreach ($inventory->getShifts() as $path => $shifts) {
            $next = self::rewriteShifts($proposals[$path] ?? $watched[$path],
                $path === $map->dataPath ? $mapData : $payloads[$path], $shifts, $relative($path), $handEdits);
            if ($next !== null) {
                $originals[$path] = $watched[$path];
                $proposals[$path] = $next['source'];
                $before[$path] = PhpDataFile::getComparableValue($payloads[$path]);
                $after[$path] = PhpDataFile::getComparableValue($next['value']);
            }
        }

        $notes = [];
        $manifestPath = $root . '/' . self::MANIFEST;
        if (! array_key_exists($manifestPath, $payloads)) {
            $notes[] = sprintf('Existing saves are not migrated: the project has no %s.', self::MANIFEST);
        } else {
            $next = self::planSaveMigration($watched[$manifestPath], $payloads[$manifestPath], $insertion, $handEdits);
            if ($next !== null) {
                $originals[$manifestPath] = $watched[$manifestPath];
                $proposals[$manifestPath] = $next['source'];
                $before[$manifestPath] = PhpDataFile::getComparableValue($payloads[$manifestPath]);
                $after[$manifestPath] = PhpDataFile::getComparableValue($next['value']);
                $notes[] = sprintf('Saves move with the map: save content version %d adds this map shift.', $next['value']['contentVersion']);
            }
        }

        $sources = new SourceSetPlan($root, $watched, $originals, $proposals, $before, $after,
            static fn(): array => [...self::findGridPaths($directory), ...self::findDataPaths($root)],
            static fn(string $path, string $staged): mixed => in_array($path, $gridPaths, true)
                ? MapGridSource::parseSource((string) file_get_contents($staged), $path)
                : PhpDataFile::getComparableValue(PhpDataFile::evaluateIsolated($staged, $root)),
            'map line insertion',
            'Map and project data files',
        );

        return new LineInsertionPlan($insertion, $sources, $handEdits, $notes, $map->getWidth(), $map->getHeight());
    }

    /**
     * Rewrites a data file's coordinates in place. A coordinate written as
     * anything but a literal, or in a file that is not one returned array
     * literal, is reported for a hand edit and left as authored.
     *
     * @param list<array{path: list<int|string>, value: int}> $shifts
     * @param list<array{file: string, where: string, reason: string}> $handEdits
     * @return array{source: string, value: array<array-key, mixed>}|null Null when nothing in the file can be rewritten.
     */
    private static function rewriteShifts(string $source, mixed $payload, array $shifts, string $file, array &$handEdits): ?array
    {
        $report = static function (array $path, string $reason) use (&$handEdits, $file): void {
            $handEdits[] = ['file' => $file, 'where' => PhpArraySourceDocument::describePath($path), 'reason' => $reason];
        };
        try {
            $document = PhpArraySourceDocument::parse($source);
        } catch (SourceUnreadable $unreadable) {
            foreach ($shifts as $shift) {
                $report($shift['path'], 'the file is not one returned array literal (' . rtrim($unreadable->getMessage(), '.') . ')');
            }
            return null;
        }

        $next = $payload;
        $kept = [];
        foreach ($shifts as $shift) {
            $refusal = self::findLiteralRefusal($document, $shift['path']);
            if ($refusal !== null) {
                $report($shift['path'], $refusal);
                continue;
            }
            $value = &$next;
            foreach ($shift['path'] as $step) {
                $value = &$value[$step];
            }
            $value = $shift['value'];
            unset($value);
            $kept[] = $shift;
        }
        if ($kept === []) {
            return null;
        }

        try {
            return ['source' => ArraySourceWriter::rewrite($document, $payload, $next)->source, 'value' => $next];
        } catch (SourcePreservationRefusal $refusal) {
            foreach ($kept as $shift) {
                $report($shift['path'], rtrim($refusal->getMessage(), '.'));
            }
            return null;
        }
    }

    /**
     * Why a coordinate is not a literal the file writes in its own place,
     * or null when it is: every array above it must be written out entry by
     * entry, so its place in the file is its place in the data.
     *
     * @param list<int|string> $path
     */
    private static function findLiteralRefusal(PhpArraySourceDocument $document, array $path): ?string
    {
        $node = $document->root();
        foreach ($path as $step) {
            if ($node->kind !== SourceNode::ARRAY) {
                return 'it is inside a value written as an expression or variable';
            }
            if ($node->hasOpaqueKey) {
                return 'it is inside an array built with a spread or a computed key';
            }
            $entry = $node->entryFor($step);
            if ($entry === null) {
                return 'the file does not write it as an entry of its own';
            }
            $node = $entry->value;
        }

        return $node->kind === SourceNode::SCALAR ? null : 'it is written as an expression or variable, not a literal';
    }

    /**
     * Raises the manifest's content version by one with a declarative map
     * shift step, or reports why it must be done by hand.
     *
     * @param list<array{file: string, where: string, reason: string}> $handEdits
     * @return array{source: string, value: array<array-key, mixed>}|null
     */
    private static function planSaveMigration(string $source, mixed $manifest, LineInsertion $insertion, array &$handEdits): ?array
    {
        $version = is_array($manifest) ? ($manifest['contentVersion'] ?? null) : null;
        $migrations = is_array($manifest) ? ($manifest['migrations'] ?? []) : null;
        $step = ['from' => $version, 'to' => is_int($version) ? $version + 1 : null, 'mapShifts' => [$insertion->getManifestEntry()]];
        $reason = null;
        if (! is_int($version) || $version < 0 || ! is_array($migrations) || ! array_is_list($migrations)) {
            $reason = 'its contentVersion or migrations list is not valid, so saves cannot be migrated automatically';
        } else {
            $next = $manifest;
            $next['contentVersion'] = $version + 1;
            $next['migrations'] = [...$migrations, $step];
            try {
                return ['source' => ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($source), $manifest, $next)->source, 'value' => $next];
            } catch (SourceUnreadable|SourcePreservationRefusal $refusal) {
                $reason = rtrim($refusal->getMessage(), '.');
            }
        }
        $handEdits[] = ['file' => self::MANIFEST, 'where' => 'contentVersion and migrations',
            'reason' => sprintf('%s; raise contentVersion by one and add the step %s by hand', $reason, var_export($step, true))];

        return null;
    }

    /**
     * The map's grid files: its terminal layers, event layer and tile layers.
     *
     * @return list<string>
     */
    private static function findGridPaths(string $directory): array
    {
        $baseName = basename($directory);
        $layers = is_dir($directory . '/' . MapLayerSource::DIRECTORY)
            ? (glob($directory . '/' . MapLayerSource::DIRECTORY . '/*.{map,deco}.php', GLOB_BRACE) ?: [])
            : [$directory . '/' . $baseName . '.map.php'];

        return [...$layers, $directory . '/' . $baseName . '.event.php', ...TileLayerSource::findPaths($directory)];
    }

    /**
     * Every data file that can hold a coordinate in a map's space: map data
     * files, the system data, reusable event scripts, cutscene pairs, and
     * the save compatibility manifest.
     *
     * @return list<string>
     */
    private static function findDataPaths(string $root): array
    {
        $paths = [];
        if (is_dir($root . '/assets/Maps')) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/assets/Maps', \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $path = $file->getPathname();
                if ($file->isFile() && basename($path) === basename(dirname($path)) . '.data.php') {
                    $paths[] = $path;
                }
            }
        }
        foreach ([self::SYSTEM, self::MANIFEST] as $relative) {
            if (is_file($root . '/' . $relative)) {
                $paths[] = $root . '/' . $relative;
            }
        }
        array_push($paths, ...(glob($root . '/assets/Events/*.php') ?: []));
        foreach (glob($root . '/assets/Cutscenes/Cinematics/*', GLOB_ONLYDIR) ?: [] as $cutscene) {
            foreach (['data', 'script'] as $member) {
                $path = $cutscene . '/' . basename($cutscene) . '.' . $member . '.php';
                if (is_file($path)) {
                    $paths[] = $path;
                }
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * Evaluates every data file outside the Editor process: together when
     * they load cleanly as one unit, one by one otherwise, so a failure
     * names its file.
     *
     * @param list<string> $paths
     * @return array<string, mixed> Payloads by path.
     */
    private static function evaluate(array $paths, string $root): array
    {
        try {
            return array_combine($paths, PhpDataFile::evaluateIsolatedFiles($paths, $root));
        } catch (Throwable) {
            $payloads = [];
            foreach ($paths as $path) {
                try {
                    $payloads[$path] = PhpDataFile::evaluateIsolated($path, $root);
                } catch (Throwable $failure) {
                    throw new RuntimeException(sprintf('%s could not be read, so the rows or columns cannot be inserted safely: %s',
                        substr($path, strlen($root) + 1), $failure->getMessage()), previous: $failure);
                }
            }

            return $payloads;
        }
    }
}
