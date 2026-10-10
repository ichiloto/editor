<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Maps\PhysicalFootprintCodec;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Editor\Tests\Fixtures\SourceWriterUnit;

require_once fixturePath('SourceWriterEnums.php');

function createFootprintAuthoringProject(array $extra = [], ?array $pieces = null): string
{
    $root = mapGraphicsProject();
    $piece = $extra + ['name' => 'Synthetic object', 'layer' => 'fixtures', 'glyphs' => ['<info>xx</info>', ' x'],
        'tiles' => ['objects' => ['1 0', '0 2']]];
    writeTestTileset($root, pieces: $pieces ?? ['object' => $piece]);
    $path = $root . '/assets/Data/Tilesets/home.php';
    file_put_contents($path, str_replace("<?php\n", "<?php\n// Keep the author's tileset notes.\n", (string) file_get_contents($path)));

    return $root;
}

function readFootprintAuthoringPiece(EditorSession $session, string $id = 'object', int $index = 0): array
{
    return array_find($session->readTilesetPreview($index)['authoring'], static fn(array $piece): bool => $piece['id'] === $id)
        ?? throw new RuntimeException("No synthetic piece {$id}.");
}

it('edits recipes through shared record history and source preserving save undo redo', function () {
    $old = [[CollisionType::SOLID, null], [CollisionType::NONE, CollisionType::COUNTER]];
    $root = createFootprintAuthoringProject(['occupancy' => $old]);
    $path = $root . '/assets/Data/Tilesets/home.php';
    file_put_contents($path, str_replace("'occupancy' =>", "// Preserve this interior footprint note.\n      'occupancy' =>", (string) file_get_contents($path)));
    $mapBefore = sourceHashTree($root . '/assets/Maps');
    $session = EditorSession::open($root);
    $record = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::all(true)['tilesets'], graphical: true)->getRecordByIndex(0);
    expect($record->isEditable())->toBeTrue($record->getReadOnlyReason() ?? 'The synthetic source must remain editable.');
    $piece = readFootprintAuthoringPiece($session);
    expect($piece)->toMatchArray(['width' => 2, 'height' => 2, 'occupancy' => [[1, null], [0, 10]],
        'occupancyDeclared' => true, 'occupancyIssue' => null])
        ->and($piece['occupancyKey'])->toBeArray();
    $row = array_find($session->readDatabaseRecord('tilesets', 0)['rows'], static fn(array $row): bool =>
        ($row['key']['field'] ?? null) === 'piece0Occupancy');
    expect($piece['occupancyKey'])->toBe($row['key']);
    $mask = [[null, 0], [10, 1]];
    expect($session->setTilesetOccupancy(0, 'home', 'object', $piece['occupancy'], $mask))
        ->toMatchArray(['changed' => true, 'occupancy' => $mask])
        ->and($session->setTilesetOccupancy(0, 'home', 'object', $mask, $mask)['changed'])->toBeFalse();
    $session->saveDatabase('tilesets');
    expect(Tileset::load($root . '/assets', 'home')->pieces['object']->occupancy)
        ->toBe([[null, CollisionType::NONE], [CollisionType::COUNTER, CollisionType::SOLID]])
        ->and(file_get_contents($path))->toContain('// Keep the author', '// Preserve this interior footprint note.', 'CollisionType::COUNTER', '<info>xx</info>');
    expect($session->undo())->toMatchArray(['databases' => ['tilesets'], 'maps' => []]);
    $session->saveDatabase('tilesets');
    expect(Tileset::load($root . '/assets', 'home')->pieces['object']->occupancy)->toBe($old)
        ->and(file_get_contents($path))->toContain('// Keep the author', '// Preserve this interior footprint note.');
    $session->redo();
    $session->saveDatabase('tilesets');
    expect(EditorSession::open($root)->readTilesetPreview(0)['authoring'][0]['occupancy'])->toBe($mask)
        ->and(sourceHashTree($root . '/assets/Maps'))->toBe($mapBefore);
});

