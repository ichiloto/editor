<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\ChangeBatch;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Field\MapGridSource;

/**
 * The layered graphics test map with every glyph presented: floor under the
 * terrain and a decor tile under each building glyph. Its holes, as [x, y]
 * cells, have neither terrain nor tiles; (3, 1) is always one, and a hole
 * under a building glyph leaves that glyph showing.
 *
 * @param list<array{int, int}> $holes
 */
function changeSetProject(array $holes = []): string
{
    $root = mapGraphicsProject();
    $directory = $root . '/assets/Maps/test-map';
    file_put_contents($directory . '/test-map.event.php', MapGridSource::buildSource("    \n    ", 'EVENTS'));
    $terrain = ['....', '....'];
    $floor = [[2816, 2816, 2816, 2816], [2816, 2816, 2816, 2816]];
    $decor = [[0, 5, 0, 0], [0, 5, 5, 0]];
    foreach ([[3, 1], ...$holes] as [$x, $y]) {
        $terrain[$y][$x] = ' ';
        $floor[$y][$x] = 0;
        $decor[$y][$x] = 0;
    }
    file_put_contents($directory . '/layers/01.terrain.map.php', MapGridSource::buildSource(implode("\n", $terrain), 'AUTHORED'));
    $rows = static fn(array $grid): string => implode("\n", array_map(static fn(array $row): string => implode(' ', $row), $grid));
    writeTileLayer($directory, '01.floor.tiles.php', $rows($floor));
    writeTileLayer($directory, '02.decor.tiles.php', $rows($decor));

    return $root;
}

/** The id of a test map layer by its name. */
function changeSetLayer(EditorSession $session, string $name): string
{
    return array_find($session->readMap('test-map')['layers'], static fn(array $layer): bool => $layer['name'] === $name)['id'];
}

/** Paints one glyph on the test map, at its current revision. */
function changeSetPaint(EditorSession $session, string $layer, int $x, int $y, string $symbol): void
{
    $session->paint('test-map', $session->readMap('test-map')['revision'], changeSetLayer($session, $layer), [[$x, $y]], $symbol);
}

/** @return list<string> The blocking and reported messages of a commit. */
function changeSetMessages(array $outcome, string $severity = 'error'): array
{
    return array_values(array_map(static fn(array $issue): string => $issue['message'],
        array_filter($outcome['issues'], static fn(array $issue): bool => $issue['severity'] === $severity)));
}

it('starts from a project whose test map has no errors', function () {
    $session = EditorSession::open(changeSetProject());

    expect($session->validateProject(['test-map'])['errors'])->toBe(0);
});

it('commits a change set that leaves its maps valid, through each map\'s own save', function () {
    $root = changeSetProject();
    $session = EditorSession::open($root);
    $session->beginChanges();
    changeSetPaint($session, 'buildings', 1, 0, ' ');

    $outcome = $session->commitChanges();

    expect($outcome['status'])->toBe('saved')
        ->and($outcome['maps'])->toBe(['test-map'])
        ->and($outcome['saved'])->toBe(['test-map'])
        ->and($outcome['blocking'])->toBe(0)
        ->and(MapGridSource::readFile($root . '/assets/Maps/test-map/layers/04.buildings.map.php'))->toBe("    \n xx ")
        ->and($session->listUnsavedChanges())->toBe([]);
});

it('refuses a change set that leaves a raw glyph showing, writes nothing and undoes it', function () {
    $root = changeSetProject();
    $before = sourceHashTree($root . '/assets');
    $session = EditorSession::open($root);
    $session->beginChanges();
    changeSetPaint($session, 'buildings', 3, 1, 'x');

    $outcome = $session->commitChanges();

    expect($outcome['status'])->toBe('refused')
        ->and($outcome['blocking'])->toBe(1)
        ->and($outcome['reason'])->toBe('1 error the player would meet.')
        ->and(changeSetMessages($outcome))->toBe(["Gameplay glyph 'x' on buildings has no graphical presentation. At (3, 1)."])
        ->and($outcome['issues'][0]['map'])->toBe('test-map')
        ->and($outcome['issues'][0]['hint'])->not->toBe('')
        ->and(sourceHashTree($root . '/assets'))->toBe($before)
        ->and($session->listUnsavedChanges())->toBe([])
        // The change set is closed: another may begin.
        ->and($session->beginChanges()['status'])->toBe('open');
});

