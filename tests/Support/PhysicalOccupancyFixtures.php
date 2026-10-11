<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapGridSource;

/** A synthetic map whose physical grid is the glyphs given, shared by the occupancy and footprint tests. */
function createPhysicalOccupancyProject(?string $source = null, string $glyphs = "###\n##"): string
{
    $root = makeTemporaryProject('editor-occupancy-paint-');
    $directory = $root . '/assets/Maps/test-map';
    file_put_contents($directory . '/test-map.map.php', MapGridSource::buildSource($glyphs, 'MAP'));
    $events = implode("\n", array_map(static fn(string $row): string => str_repeat(' ', strlen($row)), explode("\n", $glyphs)));
    file_put_contents($directory . '/test-map.event.php', MapGridSource::buildSource($events, 'EVENT'));
    $rows = array_map(static fn(string $row): array => array_fill(0, strlen($row), CollisionType::NONE), explode("\n", $glyphs));
    file_put_contents($directory . '/test-map.data.php', $source ?? '<?php return '
        . var_export(['name' => 'Physical fixture', 'events' => [], 'occupancy' => $rows], true) . ';');

    return $root;
}

function getPhysicalOccupancyMap(EditorSession $session): \Ichiloto\Editor\ProjectMap
{
    return new ReflectionProperty(EditorSession::class, 'workspace')->getValue($session)->getMapByIndex(0);
}

function createPhysicalOccupancyRpc(string $root): Closure
{
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(string $method, array $params): array =>
        $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
    expect($request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]))->toHaveKey('result');

    return $request;
}
