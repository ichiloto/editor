<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\PhpValueExporter;
use Ichiloto\Editor\History\CommandHistory;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\Maps\LineInsertionPlanner;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Storage\FileSetTransactionFailure;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapGridSource;

/** Synthetic physical cells deliberately disagree with the presentation glyphs. */
function createOccupancyGeometryProject(bool $ragged = false, bool $legacy = false): string
{
    $root = $legacy ? makeTemporaryProject('editor-physical-geometry-') : mapGraphicsProject($ragged);
    $directory = $root . '/assets/Maps/test-map';
    if ($legacy) {
        file_put_contents($directory . '/test-map.map.php', MapGridSource::buildSource("####\n" . ($ragged ? '..' : '....'), 'GROUND'));
        file_put_contents($directory . '/test-map.event.php', MapGridSource::buildSource("   E\n" . ($ragged ? '  ' : '    '), 'EVENTS'));
    }
    $rows = [
        [CollisionType::NONE, CollisionType::COUNTER, CollisionType::EXIT, CollisionType::SOLID],
        $ragged ? [CollisionType::SAVE_POINT, CollisionType::ENCOUNTER]
            : [CollisionType::SAVE_POINT, CollisionType::ENCOUNTER, CollisionType::COLLECTABLE, CollisionType::ITEM],
    ];
    writeGeometryOccupancyData($directory, $rows, $legacy);
    return $root;
}

/** Keeps unrelated authored expressions and comments around a literal declaration. */
function writeGeometryOccupancyData(string $directory, mixed $rows, bool $legacy = false, ?string $expression = null): void
{
    $declaration = $expression ?? PhpValueExporter::export($rows, 1);
    $tileset = $legacy ? '' : "    'tileset' => 'home',\n";
    file_put_contents($directory . '/test-map.data.php', "<?php\n"
        . "// Keep the original metadata and physical declaration source.\n"
        . "\$note = 'retained reference';\nreturn [\n"
        . "    'name' => 'Synthetic geometry',\n    'region' => '',\n"
        . "    'description' => strtoupper('authored'),\n    'note' => \$note,\n"
        . $tileset . "    'events' => [],\n    // Physical ground, not terminal art.\n"
        . "    'occupancy' => {$declaration},\n];\n");
}

function loadOccupancyGeometryMap(string $root): ProjectMap
{
    return ProjectMap::fromDirectory($root . '/assets/Maps', $root . '/assets/Maps/test-map');
}

it('resizes physical cells with terminal event and graphical geometry and pads only with solid cells', function (bool $legacy, bool $ragged) {
    $root = createOccupancyGeometryProject($ragged, $legacy);
    $map = loadOccupancyGeometryMap($root);
    $original = $map->getResolvedCollisionMap();
    $source = (string) file_get_contents($map->dataPath);
    $map->resize(6, 3);
    $expected = array_map(static fn(array $row): array => array_pad($row, 6, CollisionType::SOLID->value), $original);
    $expected[] = array_fill(0, 6, CollisionType::SOLID->value);
    expect($map->getResolvedCollisionMap())->toBe($expected)
        ->and($map->getTileSymbol(5, 2))->toBe(' ')
        ->and($map->getEventMarkerAt(5, 2))->toBeNull()
        ->and(file_get_contents($map->dataPath))->toBe($source);
    $map->save();
    expect(loadOccupancyGeometryMap($root)->getResolvedCollisionMap())->toBe($expected)
        ->and(file_get_contents($map->dataPath))->toContain("strtoupper('authored')", "'note' => \$note", '// Physical ground, not terminal art.');
    if (! $legacy) {
        expect(readTileRows($map->directory . '/graphics/01.floor.tiles.php')[2])->toBe(array_fill(0, 6, 0));
    }
    $map->resize(2, 1);
    $map->save();
    expect(loadOccupancyGeometryMap($root)->getResolvedCollisionMap())->toBe([array_slice($original[0], 0, 2)]);
})->with([false, true])->with([false, true]);

