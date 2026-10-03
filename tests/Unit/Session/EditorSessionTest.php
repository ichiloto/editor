<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * The editor session every interface edits through: documents read as an
 * interface draws them, a paint as one undo step that a stale revision cannot
 * overwrite, history across maps, and saving through the map's transaction.
 */
function sessionCell(array $map, string $layer, int $x, int $y): string
{
    $found = array_find($map['layers'], static fn(array $candidate): bool => $candidate['id'] === $layer);

    return $found['rows'][$y][$x] ?? throw new RuntimeException("No cell {$x},{$y} on {$layer}.");
}

it('describes the project: its maps and every database category', function () {
    $session = EditorSession::open(makeTemporaryProject());
    $project = $session->describeProject();

    expect(array_column($project['maps'], 'id'))->toContain('test-map')
        ->and($project['maps'][0])->toHaveKeys(['id', 'name', 'dirty', 'readOnly', 'revision'])
        ->and(array_column($project['databases'], 'key'))->toBe(array_map(static fn($category): string => $category->key, DatabaseCatalog::all()));
});

it('reads a map as an interface draws it: layers, glyphs, events and NPCs', function () {
    $root = makeTemporaryProject();
    $map = EditorSession::open($root)->readMap('test-map');
    $authored = ProjectWorkspace::fromProject($root)->maps[0];

    expect($map['width'])->toBe($authored->getWidth())
        ->and($map['height'])->toBe($authored->getHeight())
        ->and(array_column($map['layers'], 'id'))->toContain($map['baseLayer'], 'event')
        ->and(sessionCell($map, $map['baseLayer'], 0, 0))->toBe($authored->getLayerSymbol($map['baseLayer'], 0, 0))
        ->and(array_column($map['events'], 'marker'))->toBe(array_keys($authored->getEventDefinitions()))
        ->and($map['dirty'])->toBeFalse();
});

it('paints as one undo step, undoes and redoes it, and refuses an edit made against an older revision', function () {
    $session = EditorSession::open(makeTemporaryProject());
    $map = $session->readMap('test-map');
    $layer = $map['baseLayer'];
    $before = sessionCell($map, $layer, 2, 2);

    $result = $session->paint('test-map', $map['revision'], $layer, [[2, 2], [3, 2]], '%', 'red');
    $painted = $session->readMap('test-map');

    expect($result['status'])->toBe('applied')
        ->and($result['changed'])->toBe(2)
        ->and($result['revision'])->toBe($painted['revision'])
        ->and(sessionCell($painted, $layer, 2, 2))->toBe('%')
        ->and(array_find(array_find($painted['layers'], static fn(array $l): bool => $l['id'] === $layer)['colors'],
            static fn(array $c): bool => $c[0] === 2 && $c[1] === 2)[2] ?? null)->toBe('red')
        ->and($painted['dirty'])->toBeTrue();

    // The same request again carries a revision that is no longer current.
    expect(fn() => $session->paint('test-map', $map['revision'], $layer, [[4, 2]], '%'))
        ->toThrow(SessionRefusal::class, 'changed since revision');

    expect($session->undo())->toBe(['label' => 'Paint', 'maps' => ['test-map']])
        ->and(sessionCell($session->readMap('test-map'), $layer, 2, 2))->toBe($before);
    expect($session->redo()['maps'])->toBe(['test-map'])
        ->and(sessionCell($session->readMap('test-map'), $layer, 2, 2))->toBe('%');
    expect($session->undo()['label'])->toBe('Paint')
        ->and($session->undo())->toBe(['label' => null, 'maps' => []]);
});

it('saves a painted map through its transaction, so a fresh load sees the edit', function () {
    $root = makeTemporaryProject();
    $session = EditorSession::open($root);
    $map = $session->readMap('test-map');
    $session->paint('test-map', $map['revision'], $map['baseLayer'], [[1, 1]], '#');

    $saved = $session->saveMap('test-map');

    expect($saved['saved'])->toBe('test-map')
        ->and($session->hasUnsavedChanges())->toBeFalse()
        ->and(ProjectWorkspace::fromProject($root)->maps[0]->getLayerSymbol($map['baseLayer'], 1, 1))->toBe('#');
});

it('refuses an unknown map or layer with a reason an author reads', function () {
    $session = EditorSession::open(makeTemporaryProject());
    $map = $session->readMap('test-map');

    expect(fn() => $session->readMap('no-such-map'))->toThrow(SessionRefusal::class, 'There is no map no-such-map.')
        ->and(fn() => $session->paint('test-map', $map['revision'], 'no-layer', [[0, 0]], '#'))
            ->toThrow(SessionRefusal::class, 'test-map has no layer no-layer.');
});

