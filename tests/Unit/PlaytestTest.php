<?php

declare(strict_types=1);

use Ichiloto\Editor\Playtest\PlaytestLauncher;
use Ichiloto\Editor\Playtest\PlaytestOverlay;

/**
 * Returns a path => sha1 map of every file under a directory, following no
 * symlinks, so a test can prove the project was untouched.
 */
function checksumTree(string $directory): array
{
    $checksums = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $checksums[$file->getPathname()] = sha1_file($file->getPathname());
        }
    }

    ksort($checksums);

    return $checksums;
}

it('builds a playtest overlay without writing into the project', function (): void {
    $root = makeTemporaryProject();
    // The fixture has no system.php; a playtest needs one.
    file_put_contents($root . '/assets/Data/system.php', <<<'PHP'
    <?php

    return [
      'party' => ['members' => ['Kaelion']],
      'startingPositions' => [
        'player' => [
          'destinationMap' => 'happyville/home',
          'spawnPoint' => ['x' => 8, 'y' => 4],
          'spawnSprite' => ['^'],
        ],
      ],
    ];
    PHP);

    $before = checksumTree($root);
    $overlay = PlaytestOverlay::create($root, 'test-map', 12, 5);

    try {
        expect(is_dir($overlay->root))->toBeTrue();
        expect($overlay->root)->not->toStartWith($root);

        // The project is byte-identical.
        expect(checksumTree($root))->toBe($before);

        // Assets reach through to the author's live files.
        expect(is_link($overlay->root . '/assets/Maps'))->toBeTrue();
        expect(is_link($overlay->root . '/ichiloto.json'))->toBeTrue();

        // system.php is a real generated file, not a symlink.
        $systemPath = $overlay->root . '/assets/Data/system.php';
        expect(is_link($systemPath))->toBeFalse();
        expect(is_file($systemPath))->toBeTrue();

        $system = require $systemPath;
        expect($system['startingPositions']['player']['destinationMap'])->toBe('test-map');
        expect($system['startingPositions']['player']['spawnPoint'])->toBe(['x' => 12, 'y' => 5]);
        // Untouched system data survives.
        expect($system['party']['members'])->toBe(['Kaelion']);

        // Other data files still symlink to the originals.
        expect(is_link($overlay->root . '/assets/Data/quests.php'))->toBeTrue();

        // Saves are isolated from the project's own.
        expect(is_dir($overlay->root . '/.data'))->toBeTrue();
        expect(is_link($overlay->root . '/.data'))->toBeFalse();
    } finally {
        $overlay->destroy();
    }

    expect(is_dir($overlay->root))->toBeFalse();
    expect(checksumTree($root))->toBe($before);

    removeDirectoryRecursively($root);
});

it('destroys the overlay without following symlinks into the project', function (): void {
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Data/system.php', "<?php\n\nreturn ['startingPositions' => ['player' => []]];\n");

    $questsPath = $root . '/assets/Data/quests.php';
    $before = checksumTree($root);

    $overlay = PlaytestOverlay::create($root, 'test-map', 1, 1);
    $overlay->destroy();

    expect(is_file($questsPath))->toBeTrue();
    expect(is_dir($root . '/assets/Maps/test-map'))->toBeTrue();
    expect(checksumTree($root))->toBe($before);

    removeDirectoryRecursively($root);
});

it('refuses a playtest when system.php cannot be rewritten', function (): void {
    $root = makeTemporaryProject();
    // An object carrying state with no constructor to put it back cannot be
    // written out, so the overlay refuses rather than losing it.
    file_put_contents(
        $root . '/assets/Data/system.php',
        "<?php\n\n\$scope = new stdClass();\n\$scope->kind = 'party';\n\nreturn ['startingPositions' => ['player' => ['scope' => \$scope]]];\n",
    );

    expect(fn() => PlaytestOverlay::create($root, 'test-map', 1, 1))
        ->toThrow(RuntimeException::class, 'system.php contains');

    removeDirectoryRecursively($root);
});

it('runs the play command against the overlay without tmux', function (): void {
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Data/system.php', "<?php\n\nreturn ['startingPositions' => ['player' => []]];\n");

    $overlay = PlaytestOverlay::create($root, 'test-map', 3, 4);

    try {
        $launcher = PlaytestLauncher::discover(__FILE__);
        $command = $launcher->buildCommand($overlay);

        expect($command)->toContain('play')
            ->toContain('--no-tmux')
            ->toContain(escapeshellarg($overlay->root))
            ->toContain(escapeshellarg(__FILE__));
    } finally {
        $overlay->destroy();
    }

    removeDirectoryRecursively($root);
});

it('prefers the console the project itself installed', function (): void {
    $root = makeTemporaryProject();
    mkdir($root . '/vendor/bin', 0o777, true);
    touch($root . '/vendor/bin/ichiloto');
    file_put_contents($root . '/assets/Data/system.php', "<?php\n\nreturn ['startingPositions' => ['player' => []]];\n");

    $overlay = PlaytestOverlay::create($root, 'test-map', 0, 0);

    try {
        // How a game project installs the tooling, and the only copy
        // guaranteed to match the engine that project depends on.
        expect(PlaytestLauncher::discover(projectRoot: $root)->buildCommand($overlay))
            ->toContain(escapeshellarg($root . '/vendor/bin/ichiloto'));
    } finally {
        $overlay->destroy();
        removeDirectoryRecursively($root);
    }
});

it('finds a console installed globally, on PATH', function (): void {
    $binDirectory = rememberTemporaryProject(sys_get_temp_dir() . '/' . uniqid('ichiloto-bin-', true));
    mkdir($binDirectory, 0o777, true);
    touch($binDirectory . '/ichiloto');

    $previousPath = (string) getenv('PATH');
    putenv('PATH=' . $binDirectory);

    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Data/system.php', "<?php\n\nreturn ['startingPositions' => ['player' => []]];\n");
    $overlay = PlaytestOverlay::create($root, 'test-map', 0, 0);

    try {
        // `composer global require ichiloto/console`, which is an install
        // with no vendor directory anywhere near the project.
        expect(PlaytestLauncher::discover()->buildCommand($overlay))
            ->toContain(escapeshellarg($binDirectory . '/ichiloto'));
    } finally {
        putenv('PATH=' . $previousPath);
        $overlay->destroy();
        removeDirectoryRecursively($root);
        @unlink($binDirectory . '/ichiloto');
        @rmdir($binDirectory);
    }
});

it('discovers consoles from installed package locations', function (): void {
    $candidates = new ReflectionMethod(PlaytestLauncher::class, 'candidates')
        ->invoke(null, null, '/srv/my-game');

    expect($candidates)->toContain('/srv/my-game/vendor/bin/ichiloto')
        ->and($candidates)->not->toContain(dirname(__DIR__, 3) . '/console/bin/ichiloto');
});

it('says where it looked when there is no console to find', function (): void {
    $emptyBinDirectory = rememberTemporaryProject(sys_get_temp_dir() . '/' . uniqid('ichiloto-empty-bin-', true));
    mkdir($emptyBinDirectory, 0o777, true);
    $previousPath = (string) getenv('PATH');
    putenv('PATH=' . $emptyBinDirectory);
    $message = '';

    try {
        PlaytestLauncher::discover(projectRoot: '/nowhere');
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
    } finally {
        putenv('PATH=' . $previousPath);
        @rmdir($emptyBinDirectory);
    }

    expect($message)->toContain('ICHILOTO_CONSOLE_BIN')
        ->and($message)->toContain('/nowhere/vendor/bin/ichiloto');
});