it('adds an all null recipe and removes the field rather than persisting explicit null', function () {
    $root = createFootprintAuthoringProject();
    $path = $root . '/assets/Data/Tilesets/home.php';
    $session = EditorSession::open($root);
    $mask = [[null, null], [null, null]];
    expect(readFootprintAuthoringPiece($session))->toMatchArray(['occupancy' => null, 'occupancyDeclared' => false]);
    $session->setTilesetOccupancy(0, 'home', 'object', null, $mask);
    $session->saveDatabase('tilesets');
    expect(Tileset::load($root . '/assets', 'home')->pieces['object']->occupancy)->toBe($mask)
        ->and(file_get_contents($path))->toContain("'occupancy'");
    $session->setTilesetOccupancy(0, 'home', 'object', $mask, null);
    $session->saveDatabase('tilesets');
    expect(file_get_contents($path))->not->toContain("'occupancy'")
        ->and(readFootprintAuthoringPiece($session))->toMatchArray(['occupancy' => null, 'occupancyDeclared' => false]);
    $session->undo();
    $session->saveDatabase('tilesets');
    expect(Tileset::load($root . '/assets', 'home')->pieces['object']->occupancy)->toBe($mask);
    $session->redo();
    $session->saveDatabase('tilesets');
    expect(Tileset::load($root . '/assets', 'home')->pieces['object']->occupancy)->toBeNull();
});

it('retains glyph geometry and a repair key for a wrong size recipe independently of invalid graphics', function () {
    $root = createFootprintAuthoringProject(['occupancy' => [[CollisionType::SOLID]], 'tiles' => ['objects' => ['invalid']], 'effect' => '../invalid']);
    $session = EditorSession::open($root);
    $piece = readFootprintAuthoringPiece($session);
    expect($piece)->toMatchArray(['width' => 2, 'height' => 2, 'issue' => null, 'occupancy' => [[1]]])
        ->and($piece['occupancyIssue'])->toContain('occupancy must have 2 rows')
        ->and($piece['occupancyKey'])->toBeArray();
    expect($session->setTilesetOccupancy(0, 'home', 'object', [[1]], [[null, 0], [1, 10]])['changed'])->toBeTrue()
        ->and(readFootprintAuthoringPiece($session)['occupancyIssue'])->toBeNull();
});

it('exposes invalid recipe diagnostics without inventing a mask and permits explicit record row repair', function (mixed $bad) {
    $root = createFootprintAuthoringProject(['occupancy' => $bad]);
    $session = EditorSession::open($root);
    $piece = readFootprintAuthoringPiece($session);
    expect($piece)->toMatchArray(['width' => 2, 'height' => 2, 'issue' => null, 'occupancy' => null, 'occupancyDeclared' => true])
        ->and($piece['occupancyIssue'])->toContain('occupancy')
        ->and($piece['occupancyKey'])->toBeArray()
        ->and(fn() => $session->setTilesetOccupancy(0, 'home', 'object', null, [[1, null], [0, 10]]))
        ->toThrow(SessionRefusal::class, 'cannot be compared safely');
    expect($session->listDatabaseRecords('tilesets')['dirty'])->toBeFalse();
    $session->applyDatabaseRecord('tilesets', 0, $piece['occupancyKey'], '[[1,null],[0,10]]');
    $session->saveDatabase('tilesets');
    expect(Tileset::load($root . '/assets', 'home')->pieces['object']->occupancy)
        ->toBe([[CollisionType::SOLID, null], [CollisionType::NONE, CollisionType::COUNTER]])
        ->and(readFootprintAuthoringPiece($session)['occupancyIssue'])->toBeNull();
})->with([
    'explicit null' => [null], 'scalar' => ['bad'], 'non list' => [['row' => [CollisionType::SOLID]]],
    'integer instead of enum' => [[[1, null], [null, null]]],
    'pass through' => [[[CollisionType::PASS_THROUGH, null], [null, null]]],
    'unsupported enum' => [[[SourceWriterUnit::FIRST, null], [null, null]]],
]);