it('refuses a change set on a map that already shows a raw glyph until that is fixed too', function () {
    $root = changeSetProject(holes: [[2, 1]]);
    $session = EditorSession::open($root);
    $session->beginChanges();
    changeSetPaint($session, 'buildings', 1, 0, ' ');

    $refused = $session->commitChanges();
    expect($refused['status'])->toBe('refused')
        ->and(changeSetMessages($refused))->toBe(["Gameplay glyph 'x' on buildings has no graphical presentation. At (2, 1)."]);

    // Fixing the defect in the same change set lets it through.
    $session->beginChanges();
    changeSetPaint($session, 'buildings', 1, 0, ' ');
    changeSetPaint($session, 'buildings', 2, 1, ' ');
    expect($session->commitChanges()['status'])->toBe('saved');
});

it('reports what committing would find without writing anything when only checking', function () {
    $root = changeSetProject();
    $before = sourceHashTree($root . '/assets');
    $session = EditorSession::open($root);
    $session->beginChanges();
    changeSetPaint($session, 'buildings', 1, 0, ' ');

    $checked = $session->commitChanges(checkOnly: true);

    expect($checked['status'])->toBe('checked')
        ->and($checked['blocking'])->toBe(0)
        ->and($checked['saved'])->toBe([])
        ->and(sourceHashTree($root . '/assets'))->toBe($before)
        ->and($session->listUnsavedChanges())->toBe([]);
});

it('holds a change set to itself: no saves inside it, no undo past its start, and edits pending before it refuse it', function () {
    $session = EditorSession::open(changeSetProject());
    changeSetPaint($session, 'buildings', 1, 0, ' ');
    expect(fn() => $session->beginChanges())->toThrow(SessionRefusal::class, 'Save or undo pending edits');

    $session->undo();
    $session->beginChanges();
    expect(fn() => $session->saveMap('test-map'))->toThrow(SessionRefusal::class, 'Saving a map is not part of a change set')
        ->and(fn() => $session->saveAll())->toThrow(SessionRefusal::class, 'is not part of a change set')
        ->and(fn() => $session->undo())->toThrow(SessionRefusal::class, 'before the change set began')
        ->and(fn() => $session->beginChanges())->toThrow(SessionRefusal::class, 'already open');

    changeSetPaint($session, 'buildings', 1, 0, ' ');
    expect($session->cancelChanges()['status'])->toBe('cancelled')
        ->and($session->listUnsavedChanges())->toBe([]);
});

it('undoes what a refused change set wrote at once, such as inserted rows', function () {
    $root = changeSetProject();
    $before = sourceHashTree($root . '/assets');
    $buildings = changeSetLayer(EditorSession::open($root), 'buildings');

    $outcome = ChangeBatch::apply($root, [
        ['method' => 'map.insertLines', 'params' => ['map' => 'test-map', 'axis' => 'y', 'at' => 2, 'count' => 1], 'answer' => 'write'],
        ['method' => 'map.paint', 'params' => ['map' => 'test-map', 'layer' => $buildings, 'cells' => [[3, 1]], 'symbol' => 'x']],
    ]);

    expect($outcome['status'])->toBe('refused')
        ->and(changeSetMessages($outcome))->toContain("Gameplay glyph 'x' on buildings has no graphical presentation. At (3, 1).")
        ->and(sourceHashTree($root . '/assets'))->toBe($before);
});

it('applies a batch of session requests as one change set, filling each map\'s revision as it stands', function () {
    $root = changeSetProject();
    $buildings = changeSetLayer(EditorSession::open($root), 'buildings');

    $outcome = ChangeBatch::apply($root, [
        ['method' => 'map.paint', 'params' => ['map' => 'test-map', 'layer' => $buildings, 'cells' => [[1, 0]], 'symbol' => ' ']],
        ['method' => 'map.paint', 'params' => ['map' => 'test-map', 'layer' => $buildings, 'cells' => [[2, 1]], 'symbol' => ' ']],
    ]);

    expect($outcome['status'])->toBe('saved')
        ->and($outcome['saved'])->toBe(['test-map'])
        ->and(EditorSession::open($root)->readMap('test-map')['revision'])->toBeInt();
    expect(MapGridSource::readFile($root . '/assets/Maps/test-map/layers/04.buildings.map.php'))->toBe("    \n x  ");
});