it('serves the session over its line protocol, answering refusals and bad requests by kind', function () {
    $root = makeTemporaryProject();
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(int $id, string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => $id, 'method' => $method, 'params' => $params]));

    expect($request(1, 'maps.list')['error']['kind'])->toBe('request')
        ->and($request(2, 'hello', ['protocol' => 99, 'project' => $root])['error']['message'])->toContain('protocol 1')
        ->and($host->handle('not json')['error']['kind'])->toBe('request');

    $hello = $request(3, 'hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);
    expect($hello['id'])->toBe(3)
        ->and($hello['result']['protocol'])->toBe(SessionHost::PROTOCOL)
        ->and(array_column($hello['result']['project']['maps'], 'id'))->toContain('test-map');

    $map = $request(4, 'map.read', ['map' => 'test-map'])['result'];
    expect($request(5, 'map.paint', ['map' => 'test-map', 'revision' => $map['revision'], 'layer' => $map['baseLayer'],
        'cells' => [[0, 0]], 'symbol' => '%'])['result']['status'])->toBe('applied')
        ->and($request(6, 'map.paint', ['map' => 'test-map', 'revision' => $map['revision'], 'layer' => $map['baseLayer'],
            'cells' => [[0, 0]], 'symbol' => '#'])['error']['kind'])->toBe('refusal')
        ->and($request(7, 'map.paint', ['map' => 'test-map', 'revision' => 1, 'layer' => 'tile', 'cells' => [[0]], 'symbol' => '#'])['error'])
            ->toBe(['kind' => 'request', 'message' => '"cells" must be a list of [x, y] integer pairs.'])
        ->and($request(8, 'no.such')['error']['message'])->toBe('Unknown method "no.such".')
        ->and($request(9, 'history.undo')['result']['maps'])->toBe(['test-map']);
});

/** The inspector row with a label, the first when several share it. */
function inspectorRow(array $inspector, string $label, ?string $after = null): array
{
    $rows = $inspector['rows'];
    $start = $after === null ? 0 : array_search($after, array_column($rows, 'label'), true) + 1;
    foreach (array_slice($rows, $start) as $row) {
        if (trim($row['label']) === $label) {
            return $row;
        }
    }

    throw new RuntimeException("No inspector row {$label}.");
}

it('lists a map\'s inspector rows with how each is edited and the key that names it', function () {
    $session = EditorSession::open(makeTemporaryProject());
    $inspector = $session->readInspector('test-map');

    expect(inspectorRow($inspector, 'Name'))->toMatchArray(['kind' => 'text', 'key' => ['target' => 'map', 'field' => 'name']])
        ->and(inspectorRow($inspector, 'X', 'Size')['kind'])->toBe('integer')
        ->and(inspectorRow($inspector, 'Events')['kind'])->toBe('info')
        ->and(inspectorRow($inspector, 'Kind')['kind'])->toBe('info')
        ->and(inspectorRow($inspector, 'Background Music'))->toMatchArray(['kind' => 'reference', 'reference' => 'bgm'])
        ->and($inspector['revision'])->toBe($session->readMap('test-map')['revision']);
});

it('applies an inspector edit as one undo step and refuses a stale or read-only row', function () {
    $session = EditorSession::open(makeTemporaryProject());
    $inspector = $session->readInspector('test-map');
    $name = inspectorRow($inspector, 'Name');

    $applied = $session->applyInspector('test-map', $inspector['revision'], $name['key'], 'Renamed Map');

    expect($applied['changed'])->toBeTrue()
        ->and($session->readMap('test-map')['name'])->toBe('Renamed Map')
        ->and(fn() => $session->applyInspector('test-map', $inspector['revision'], $name['key'], 'Again'))
            ->toThrow(SessionRefusal::class, 'changed since revision');

    expect($session->undo()['maps'])->toBe(['test-map'])
        ->and($session->readMap('test-map')['name'])->toBe($name['value']);

    $fresh = $session->readInspector('test-map');
    expect(fn() => $session->applyInspector('test-map', $fresh['revision'], ['target' => 'map', 'field' => 'nope'], 'x'))
        ->toThrow(SessionRefusal::class, 'no longer in the inspector');
});

