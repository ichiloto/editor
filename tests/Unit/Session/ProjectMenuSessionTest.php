<?php

declare(strict_types=1);

use Ichiloto\Editor\Console\ConsoleBinary;
use Ichiloto\Editor\Console\ProjectCreator;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionHost;

/**
 * What a graphical editor's File and Edit menus ask the session for: a new
 * project, made by the console's own `ichiloto new`, and a search across
 * the open project. Synthetic fixtures only.
 */

/** A stand-in console that records its arguments and creates what `new` would, or fails as told. */
function fakeConsole(string $root, bool $fails = false): ConsoleBinary
{
    $path = $root . '/fake-ichiloto';
    file_put_contents($path, <<<PHP
<?php
file_put_contents('{$root}/arguments.json', json_encode(array_slice(\$argv, 1)));
if ({$fails}0) { fwrite(STDERR, 'The guild is closed.'); exit(1); }
\$directory = \$argv[array_search('--directory', \$argv, true) + 1];
mkdir(\$directory);
file_put_contents(\$directory . '/ichiloto.json', '{}');
PHP);

    return new ConsoleBinary($path);
}

it('creates a project through the console, passing what the author chose', function () {
    $root = sys_get_temp_dir() . '/ichiloto-new-' . bin2hex(random_bytes(4));
    mkdir($root);
    $creator = new ProjectCreator(fakeConsole($root));

    $created = $creator->createProject('Lake Legend', $root . '/lake-legend', 'Mira', 'active_time');

    expect($created)->toBe(realpath($root . '/lake-legend'))
        ->and(json_decode((string) file_get_contents($root . '/arguments.json'), true))
        ->toBe(['new', 'Lake Legend', '--directory', $root . '/lake-legend', '--no-interaction', '--no-install', '--hero', 'Mira', '--battle-engine', 'active_time'])
        ->and(fn() => $creator->createProject('Again', $root . '/lake-legend'))->toThrow(RuntimeException::class, 'already exists')
        ->and(fn() => $creator->createProject('', $root . '/other'))->toThrow(RuntimeException::class, 'title')
        ->and(fn() => $creator->createProject('Rel', 'relative/path'))->toThrow(RuntimeException::class, 'Choose where')
        ->and(fn() => $creator->createProject('Gone', $root . '/missing/game'))->toThrow(RuntimeException::class, 'does not exist')
        ->and(fn() => $creator->createProject('Odd', $root . '/odd', battleEngine: 'turbo'))->toThrow(RuntimeException::class, 'traditional or active_time')
        ->and(fn() => new ProjectCreator(fakeConsole($root, true))->createProject('Shut', $root . '/shut'))->toThrow(RuntimeException::class, 'The guild is closed.');

    removeDirectoryRecursively($root);
});

it('finds maps, events and NPCs across the project, by what they are called and hold', function () {
    $root = makeTemporaryProject();
    $session = EditorSession::open($root);
    [$map] = $session->describeMaps();
    $session->createNpc($map['id'], $map['revision'], 2, 2, 'Lantern Keeper');

    expect(array_column($session->searchProject('test'), 'kind'))->toContain('map')
        // A chest found by the item it gives, with where it is.
        ->and($session->searchProject('s-potion'))->toBe([[
            'kind' => 'event', 'map' => 'test-map', 'label' => 'E Chest', 'detail' => 'Test Map · S-Potion', 'marker' => 'E',
            ...array_intersect_key($session->searchProject('E')[0], ['x' => true, 'y' => true]),
        ]])
        // One character is a marker, not every text holding it.
        ->and(array_column($session->searchProject('E'), 'marker'))->toBe(['E'])
        ->and($session->searchProject('lantern'))->toHaveCount(1)
        ->and($session->searchProject('lantern')[0])->toMatchArray(['kind' => 'npc', 'map' => 'test-map', 'label' => 'Lantern Keeper', 'index' => 0, 'x' => 2, 'y' => 2])
        ->and($session->searchProject('   '))->toBe([]);
});

it('serves project creation without an open project, and search with one', function () {
    $root = makeTemporaryProject();
    $host = new SessionHost(fopen('php://memory', 'r'), fopen('php://memory', 'w'), fopen('php://memory', 'w'));
    $request = static fn(int $id, string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => $id, 'method' => $method, 'params' => $params]));

    // No project is open, and a refused creation says why.
    expect($request(1, 'project.create', ['title' => 'Lake', 'directory' => 'relative'])['error'])->toMatchArray(['kind' => 'refusal'])
        ->and($request(2, 'project.search', ['query' => 'test'])['error']['message'])->toContain('Say hello');

    $request(3, 'hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]);

    expect($request(4, 'project.search', ['query' => 'test'])['result'][0])->toMatchArray(['kind' => 'map', 'map' => 'test-map']);
});
