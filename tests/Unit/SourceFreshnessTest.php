<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\Storage\FileSetTransaction;
use Ichiloto\Editor\Storage\FileSetTransactionFailure;

/** Each owner keeps an unrelated expression and an authored comment. */
function createSourceFreshnessProject(string $kind): array
{
    $root = $kind === 'map' ? makeTemporaryProject('source-freshness-') : cutsceneProject();
    if ($kind === 'map') {
        $path = $root . '/assets/Maps/test-map/test-map.data.php';
        $data = require $path;
        $data['description'] = 'Loaded map';
        $data['futureValue'] = 7;
        $data['npcs'] = [['id' => 'guide', 'name' => 'Guide', 'x' => 1, 'y' => 1,
            'script' => [['type' => 'transfer', 'map' => 'test-map', 'x' => 2, 'y' => 1]]]];
        $source = str_replace("'futureValue' => 7", "'futureValue' => abs(7)", var_export($data, true));
        file_put_contents($path, "<?php\n// Authored map source.\nreturn $source;\n");
        return [$root, $path, 'test-map'];
    }
    $path = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    file_put_contents($path, "<?php\n// Authored cinematic source.\nreturn [
        ['type' => 'camera', 'operation' => 'pan', 'target' => ['kind' => 'position', 'x' => 2, 'y' => 1], 'seconds' => 0.5],
        ['type' => 'wait', 'seconds' => abs(1)],
    ];\n");
    return [$root, $path, 'harbour'];
}

function readSourceFreshnessPlacement(EditorSession $session, string $kind, string $map): array
{
    $view = $kind === 'map' ? $session->readNpc($map, 0, ['script'])
        : $session->readDatabaseRecord('cutscenes/cinematic', 0, ['commands']);
    $row = array_find($view['rows'], static fn(array $row): bool => isset($row['mapPlacement']))
        ?? throw new RuntimeException('The synthetic placement is missing.');
    $context = $kind === 'map' ? ['kind' => 'npc', 'map' => $map, 'revision' => $view['revision'], 'index' => 0]
        : ['kind' => 'database', 'category' => 'cutscenes/cinematic', 'index' => 0];
    return [$row, $context];
}

/** A sparse route shares its owner with untouched legacy Inn references. */
function createSourceFreshnessRouteProject(string $kind): array
{
    [$root, $path, $map] = createSourceFreshnessProject($kind);
    $commands = [
        ['type' => 'move_route', 'subject' => 'player', 'waypoints' => [['x' => 2], ['y' => 1]], 'secondsPerStep' => 0.3],
        ['type' => 'inn', 'cost' => 5, 'confirmDialogue' => ['text' => 'Synthetic rest?'],
            'presentation' => ['treatment' => 'leader', 'leaders' => ['retired-actor' => 'retired-rest']]],
        ['type' => 'wait', 'seconds' => 1],
    ];
    $data = $kind === 'map' ? require $path : $commands;
    if ($kind === 'map') { $data['npcs'][0]['script'] = $commands; }
    else {
        $metadataPath = str_replace('.script.php', '.data.php', $path);
        $metadata = require $metadataPath;
        $metadata['skip'] = ['policy' => 'forbidden'];
        file_put_contents($metadataPath, "<?php\nreturn " . var_export($metadata, true) . ";\n");
    }
    $source = str_replace(["'futureValue' => 7", "'seconds' => 1"], ["'futureValue' => abs(7)", "'seconds' => abs(1)"], var_export($data, true));
    file_put_contents($path, "<?php\n// Authored mixed route and Inn source.\nreturn $source;\n");
    return [$root, $path, $map];
}

function loadSourceFreshnessOwner(string $root, string $kind): ProjectMap|CutsceneAsset
{
    return $kind === 'map' ? ProjectMap::fromDirectory($root . '/assets/Maps', $root . '/assets/Maps/test-map')
        : CutsceneAsset::load(CutsceneType::CINEMATIC, $root . '/assets/Cutscenes/Cinematics/harbour-lanterns', $root);
}

function editSourceFreshnessOwner(ProjectMap|CutsceneAsset $owner, string $name): void
{
    if ($owner instanceof ProjectMap) {
        $owner->setMapField('description', $name);
    } else {
        $owner->apply([...$owner->payload(), 'name' => $name]);
    }
}

