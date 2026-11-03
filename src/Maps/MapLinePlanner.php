<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourceNode;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Cutscenes\Source\SourceUnreadable;
use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\Progression\ProjectWorldAbilities;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Storage\SourceSetPlan;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayerSource;
use Ichiloto\Engine\Field\MapPhysicalOccupancy;
use Ichiloto\Engine\Field\Reachability\ProjectReachability;
use Ichiloto\Engine\Field\Reachability\MapReachabilityReport;
use Ichiloto\Engine\Core\Rect;
use RuntimeException;
use Throwable;

/**
 * Plans inserting blank rows or columns into a map, or removing them, so the
 * whole project stays consistent: the map's grids grow or shrink, and every
 * coordinate in its space at or beyond the line moves, in whichever file
 * holds it ({@see MapLineInventory}). Every changed file is rewritten through
 * the source-preserving writers; a coordinate the source cannot take as a
 * literal is reported for a hand edit instead of being flattened. A removal
 * is refused while anything authored stands on a removed line. When the
 * project has a save compatibility manifest, the plan raises its content
 * version with a declarative map edit step, so existing saves follow; saves
 * standing on removed lines land on a cell the author confirms.
 *
 * Planning reads the project from disk and writes nothing.
 *
 * @package Ichiloto\Editor\Maps
 */
final class MapLinePlanner
{
    private const string MANIFEST = SaveManifestStep::PATH;
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
    public static function planInsertion(string $projectRoot, string $mapId, string $axis, int $at, int $count): MapLinePlan
    {
        $insertion = new LineInsertion($mapId, $axis, $at, $count);

        return ProjectDirectoryContext::run($projectRoot, static fn(string $root): MapLinePlan => self::buildPlan($root, $insertion));
    }

    /**
     * Plans the removal; callers show the plan, including where saves on the
     * removed lines land, and obtain confirmation before applying it.
     *
     * @param array{int, int}|null $landing Where saves on the removed lines land, after the removal; null proposes the reachable cell nearest them.
     * @throws RuntimeException When the map or a data file cannot be read, something authored stands on a removed line, or the landing is unusable; nothing is written.
     */
    public static function planRemoval(string $projectRoot, string $mapId, string $axis, int $at, int $count, ?array $landing = null): MapLinePlan
    {
        $removal = new LineRemoval($mapId, $axis, $at, $count, $landing);

        return ProjectDirectoryContext::run($projectRoot, static fn(string $root): MapLinePlan => self::buildPlan($root, $removal));
    }

    /**
     * Where the player can reach in the project as saved, with the project's current world abilities installed.
     *
     * @throws RuntimeException When the project's world abilities cannot be read.
     */
    private static function analyzeReachability(string $root): ProjectReachability
    {
        ProjectWorldAbilities::installFromProject($root)->assertReadable();

        return ProjectReachability::analyze($root . '/assets');
    }