it('inserts physical cells at the same line as presentation cells including ragged row and column edges', function (bool $legacy, bool $ragged, string $axis, int $at) {
    $root = createOccupancyGeometryProject($ragged, $legacy);
    $map = loadOccupancyGeometryMap($root);
    $expected = $map->getResolvedCollisionMap();
    if ($axis === 'y') {
        $beside = $expected[$at] ?? $expected[$at - 1];
        array_splice($expected, $at, 0, array_fill(0, 2, array_fill(0, count($beside), CollisionType::SOLID->value)));
    } else {
        foreach ($expected as &$row) {
            if (count($row) >= $at) {
                array_splice($row, $at, 0, array_fill(0, 2, CollisionType::SOLID->value));
            }
        }
        unset($row);
    }
    $before = $map->captureGridSnapshot();
    $map->insertLines($axis, $at, 2);
    $after = $map->captureGridSnapshot();
    expect($map->getResolvedCollisionMap())->toBe($expected)
        ->and(array_map(count(...), $map->getLayerSet()->getComposedGrid()))->toBe(array_map(count(...), $expected));
    $map->save();
    expect(loadOccupancyGeometryMap($root)->getResolvedCollisionMap())->toBe($expected);
    $map->restoreGridSnapshot($before);
    $map->save();
    expect($map->getMapDataField(['occupancy']))->toBe($before['occupancy']);
    $map->restoreGridSnapshot($after);
    $map->save();
    expect(loadOccupancyGeometryMap($root)->getResolvedCollisionMap())->toBe($expected);
})->with([false, true])->with([false, true])->with([
    'first column' => ['x', 0], 'middle column' => ['x', 1], 'ragged boundary' => ['x', 2], 'last column' => ['x', 4],
    'first row' => ['y', 0], 'middle row' => ['y', 1], 'last row' => ['y', 2],
]);

it('keeps resize history meaningful across save undo redo and unrelated metadata edits', function () {
    $root = createOccupancyGeometryProject();
    $map = loadOccupancyGeometryMap($root);
    $originals = sourceHashTree($map->directory);
    $before = $map->captureGridSnapshot();
    $map->resize(6, 3);
    $after = $map->captureGridSnapshot();
    $history = new CommandHistory();
    $history->record(new GenericCommand('Resize', fn() => $map->restoreGridSnapshot($after), fn() => $map->restoreGridSnapshot($before)));
    $map->save();
    $resized = sourceHashTree($map->directory);
    expect($map->isDirty())->toBeFalse();
    $history->undo();
    expect($map->isDirty())->toBeTrue()->and($map->getMapDataField(['occupancy']))->toBe($before['occupancy']);
    $map->save();
    expect(sourceHashTree($map->directory))->toBe($originals);
    $history->redo();
    $map->save();
    expect(sourceHashTree($map->directory))->toBe($resized)->and($map->isDirty())->toBeFalse();
    $map->setMapField('name', 'Later metadata');
    $history->undo();
    expect($map->getDisplayName())->toBe('Later metadata')->and($map->getMapDataField(['occupancy']))->toBe($before['occupancy']);
});

it('carries physical geometry through the existing inspector resize command', function () {
    $root = createOccupancyGeometryProject();
    [$editor, $map] = layeredCanvasEditor($root);
    $before = sourceHashTree($map->directory);
    $physical = $map->getResolvedCollisionMap();
    callEditorMethod($editor, 'setEditingMode', 'map');
    callEditorMethod($editor, 'applyInspectorFieldValue', ['label' => '  X', 'value' => '4', 'target' => 'map-size', 'field' => 'width'], '6');
    expect($map->getResolvedCollisionMap()[0])->toBe([...$physical[0], CollisionType::SOLID->value, CollisionType::SOLID->value]);
    $map->save();
    callEditorMethod($editor, 'performUndo');
    $map->save();
    expect(sourceHashTree($map->directory))->toBe($before)->and($map->getResolvedCollisionMap())->toBe($physical);
    callEditorMethod($editor, 'performRedo');
    expect($map->getResolvedCollisionMap()[0])->toBe([...$physical[0], CollisionType::SOLID->value, CollisionType::SOLID->value]);
});

it('does not add physical declarations during legacy geometry edits or snapshot restoration', function (string $operation) {
    $root = mapGraphicsProject(ragged: true);
    $map = loadLayeredMap($root);
    $source = file_get_contents($map->dataPath);
    $before = $map->captureGridSnapshot();
    if ($operation === 'resize') {
        $map->resize(5, 3);
    } else {
        $map->insertLines($operation, 1, 1);
    }
    $map->save();
    expect($map->hasMapDataField(['occupancy']))->toBeFalse()->and(file_get_contents($map->dataPath))->toBe($source);
    $map->restoreGridSnapshot($before);
    $map->save();
    expect($map->hasMapDataField(['occupancy']))->toBeFalse()->and(file_get_contents($map->dataPath))->toBe($source);
})->with(['resize', 'x', 'y']);