it('uses one shared connected recipe and preserves connected shapes when editing it', function () {
    $root = createFootprintAuthoringProject(['connects' => 'lines', 'glyphs' => [
        'horizontal' => '<info>-</info>', 'vertical' => '|', 'corner' => '+'], 'tiles' => ['objects' => '12']]);
    $session = EditorSession::open($root);
    $session->setTilesetOccupancy(0, 'home', 'object', null, [[10]]);
    $session->saveDatabase('tilesets');
    $piece = Tileset::load($root . '/assets', 'home')->pieces['object'];
    expect($piece->occupancy)->toBe([[CollisionType::COUNTER]])
        ->and([$piece->width, $piece->height])->toBe([1, 1])
        ->and($piece->getSourceShapeGrid())->toBe(['horizontal' => '<info>-</info>', 'vertical' => '|', 'corner' => '+'])
        ->and(fn() => $session->setTilesetOccupancy(0, 'home', 'object', [[10]], [[10, 10]]))
        ->toThrow(SessionRefusal::class, 'must be 1 cells wide');
});

it('refuses stale record identity piece identity and expected recipe without any mutation', function () {
    $root = createFootprintAuthoringProject(['occupancy' => [[CollisionType::NONE, null], [null, null]]]);
    $session = EditorSession::open($root);
    $before = sourceHashTree($root);
    foreach ([
        [0, 'other', 'object', [[0, null], [null, null]], [[1, null], [null, null]]],
        [0, 'home', 'missing', [[0, null], [null, null]], [[1, null], [null, null]]],
        [0, 'home', 'object', null, [[1, null], [null, null]]],
        [0, 'home', 'object', [[1, null], [null, null]], [[1, null], [null, null]]],
    ] as $args) {
        expect(fn() => $session->setTilesetOccupancy(...$args))->toThrow(SessionRefusal::class)
            ->and($session->listDatabaseRecords('tilesets')['dirty'])->toBeFalse()
            ->and(sourceHashTree($root))->toBe($before);
    }
    $session->setTilesetOccupancy(0, 'home', 'object', [[0, null], [null, null]], [[1, null], [null, null]]);
    expect(fn() => $session->setTilesetOccupancy(0, 'home', 'object', [[0, null], [null, null]], [[10, null], [null, null]]))
        ->toThrow(SessionRefusal::class, 'changed')
        ->and(readFootprintAuthoringPiece($session)['occupancy'])->toBe([[1, null], [null, null]]);
});

it('resolves the fresh occupancy row by stable piece id after another piece is removed', function () {
    $root = createFootprintAuthoringProject(pieces: [
        'first' => ['name' => 'First', 'layer' => 'fixtures', 'glyphs' => ['x']],
        'object' => ['name' => 'Target', 'layer' => 'fixtures', 'glyphs' => ['x']],
    ]);
    $session = EditorSession::open($root);
    expect(readFootprintAuthoringPiece($session)['occupancyKey']['field'])->toBe('piece1Occupancy');
    $session->removeDatabaseItem('tilesets', 0, ['field' => 'piece0Name', 'frame' => []]);
    expect($session->setTilesetOccupancy(0, 'home', 'object', null, [[1]])['changed'])->toBeTrue()
        ->and(readFootprintAuthoringPiece($session)['occupancyKey']['field'])->toBe('piece0Occupancy');
    $session->saveDatabase('tilesets');
    expect(array_keys(Tileset::load($root . '/assets', 'home')->pieces))->toBe(['object'])
        ->and(Tileset::load($root . '/assets', 'home')->pieces['object']->occupancy)->toBe([[CollisionType::SOLID]]);
});

