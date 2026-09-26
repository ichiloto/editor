<?php

declare(strict_types=1);

use Ichiloto\Editor\Field\MapEncounters;
use Ichiloto\Editor\History\CommandHistory;
use Ichiloto\Editor\History\PaintStrokeCommand;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Field\MapCell;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSource;
use Ichiloto\Engine\IO\Console\TerminalText;

/**
 * Production evidence over disposable copies of Last Legend: the maps that
 * carry hand-authored PHP -- comments, use imports, enum expressions,
 * require-backed scripts, prelude variables -- survive editor edits with
 * everything but the edited node byte-identical, and survive exact
 * restoration byte-for-byte. The real checkout is never written.
 */

/**
 * The sha256 and mtime of every file under the disposable copy's maps.
 *
 * @return array<string, array{0: string, 1: int}>
 */
function mapTreeState(string $root): array
{
    clearstatcache();
    $state = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/assets/Maps', FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $state[substr($file->getPathname(), strlen($root) + 1)] = [
                hash_file('sha256', $file->getPathname()),
                (int) filemtime($file->getPathname()),
            ];
        }
    }

    ksort($state);

    return $state;
}

function createLastLegendMapIntegrityProject(string $prefix): ?string
{
    $root = disposableLastLegend($prefix);
    if (getenv('ICHILOTO_GAME_SRC')) {
        expect($root)->not->toBeNull('ICHILOTO_GAME_SRC is pinned but its Last Legend fixture is unusable');
    }

    return $root;
}

it('opens every Last Legend map, and merely opening, validating and saving clean writes nothing', function () {
    $root = createLastLegendMapIntegrityProject('last-legend-map-integrity-');

    if ($root === null) {
        $this->markTestSkipped('No Last Legend checkout is pinned (ICHILOTO_GAME_SRC).');
    }

    foreach (array_keys(mapTreeState($root)) as $path) {
        touch($root . '/' . $path, 1000000000);
    }

    $before = mapTreeState($root);
    $workspace = ProjectWorkspace::fromProject($root);
    expect(count($workspace->maps))->toBeGreaterThanOrEqual(10);
    $preserved = 0;

    foreach ($workspace->maps as $map) {
        if ($map->dataSourceIssue() === null) {
            $preserved++;
        }

        $map->validateLayerContracts();
        $map->save();
    }

    expect($preserved)->toBe(count($workspace->maps), 'every committed map data source is preservable')
        ->and(mapTreeState($root))->toBe($before, 'no byte and no mtime moved');
});

it('edits one metadata field on the require-and-enum map and restores it byte-for-byte', function () {
    $root = createLastLegendMapIntegrityProject('last-legend-map-edit-');

    if ($root === null) {
        $this->markTestSkipped('No Last Legend checkout is pinned (ICHILOTO_GAME_SRC).');
    }

    $directory = $root . '/assets/Maps/overworld/garden-of-roads';

    expect(is_dir($directory))->toBeTrue('the pinned game must contain Garden of Roads');

    $before = mapTreeState($root);
    $map = ProjectMap::fromDirectory($root . '/assets/Maps', $directory);
    expect($map->dataSourceIssue())->toBeNull();

    // The disposable metadata edit.
    $original = $map->getMapField('description');
    $map->setMapField('description', 'A disposable verification edit.');
    $map->save();
    $after = mapTreeState($root);
    $changed = array_keys(array_filter(
        $after,
        static fn(array $entry, string $path): bool => ($before[$path] ?? null) !== $entry,
        ARRAY_FILTER_USE_BOTH,
    ));

    expect($changed)->toBe(['assets/Maps/overworld/garden-of-roads/garden-of-roads.data.php'], 'one file changed');
    $data = (string) file_get_contents($map->dataPath);
    expect($data)->toContain('use Ichiloto\Engine\Core\Enumerations\MovementHeading;')
        ->and($data)->toContain("'script' => require dirname(__DIR__, 3) . '/Events/garden-crosswind-crownback.php',")
        ->and($data)->toContain('[MovementHeading::NORTH->value]');

    // Exact restoration: the file returns to its committed bytes.
    if ($original === null) {
        $next = $map->getEditableData();
        unset($next['description']);
        // Restoring an absent key exactly.
        $map->setMapDataField(['description'], null);
    } else {
        $map->setMapField('description', $original);
    }

    $map->save();
    $restored = mapTreeState($root);

    foreach ($before as $path => [$hash]) {
        expect($restored[$path][0])->toBe($hash, $path . ' is byte-identical again');
    }
});