it('refuses malformed declared physical data before grid state or history changes', function (mixed $rows, string $operation) {
    $root = createOccupancyGeometryProject();
    $directory = $root . '/assets/Maps/test-map';
    writeGeometryOccupancyData($directory, $rows);
    $map = loadOccupancyGeometryMap($root);
    $data = $map->getMapDataField([]);
    $grids = $map->getGridSources();
    $version = $map->getContentVersion();
    $hashes = sourceHashTree($root);
    $history = new CommandHistory();
    $apply = match ($operation) {
        'resize' => fn() => $map->resize(6, 3),
        'no-op resize' => fn() => $map->resize(4, 2),
        'snapshot' => fn() => $map->captureGridSnapshot(),
        default => fn() => $map->insertLines($operation, 1, 1),
    };
    expect(function () use ($apply, $history): void {
        $apply();
        $history->record(new GenericCommand('Geometry', fn() => null, fn() => null));
    })->toThrow(MapSourceRefusal::class, 'occupancy');
    expect($map->getGridSources())->toBe($grids)->and($map->getMapDataField([]))->toBe($data)
        ->and($map->getContentVersion())->toBe($version)->and($history->count())->toBe(0)
        ->and($map->isDirty())->toBeFalse()->and(sourceHashTree($root))->toBe($hashes);
})->with([
    'declared null' => [null], 'non-list' => [['one' => []]], 'missing rows' => [[]],
    'short row' => [[[CollisionType::NONE], array_fill(0, 4, CollisionType::NONE)]],
    'integer cells' => [array_fill(0, 2, array_fill(0, 4, 0))],
    'unresolved passage' => [array_fill(0, 2, array_fill(0, 4, CollisionType::PASS_THROUGH))],
])->with(['resize', 'no-op resize', 'x', 'y', 'snapshot']);

it('refuses computed physical source edits before mutation rather than flattening them', function (string $operation, bool $wholeSource) {
    $root = createOccupancyGeometryProject();
    $directory = $root . '/assets/Maps/test-map';
    $rows = loadOccupancyGeometryMap($root)->getMapDataField(['occupancy']);
    writeGeometryOccupancyData($directory, $rows, expression: 'array_values(' . PhpValueExporter::export($rows, 1) . ')');
    if ($wholeSource) {
        $path = $directory . '/test-map.data.php';
        file_put_contents($path, str_replace('return [', 'return array_merge([', str_replace("];\n", "]);\n", (string) file_get_contents($path))));
    }
    $map = loadOccupancyGeometryMap($root);
    $before = $map->captureGridSnapshot();
    $version = $map->getContentVersion();
    $hashes = sourceHashTree($root);
    expect(fn() => $operation === 'resize' ? $map->resize(6, 3) : $map->insertLines($operation, 1, 1))
        ->toThrow(MapSourceRefusal::class);
    expect($map->captureGridSnapshot())->toBe($before)->and($map->getContentVersion())->toBe($version)
        ->and($map->isDirty())->toBeFalse()->and(sourceHashTree($root))->toBe($hashes);
})->with(['resize', 'x', 'y'])->with([false, true]);

it('refuses unsafe occupancy snapshots before changing grids or undo stacks', function (string $failure) {
    $root = createOccupancyGeometryProject();
    $map = loadOccupancyGeometryMap($root);
    $before = $map->captureGridSnapshot();
    $map->resize(6, 3);
    $after = $map->captureGridSnapshot();
    $bad = $before;
    if ($failure === 'old snapshot') {
        unset($bad['occupancy']);
    } elseif ($failure === 'non-array snapshot') {
        $bad['occupancy'] = 'not physical rows';
    } elseif ($failure === 'wrong geometry') {
        $bad['occupancy'] = $after['occupancy'];
    } else {
        $bad['occupancy'][0][0] = CollisionType::PASS_THROUGH;
    }
    $history = new CommandHistory();
    $history->record(new GenericCommand('Resize', fn() => $map->restoreGridSnapshot($after), fn() => $map->restoreGridSnapshot($bad)));
    $version = $map->getContentVersion();
    $hashes = sourceHashTree($root);
    expect(fn() => $history->undo())->toThrow(MapSourceRefusal::class);
    expect($map->captureGridSnapshot())->toBe($after)->and($map->getContentVersion())->toBe($version)
        ->and($history->count())->toBe(1)->and($history->canRedo())->toBeFalse()->and(sourceHashTree($root))->toBe($hashes);
})->with(['old snapshot', 'malformed snapshot', 'non-array snapshot', 'wrong geometry']);

