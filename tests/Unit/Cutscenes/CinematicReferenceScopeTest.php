<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

it('binds a cinematic visual to a world object on the map the cinematic starts on', function () {
    $root = cutsceneProject();
    $mapPath = $root . '/assets/Maps/harbour/harbour.data.php';
    $map = require $mapPath;
    $map['worldObjects'] = [['id' => 'beacon', 'anchor' => ['x' => 6, 'y' => 3], 'pivot' => ['x' => 0.5, 'y' => 1.0], 'sprites2d' => null]];
    file_put_contents($mapPath, "<?php\nreturn " . var_export($map, true) . ";\n");
    $dataPath = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.data.php';
    $session = EditorSession::open($root);
    $row = static fn(string $name): array => array_find($session->readDatabaseRecord('cutscenes/cinematic', 0)['rows'],
        static fn(array $row): bool => ($row['entry'] ?? []) === [0] && ($row['name'] ?? '') === $name)
        ?? throw new RuntimeException('Missing cast row ' . $name);

    $session->applyDatabaseRecord('cutscenes/cinematic', 0, $row('Bind Visual To')['key'], 'world_object');
    expect(fn() => $session->applyDatabaseRecord('cutscenes/cinematic', 0, $row('Bound World Object')['key'], 'elsewhere'))
        ->toThrow(SessionRefusal::class, 'Unknown map_world_objects');
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, $row('Bound World Object')['key'], 'beacon');
    $session->saveDatabase('cutscenes/cinematic');

    expect((require $dataPath)['cast'][0]['subject'])->toBe(['kind' => 'world_object', 'id' => 'beacon']);
});
