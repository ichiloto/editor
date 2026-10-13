<?php

declare(strict_types=1);

use Ichiloto\Editor\Playtest\PlaytestLaunch;
use Ichiloto\Editor\Playtest\PlaytestLauncher;
use Ichiloto\Engine\Core\GameLaunchContext;
use Ichiloto\Engine\Core\GameStartupMode;
use Ichiloto\Engine\IO\LaunchDataRoot;

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

/** The playtest data roots in the temporary directory, to show a refused launch leaves none behind. */
function listPlaytestDataRoots(): array
{
    return glob(sys_get_temp_dir() . '/ichiloto-playtest-*', GLOB_ONLYDIR) ?: [];
}

it('plays the real project from its own root, keeping its data apart and writing nothing into the project', function (): void {
    $root = playtestProject();
    $before = checksumTree($root);
    $launch = PlaytestLaunch::fromCell($root, 'test-map', 12, 5);

    try {
        expect($launch->projectRoot)->toBe(realpath($root))
            ->and($launch->getContext())->toBe(['mode' => 'new-game', 'start' => ['mapId' => 'test-map', 'x' => 12, 'y' => 5],
                'dataRoot' => $launch->dataRoot])
            ->and([$launch->mapId, $launch->x, $launch->y])->toBe(['test-map', 12, 5])
            // Outside the project and not above it, as the Engine requires, and nothing in it but the logs.
            ->and($launch->dataRoot)->not->toStartWith(realpath($root))
            ->and(LaunchDataRoot::validate($launch->dataRoot, realpath($root)))->toBe($launch->dataRoot)
            ->and(array_values(array_diff(scandir($launch->dataRoot), ['.', '..'])))->toBe(['logs'])
            ->and(checksumTree($root))->toBe($before);
    } finally {
        $launch->destroy();
    }

    expect(is_dir($launch->dataRoot))->toBeFalse()
        ->and(checksumTree($root))->toBe($before);
});

it('carries the player settings into the playtest and leaves the saves behind', function (): void {
    $root = playtestProject();
    mkdir($root . '/.data/saves', 0777, true);
    file_put_contents($root . '/.data/player-settings.json', '{"audio":{"music":false,"sfx":false}}');
    file_put_contents($root . '/.data/saves/slot-1.json', '{}');
    $launch = PlaytestLaunch::fromCell($root, 'test-map', 1, 1);

    try {
        // A copy, so a playtest's own changes never reach the project.
        expect(file_get_contents($launch->dataRoot . '/player-settings.json'))->toBe('{"audio":{"music":false,"sfx":false}}')
            ->and(is_link($launch->dataRoot . '/player-settings.json'))->toBeFalse()
            ->and(is_dir($launch->dataRoot . '/saves'))->toBeFalse();
    } finally {
        $launch->destroy();
    }

    expect(file_get_contents($root . '/.data/saves/slot-1.json'))->toBe('{}');
});

it('says where each kind of playtest starts in the launch context the Engine reads', function (): void {
    $root = playtestProject();
    $launches = [
        'title' => PlaytestLaunch::fromTitle($root),
        'cinematic' => PlaytestLaunch::fromCinematic($root, 'test-map', 2, 2, 'dawn-crossing'),
        'battle' => PlaytestLaunch::fromBattleTest($root, ['troop' => 'Pair', 'members' => [['actor' => 'Kaelion', 'level' => 3]]]),
    ];

    try {
        foreach ($launches as $launch) {
            putenv(GameLaunchContext::ENVIRONMENT_VARIABLE . '=' . substr($launch->buildPlayOption(), strlen('--launch-context=')));
            $contexts[] = GameLaunchContext::consumeEnvironment();
        }
        [$title, $cinematic, $battle] = $contexts;

        expect($title->mode)->toBe(GameStartupMode::TITLE)
            ->and($title->start)->toBeNull()
            ->and($title->dataRoot)->toBe($launches['title']->dataRoot)
            ->and($cinematic->mode)->toBe(GameStartupMode::NEW_GAME)
            ->and([$cinematic->start->mapId, $cinematic->start->x, $cinematic->start->y])->toBe(['test-map', 2, 2])
            ->and($cinematic->cinematicId)->toBe('dawn-crossing')
            ->and($battle->mode)->toBeNull()
            ->and($battle->battleTest->troop)->toBe('Pair')
            ->and($battle->battleTest->toArray())->toBe(['troop' => 'Pair', 'members' => [['actor' => 'Kaelion', 'level' => 3]]]);
    } finally {
        array_walk($launches, static fn(PlaytestLaunch $launch) => $launch->destroy());
    }
});

it('hands the play command the launch as its option, with no start mode or context of its own to compete', function (): void {
    $root = playtestProject();
    putenv(GameStartupMode::ENVIRONMENT_VARIABLE . '=title');
    putenv(GameLaunchContext::ENVIRONMENT_VARIABLE . '={"mode":"title"}');
    $launch = PlaytestLaunch::fromCell($root, 'test-map', 1, 1);

    try {
        $environment = $launch->buildEnvironment();

        expect($environment)->not->toHaveKey(GameStartupMode::ENVIRONMENT_VARIABLE)
            ->and($environment)->not->toHaveKey(GameLaunchContext::ENVIRONMENT_VARIABLE)
            ->and($launch->buildPlayOption())->toStartWith('--launch-context=')
            ->and(json_decode(substr($launch->buildPlayOption(), strlen('--launch-context=')), true))->toBe($launch->getContext());
    } finally {
        putenv(GameStartupMode::ENVIRONMENT_VARIABLE);
        putenv(GameLaunchContext::ENVIRONMENT_VARIABLE);
        $launch->destroy();
    }
});