it('names the step a batch was refused at, and writes nothing of it', function () {
    $root = changeSetProject();
    $before = sourceHashTree($root . '/assets');
    $buildings = changeSetLayer(EditorSession::open($root), 'buildings');

    $outcome = ChangeBatch::apply($root, [
        ['method' => 'map.paint', 'params' => ['map' => 'test-map', 'layer' => $buildings, 'cells' => [[1, 0]], 'symbol' => ' ']],
        ['method' => 'map.paint', 'params' => ['map' => 'test-map', 'layer' => 'no-layer', 'cells' => [[0, 0]], 'symbol' => '#']],
        ['method' => 'map.save', 'params' => ['map' => 'test-map']],
    ]);

    expect($outcome)->toMatchArray(['status' => 'failed', 'step' => 2, 'method' => 'map.paint'])
        ->and($outcome['message'])->toContain('test-map has no layer no-layer.')
        ->and(sourceHashTree($root . '/assets'))->toBe($before)
        ->and(ChangeBatch::apply($root, [['method' => 'map.save', 'params' => ['map' => 'test-map']]])['message'])
            ->toContain('Saving a map is not part of a change set')
        ->and(ChangeBatch::apply($root, [['method' => 'changes.commit', 'params' => []]])['message'])
            ->toContain('made by the batch itself');
});

it('reads a batch file as a list of changes or as {"changes": [...]}', function () {
    $directory = rememberTemporaryProject(sys_get_temp_dir() . '/ichiloto-batch-' . bin2hex(random_bytes(4)));
    mkdir($directory);
    file_put_contents($directory . '/list.json', '[{"method": "maps.list", "params": {}}]');
    file_put_contents($directory . '/object.json', '{"changes": [{"method": "maps.list", "params": {}}]}');
    file_put_contents($directory . '/broken.json', '{"changes": 3}');

    expect(ChangeBatch::readFile($directory . '/list.json'))->toBe([['method' => 'maps.list', 'params' => []]])
        ->and(ChangeBatch::readFile($directory . '/object.json'))->toBe([['method' => 'maps.list', 'params' => []]])
        ->and(fn() => ChangeBatch::readFile($directory . '/broken.json'))->toThrow(Ichiloto\Editor\Session\InvalidRequest::class, 'list of changes');
});

it('judges events made inside the change set: a chest with no reward is refused, one with a reward is written', function () {
    $root = changeSetProject();
    writeItemRecords($root, new Ichiloto\Engine\Entities\Inventory\Items\Item(id: 'item.tonic', name: 'Tonic', description: '', icon: 't', price: 5));
    $session = EditorSession::open($root);
    $session->beginChanges();
    $created = $session->createEvent('test-map', $session->readMap('test-map')['revision'], [[0, 0]], 'Chest', 'C');
    $loot = static fn(string $field): array => ['target' => 'event', 'marker' => 'C', 'path' => ['data', $field]];
    $session->applyInspector('test-map', $created['revision'], $loot('lootType'), 'item');

    $refused = $session->commitChanges();
    expect($refused['status'])->toBe('refused')
        ->and(array_column($refused['issues'], 'code'))->toContain('chest.reward')
        ->and(array_column($refused['issues'], 'map'))->each->toBe('test-map');

    $session->beginChanges();
    $created = $session->createEvent('test-map', $session->readMap('test-map')['revision'], [[0, 0]], 'Chest', 'C');
    $revision = $session->applyInspector('test-map', $created['revision'], $loot('lootType'), 'item')['revision'];
    $session->applyInspector('test-map', $revision, $loot('loot'), 'item.tonic');

    $saved = $session->commitChanges();
    expect($saved['status'])->toBe('saved')
        ->and((require $root . '/assets/Maps/test-map/test-map.data.php')['events']['C']['data']['loot'])->toBe('item.tonic');
});