it('round-trips Garden BGM and encounters through the accepted map runtime metadata model', function () {
    $root = createLastLegendMapIntegrityProject('last-legend-map-garden-');

    if ($root === null) {
        $this->markTestSkipped('No Last Legend checkout is pinned (ICHILOTO_GAME_SRC).');
    }

    $directory = $root . '/assets/Maps/overworld/garden-of-roads';

    expect(is_dir($directory))->toBeTrue('the pinned game must contain Garden of Roads');

    $map = ProjectMap::fromDirectory($root . '/assets/Maps', $directory);
    $before = (string) file_get_contents($map->dataPath);
    $encounters = MapEncounters::fromMap($map);
    $hadEncounters = $encounters->isDeclared();
    $originalEncounters = $map->getMapDataField([MapEncounters::KEY]);
    $originalBgm = $map->getMapDataField(['bgm']);

    // The accepted model edits the block; the writer lands it surgically.
    $map->setMapDataField(['bgm'], 'overworld-music_moonlit-map-a');
    $withTroop = $encounters->isSupported() && $encounters->rows() !== []
        ? $encounters->withRate($encounters->rate() + 1)
        : MapEncounters::of(null)->withTroopAdded('Bat x 2');
    $map->setMapDataField([MapEncounters::KEY], $withTroop);
    $map->save();
    $edited = (string) file_get_contents($map->dataPath);

    expect($edited)->toContain("'bgm' => 'overworld-music_moonlit-map-a'")
        ->and($edited)->toContain('use Ichiloto\Engine\Core\Enumerations\MovementHeading;')
        ->and($edited)->toContain("require dirname(__DIR__, 3)");

    // And back, exactly.
    $map->setMapDataField(['bgm'], is_string($originalBgm) ? $originalBgm : null);
    $map->setMapDataField([MapEncounters::KEY], $hadEncounters ? $originalEncounters : null);
    $map->save();
    expect((string) file_get_contents($map->dataPath))->toBe($before, 'the committed bytes are back');
});

/** Independently inventory the source tree so read-only or undiscovered maps cannot evade coverage. */
function getIntegrityMaps(string $root, bool $production): array
{
    $files = array_keys(mapTreeState($root));
    $dataFiles = array_values(array_filter($files, static fn(string $path): bool => str_ends_with($path, '.data.php')));
    $gridFiles = array_values(array_filter($files, static fn(string $path): bool => preg_match('/\.(map|deco|event)\.php$/', $path) === 1));
    $workspace = ProjectWorkspace::fromProject($root);
    expect(count($workspace->maps))->toBeGreaterThanOrEqual($production ? 30 : 1);
    $loadedData = [];
    $loadedGrids = [];
    foreach ($workspace->maps as $map) {
        expect($map->getGridSourceIssue())->toBeNull($map->mapId)
            ->and($map->dataSourceIssue())->toBeNull($map->mapId)
            ->and($map->isLegacyMap())->toBeFalse($map->mapId)
            ->and(is_file($map->mapPath))->toBeFalse($map->mapId . ' has no root map grid');
        $map->validateLayerContracts();
        $loadedData[] = substr($map->dataPath, strlen($root) + 1);
        foreach ($map->getLayers() as $layer) {
            $loadedGrids[] = substr($layer['path'], strlen($root) + 1);
        }
    }
    sort($loadedData);
    sort($loadedGrids);
    expect($loadedData)->toBe($dataFiles, 'every authored map was opened')
        ->and($loadedGrids)->toBe($gridFiles, 'every gameplay, decoration and event file was opened');

    return $workspace->maps;
}