it('refuses unsupported computed recipe source before any record or history mutation', function () {
    $root = createFootprintAuthoringProject();
    $path = $root . '/assets/Data/Tilesets/home.php';
    $source = <<<'PHP'
    <?php
    use Ichiloto\Engine\Events\Enumerations\CollisionType;
    // Keep this computation, not a flattened replacement.
    return ['name' => 'Home', 'sheets' => ['B' => 'Graphics/Tilesets/Home_B.png'], 'pieces' => [
        'object' => ['name' => 'Computed object', 'layer' => 'fixtures', 'glyphs' => ['xx', 'xx'],
            'occupancy' => (static fn(): array => [[CollisionType::NONE, null], [null, null]])()],
    ]];
    PHP;
    file_put_contents($path, $source);
    $session = EditorSession::open($root);
    $before = sourceHashTree($root);
    expect(fn() => $session->setTilesetOccupancy(0, 'home', 'object', [[0, null], [null, null]], [[1, null], [null, null]]))
        ->toThrow(SessionRefusal::class)
        ->and($session->listDatabaseRecords('tilesets')['dirty'])->toBeFalse()
        ->and(readFootprintAuthoringPiece($session)['occupancy'])->toBe([[0, null], [null, null]])
        ->and(sourceHashTree($root))->toBe($before)
        ->and($session->undo())->toMatchArray(['label' => null, 'maps' => [], 'databases' => []]);
});

it('omits the recipe field from TUI schemas while preserving authored recipes during unrelated terminal edits', function () {
    $mask = [[CollisionType::SOLID, null], [CollisionType::NONE, CollisionType::COUNTER]];
    $root = createFootprintAuthoringProject(['occupancy' => $mask]);
    $terminal = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::all(false)['tilesets']);
    $graphical = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::all(true)['tilesets'], graphical: true);
    expect(array_column($terminal->getSettingsFields(0), 'field'))->not->toContain('piece0Occupancy')
        ->and(array_column($graphical->getSettingsFields(0), 'field'))->toContain('piece0Occupancy');
    $terminal->setField(0, 'name', 'Updated name');
    $terminal->save();
    expect(Tileset::load($root . '/assets', 'home')->pieces['object']->occupancy)->toBe($mask);
});

it('refuses invalid recipe geometry at the dedicated Engine validation boundary without partial edits', function (array $mask) {
    $root = createFootprintAuthoringProject();
    $session = EditorSession::open($root);
    $before = sourceHashTree($root);
    expect(fn() => $session->setTilesetOccupancy(0, 'home', 'object', null, $mask))->toThrow(SessionRefusal::class)
        ->and($session->listDatabaseRecords('tilesets')['dirty'])->toBeFalse()
        ->and(sourceHashTree($root))->toBe($before);
})->with(['empty' => [[]], 'short height' => [[[1, null]]], 'ragged' => [[[1], [null, null]]],
    'wide' => [[[1, null, null], [null, null, null]]]]);