it('asks a batch to choose the piece a glyph plays when several draw it, and paints it once chosen', function () {
    $root = changeSetProject();
    writeTestTileset($root, pieces: [
        'window' => ['name' => 'Window', 'layer' => 'buildings', 'glyphs' => ['x'], 'tiles' => ['decor' => ['5']]],
        'crate' => ['name' => 'Crate', 'layer' => 'buildings', 'glyphs' => ['x'], 'tiles' => ['decor' => ['6']]],
    ]);
    $buildings = changeSetLayer(EditorSession::open($root), 'buildings');
    $paint = ['method' => 'map.paint', 'params' => ['map' => 'test-map', 'layer' => $buildings, 'cells' => [[3, 1]], 'symbol' => 'x']];

    $asked = ChangeBatch::apply($root, [$paint], checkOnly: true);
    expect($asked['status'])->toBe('failed')
        ->and($asked['message'])->toContain('Glyph x could be several pieces; give the change "choices": {"x": key}');

    $paint['params']['choices'] = ['x' => 'crate:0:0'];
    expect(ChangeBatch::apply($root, [$paint])['status'])->toBe('saved')
        ->and(MapGridSource::readFile($root . '/assets/Maps/test-map/graphics/02.decor.tiles.php'))->toBe("0 5 0 0\n0 5 5 6");
});

/**
 * The change set test project with a second map that already shows a raw
 * glyph (an error), and a skill a class names, so retiring it writes a file
 * set that never touches a map.
 */
function sourceSetChangeSetProject(): string
{
    $root = changeSetProject();
    $maps = $root . '/assets/Maps';
    mkdir($maps . '/broken-map');
    copyDirectoryRecursively($maps . '/test-map', $maps . '/broken-map');
    foreach (glob($maps . '/broken-map/test-map.*') ?: [] as $file) {
        rename($file, $maps . '/broken-map/broken-' . substr(basename($file), strlen('test-')));
    }
    $data = $maps . '/broken-map/broken-map.data.php';
    file_put_contents($data, str_replace("'Test Map'", "'Broken Map'", (string) file_get_contents($data)));
    // A hole under a building glyph: the glyph shows raw, an error found before any change set.
    file_put_contents($maps . '/broken-map/layers/01.terrain.map.php', MapGridSource::buildSource("....\n..  ", 'AUTHORED'));
    writeTileLayer($maps . '/broken-map', '01.floor.tiles.php', "2816 2816 2816 2816\n2816 2816 0 0");
    writeTileLayer($maps . '/broken-map', '02.decor.tiles.php', "0 5 0 0\n0 5 0 0");
    writeSkillRecords($root,
        new Ichiloto\Engine\Entities\Skills\SpecialSkill('Old Cut', 'Cuts.', '', 2, 0),
        new Ichiloto\Engine\Entities\Skills\SpecialSkill('New Cut', 'Cuts well.', '', 4, 0),
    );
    $classes = $root . '/assets/Data/classes.php';
    file_put_contents($classes, preg_replace("/'name' => 'Vanguard',\n/",
        "'name' => 'Vanguard',\n    'skillsToLearn' => [\n      ['level' => 2, 'skill' => 'Old Cut'],\n    ],\n", (string) file_get_contents($classes), 1));

    return $root;
}

/** The batch change that retires Old Cut into New Cut, writing its file set at once. */
function sourceSetRetirement(): array
{
    return ['method' => 'skills.retire', 'params' => ['index' => 0, 'replacement' => 'New Cut'], 'answer' => 'write'];
}

it('starts the file set project with an error on the second map only', function () {
    $session = EditorSession::open(sourceSetChangeSetProject());

    expect($session->validateProject(['test-map'])['errors'])->toBe(0)
        ->and($session->validateProject(['broken-map'])['errors'])->toBeGreaterThan(0);
});

it('judges only the maps a batch changed when a step writes a file set that reloads the project', function () {
    $root = sourceSetChangeSetProject();
    $buildings = changeSetLayer(EditorSession::open($root), 'buildings');

    $outcome = ChangeBatch::apply($root, [
        sourceSetRetirement(),
        ['method' => 'map.paint', 'params' => ['map' => 'test-map', 'layer' => $buildings, 'cells' => [[1, 0]], 'symbol' => ' ']],
    ]);

    expect($outcome['status'])->toBe('saved')
        ->and($outcome['maps'])->toBe(['test-map'])
        ->and($outcome['blocking'])->toBe(0)
        ->and(array_column($outcome['issues'], 'map'))->not->toContain('broken-map')
        ->and((require $root . '/assets/Data/classes.php')[0]['skillsToLearn'])->toBe([['level' => 2, 'skill' => 'New Cut']])
        ->and(MapGridSource::readFile($root . '/assets/Maps/test-map/layers/04.buildings.map.php'))->toBe("    \n xx ");
    expect(ChangeBatch::apply($root, [['method' => 'skills.retire', 'params' => ['index' => 0], 'answer' => 'write']]))
        ->toMatchArray(['status' => 'saved', 'maps' => [], 'blocking' => 0]);
});