it('refuses a start the Engine would not read, leaving no data root behind', function (): void {
    $root = playtestProject();
    $before = listPlaytestDataRoots();

    expect(fn() => PlaytestLaunch::fromCell($root, 'test-map', -1, 0))->toThrow(RuntimeException::class, 'The game cannot start that way')
        ->and(fn() => PlaytestLaunch::fromCinematic($root, 'test-map', 0, 0, 'Not A Stable Id'))->toThrow(RuntimeException::class)
        ->and(listPlaytestDataRoots())->toBe($before);
});

it('plays a project whose system data holds PHP objects, since nothing is rewritten for a playtest', function (): void {
    $root = makeTemporaryProject();
    file_put_contents(
        $root . '/assets/Data/system.php',
        "<?php\n\n\$scope = new stdClass();\n\$scope->kind = 'party';\n\nreturn ['startingPositions' => ['player' => ['scope' => \$scope]]];\n",
    );

    $launch = PlaytestLaunch::fromCell($root, 'test-map', 1, 1);
    $launch->destroy();

    expect(is_dir($launch->dataRoot))->toBeFalse();
});

it('runs the play command in this terminal from the project root without tmux', function (): void {
    $root = playtestProject();
    $launch = PlaytestLaunch::fromCell($root, 'test-map', 3, 4);

    try {
        expect(PlaytestLauncher::discover(__FILE__)->buildPlayArguments($launch))
            ->toBe([PHP_BINARY, __FILE__, 'play', '--no-tmux', $launch->buildPlayOption(), '-d', realpath($root)]);
    } finally {
        $launch->destroy();
    }
});

it('prefers the console the project itself installed', function (): void {
    $root = makeTemporaryProject();
    mkdir($root . '/vendor/bin', 0o777, true);
    touch($root . '/vendor/bin/ichiloto');
    $launch = PlaytestLaunch::fromCell($root, 'test-map', 0, 0);

    try {
        // How a game project installs the tooling, and the only copy
        // guaranteed to match the engine that project depends on.
        expect(PlaytestLauncher::discover(projectRoot: $root)->buildPlayArguments($launch)[1])
            ->toBe($root . '/vendor/bin/ichiloto');
    } finally {
        $launch->destroy();
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
    $launch = PlaytestLaunch::fromCell($root, 'test-map', 0, 0);

    try {
        // `composer global require ichiloto/console`, which is an install
        // with no vendor directory anywhere near the project.
        expect(PlaytestLauncher::discover()->buildPlayArguments($launch)[1])
            ->toBe($binDirectory . '/ichiloto');
    } finally {
        putenv('PATH=' . $previousPath);
        $launch->destroy();
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

/** A throwaway directory for a playtest's stand-in console, removed after the test whatever its outcome. */
function playtestScratch(): string
{
    $scratch = rememberTemporaryProject(sys_get_temp_dir() . '/' . uniqid('ichiloto-console-', true));
    mkdir($scratch);

    return $scratch;
}

function playtestProject(): string
{
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Data/system.php', "<?php\n\nreturn ['startingPositions' => ['player' => []]];\n");

    return $root;
}

it('runs a playtest in the background with its renderer and stops the whole game', function (): void {
    $root = playtestProject();
    $scratch = playtestScratch();
    $launch = PlaytestLaunch::fromCell($root, 'test-map', 2, 3);
    $run = (new PlaytestLauncher(writeFakeConsole($scratch)))->start($launch, 'gpui');

    try {
        $deadline = microtime(true) + 5;
        while (! str_contains((string) @file_get_contents($launch->getLogPath()), 'dataRoot') && microtime(true) < $deadline) {
            usleep(20000);
        }
        $pid = $run->getProcessId();
        $children = trim((string) shell_exec('pgrep -P ' . $pid));

        expect($run->isRunning())->toBeTrue()
            ->and($run->readLogTail())->toContain('play --no-tmux --no-interaction --renderer=gpui --terminal-window '
                . $launch->buildPlayOption() . ' -d ' . realpath($root))
            ->and($children)->not->toBe('');

        $run->stop();
        usleep(100000);
        expect($run->isRunning())->toBeFalse()
            ->and($run->wasStopped())->toBeTrue()
            ->and(is_dir($launch->dataRoot))->toBeFalse()
            // The child the play command started went with it.
            ->and(trim((string) shell_exec('ps -p ' . (int) $children . ' -o pid=')))->toBe('');
    } finally {
        // A failed expectation still stops the game it started and takes its data root with it.
        $run->stop();
        $launch->destroy();
    }
});

it('reports how a failed playtest ended once its data root is gone', function (): void {
    $root = playtestProject();
    $scratch = playtestScratch();
    $launch = PlaytestLaunch::fromCell($root, 'test-map', 0, 0);
    $run = (new PlaytestLauncher(writeFakeConsole($scratch, 3)))->start($launch, 'gpui');

    try {
        $deadline = microtime(true) + 5;
        while ($run->isRunning() && microtime(true) < $deadline) {
            usleep(20000);
        }

        expect($run->getExitCode())->toBe(3)
            ->and(is_dir($launch->dataRoot))->toBeFalse()
            ->and($run->readLogTail())->toContain('renderer missing');
    } finally {
        $run->stop();
        $launch->destroy();
    }
});