    private static function buildPlan(string $root, MapLineEdit $edit): MapLinePlan
    {
        $directory = $root . '/assets/Maps/' . $edit->mapId;
        $map = ProjectMap::fromDirectory($root . '/assets/Maps', $directory);
        [$width, $height] = [$map->getWidth(), $map->getHeight()];
        // Relative points are judged against where their subjects stand before the edit moves them.
        $subjects = MapSubjectCells::readMap($map);
        $edit instanceof LineRemoval
            ? $map->removeLines($edit->axis, $edit->at, $edit->count)
            : $map->insertLines($edit->axis, $edit->at, $edit->count);

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
        $inventory = new MapLineInventory($edit, $commonEvents, $subjects);
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

        $blocked = [];
        foreach ($inventory->getBlocked() as $path => $entries) {
            foreach ($entries as $entry) {
                $blocked[] = sprintf('%s %s (%s)', $relative($path), PhpArraySourceDocument::describePath($entry['path']), $entry['reason']);
            }
        }
        if ($blocked !== []) {
            throw new RuntimeException(sprintf('%s %s is refused; nothing was changed. Move these off the removed lines first: %s.',
                $edit->verb, $edit->description, implode('; ', $blocked)));
        }

        $handEdits = [];
        foreach ($inventory->getHandEdits() as $path => $edits) {
            foreach ($edits as $handEdit) {
                $handEdits[] = ['file' => $relative($path), 'where' => PhpArraySourceDocument::describePath($handEdit['path']), 'reason' => $handEdit['reason']];
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
        $report = array_key_exists($manifestPath, $payloads) ? self::analyzeReachability($root)->reports[$edit->mapId] ?? null : null;
        if (! array_key_exists($manifestPath, $payloads)) {
            $notes[] = sprintf('Existing saves are not migrated: the project has no %s.', self::MANIFEST);
        } elseif (! ($report?->isAnyReachable(new Rect(0, 0, max(1, $width), max(1, $height))) ?? false)) {
            // As for save relocations: a map the player reaches nowhere today, such as one not yet joined to the
            // world, holds no save to move.
            $notes[] = 'No save moves: the player reaches this map nowhere today.';
        } else {
            if ($edit instanceof LineRemoval) {
                $edit = $edit->withLanding(self::findLanding($report, $edit, $width, $height));
                $notes[] = sprintf('Saves standing on the removed lines land at x %d, y %d.', ...$edit->landing);
            }
            $next = self::planSaveMigration($watched[$manifestPath], $payloads[$manifestPath], $edit, $handEdits);
            if ($next !== null) {
                $originals[$manifestPath] = $watched[$manifestPath];
                $proposals[$manifestPath] = $next['source'];
                $before[$manifestPath] = PhpDataFile::getComparableValue($payloads[$manifestPath]);
                $after[$manifestPath] = PhpDataFile::getComparableValue($next['value']);
                $notes[] = sprintf('Saves move with the map: save content version %d adds this map %s.', $next['value']['contentVersion'],
                    $edit instanceof LineRemoval ? 'removal' : 'shift');
            }
        }

        $sources = new SourceSetPlan($root, $watched, $originals, $proposals, $before, $after,
            static fn(): array => [...self::findGridPaths($directory), ...self::findDataPaths($root)],
            static fn(string $path, string $staged): mixed => in_array($path, $gridPaths, true)
                ? MapGridSource::parseSource((string) file_get_contents($staged), $path)
                : PhpDataFile::getComparableValue(PhpDataFile::evaluateIsolated($staged, $root)),
            'map line ' . ($edit instanceof LineRemoval ? 'removal' : 'insertion'),
            'Map and project data files',
        );

        return new MapLinePlan($edit, $sources, $handEdits, $notes, $map->getWidth(), $map->getHeight());
    }

    /**
     * Where saves standing on the removed lines land, in the map's
     * coordinates after the removal: the author's cell when given, which
     * must be one the player can reach today off the removed lines, or else
     * the reachable cell nearest the reachable part of the removed lines.
     *
     * @return array{int, int}
     * @throws RuntimeException When no reachable cell is left, or the given landing is not one.
     */
    private static function findLanding(?MapReachabilityReport $report, LineRemoval $removal, int $width, int $height): array
    {
        $vertical = $removal->axis === 'y';
        // Today's cell for a cell after the removal: lines from the removed ones on sit further along by the count.
        $toToday = static fn(int $x, int $y): array => $vertical
            ? [$x, $y >= $removal->at ? $y + $removal->count : $y]
            : [$x >= $removal->at ? $x + $removal->count : $x, $y];
        if ($removal->landing !== null) {
            [$x, $y] = $toToday(...$removal->landing);
            if ($x >= $width || $y >= $height || ! ($report?->isReachable($x, $y) ?? false)) {
                throw new RuntimeException(sprintf('Saves cannot land at x %d, y %d: the player cannot reach that cell. Choose a reachable cell off the removed lines.',
                    ...$removal->landing));
            }

            return $removal->landing;
        }
        $kept = $removed = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if (! ($report?->isReachable($x, $y) ?? false)) {
                    continue;
                }
                if ($removal->isRemoved($vertical ? $y : $x)) {
                    $removed[] = [$x, $y];
                } else {
                    $kept[] = [$x, $y];
                }
            }
        }
        if ($kept === []) {
            throw new RuntimeException(sprintf('%s leaves no cell the player can reach, so saves on it have nowhere to land; nothing was changed.', $removal->mapId));
        }
        // Nearest the middle of where a saved player could stand on the removed lines, or of the lines themselves.
        $middle = $removed !== []
            ? [array_sum(array_column($removed, 0)) / count($removed), array_sum(array_column($removed, 1)) / count($removed)]
            : ($vertical ? [($width - 1) / 2, $removal->at + ($removal->count - 1) / 2] : [$removal->at + ($removal->count - 1) / 2, ($height - 1) / 2]);
        usort($kept, static fn(array $a, array $b): int => [abs($a[0] - $middle[0]) + abs($a[1] - $middle[1]), $a[1], $a[0]]
            <=> [abs($b[0] - $middle[0]) + abs($b[1] - $middle[1]), $b[1], $b[0]]);
        [$x, $y] = $kept[0];

        return $vertical ? [$x, $y >= $removal->at ? $y - $removal->count : $y] : [$x >= $removal->at ? $x - $removal->count : $x, $y];
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
     * edit step, or reports why it must be done by hand.
     *
     * @param list<array{file: string, where: string, reason: string}> $handEdits
     * @return array{source: string, value: array<array-key, mixed>}|null
     */
    private static function planSaveMigration(string $source, mixed $manifest, MapLineEdit $edit, array &$handEdits): ?array
    {
        $next = SaveManifestStep::append($source, $manifest, $edit->getManifestEdits());
        if (is_array($next)) {
            return $next;
        }
        $handEdits[] = ['file' => self::MANIFEST, 'where' => 'contentVersion and migrations',
            'reason' => sprintf('%s; raise contentVersion by one and add the step %s by hand', $next,
                var_export(SaveManifestStep::describe($manifest, $edit->getManifestEdits()), true))];

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
                    throw new RuntimeException(sprintf('%s could not be read, so the rows or columns cannot be changed safely: %s',
                        substr($path, strlen($root) + 1), $failure->getMessage()), previous: $failure);
                }
            }

            return $payloads;
        }
    }
}