/** Prefer an occupied cell, but also exercise intentionally empty layers. */
function findIntegrityCell(ProjectMap $map, string $layer): array
{
    $first = null;
    for ($y = 0; $y < $map->getHeight(); $y++) {
        for ($x = 0; $x < $map->getWidth(); $x++) {
            if ($map->hasLayerCell($layer, $x, $y)) {
                $first ??= [$x, $y];
                if (! MapCell::isBlank($map->getLayerSymbol($layer, $x, $y))) {
                    return [$x, $y];
                }
            }
        }
    }
    expect($first)->not->toBeNull($map->mapId . '/' . $layer . ' must have an editable cell');

    return $first;
}

/** Raw source lines include their exact line endings, indentation and formatter tags. */
function assertIntegrityRowEdit(string $before, string $after, int $row, string $path): void
{
    $bodyLine = null;
    foreach (token_get_all($before, TOKEN_PARSE) as $token) {
        if (is_array($token) && $token[0] === T_START_HEREDOC) {
            $bodyLine = $token[2];
            break;
        }
    }
    expect($bodyLine)->not->toBeNull($path);
    $oldLines = preg_split('/(?<=\n)|(?<=\r)(?!\n)/', $before);
    $newLines = preg_split('/(?<=\n)|(?<=\r)(?!\n)/', $after);
    expect(count($newLines))->toBe(count($oldLines), $path . ' source line count');
    expect($newLines[$bodyLine + $row])->not->toBe($oldLines[$bodyLine + $row], $path . ' edited row');
    $newLines[$bodyLine + $row] = $oldLines[$bodyLine + $row];
    expect($newLines)->toBe($oldLines, $path . ' all other rows and source scaffolding');
}

