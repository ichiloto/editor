<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\Reachability\ProjectReachability;

it('plans safe landings using a new staged tileset and saves or checks the whole transaction', function (bool $checkOnly) {
    $root = createPhysicalOccupancyProject(glyphs: "...\n...\n...");
    writeTestTileset($root);
    file_put_contents($root . '/assets/Data/system.php', '<?php return ' . var_export([
        'startingPositions' => ['player' => ['destinationMap' => 'test-map', 'spawnPoint' => ['x' => 0, 'y' => 0], 'spawnSprite' => ['South']]],
    ], true) . ';');
    $before = sourceHashTree($root . '/assets');
    $session = EditorSession::open($root);
    $session->beginChanges();
    $index = $session->createDatabaseRecord('tilesets', 'new-floor')['index'];
    $set = static function (string $field, string $value) use ($session, $index): void {
        $row = array_find($session->readDatabaseRecord('tilesets', $index)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === $field);
        $session->applyDatabaseRecord('tilesets', $index, $row['key'], $value);
    };
    $set('name', 'New Floor');
    $set('sheets.A2', 'Graphics/Tilesets/Home_A2.png');
    $session->addDatabaseItem('tilesets', $index, ['target' => 'record', 'field' => 'kindList', 'frame' => []]);
    foreach (['Id' => 'floor', 'Name' => 'Floor', 'Layer' => $session->readMap('test-map')['layers'][0]['name'], 'Glyph' => '<fg=green>.</>', 'Artawaiting' => 'false', 'Artlayer' => 'floor', 'Arttile' => '2816', 'Passage' => 'walk', 'Atlas' => 'ground'] as $field => $value) {
        $set('kind0' . $field, $value);
    }
    $session->applyInspector('test-map', $session->readMap('test-map')['revision'], ['target' => 'map-kind', 'field' => 'tileset'], 'new-floor');
    $session->convertToKinds('test-map', $session->readMap('test-map')['revision'], ['.' => 'floor']);
    $session->paintOccupancy('test-map', $session->readMap('test-map')['revision'], [[1, 1]], CollisionType::SOLID->value);
    $workspace = new ReflectionProperty(EditorSession::class, 'workspace')->getValue($session);
    $report = $workspace->analyzeReachability();
    expect($report->reports)->toHaveKey('test-map')
        ->and($report->reports['test-map']->isReachable(1, 0))->toBeTrue()
        ->and($report->reports['test-map']->isReachable(1, 1))->toBeFalse()
        ->and(is_file($root . '/assets/Data/Tilesets/new-floor.php'))->toBeFalse();
    $outcome = $session->commitChanges(relocateSaves: true, checkOnly: $checkOnly);
    expect($outcome['status'])->toBe($checkOnly ? 'checked' : 'saved', json_encode($outcome))
        ->and($outcome['relocated'])->not->toBe([]);
    $session->close();
    if ($checkOnly) {
        expect(sourceHashTree($root . '/assets'))->toBe($before);
    } else {
        $manifest = require $root . '/assets/Data/save-compatibility.php';
        $move = $manifest['migrations'][0]['relocations'][0];
        expect($move['cells'])->toBe([[1, 1]])
            ->and($move['map'])->toBe('test-map')
            ->and($manifest['contentVersion'])->toBe(1)
            ->and(is_file($root . '/assets/Data/Tilesets/new-floor.php'))->toBeTrue()
            ->and(ProjectReachability::analyze($root . '/assets')->reports['test-map']->isReachable(...$move['to']))->toBeTrue();
    }
})->with(['check without writes' => [true], 'atomic save' => [false]]);