function rewriteSourceFreshnessFile(string $path, string $mode): void
{
    $source = (string) file_get_contents($path);
    $changed = match ($mode) {
        'literal' => str_replace("'x' => 2", "'x' => 3", $source),
        'expression' => str_replace("'x' => 2", "'x' => (1 + 1)", $source),
        'comment' => $source . "\n// Written by another author.\n",
    };
    if ($changed === $source) {
        throw new RuntimeException('The synthetic rewrite did not change the source.');
    }
    file_put_contents($path, $changed);
}

it('refuses external source changes before first placement review and after review without mutating cached data', function (string $kind, string $mode, bool $reviewed) {
    [$root, $path, $map] = createSourceFreshnessProject($kind);
    $session = EditorSession::open($root);
    $revision = $session->readMap($map)['revision'];
    if ($reviewed) {
        [$row, $context] = readSourceFreshnessPlacement($session, $kind, $map);
        expect($row['mapPlacement']['issue'])->toBeNull();
    }
    rewriteSourceFreshnessFile($path, $mode);
    $before = sourceHashTree($root);
    if (! $reviewed) {
        [$row, $context] = readSourceFreshnessPlacement($session, $kind, $map);
        expect($row['mapPlacement']['issue'])->toContain('changed after opening');
    }
    expect(fn() => $session->applyMapPlacement($context, $row['key'], $row['mapPlacement'], $map, 0, 4, 2, null, $revision))
        ->toThrow(SessionRefusal::class);
    [$current] = readSourceFreshnessPlacement($session, $kind, $map);
    expect($current['mapPlacement']['points'][0]['point'])->toBe([2, 1])
        ->and($session->undo()['label'])->toBeNull()
        ->and(sourceHashTree($root))->toBe($before);
})->with(['map', 'cinematic'])->with(['literal', 'comment', 'expression'])->with([false, true]);

it('refuses stale ordinary field descriptors before first review and after review', function (string $kind, string $mode, bool $reviewed) {
    [$root, $path, $map] = createSourceFreshnessProject($kind);
    $session = EditorSession::open($root);
    $session->readMap($map);
    $read = static fn() => $kind === 'map' ? $session->readInspector($map)
        : $session->readDatabaseRecord('cutscenes/cinematic', 0);
    if ($reviewed) {
        expect(array_any($read()['rows'], static fn(array $row): bool => isset($row['mapPlacement'])))->toBeFalse();
    }
    rewriteSourceFreshnessFile($path, $mode);
    $before = sourceHashTree($root);
    expect($read)->toThrow(SessionRefusal::class, 'changed after opening');
    if ($kind === 'map') {
        expect(fn() => $session->readNpc($map, 0))->toThrow(SessionRefusal::class, 'changed after opening');
    }
    expect($session->undo()['label'])->toBeNull()->and(sourceHashTree($root))->toBe($before);
})->with(['map', 'cinematic'])->with(['literal', 'comment', 'expression'])->with([false, true]);

it('keeps ordinary field review available for dirty edits and missing source recovery', function (string $kind) {
    [$root, $path, $map] = createSourceFreshnessProject($kind);
    $session = EditorSession::open($root);
    $session->readMap($map);
    $read = static fn() => $kind === 'map' ? $session->readInspector($map)
        : $session->readDatabaseRecord('cutscenes/cinematic', 0);
    $view = $read();
    $field = $kind === 'map' ? 'description' : 'name';
    $row = array_find($view['rows'], static fn(array $row): bool => ($row['key']['field'] ?? '') === $field)
        ?? throw new RuntimeException('The synthetic ordinary field is missing.');
    if ($kind === 'map') {
        $session->applyInspector($map, $view['revision'], $row['key'], 'Pending internal edit');
    } else {
        $session->applyDatabaseRecord('cutscenes/cinematic', 0, $row['key'], 'Pending internal edit');
    }
    expect(array_find($read()['rows'], static fn(array $row): bool => ($row['key']['field'] ?? '') === $field)['value'])
        ->toBe('Pending internal edit');
    unlink($path);
    expect($read()['rows'])->not->toBeEmpty();
    if ($kind === 'map') { $session->saveMap($map); }
    else { $session->saveDatabase('cutscenes/cinematic'); }
    $reopened = loadSourceFreshnessOwner($root, $kind);
    expect($reopened instanceof ProjectMap ? $reopened->getEditableData()['description'] : $reopened->payload()['name'])
        ->toBe('Pending internal edit')->and(file_get_contents($path))->toContain('Authored', 'abs(');
    $session->undo();
    expect($read()['rows'])->not->toBeEmpty();
})->with(['map', 'cinematic']);

