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
    $candidates = new ReflectionMethod(\Ichiloto\Editor\Console\ConsoleBinary::class, 'candidates')
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

/**
 * A stand-in console: it records its arguments, starts a child that would
 * outlive it, and either keeps running or exits with the code given.
 */
function writeFakeConsole(string $directory, ?int $exitCode = null): string
{
    $path = $directory . '/fake-console.php';
    file_put_contents($path, '<?php echo implode(" ", array_slice($argv, 1)), "\n"; '
        . ($exitCode === null
            ? '$child = proc_open(["sleep", "60"], [], $pipes); sleep(60);'
            : sprintf('fwrite(STDERR, "renderer missing\n"); exit(%d);', $exitCode)));

    return $path;
}

function playtestProject(): string
{
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Data/system.php', "<?php\n\nreturn ['startingPositions' => ['player' => []]];\n");

    return $root;
}

it('carries the player settings into the playtest and leaves the saves behind', function (): void {
    $root = playtestProject();
    mkdir($root . '/.data/saves', 0777, true);
    file_put_contents($root . '/.data/player-settings.json', '{"audio":{"music":false,"sfx":false}}');
    file_put_contents($root . '/.data/saves/slot-1.json', '{}');
    $overlay = PlaytestOverlay::create($root, 'test-map', 1, 1);

    try {
        // A copy, so a playtest's own changes never reach the project.
        expect(file_get_contents($overlay->root . '/.data/player-settings.json'))->toBe('{"audio":{"music":false,"sfx":false}}')
            ->and(is_link($overlay->root . '/.data/player-settings.json'))->toBeFalse()
            ->and(is_dir($overlay->root . '/.data/saves'))->toBeFalse();
    } finally {
        $overlay->destroy();
    }

    removeDirectoryRecursively($root);
});

it('runs a playtest in the background with its renderer and stops the whole game', function (): void {
    $root = playtestProject();
    $scratch = sys_get_temp_dir() . '/' . uniqid('ichiloto-playtest-run-', true);
    mkdir($scratch);
    $overlay = PlaytestOverlay::create($root, 'test-map', 2, 3);
    $run = (new PlaytestLauncher(writeFakeConsole($scratch)))->start($overlay, 'gpui', $scratch . '/play.log');

    $deadline = microtime(true) + 5;
    while (! str_contains((string) @file_get_contents($scratch . '/play.log'), 'play') && microtime(true) < $deadline) {
        usleep(20000);
    }
    $pid = $run->getProcessId();
    $children = trim((string) shell_exec('pgrep -P ' . $pid));

    expect($run->isRunning())->toBeTrue()
        ->and(file_get_contents($scratch . '/play.log'))->toContain('play --no-tmux --no-interaction --renderer=gpui -d ' . $overlay->root)
        ->and($children)->not->toBe('');

    $run->stop();
    usleep(100000);
    expect($run->isRunning())->toBeFalse()
        ->and($run->wasStopped())->toBeTrue()
        ->and(is_dir($overlay->root))->toBeFalse()
        // The child the play command started went with it.
        ->and(trim((string) shell_exec('ps -p ' . (int) $children . ' -o pid=')))->toBe('');

    removeDirectoryRecursively($root);
    removeDirectoryRecursively($scratch);
});

it('reports how a failed playtest ended and removes its overlay', function (): void {
    $root = playtestProject();
    $scratch = sys_get_temp_dir() . '/' . uniqid('ichiloto-playtest-run-', true);
    mkdir($scratch);
    $overlay = PlaytestOverlay::create($root, 'test-map', 0, 0);
    $run = (new PlaytestLauncher(writeFakeConsole($scratch, 3)))->start($overlay, 'gpui', $scratch . '/play.log');

    $deadline = microtime(true) + 5;
    while ($run->isRunning() && microtime(true) < $deadline) {
        usleep(20000);
    }

    expect($run->getExitCode())->toBe(3)
        ->and($run->readLogTail())->toContain('renderer missing')
        ->and(is_dir($overlay->root))->toBeFalse();

    removeDirectoryRecursively($root);
    removeDirectoryRecursively($scratch);
});