it('round-trips every authored gameplay decoration and event layer through save undo and redo', function (bool $production) {
    $root = $production ? createLastLegendMapIntegrityProject('last-legend-layer-roundtrip-') : layeredMapProject(true);
    if ($root === null) {
        $this->markTestSkipped('No Last Legend checkout is pinned (ICHILOTO_GAME_SRC).');
    }
    $maps = getIntegrityMaps($root, $production);
    $visited = [];
    foreach ($maps as $map) {
        foreach ($map->getLayers() as $layer) {
            foreach (array_keys(mapTreeState($root)) as $path) {
                touch($root . '/' . $path, 1000000000);
            }
            $before = mapTreeState($root);
            $path = $layer['path'];
            $relativePath = substr($path, strlen($root) + 1);
            $source = (string) file_get_contents($path);
            $widths = array_map(count(...), MapLayer::parseGrid(MapGridSource::readFile($path)));
            [$x, $y] = findIntegrityCell($map, $layer['id']);
            $symbol = $map->getLayerSymbol($layer['id'], $x, $y);
            $style = $map->getLayerCellStyle($layer['id'], $x, $y);
            // Preserve decoration crop keys and event identities; recolouring is still a real edit.
            $painted = $layer['decoration'] || $layer['id'] === 'event' ? $symbol : ($symbol === '##' ? '@@' : '##');
            $prefix = $style['prefix'] === '<fg=#123456>' ? '<fg=#654321>' : '<fg=#123456>';
            $command = new PaintStrokeCommand($map, $layer['id']);
            $command->appendCell($x, $y, $symbol, $painted, $style, ['prefix' => $prefix, 'suffix' => '</>']);
            $history = new CommandHistory();
            $command->execute();
            $history->record($command);
            expect($command->hasChanges())->toBeTrue();
            $map->save();
            $edited = (string) file_get_contents($path);
            assertIntegrityRowEdit($source, $edited, $y, $relativePath);
            expect(array_map(count(...), MapLayer::parseGrid(MapGridSource::readFile($path))))->toBe($widths, $relativePath . ' row widths');
            $reloaded = ProjectDirectoryContext::run($root, fn() => ProjectMap::fromDirectory($root . '/assets/Maps', $map->directory));
            expect($reloaded->getLayerSymbol($layer['id'], $x, $y))->toBe($painted)
                ->and($reloaded->getLayerCellStyle($layer['id'], $x, $y))->toBe(['prefix' => $prefix, 'suffix' => '</>'])
                ->and($reloaded->isDirty())->toBeFalse();
            $after = mapTreeState($root);
            expect(array_keys($after))->toBe(array_keys($before));
            foreach ($before as $file => $state) {
                if ($file !== $relativePath) {
                    expect($after[$file])->toBe($state, $file . ' untouched bytes and mtime');
                }
            }
            expect($after[$relativePath][0])->not->toBe($before[$relativePath][0]);
            expect($history->undo())->toBe($command);
            $map->save();
            expect(file_get_contents($path))->toBe($source, $relativePath . ' undo after save');
            expect($history->redo())->toBe($command);
            $map->save();
            expect(file_get_contents($path))->toBe($edited, $relativePath . ' redo after save');
            $history->undo();
            $map->save();
            $restored = mapTreeState($root);
            foreach ($before as $file => $state) {
                expect($restored[$file][0])->toBe($state[0], $file . ' restored bytes');
                if ($file !== $relativePath) {
                    expect($restored[$file][1])->toBe($state[1], $file . ' untouched mtime after history');
                }
            }
            $clean = mapTreeState($root);
            $map->save();
            expect(mapTreeState($root))->toBe($clean, 'saving the restored clean map writes nothing');
            $visited[] = $relativePath;
        }
    }
    sort($visited);
    expect($visited)->toBe(array_values(array_filter(array_keys(mapTreeState($root)), static fn(string $path): bool => preg_match('/\.(map|deco|event)\.php$/', $path) === 1)));
})->with(['ragged probe' => false, 'Last Legend' => true]);

it('previews every authored map as the Engine composed terminal grid including unsaved edits', function (bool $production) {
    $root = $production ? createLastLegendMapIntegrityProject('last-legend-layer-preview-') : layeredMapProject(true);
    if ($root === null) {
        $this->markTestSkipped('No Last Legend checkout is pinned (ICHILOTO_GAME_SRC).');
    }
    $before = mapTreeState($root);
    foreach (getIntegrityMaps($root, $production) as $map) {
        $composed = MapLayerSource::loadFromDirectory($map->directory, $map->mapId)->getComposedGrid();
        $expected = array_map(static fn(array $row): string => rtrim(implode('', $row)), $composed);
        $snapshot = $map->captureLayerSnapshot();
        $hidden = array_fill_keys(array_column($map->getLayers(), 'id'), false);
        expect($map->renderPreview($map->getWidth(), $map->getHeight(), terminalPreview: true))->toBe($expected, $map->mapId);
        expect($map->renderPreview($map->getWidth(), $map->getHeight(), showNpcOverlay: true, layerVisibility: $hidden, activeLayer: 'event', terminalPreview: true, dimInactive: true))->toBe($expected, 'canvas overlays and visibility do not change terminal output');
        $cropped = array_map(static fn(array $row): string => rtrim(implode('', array_slice($row, 1, 3))), array_slice($composed, 1, 4));
        expect($map->renderPreview(3, 4, 1, 1, terminalPreview: true))->toBe(array_pad($cropped, 4, ''), $map->mapId . ' clipped viewport');
        $gameplay = [];
        foreach ($map->getLayers() as $layer) {
            if ($layer['decoration'] || $layer['id'] === 'event') {
                [$x, $y] = findIntegrityCell($map, $layer['id']);
                $map->setLayerCell($layer['id'], $x, $y, '!', '<fg=red>', '</>');
                expect($map->renderPreview($map->getWidth(), $map->getHeight(), terminalPreview: true))->toBe($expected, $layer['path'] . ' is terminal-invisible');
            } else {
                $gameplay[] = $layer['id'];
            }
        }
        $top = $gameplay[array_key_last($gameplay)];
        [$x, $y] = findIntegrityCell($map, $top);
        $symbol = TerminalText::stripAnsi($composed[$y][$x]) === '##' ? '@@' : '##';
        $map->setLayerCell($top, $x, $y, $symbol, '<fg=cyan>', '</>');
        $composed[$y][$x] = MapCell::parseRow('<fg=cyan>' . $symbol . '</>')[0];
        $edited = array_map(static fn(array $row): string => rtrim(implode('', $row)), $composed);
        expect($edited)->not->toBe($expected)
            ->and($map->renderPreview($map->getWidth(), $map->getHeight(), terminalPreview: true))->toBe($edited, 'unsaved topmost gameplay edit is visible');
        $map->restoreLayerSnapshot($snapshot);
        expect($map->renderPreview($map->getWidth(), $map->getHeight(), terminalPreview: true))->toBe($expected);
    }
    expect(mapTreeState($root))->toBe($before, 'preview never rewrites authored files');
})->with(['ragged probe' => false, 'Last Legend' => true]);