it('refuses stale direct authoring and saves while keeping dirty internal edits intact', function (string $kind, string $mode, bool $dirty) {
    [$root, $path] = createSourceFreshnessProject($kind);
    $owner = loadSourceFreshnessOwner($root, $kind);
    if ($dirty) {
        editSourceFreshnessOwner($owner, 'Pending internal edit');
    }
    $owner->assertSourcesUnchanged();
    $state = $owner instanceof ProjectMap ? $owner->getEditableData() : $owner->captureEditState();
    $version = $owner->stateVersion();
    rewriteSourceFreshnessFile($path, $mode);
    $before = sourceHashTree($root);
    $refusal = $kind === 'map' ? MapSourceRefusal::class : FileSetTransactionFailure::class;
    expect(fn() => $owner->assertSourcesUnchanged())->toThrow($refusal, 'changed after opening')
        ->and(fn() => editSourceFreshnessOwner($owner, 'Must not apply'))->toThrow($refusal, 'changed after opening')
        ->and(fn() => $owner->save())->toThrow($refusal, 'changed after opening');
    expect($owner instanceof ProjectMap ? $owner->getEditableData() : $owner->captureEditState())->toBe($state)
        ->and($owner->stateVersion())->toBe($version)
        ->and($owner->isDirty())->toBe($dirty)
        ->and(sourceHashTree($root))->toBe($before)
        ->and(glob(dirname($path) . '/*.tmp-*'))->toBe([]);
})->with(['map', 'cinematic'])->with(['literal', 'comment', 'expression'])->with([false, true]);

it('refuses a stale displayed map independently of cinematic placement source', function (string $member) {
    [$root, $path, $map] = createSourceFreshnessProject('cinematic');
    $session = EditorSession::open($root);
    [$row, $context] = readSourceFreshnessPlacement($session, 'cinematic', $map);
    $revision = $session->readMap($map)['revision'];
    $mapPath = $root . '/assets/Maps/harbour/harbour.' . $member . '.php';
    file_put_contents($mapPath, file_get_contents($mapPath) . "\n// External preview-map edit.\n");
    $before = sourceHashTree($root);
    expect(fn() => $session->applyMapPlacement($context, $row['key'], $row['mapPlacement'], $map, 0, 4, 2, null, $revision))
        ->toThrow(SessionRefusal::class, 'changed after opening');
    expect($session->undo()['label'])->toBeNull()
        ->and(sourceHashTree($root))->toBe($before)
        ->and(file_get_contents($path))->toContain("'x' => 2");
})->with(['data', 'map', 'event']);

it('keeps the owner baseline correct across dirty placement undo save redo and reopen', function (string $kind) {
    [$root, $path, $map] = createSourceFreshnessProject($kind);
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    [$row, $context] = readSourceFreshnessPlacement($session, $kind, $map);
    $session->applyMapPlacement($context, $row['key'], $row['mapPlacement'], $map, 0, 4, 2, null, $session->readMap($map)['revision']);
    $save = static fn() => $kind === 'map' ? $session->saveMap($map) : $session->saveDatabase('cutscenes/cinematic');
    $save();
    expect(file_get_contents($path))->toContain("'x' => 4", 'abs(');
    $session->undo();
    $save();
    expect(file_get_contents($path))->toBe($original);
    $session->redo();
    $save();
    [$reopened] = readSourceFreshnessPlacement(EditorSession::open($root), $kind, $map);
    expect($reopened['mapPlacement']['points'][0]['point'])->toBe([4, 2])
        ->and($reopened['mapPlacement']['issue'])->toBeNull()
        ->and(file_get_contents($path))->toContain('Authored', 'abs(');
})->with(['map', 'cinematic']);