it('validates recipe RPC identities expected baseline and JSON list types before editing', function () {
    $root = createFootprintAuthoringProject();
    $input = fopen('php://memory', 'r');
    $output = fopen('php://memory', 'w+');
    $diagnostics = fopen('php://memory', 'w+');
    $host = new SessionHost($input, $output, $diagnostics);
    $request = static fn(string $method, array $params): array => $host->handle(json_encode(
        ['id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
    try {
        expect($request('hello', ['project' => $root, 'protocol' => SessionHost::PROTOCOL]))->toHaveKey('result');
        $params = ['index' => 0, 'tileset' => 'home', 'piece' => 'object', 'expected' => null, 'value' => [[1, null], [0, 10]]];
        foreach ([['index' => '0'], ['tileset' => 'Home'], ['piece' => ''], ['value' => 'bad'], ['value' => 1],
            ['value' => [[true, null], [0, 10]]], ['value' => [['1', null], [0, 10]]], ['value' => [[1.0, null], [0, 10]]],
            ['value' => [[9, null], [0, 10]]], ['value' => [[999, null], [0, 10]]], ['value' => (object) ['0' => [1, null], '1' => [0, 10]]],
            ['value' => [(object) ['0' => 1, '1' => null], [0, 10]]], ['expected' => [[9, null], [0, 10]]]] as $invalid) {
            $wire = json_encode(['id' => 1, 'method' => 'tileset.setOccupancy', 'params' => $invalid + $params], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            expect($host->handle($wire)['error']['kind'])->toBe('request');
        }
        foreach (['index', 'tileset', 'piece', 'expected', 'value'] as $missing) {
            $invalid = $params;
            unset($invalid[$missing]);
            expect($request('tileset.setOccupancy', $invalid)['error']['kind'])->toBe('request');
        }
        expect($request('database.records', ['category' => 'tilesets'])['result']['dirty'])->toBeFalse()
            ->and($request('tileset.setOccupancy', $params)['result'])->toMatchArray(['changed' => true, 'occupancy' => $params['value']])
            ->and($request('tileset.setOccupancy', ['expected' => $params['value']] + $params)['result'])
            ->toMatchArray(['changed' => false, 'occupancy' => $params['value']])
            ->and($request('tileset.setOccupancy', $params)['error']['kind'])->toBe('refusal');
        $params['expected'] = $params['value'];
        $params['value'] = null;
        expect($request('tileset.setOccupancy', $params)['result']['occupancy'])->toBeNull();
        rewind($diagnostics);
        expect(stream_get_contents($diagnostics))->toBe('');
    } finally {
        $host->run();
        fclose($input);
        fclose($output);
        fclose($diagnostics);
    }
});

it('returns a recipe RPC refusal without successful occupancy metadata when nonliteral authored source cannot be edited', function () {
    $root = createFootprintAuthoringProject();
    $path = $root . '/assets/Data/Tilesets/home.php';
    file_put_contents($path, <<<'PHP'
    <?php
    use Ichiloto\Engine\Events\Enumerations\CollisionType;
    return (static fn(): array => [
        // Preserve this nonliteral, author-owned source computation.
        'name' => 'Home', 'sheets' => ['B' => 'Graphics/Tilesets/Home_B.png'], 'pieces' => [
            'object' => ['name' => 'Computed object', 'layer' => 'fixtures', 'glyphs' => ['xx', 'xx'],
                'occupancy' => [[CollisionType::NONE, null], [null, null]]],
        ],
    ])();
    PHP);
    $input = fopen('php://memory', 'r');
    $output = fopen('php://memory', 'w+');
    $diagnostics = fopen('php://memory', 'w+');
    $host = new SessionHost($input, $output, $diagnostics);
    $request = static fn(string $method, array $params): array => $host->handle(json_encode(
        ['id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
    $before = sourceHashTree($root);
    try {
        $request('hello', ['project' => $root, 'protocol' => SessionHost::PROTOCOL]);
        $reply = $request('tileset.setOccupancy', ['index' => 0, 'tileset' => 'home', 'piece' => 'object',
            'expected' => [[0, null], [null, null]], 'value' => [[1, null], [null, null]]]);
        expect($reply)->not->toHaveKey('result')
            ->and($reply['error']['kind'])->toBe('refusal')
            ->and($request('database.records', ['category' => 'tilesets'])['result']['dirty'])->toBeFalse()
            ->and($request('history.undo', [])['result'])->toMatchArray(['label' => null, 'maps' => [], 'databases' => []])
            ->and(sourceHashTree($root))->toBe($before);
        rewind($diagnostics);
        expect(stream_get_contents($diagnostics))->toBe('');
    } finally {
        $host->run();
        fclose($input);
        fclose($output);
        fclose($diagnostics);
    }
});