it('refuses executable source in every authored layer before evaluation save duplicate or move', function (bool $production) {
    $root = $production ? createLastLegendMapIntegrityProject('last-legend-layer-refusal-') : layeredMapProject(true);
    if ($root === null) {
        $this->markTestSkipped('No Last Legend checkout is pinned (ICHILOTO_GAME_SRC).');
    }
    foreach (getIntegrityMaps($root, $production) as $map) {
        $data = (string) file_get_contents($map->dataPath);
        $dataMtime = filemtime($map->dataPath);
        file_put_contents($map->dataPath, "<?php\nfile_put_contents(" . var_export($root . '/data-executed', true) . ", 'yes');\nreturn [];\n");
        try {
            foreach ($map->getLayers() as $layer) {
                $path = $layer['path'];
                $source = (string) file_get_contents($path);
                $mtime = filemtime($path);
                $injected = "file_put_contents(" . var_export($root . '/grid-executed', true) . ", 'yes');\n";
                file_put_contents($path, preg_replace('/<\?php\s*/', "<?php\n" . $injected, $source, 1));
                try {
                    $poisoned = mapTreeState($root);
                    $load = fn() => ProjectMap::fromDirectory($root . '/assets/Maps', $map->directory);
                    expect($load)->toThrow(MapSourceRefusal::class, basename($path))
                        ->and($load)->toThrow(MapSourceRefusal::class, 'T_STRING at line 2');
                    foreach ([
                        fn() => $map->save(),
                        fn() => $map->duplicateTo($root . '/assets/Maps/refused-copy', 'refused-copy', 'Refused Copy'),
                        fn() => $map->moveTo('refused-move'),
                    ] as $action) {
                        expect($action)->toThrow(MapSourceRefusal::class, basename($path));
                        expect(mapTreeState($root))->toBe($poisoned, $path . ' refusal leaves all bytes and mtimes untouched');
                    }
                    expect(is_file($root . '/grid-executed'))->toBeFalse()
                        ->and(is_file($root . '/data-executed'))->toBeFalse()
                        ->and(is_dir($root . '/assets/Maps/refused-copy'))->toBeFalse()
                        ->and(is_dir($root . '/assets/Maps/refused-move'))->toBeFalse();
                } finally {
                    file_put_contents($path, $source);
                    touch($path, $mtime);
                }
            }
        } finally {
            file_put_contents($map->dataPath, $data);
            touch($map->dataPath, $dataMtime);
        }
    }
})->with(['ragged probe' => false, 'Last Legend' => true]);
