<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

it('creates a tileset with a stable file identity through undo save and reopen', function () {
    $root = makeTemporaryProject();
    writeTestTileset($root);
    $original = file_get_contents($root . '/assets/Data/Tilesets/home.php');
    $session = EditorSession::open($root);
    $index = $session->createDatabaseRecord('tilesets', 'meadow')['index'];
    $key = ['target' => 'record', 'field' => 'name', 'frame' => []];
    expect(is_file($root . '/assets/Data/Tilesets/meadow.php'))->toBeFalse()
        ->and($session->undo()['databases'])->toBe(['tilesets'])
        ->and($session->listDatabaseRecords('tilesets')['records'])->toBe(['Home'])
        ->and($session->redo()['databases'])->toBe(['tilesets']);
    $session->applyDatabaseRecord('tilesets', $index, $key, 'Meadow Terrain');
    $session->applyDatabaseRecord('tilesets', $index, ['target' => 'record', 'field' => 'sheets.A2', 'frame' => []], 'Graphics/Tilesets/Home_A2.png');
    $session->saveDatabase('tilesets');
    $session->close();

    expect(require $root . '/assets/Data/Tilesets/meadow.php')->toBe(['name' => 'Meadow Terrain', 'sheets' => ['A2' => 'Graphics/Tilesets/Home_A2.png']])
        ->and(file_get_contents($root . '/assets/Data/Tilesets/home.php'))->toBe($original);
    $reopened = EditorSession::open($root);
    expect($reopened->listDatabaseRecords('tilesets')['records'])->toContain('Meadow Terrain')
        ->and(array_column($reopened->listReferences(null, 'tilesets'), 'value'))->toContain('meadow');
    $reopened->close();
});

it('duplicates file-identified records under the requested identity without changing the original', function (string $category, string $source, string $copy) {
    $root = makeTemporaryProject();
    writeTestTileset($root);
    $directory = $category === 'tilesets' ? '/assets/Data/Tilesets/' : '/assets/Events/';
    $originalPath = $root . $directory . $source . '.php';
    $original = file_get_contents($originalPath);
    $session = EditorSession::open($root);
    $session->duplicateDatabaseRecord($category, 0, $copy);
    expect($session->undo()['databases'])->toBe([$category])
        ->and($session->listDatabaseRecords($category)['dirty'])->toBeFalse()
        ->and($session->redo()['databases'])->toBe([$category]);
    $session->saveDatabase($category);
    $session->close();
    expect(is_file($root . $directory . $copy . '.php'))->toBeTrue()
        ->and(require $root . $directory . $copy . '.php')->toBe(require $originalPath)
        ->and(file_get_contents($originalPath))->toBe($original);
    $reopened = EditorSession::open($root);
    expect(array_column($reopened->listReferences(null, $category), 'value'))->toContain($source, $copy);
    $reopened->close();
})->with([
    'tileset' => ['tilesets', 'home', 'meadow'],
    'script' => ['common_events', 'dresser-note', 'copied-note'],
]);

it('refuses unsafe and occupied file identities without writing or adding history', function () {
    $root = makeTemporaryProject();
    writeTestTileset($root);
    $session = EditorSession::open($root);
    $session->createDatabaseRecord('tilesets', 'reserved');
    // A file created after opening still owns its path, even outside the cached record list.
    file_put_contents($root . '/assets/Data/Tilesets/occupied.php', "<?php return ['name' => 'Occupied', 'sheets' => []];");
    $before = sourceHashTree($root);
    foreach (['../escape', '/absolute', 'nested/name', 'nested\\name', 'Upper Case', '', 'home', 'reserved', 'occupied'] as $identity) {
        expect(fn() => $session->createDatabaseRecord('tilesets', $identity))->toThrow(SessionRefusal::class)
            ->and(fn() => $session->duplicateDatabaseRecord('tilesets', 0, $identity))->toThrow(SessionRefusal::class);
    }
    expect(sourceHashTree($root))->toBe($before)
        ->and($session->undo()['label'])->toBe('Tileset create')
        ->and($session->listDatabaseRecords('tilesets'))->toMatchArray(['dirty' => false, 'records' => ['Home']]);
    $session->close();
});

it('reserves generated file identities across unsaved creates and copies', function () {
    $root = makeTemporaryProject();
    writeTestTileset($root);
    $session = EditorSession::open($root);
    $session->createDatabaseRecord('tilesets');
    $session->createDatabaseRecord('tilesets');
    $session->duplicateDatabaseRecord('tilesets', 0);
    $session->duplicateDatabaseRecord('tilesets', 0);
    $session->saveDatabase('tilesets');
    $session->close();
    $reopened = EditorSession::open($root);
    // Unconfigured blank tilesets are intentionally absent from the usable-tileset picker.
    expect(array_map(static fn($record): string => $record->recordId, loadRecordDatabase($root, 'tilesets')->getRecords()))
        ->toContain('home', 'home-2', 'home-3', 'new-tileset', 'new-tileset-2')
        ->toHaveCount(5);
    $reopened->close();
});