it('retains no-op and older snapshot compatibility when physical state is already valid', function (bool $declared) {
    $root = $declared ? createOccupancyGeometryProject() : mapGraphicsProject();
    $map = loadOccupancyGeometryMap($root);
    $snapshot = $map->captureGridSnapshot();
    $version = $map->getContentVersion();
    $hashes = sourceHashTree($root);
    $map->resize(4, 2);
    expect($map->captureGridSnapshot())->toBe($snapshot)->and($map->getContentVersion())->toBe($version)
        ->and($map->isDirty())->toBeFalse()->and(sourceHashTree($root))->toBe($hashes);
    if (! $declared) {
        unset($snapshot['occupancy']);
        $map->resize(6, 3);
        $map->restoreGridSnapshot($snapshot);
        expect($map->isDirty())->toBeFalse()->and($map->hasMapDataField(['occupancy']))->toBeFalse();
    }
})->with([false, true]);

it('refuses external source changes before geometry or history mutation', function (string $operation) {
    $root = createOccupancyGeometryProject();
    $map = loadOccupancyGeometryMap($root);
    $before = $map->captureGridSnapshot();
    $version = $map->getContentVersion();
    file_put_contents($map->dataPath, (string) file_get_contents($map->dataPath) . "// External author edit.\n");
    $hashes = sourceHashTree($root);
    $history = new CommandHistory();
    $apply = match ($operation) {
        'resize' => fn() => $map->resize(6, 3),
        'restore' => fn() => $map->restoreGridSnapshot($before),
        default => fn() => $map->insertLines($operation, 1, 1),
    };
    $history->record(new GenericCommand('Geometry', fn() => null, $apply));
    expect(fn() => $history->undo())->toThrow(MapSourceRefusal::class, 'changed after opening');
    expect($map->captureGridSnapshot())->toBe($before)->and($map->getContentVersion())->toBe($version)
        ->and($history->count())->toBe(1)->and($history->canRedo())->toBeFalse()->and(sourceHashTree($root))->toBe($hashes);
})->with(['resize', 'x', 'y', 'restore']);

it('keeps declared physical data untouched when graphical geometry preflight refuses', function (string $operation) {
    $root = createOccupancyGeometryProject();
    writeTileLayer($root . '/assets/Maps/test-map', '02.decor.tiles.php', "0 bad 0 0\n0 0 0 5");
    $map = loadOccupancyGeometryMap($root);
    $before = $map->captureGridSnapshot();
    $version = $map->getContentVersion();
    $hashes = sourceHashTree($root);
    expect(fn() => $operation === 'resize' ? $map->resize(6, 3) : $map->insertLines($operation, 1, 1))
        ->toThrow(MapSourceRefusal::class, '02.decor.tiles.php');
    expect($map->captureGridSnapshot())->toBe($before)->and($map->getContentVersion())->toBe($version)
        ->and($map->isDirty())->toBeFalse()->and(sourceHashTree($root))->toBe($hashes);
})->with(['resize', 'x', 'y']);

it('rolls back physical data and every grid together when geometry save installation fails', function (string $operation) {
    $root = createOccupancyGeometryProject();
    $map = loadOccupancyGeometryMap($root);
    $hashes = sourceHashTree($map->directory);
    if ($operation === 'resize') {
        $map->resize(6, 3);
    } else {
        $map->insertLines($operation, 1, 1);
    }
    $physical = $map->getResolvedCollisionMap();
    $failure = null;
    try {
        $map->save(files: new FailingFileSetOperations(failures: ['move' => [$map->eventPath]]));
    } catch (FileSetTransactionFailure $thrown) {
        $failure = $thrown;
    }
    expect($failure)->not->toBeNull()->and($failure->wasRolledBack)->toBeTrue()
        ->and(sourceHashTree($map->directory))->toBe($hashes)->and($map->getResolvedCollisionMap())->toBe($physical)
        ->and($map->isDirty())->toBeTrue();
    $map->save();
    expect(loadOccupancyGeometryMap($root)->getResolvedCollisionMap())->toBe($physical)->and($map->isDirty())->toBeFalse();
})->with(['resize', 'x', 'y']);