it('keeps nullable waypoint placement compatible with unchanged Inn semantics and owner baselines', function (string $kind) {
    [$root, $path, $map] = createSourceFreshnessRouteProject($kind);
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    [$row, $context] = readSourceFreshnessPlacement($session, $kind, $map);
    expect($row['mapPlacement']['kind'])->toBe('waypoints')
        ->and($row['mapPlacement']['points'][0]['point'])->toBe([2, null])
        ->and(array_keys($row['mapPlacement']['points'][0]['fields']))->toBe(['x'])
        ->and($row['mapPlacement']['points'][1]['point'])->toBe([2, 1])
        ->and(array_keys($row['mapPlacement']['points'][1]['fields']))->toBe(['y']);
    $session->applyMapPlacement($context, $row['key'], $row['mapPlacement'], $map, 0, 4, 3, [1, 1], $session->readMap($map)['revision']);
    $save = static fn() => $kind === 'map' ? $session->saveMap($map) : $session->saveDatabase('cutscenes/cinematic');
    $save();
    $saved = require $path;
    $commands = $kind === 'map' ? $saved['npcs'][0]['script'] : $saved;
    expect($commands[0]['waypoints'])->toBe([['x' => 4], ['y' => 1]])
        ->and($commands[0]['secondsPerStep'])->toBe(0.3)
        ->and($commands[1]['presentation'])->toBe(['treatment' => 'leader', 'leaders' => ['retired-actor' => 'retired-rest']])
        ->and(file_get_contents($path))->toContain('Authored mixed route and Inn source', 'abs(1)');
    $session->undo();
    $save();
    expect(file_get_contents($path))->toBe($original);
    $session->redo();
    $save();
    [$reopened] = readSourceFreshnessPlacement(EditorSession::open($root), $kind, $map);
    expect($reopened['mapPlacement']['points'][0]['point'])->toBe([4, null])
        ->and($reopened['mapPlacement']['points'][1]['point'])->toBe([4, 1])
        ->and($reopened['mapPlacement']['issue'])->toBeNull();
})->with(['map', 'cinematic']);

it('refuses stale sparse waypoint placement beside untouched Inn references', function (string $kind, bool $reviewed) {
    [$root, $path, $map] = createSourceFreshnessRouteProject($kind);
    $session = EditorSession::open($root);
    $revision = $session->readMap($map)['revision'];
    if ($reviewed) { [$row, $context] = readSourceFreshnessPlacement($session, $kind, $map); }
    rewriteSourceFreshnessFile($path, 'comment');
    $before = sourceHashTree($root);
    if (!$reviewed) { [$row, $context] = readSourceFreshnessPlacement($session, $kind, $map); }
    expect(fn() => $session->applyMapPlacement($context, $row['key'], $row['mapPlacement'], $map, 0, 4, 3, [1, 1], $revision))
        ->toThrow(SessionRefusal::class);
    [$current] = readSourceFreshnessPlacement($session, $kind, $map);
    expect($current['mapPlacement']['issue'])->toContain('changed after opening')
        ->and($current['mapPlacement']['points'][0]['point'])->toBe([2, null])
        ->and($session->undo()['label'])->toBeNull()
        ->and(sourceHashTree($root))->toBe($before);
})->with(['map', 'cinematic'])->with([false, true]);

it('recovers missing map data or the complete map folder without losing internal edits', function (bool $wholeFolder, bool $dirty) {
    [$root, $path] = createSourceFreshnessProject('map');
    $owner = loadSourceFreshnessOwner($root, 'map');
    $original = (string) file_get_contents($path);
    if ($wholeFolder) {
        removeDirectoryRecursively(dirname($path));
    } else {
        unlink($path);
    }
    $owner->assertSourcesUnchanged();
    if ($dirty) {
        editSourceFreshnessOwner($owner, 'Recovered internal edit');
    }
    $owner->save();
    $reopened = loadSourceFreshnessOwner($root, 'map');
    expect($reopened->getDescription())->toBe($dirty ? 'Recovered internal edit' : 'Loaded map')
        ->and($owner->isDirty())->toBeFalse()
        ->and(file_get_contents($path))->toContain('Authored map source.', 'abs(7)');
    if (! $dirty) {
        expect(file_get_contents($path))->toBe($original);
    }
})->with([false, true])->with([false, true]);

