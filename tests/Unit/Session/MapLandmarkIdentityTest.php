<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * A landmark's stable id, chosen by its author through its Id row. Other maps name a landmark as their atlas entry,
 * so a rename carries those entries with it in one undo step, or renames nothing.
 */

/**
 * The world/test-map atlas project with test-map entering the world at its town-gate landmark, the entry written as
 * the given PHP expression.
 *
 * @return array{string, string, EditorSession}
 */
function createLandmarkIdentityProject(string $entry = "'town-gate'"): array
{
    [$root, $file] = createAtlasProject();
    file_put_contents($file, str_replace("// Map-owned presentation stays authored.\n",
        "// Map-owned presentation stays authored.\n    'atlas' => ['parent' => ['map' => 'world', 'landmark' => {$entry}]],\n",
        (string) file_get_contents($file)));

    return [$root, $file, EditorSession::open($root)];
}

it('renames a landmark and the atlas entry naming it as one undo step, saving both as authored', function () {
    [$root, $file, $session] = createLandmarkIdentityProject();
    $row = findLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'id', 'index' => 0], mapId: 'world');
    expect($row)->toMatchArray(['kind' => 'text', 'value' => 'town-gate', 'raw' => 'town-gate', 'label' => 'Id']);

    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
    $request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);
    $revision = $request('map.read', ['map' => 'world'])['result']['revision'];
    $childRevision = $request('map.read', ['map' => 'test-map'])['result']['revision'];

    $applied = $request('inspector.apply', ['map' => 'world', 'revision' => $revision, 'key' => $row['key'], 'value' => 'harbour-gate'])['result'];
    expect($applied)->toMatchArray(['status' => 'applied', 'changed' => true])
        ->and(array_keys($applied['revisions']))->toBe(['test-map'])
        ->and($applied['revisions']['test-map'])->not->toBe($childRevision)
        ->and($request('map.read', ['map' => 'test-map'])['result']['revision'])->toBe($applied['revisions']['test-map']);

    $undone = $request('history.undo')['result'];
    expect($undone['label'])->toBe('Landmark rename town-gate to harbour-gate')
        ->and($undone['maps'])->toEqualCanonicalizing(['world', 'test-map']);
    $request('history.redo');
    $saved = $request('project.saveAll')['result'];
    expect($saved['failures'] ?? [])->toBe([]);

    $world = (string) file_get_contents($root . '/assets/Maps/world/world.data.php');
    $child = (string) file_get_contents($file);
    expect($world)->toContain("'harbour-gate'")->not->toContain("'town-gate'")
        ->and($child)->toContain('// Map-owned presentation stays authored.', "'harbour-gate'")->not->toContain("'town-gate'");
    $loaded = ProjectWorkspace::fromProject($root);
    $testMap = array_find($loaded->maps, static fn($map): bool => $map->mapId === 'test-map');
    expect($testMap->getMapDataField(['atlas', 'parent']))->toBe(['map' => 'world', 'landmark' => 'harbour-gate'])
        ->and(Ichiloto\Editor\Field\MapAtlas::resolveGlobal($testMap, Ichiloto\Editor\Field\MapAtlas::readBlock($testMap),
            static fn(string $id) => array_find($loaded->maps, static fn($map): bool => $map->mapId === $id)))
        ->toBe(['map' => 'world', 'landmark' => 'harbour-gate']);
});

it('names a landmark that had no id, and keeps its translation key through a rename', function () {
    [, , $session] = createLandmarkProject();
    $heading = findLandmarkRow($session, ['target' => 'map-landmarks']);
    $session->addInspectorListEntry('test-map', $session->readMap('test-map')['revision'], $heading['key']);
    applyLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'name', 'index' => 0], 'Harbour Gate');
    $key = readLandmarkEntries($session)[0]['name']['message'];

    $applied = applyLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'id', 'index' => 0], ' harbour-gate ');
    expect($applied)->toMatchArray(['changed' => true])->not->toHaveKey('revisions')
        ->and(readLandmarkEntries($session)[0])->toMatchArray(['id' => 'harbour-gate', 'name' => ['message' => $key, 'default' => 'Harbour Gate']])
        ->and(findLandmarkRow($session, ['target' => 'map-landmarks', 'index' => 0])['label'])->toBe('Landmark harbour-gate')
        ->and(applyLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'id', 'index' => 0], 'harbour-gate')['changed'])->toBeFalse();
});

it('refuses an id the Engine would not load or another landmark has, changing nothing', function (string $id, string $reason) {
    [, , $session] = createLandmarkIdentityProject();
    $heading = findLandmarkRow($session, ['target' => 'map-landmarks'], mapId: 'world');
    $session->addInspectorListEntry('world', $session->readMap('world')['revision'], $heading['key']);
    $revision = $session->readMap('world')['revision'];
    $entries = readLandmarkEntries($session, 'world');

    expect(fn() => applyLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'id', 'index' => 0], $id, mapId: 'world'))
        ->toThrow(SessionRefusal::class, $reason)
        ->and(readLandmarkEntries($session, 'world'))->toBe($entries)
        ->and($session->readMap('world')['revision'])->toBe($revision)
        ->and(getSessionMap($session)->getMapDataField(['atlas', 'parent', 'landmark']))->toBe('town-gate');
})->with([
    'spaces' => ['Harbour Gate', 'is not a landmark id'],
    'leading digit' => ['1-gate', 'is not a landmark id'],
    'blank' => ['', 'is not a landmark id'],
    'too long' => [str_repeat('a', 65), 'is not a landmark id'],
    'taken' => ['landmark-1', 'Another landmark on world is already landmark-1.'],
]);

it('renames nothing when a map naming the landmark cannot take the new entry in its source', function () {
    [$root, $file, $session] = createLandmarkIdentityProject("implode('-', ['town', 'gate'])");
    $before = sourceHashTree($root);
    $revision = $session->readMap('world')['revision'];

    expect(fn() => applyLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'id', 'index' => 0], 'harbour-gate', mapId: 'world'))
        ->toThrow(SessionRefusal::class, 'Landmark town-gate was not renamed')
        ->and(readLandmarkEntries($session, 'world')[0]['id'])->toBe('town-gate')
        ->and($session->readMap('world')['revision'])->toBe($revision)
        ->and($session->readMap('world')['dirty'])->toBeFalse()
        ->and($session->readMap('test-map')['dirty'])->toBeFalse()
        ->and($session->undo()['label'])->toBeNull()
        ->and(sourceHashTree($root))->toBe($before);
});