it('includes physical geometry in the project insertion source set with coordinate edits and exact undo', function (string $axis, bool $withCoordinates) {
    $root = createOccupancyGeometryProject();
    $directory = $root . '/assets/Maps/test-map';
    $path = $directory . '/test-map.data.php';
    if ($withCoordinates) {
        file_put_contents($path, str_replace("'events' => [],", "'npcs' => [['id' => 'visitor', 'name' => 'Synthetic visitor', 'sprite' => 'v', 'x' => 2, 'y' => 1]],\n"
            . "    'events' => ['D' => ['class' => 'Ichiloto\\Engine\\Events\\Triggers\\SleepEventTrigger',\n"
            . "        'data' => ['spawnPoint' => ['x' => 2, 'y' => 1]]]],", (string) file_get_contents($path)));
    }
    $original = loadOccupancyGeometryMap($root);
    $before = sourceHashTree($root);
    $originalPhysical = $original->getResolvedCollisionMap();
    $expected = $originalPhysical;
    if ($axis === 'x') {
        foreach ($expected as &$row) {
            array_splice($row, 1, 0, [CollisionType::SOLID->value, CollisionType::SOLID->value]);
        }
        unset($row);
    } else {
        array_splice($expected, 1, 0, array_fill(0, 2, array_fill(0, 4, CollisionType::SOLID->value)));
    }
    $plan = LineInsertionPlanner::planProject($root, 'test-map', $axis, 1, 2);
    expect(sourceHashTree($root))->toBe($before)->and($plan->getChangedPaths())->toContain($path);
    $plan->apply();
    $after = sourceHashTree($root);
    $map = loadOccupancyGeometryMap($root);
    expect($map->getResolvedCollisionMap())->toBe($expected)
        ->and(file_get_contents($path))->toContain("strtoupper('authored')", "'note' => \$note", '// Physical ground, not terminal art.');
    if ($withCoordinates) {
        $point = $axis === 'x' ? ['x' => 4, 'y' => 1] : ['x' => 2, 'y' => 3];
        expect(['x' => $map->getMapDataField(['npcs', 0, 'x']), 'y' => $map->getMapDataField(['npcs', 0, 'y'])])->toBe($point)
            ->and($map->getEventField('D', ['data', 'spawnPoint']))->toBe($point);
    }
    $plan->revert();
    expect(sourceHashTree($root))->toBe($before)->and(loadOccupancyGeometryMap($root)->getResolvedCollisionMap())->toBe($originalPhysical);
    $plan->apply();
    expect(sourceHashTree($root))->toBe($after)->and(loadOccupancyGeometryMap($root)->getResolvedCollisionMap())->toBe($expected);
})->with(['x', 'y'])->with([false, true]);

it('refuses a project insertion with malformed or unrewritable occupancy before proposing any writes', function (bool $malformed) {
    $root = createOccupancyGeometryProject();
    $map = loadOccupancyGeometryMap($root);
    $rows = $map->getMapDataField(['occupancy']);
    writeGeometryOccupancyData($map->directory, $malformed ? null : $rows,
        expression: $malformed ? null : 'array_values(' . PhpValueExporter::export($rows, 1) . ')');
    $before = sourceHashTree($root);
    expect(fn() => LineInsertionPlanner::planProject($root, 'test-map', 'y', 1, 1))->toThrow(MapSourceRefusal::class)
        ->and(sourceHashTree($root))->toBe($before);
})->with([false, true]);

it('preserves unrelated object expressions in isolated insertion readback with and without declared occupancy', function (bool $declared) {
    $root = $declared ? createOccupancyGeometryProject() : mapGraphicsProject();
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($path, str_replace("'events' => [],", "'events' => [], 'futureObject' => new \\DateTimeImmutable('2001-02-03'),",
        (string) file_get_contents($path)));
    $before = sourceHashTree($root);
    $plan = LineInsertionPlanner::planProject($root, 'test-map', 'x', 1, 1);
    $plan->apply();
    $map = loadOccupancyGeometryMap($root);
    expect($map->hasMapDataField(['occupancy']))->toBe($declared)
        ->and($map->getMapDataField(['futureObject'])->format('Y-m-d'))->toBe('2001-02-03')
        ->and(file_get_contents($path))->toContain("new \\DateTimeImmutable('2001-02-03')");
    if ($declared) {
        expect(array_map(count(...), $map->getResolvedCollisionMap()))->toBe([5, 5]);
    }
    $plan->revert();
    expect(sourceHashTree($root))->toBe($before);
})->with([false, true]);