it('recovers missing cinematic members exactly and adopts their new saved baseline', function (string $member, bool $dirty) {
    [$root, $path] = createSourceFreshnessProject('cinematic');
    $owner = loadSourceFreshnessOwner($root, 'cinematic');
    $original = sourceHashTree($owner->folder);
    foreach ($owner->paths() as $owned) {
        if ($member === 'both' || str_ends_with($owned, '.' . $member . '.php')) {
            unlink($owned);
        }
    }
    $owner->assertSourcesUnchanged();
    if ($dirty) {
        editSourceFreshnessOwner($owner, 'Recovered cinematic');
    }
    expect($owner->save())->toBeTrue();
    $owner->assertSourcesUnchanged();
    $reopened = loadSourceFreshnessOwner($root, 'cinematic');
    expect($owner->isDirty())->toBeFalse()
        ->and($reopened->name())->toBe($dirty ? 'Recovered cinematic' : $owner->name())
        ->and(file_get_contents($path))->toContain('Authored cinematic source.', 'abs(1)');
    if (! $dirty) {
        expect(sourceHashTree($owner->folder))->toBe($original);
    }
    editSourceFreshnessOwner($owner, 'Another internal edit');
    $owner->save();
    expect(loadSourceFreshnessOwner($root, 'cinematic')->name())->toBe('Another internal edit');
})->with(['data', 'script', 'both'])->with([false, true]);

it('refuses directory replacements rather than treating them as recoverable missing files', function (string $kind) {
    [$root, $path] = createSourceFreshnessProject($kind);
    $owner = loadSourceFreshnessOwner($root, $kind);
    unlink($path);
    mkdir($path);
    $before = sourceHashTree($root);
    expect(fn() => $owner->save())->toThrow(RuntimeException::class, 'changed after opening')
        ->and(is_dir($path))->toBeTrue()
        ->and(sourceHashTree($root))->toBe($before);
})->with(['map', 'cinematic']);

it('refuses new cinematic target collisions rather than overwriting another author', function () {
    $root = cutsceneProject();
    $owner = CutsceneAsset::create(CutsceneType::CINEMATIC, 'new-scene', $root . '/assets/Cutscenes/Cinematics',
        ['id' => 'new-scene', 'name' => 'New Scene', 'commands' => []], $root);
    mkdir($owner->folder);
    file_put_contents($owner->partnerPath(), "<?php\n// Another author owns this file.\nreturn [];\n");
    $before = sourceHashTree($root);
    expect(fn() => $owner->assertSourcesUnchanged())->toThrow(FileSetTransactionFailure::class, 'changed after opening')
        ->and(fn() => $owner->save())->toThrow(FileSetTransactionFailure::class, 'changed after opening')
        ->and($owner->isNew())->toBeTrue()
        ->and($owner->isDirty())->toBeTrue()
        ->and(is_file($owner->dataPath()))->toBeFalse()
        ->and(sourceHashTree($root))->toBe($before);
});

it('refuses stale deletion and recovers the saved deletion through its existing undo state', function () {
    [$root, $path] = createSourceFreshnessProject('cinematic');
    $owner = loadSourceFreshnessOwner($root, 'cinematic');
    $state = $owner->captureEditState();
    $original = sourceHashTree($owner->folder);
    $source = (string) file_get_contents($path);
    $owner->markDeleted(true);
    rewriteSourceFreshnessFile($path, 'comment');
    $before = sourceHashTree($root);
    expect(fn() => $owner->save())->toThrow(FileSetTransactionFailure::class, 'changed after opening')
        ->and(sourceHashTree($root))->toBe($before);
    file_put_contents($path, $source);
    $owner->save();
    expect(array_filter($owner->paths(), is_file(...)))->toBe([]);
    $owner->restoreEditState($state);
    $owner->save();
    expect(sourceHashTree($owner->folder))->toBe($original)
        ->and(loadSourceFreshnessOwner($root, 'cinematic')->isEditable())->toBeTrue();
});

