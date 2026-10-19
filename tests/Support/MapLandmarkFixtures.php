<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Field\MapGridSource;

/**
 * A synthetic map with a transfer T, a dialogue E and a world object, and the session over it.
 *
 * @return array{string, string, EditorSession}
 */
function createLandmarkProject(string $landmarks = '', bool $ragged = false): array
{
    $root = mapGraphicsProject($ragged);
    $directory = $root . '/assets/Maps/test-map';
    file_put_contents($directory . '/test-map.event.php', MapGridSource::buildSource($ragged ? "T  E\n  " : "T  E\n   T", 'EVENTS'));
    $file = $directory . '/test-map.data.php';
    file_put_contents($file, str_replace("'events' => [],", <<<PHP
'events' => [
        'T' => ['class' => 'Ichiloto\\\\Engine\\\\Events\\\\Triggers\\\\TransferPlayerTrigger',
            'data' => ['destinationMap' => 'test-map', 'spawnPoint' => ['x' => 1, 'y' => 0]]],
        'E' => ['class' => 'Ichiloto\\\\Engine\\\\Events\\\\Triggers\\\\DialogueEventTrigger', 'data' => []],
    ],
    // Map-owned presentation stays authored.
    'worldObjects' => [['id' => 'old-tower', 'anchor' => ['x' => 1, 'y' => 1], 'pivot' => ['x' => 0.5, 'y' => 1.0], 'sprites2d' => null]],
    {$landmarks}
PHP, (string) file_get_contents($file)));

    return [$root, $file, EditorSession::open($root)];
}

/** The map inspector row whose key has these members, read fresh. */
function findLandmarkRow(EditorSession $session, array $key, ?string $marker = null, string $mapId = 'test-map'): array
{
    $rows = $session->readInspector($mapId, $marker)['rows'];
    return array_find($rows, static fn(array $row): bool => array_intersect_assoc($key, $row['key'] ?? []) === $key && array_diff_key($key, $row['key'] ?? []) === [])
        ?? throw new RuntimeException('Missing inspector row ' . json_encode($key));
}

function applyLandmarkRow(EditorSession $session, array $key, string $value, ?string $marker = null, string $mapId = 'test-map'): array
{
    $row = findLandmarkRow($session, $key, $marker, $mapId);
    return $session->applyInspector($mapId, $session->readMap($mapId)['revision'], $row['key'], $value);
}

/** The map's declarations as the Editor holds them, unsaved edits included. */
function readLandmarkEntries(EditorSession $session, string $mapId = 'test-map'): array
{
    return Ichiloto\Editor\Field\MapLandmarks::readMap(getSessionMap($session, $mapId))->entries;
}

function getSessionMap(EditorSession $session, string $mapId = 'test-map'): Ichiloto\Editor\ProjectMap
{
    return new ReflectionMethod(EditorSession::class, 'requireMap')->invoke($session, $mapId);
}

/**
 * The landmark project with a second, global map "world" whose transfer T leads into test-map and is declared as
 * the landmark town-gate, and the session over both.
 *
 * @return array{string, string, EditorSession}
 */
function createAtlasProject(string $testMapAtlas = ''): array
{
    [$root, $file] = createLandmarkProject($testMapAtlas);
    $world = $root . '/assets/Maps/world';
    copyDirectoryRecursively($root . '/assets/Maps/test-map', $world);
    rename($world . '/test-map.data.php', $world . '/world.data.php');
    rename($world . '/test-map.event.php', $world . '/world.event.php');
    $source = str_replace(["'name' => 'Test Map'", "// Map-owned presentation stays authored.\n"],
        ["'name' => 'World', 'atlas' => ['scope' => 'global'], 'landmarks' => [['id' => 'town-gate', 'kind' => 'entrance', 'anchor' => ['event' => 'T']]]", ''],
        (string) file_get_contents($world . '/world.data.php'));
    file_put_contents($world . '/world.data.php', $source);

    return [$root, $file, EditorSession::open($root)];
}