it('still judges a map whose files a batch\'s file set rewrote, and an edit made to an untouched map', function () {
    $root = sourceSetChangeSetProject();
    $before = sourceHashTree($root . '/assets');
    $buildings = changeSetLayer(EditorSession::open($root), 'buildings');

    $rewritten = ChangeBatch::apply($root, [
        ['method' => 'map.insertLines', 'params' => ['map' => 'broken-map', 'axis' => 'y', 'at' => 2, 'count' => 1], 'answer' => 'write'],
    ]);
    $edited = ChangeBatch::apply($root, [
        sourceSetRetirement(),
        ['method' => 'map.paint', 'params' => ['map' => 'broken-map', 'layer' => $buildings, 'cells' => [[1, 0]], 'symbol' => ' ']],
    ]);

    expect($rewritten['status'])->toBe('refused')
        ->and($rewritten['maps'])->toBe(['broken-map'])
        ->and(array_unique(array_column($rewritten['issues'], 'map')))->toBe(['broken-map'])
        ->and($edited['status'])->toBe('refused')
        ->and($edited['maps'])->toBe(['broken-map'])
        ->and(sourceHashTree($root . '/assets'))->toBe($before);
});

it('keeps the revision of every map a file set never wrote, through its undo and redo', function () {
    $session = EditorSession::open(sourceSetChangeSetProject());
    $revision = static fn(string $map): int => $session->readMap($map)['revision'];
    [$test, $broken] = [$revision('test-map'), $revision('broken-map')];

    $question = $session->retireSkill(0, 'New Cut');
    $session->retireSkill(0, 'New Cut', 'write', $question['confirm']);
    expect([$revision('test-map'), $revision('broken-map')])->toBe([$test, $broken])
        ->and($session->undo())->toMatchArray(['maps' => [], 'revisions' => []])
        ->and($session->redo())->toMatchArray(['maps' => [], 'revisions' => []])
        ->and([$revision('test-map'), $revision('broken-map')])->toBe([$test, $broken]);

    // A file set that rewrites a map moves its revision on, and only its.
    $question = $session->insertMapLines('test-map', $test, 'y', 2, 1);
    $inserted = $session->insertMapLines('test-map', $test, 'y', 2, 1, 'write', $question['confirm']);
    $undone = $session->undo();
    expect($inserted['revision'])->toBeGreaterThan($test)
        ->and($undone['maps'])->toBe(['test-map'])
        ->and($undone['revisions']['test-map'])->toBeGreaterThan($inserted['revision'])
        ->and($revision('broken-map'))->toBe($broken)
        ->and(fn() => changeSetPaint($session, 'buildings', 1, 0, ' '))->not->toThrow(SessionRefusal::class)
        ->and(fn() => $session->paint('test-map', $test, changeSetLayer($session, 'buildings'), [[2, 1]], ' '))
            ->toThrow(SessionRefusal::class, 'changed since revision');
});

it('draws a map read afresh by a file set on a canvas of its own', function () {
    $session = EditorSession::open(sourceSetChangeSetProject());
    $first = $session->exchangeAuthoringCanvas('test-map', []);

    $question = $session->retireSkill(0, 'New Cut');
    $session->retireSkill(0, 'New Cut', 'write', $question['confirm']);
    $after = $session->exchangeAuthoringCanvas('test-map', [], sessionId: $first['sessionId']);

    expect($after['sessionId'])->not->toBe($first['sessionId'])
        ->and($after['revision'])->toBe($first['revision'])
        ->and($session->exchangeAuthoringCanvas('test-map', [], sessionId: $after['sessionId'])['sessionId'])->toBe($after['sessionId']);
    $session->closeAuthoringCanvas('test-map');
});