it('guards unchanged members if another author writes during the save backup callback', function (string $kind) {
    [$root, $path] = createSourceFreshnessProject($kind);
    $owner = loadSourceFreshnessOwner($root, $kind);
    editSourceFreshnessOwner($owner, 'Pending internal edit');
    $target = $kind === 'map' ? $owner->eventPath : $owner->partnerPath();
    $source = (string) file_get_contents($target);
    $before = sourceHashTree($root);
    expect(fn() => $owner->save(static function () use ($target, $source): void {
        file_put_contents($target, $source . "\n// External edit during backup.\n");
    }))->toThrow(FileSetTransactionFailure::class, 'changed after opening');
    $before[substr($target, strlen($root) + 1)] = hash_file('sha256', $target);
    expect(sourceHashTree($root))->toBe($before)
        ->and($owner->isDirty())->toBeTrue()
        ->and(glob(dirname($path) . '/*.tmp-*'))->toBe([]);
})->with(['map', 'cinematic']);

it('reviews expected sources without staging and preserves their access metadata', function () {
    [$root, $path] = createSourceFreshnessProject('map');
    $transaction = new FileSetTransaction($root . '/not-created');
    $source = (string) file_get_contents($path);
    $transaction->expectSource($path, $source);
    touch($path, time() - 3600, time() - 7200);
    clearstatcache(true, $path);
    $before = stat($path);
    $transaction->assertSourcesUnchanged();
    clearstatcache(true, $path);
    $after = stat($path);
    expect(is_dir($root . '/not-created'))->toBeFalse()
        ->and($after['atime'])->toBe($before['atime'])
        ->and($after['mtime'])->toBe($before['mtime'])
        ->and($after['mode'])->toBe($before['mode']);
    rewriteSourceFreshnessFile($path, 'comment');
    expect(fn() => $transaction->assertSourcesUnchanged())->toThrow(FileSetTransactionFailure::class, 'changed after opening');
});

it('refuses stale cinematic field edits before changing record state or history', function () {
    [$root, $path] = createSourceFreshnessProject('cinematic');
    $session = EditorSession::open($root);
    $view = $session->readDatabaseRecord('cutscenes/cinematic', 0);
    $name = array_find($view['rows'], static fn(array $row): bool => ($row['key']['field'] ?? '') === 'name');
    $library = Ichiloto\Editor\Cutscenes\CutsceneLibrary::fromProject($root);
    $asset = $library->find(CutsceneType::CINEMATIC, 'harbour-lanterns');
    $version = $asset->stateVersion();
    $state = $asset->captureEditState();
    rewriteSourceFreshnessFile($path, 'comment');
    expect(fn() => $session->applyDatabaseRecord('cutscenes/cinematic', 0, $name['key'], 'Must not apply'))
        ->toThrow(SessionRefusal::class, 'changed after opening');
    expect(fn() => $library->changeAsset(CutsceneType::CINEMATIC, 0, 'Must not apply',
        static fn() => throw new LogicException('The stale authoring callback must not run.')))
        ->toThrow(FileSetTransactionFailure::class, 'changed after opening');
    expect($asset->captureEditState())->toBe($state)
        ->and($asset->stateVersion())->toBe($version)
        ->and($session->undo()['label'])->toBeNull();
});

it('refuses external creation of a pending map layer during save', function () {
    $root = makeTemporaryProject('source-freshness-layers-');
    $directory = $root . '/assets/Maps/fresh-map';
    ProjectMap::createBlank($directory, 'fresh-map', 'Fresh Map', 12, 5);
    $owner = ProjectMap::fromDirectory($root . '/assets/Maps', $directory);
    $layer = $owner->createLayer('canopy', true);
    $target = array_find($owner->getLayers(), static fn(array $entry): bool => $entry['id'] === $layer)['path'];
    $before = sourceHashTree($root);
    expect(fn() => $owner->save(static function () use ($target): void {
        file_put_contents($target, "<?php\n// Another author created this layer.\nreturn 'external';\n");
    }))->toThrow(FileSetTransactionFailure::class, 'changed after opening');
    $before[substr($target, strlen($root) + 1)] = hash_file('sha256', $target);
    ksort($before);
    expect(sourceHashTree($root))->toBe($before)
        ->and(file_get_contents($target))->toContain('Another author')
        ->and($owner->isDirty())->toBeTrue();
});