it('moves an event through its position row and lists the choices of a reference row', function () {
    $session = EditorSession::open(makeTemporaryProject());
    $event = $session->readInspector('test-map', 'E');
    $x = inspectorRow($event, 'X', 'Position');
    $before = (int) $x['value'];

    expect(inspectorRow($event, 'Type')['kind'])->toBe('info')
        ->and($x['key'])->toBe(['target' => 'event-bounds', 'field' => 'x', 'marker' => 'E']);

    $session->applyInspector('test-map', $event['revision'], $x['key'], (string) ($before + 1));
    $moved = array_find($session->readMap('test-map')['events'], static fn(array $candidate): bool => $candidate['marker'] === 'E');

    expect($moved['cells'][0][0])->toBe($before + 1)
        ->and(fn() => $session->readInspector('test-map', 'Q'))->toThrow(SessionRefusal::class, 'test-map has no event Q.')
        ->and(array_column($session->listReferences('test-map', 'bgm'), 'value'))->toBeArray()
        ->and(fn() => $session->listReferences('test-map', 'no-such-category'))->toThrow(SessionRefusal::class);
});

it('lists a schema database\'s records and reads one record\'s rows', function () {
    $session = EditorSession::open(makeTemporaryProject());
    $items = $session->listDatabaseRecords('items');
    $record = $session->readDatabaseRecord('items', 0);

    expect($items['records'])->not->toBe([])
        ->and($items['editable'])->toBeTrue()
        ->and(array_column($record['rows'], 'label'))->toContain('Name')
        ->and(array_find($record['rows'], static fn(array $row): bool => $row['label'] === 'Name')['value'])->toBe($items['records'][0])
        ->and(fn() => $session->readDatabaseRecord('items', 999))->toThrow(SessionRefusal::class, 'items has no record 999.')
        ->and(fn() => $session->listDatabaseRecords('actors'))->toThrow(SessionRefusal::class, 'edited in the terminal editor for now')
        ->and(fn() => $session->listDatabaseRecords('nope'))->toThrow(SessionRefusal::class, 'There is no database category nope.');
});

it('reads a map\'s world as the game uploads it, its glyph layers named by the editor\'s layer ids', function () {
    $root = mapGraphicsProject();
    $session = EditorSession::open($root);
    $world = $session->readWorld('test-map');
    $put = $world['operations'][0];

    expect($put['op'])->toBe('put')
        ->and($put['value']['tileset']['sheets'])->toBe(['Graphics/Tilesets/Home_A2.png', 'Graphics/Tilesets/Home_B.png'])
        ->and(array_column($put['value']['layers'], 'id'))->toContain('tiles:floor', 'tiles:decor')
        ->and(array_values($world['layerIds']))->toBe(array_values(array_filter(array_column($put['value']['layers'], 'id'),
            static fn(string $id): bool => str_starts_with($id, 'map:'))))
        ->and(array_keys($world['layerIds']))->toBe(array_values(array_filter(array_column($session->readMap('test-map')['layers'], 'id'),
            static fn(string $id): bool => $id !== 'event')))
        ->and($world['assetRoot'])->toBe($root . '/assets')
        ->and($world['graphicsIssue'])->toBeNull();
});

it('still draws a map\'s glyphs when the game would refuse its graphics, saying why', function () {
    $root = mapGraphicsProject();
    $data = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($data, str_replace("'tileset' => 'home'", "'tileset' => 'missing'", (string) file_get_contents($data)));
    $world = EditorSession::open($root)->readWorld('test-map');

    expect($world['graphicsIssue'])->toContain('missing')
        ->and($world['operations'][0]['value'])->not->toHaveKey('tileset')
        ->and(array_column($world['operations'], 'op'))->toContain('worldRows');
});

it('lays out the tile palette as RPG Maker MZ does, drawn by the same world', function () {
    $session = EditorSession::open(mapGraphicsProject());
    $palette = $session->readTilePalette('test-map');
    [$a, $b] = $palette['tabs'];

    // A2's 32 autotile kinds, one entry each; B tile by tile from the empty tile.
    expect(array_column($palette['tabs'], 'name'))->toBe(['A', 'B'])
        ->and($a['ids'][0])->toBe([2816, 2864, 2912, 2960, 3008, 3056, 3104, 3152])
        ->and(count($a['ids']))->toBe(4)
        ->and($b['ids'][0])->toBe([0, 1, 2, 3, 4, 5, 6, 7])
        ->and(count($b['ids']))->toBe(32)
        ->and($b['operations'][0]['id'])->toBe('palette:B')
        ->and($b['operations'][0]['value']['columns'])->toBe(8)
        ->and(fn() => EditorSession::open(makeTemporaryProject())->readTilePalette('test-map'))
        ->toThrow(SessionRefusal::class, 'names no tileset');
});