it('retains physical geometry in the existing revisioned insertion command and its undo redo', function (string $axis) {
    $root = createOccupancyGeometryProject();
    $session = EditorSession::open($root);
    $read = $session->readMap('test-map');
    $before = sourceHashTree($root);
    $physical = loadOccupancyGeometryMap($root)->getResolvedCollisionMap();
    $question = $session->insertMapLines('test-map', $read['revision'], $axis, 1, 2);
    expect($question['paths'])->toContain('assets/Maps/test-map/test-map.data.php')
        ->and(sourceHashTree($root))->toBe($before);
    $session->insertMapLines('test-map', $read['revision'], $axis, 1, 2, 'write', $question['confirm']);
    $after = sourceHashTree($root);
    $inserted = loadOccupancyGeometryMap($root)->getResolvedCollisionMap();
    expect($inserted)->not->toBe($physical)->and($session->readMap('test-map')['physicalOccupancy'])->toBeTrue();
    $session->undo();
    expect(sourceHashTree($root))->toBe($before)->and(loadOccupancyGeometryMap($root)->getResolvedCollisionMap())->toBe($physical);
    $session->redo();
    expect(sourceHashTree($root))->toBe($after)->and(loadOccupancyGeometryMap($root)->getResolvedCollisionMap())->toBe($inserted);
})->with(['x', 'y']);

it('follows legacy geometry exactly when a ragged map contains an empty interior row', function (string $operation, int $at) {
    $root = createOccupancyGeometryProject(legacy: true);
    $directory = $root . '/assets/Maps/test-map';
    file_put_contents($directory . '/test-map.map.php', MapGridSource::buildSource("...\n\n..", 'GROUND'));
    file_put_contents($directory . '/test-map.event.php', MapGridSource::buildSource(" E \n\n  ", 'EVENTS'));
    $rows = [[CollisionType::EXIT, CollisionType::NONE, CollisionType::COUNTER], [], [CollisionType::ENCOUNTER, CollisionType::ITEM]];
    writeGeometryOccupancyData($directory, $rows, legacy: true);
    $map = loadOccupancyGeometryMap($root);
    $expected = $rows;
    if ($operation === 'resize') {
        $expected = array_map(static fn(array $row): array => array_pad($row, 4, CollisionType::SOLID), $expected);
        $expected[] = array_fill(0, 4, CollisionType::SOLID);
        $map->resize(4, 4);
    } elseif ($operation === 'y') {
        $beside = $expected[$at] ?? $expected[$at - 1];
        array_splice($expected, $at, 0, [array_fill(0, count($beside), CollisionType::SOLID)]);
        $map->insertLines('y', $at, 1);
    } else {
        foreach ($expected as &$row) {
            if (count($row) >= $at) {
                array_splice($row, $at, 0, [CollisionType::SOLID]);
            }
        }
        unset($row);
        $map->insertLines('x', $at, 1);
    }
    expect($map->getMapDataField(['occupancy']))->toBe($expected)
        ->and(array_map(count(...), $map->getLayerSet()->getComposedGrid()))->toBe(array_map(count(...), $expected));
    $map->save();
    expect(loadOccupancyGeometryMap($root)->getMapDataField(['occupancy']))->toBe($expected);
})->with(['resize' => ['resize', 0], 'empty column' => ['x', 0], 'short columns' => ['x', 3],
    'empty adjacent row' => ['y', 1], 'append row' => ['y', 3]]);

it('grows an empty legacy physical row deliberately and refuses unrepresentable empty row insertions without mutation', function () {
    $root = createOccupancyGeometryProject(legacy: true);
    $directory = $root . '/assets/Maps/test-map';
    file_put_contents($directory . '/test-map.map.php', MapGridSource::buildSource('', 'GROUND'));
    file_put_contents($directory . '/test-map.event.php', MapGridSource::buildSource('', 'EVENTS'));
    writeGeometryOccupancyData($directory, [[]], legacy: true);
    $map = loadOccupancyGeometryMap($root);
    $before = $map->captureGridSnapshot();
    $version = $map->getContentVersion();
    $hashes = sourceHashTree($root);
    expect(fn() => $map->insertLines('y', 0, 1))->toThrow(MapSourceRefusal::class, 'Nothing was changed.');
    expect($map->captureGridSnapshot())->toBe($before)->and($map->getContentVersion())->toBe($version)
        ->and($map->isDirty())->toBeFalse()->and(sourceHashTree($root))->toBe($hashes);
    $map->resize(2, 2);
    $map->save();
    expect(loadOccupancyGeometryMap($root)->getMapDataField(['occupancy']))->toBe(array_fill(0, 2, array_fill(0, 2, CollisionType::SOLID)));
});
