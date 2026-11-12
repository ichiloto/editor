<?php

declare(strict_types=1);

/**
 * The suite's throwaway fixtures have one owner: each is removed when the
 * test or script that made it ends, and one the filesystem refuses to
 * remove is reported instead of left behind unseen.
 */

/**
 * Runs PHP code in a fresh process that has loaded the suite's helpers, as
 * a probe script or the GUI editor's tests do.
 *
 * @return array{0: string, 1: string, 2: int} Its output, its error stream and its exit status.
 */
function runWithTestHelpers(string $code): array
{
    $tests = dirname(__DIR__);
    $process = proc_open(
        [PHP_BINARY, '-r', 'require $argv[1] . "/bootstrap.php"; require $argv[1] . "/Pest.php"; ' . $code, $tests],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [$output, $errors, proc_close($process)];
}

it('removes every owned directory and file, and stops owning them', function () {
    $project = makeTemporaryProject('ichiloto-fixture-owner-');
    $directory = makeTemporaryDirectory('ichiloto-fixture-owner-');
    mkdir($directory . '/nested/deeper', 0o777, true);
    file_put_contents($directory . '/nested/deeper/file.txt', 'kept until the end');
    symlink($project, $directory . '/link');
    $file = makeTemporaryFile('ichiloto-fixture-owner-', '.php');

    expect(TemporaryFixtures::removeAll())->toBe([])
        ->and(file_exists($project))->toBeFalse()
        ->and(file_exists($directory))->toBeFalse()
        ->and(file_exists($file))->toBeFalse()
        ->and(TemporaryFixtures::removeAll())->toBe([]);
});

it('removes a link as a link and leaves what it points at', function () {
    $target = makeTemporaryDirectory('ichiloto-fixture-target-');
    file_put_contents($target . '/shared.txt', 'not the fixture\'s to remove');
    $directory = makeTemporaryDirectory('ichiloto-fixture-owner-');
    symlink($target, $directory . '/shared');

    expect(TemporaryFixtures::removePath($directory))->toBe([])
        ->and(file_exists($directory))->toBeFalse()
        ->and(file_get_contents($target . '/shared.txt'))->toBe('not the fixture\'s to remove');
});

it('removes a directory the test left read-only', function () {
    $directory = makeTemporaryDirectory('ichiloto-fixture-owner-');
    mkdir($directory . '/locked');
    file_put_contents($directory . '/locked/file.txt', 'inside');
    chmod($directory . '/locked', 0o555);

    expect(TemporaryFixtures::removeAll())->toBe([])
        ->and(file_exists($directory))->toBeFalse();
});

it('refuses to own or remove a path outside the temporary directory', function () {
    expect(fn() => rememberTemporaryProject(fixturePath('sample-project')))->toThrow(RuntimeException::class, 'not a temporary fixture')
        ->and(fn() => removeDirectoryRecursively(fixturePath('sample-project')))->toThrow(RuntimeException::class, 'not a temporary fixture')
        ->and(is_dir(fixturePath('sample-project')))->toBeTrue();
});

it('reports a fixture the filesystem refuses to remove, with the reason and the test that made it', function () {
    $directory = makeTemporaryDirectory('ichiloto-fixture-refused-');
    file_put_contents($directory . '/immutable.txt', 'refused');
    exec('chflags uchg ' . escapeshellarg($directory . '/immutable.txt'), result_code: $status);

    try {
        $problems = TemporaryFixtures::removeAll();
    } finally {
        exec('chflags nouchg ' . escapeshellarg($directory . '/immutable.txt'));
        removeDirectoryRecursively($directory);
    }

    expect($status)->toBe(0)
        ->and($problems)->toHaveCount(1)
        ->and($problems[0])->toContain($directory . '/immutable.txt: unlink(')
        ->and($problems[0])->toContain('made by P\\Tests\\Unit\\TemporaryFixturesTest::');
})->skip(PHP_OS_FAMILY !== 'Darwin', 'Marking a file immutable without privileges needs the BSD file flags.');

it('removes the fixtures a script made when the script ends', function () {
    [$output, $errors, $status] = runWithTestHelpers(
        'echo json_encode([makeTemporaryProject(), makeTemporaryDirectory("ichiloto-script-"), makeTemporaryFile("ichiloto-script-", ".php")]);',
    );
    $paths = json_decode($output, true);

    expect($status)->toBe(0)
        ->and($errors)->toBe('')
        ->and($paths)->toHaveCount(3)
        ->and(array_filter($paths, file_exists(...)))->toBe([]);
});

it('says on the error stream which fixtures a script left because they could not be removed', function () {
    [$output, $errors, $status] = runWithTestHelpers(
        '$directory = makeTemporaryDirectory("ichiloto-script-refused-"); file_put_contents("$directory/immutable.txt", "refused");'
        . ' exec("chflags uchg " . escapeshellarg("$directory/immutable.txt")); echo $directory;',
    );
    exec('chflags nouchg ' . escapeshellarg($output . '/immutable.txt'));
    rememberTemporaryProject($output);

    expect($status)->toBe(0)
        ->and($errors)->toContain('Temporary fixtures could not be removed:')
        ->and($errors)->toContain($output . '/immutable.txt: unlink(')
        ->and($errors)->toContain('made by code outside a test');
})->skip(PHP_OS_FAMILY !== 'Darwin', 'Marking a file immutable without privileges needs the BSD file flags.');

it('removes the fixtures of a process that was killed before it could clean up', function () {
    $tests = dirname(__DIR__);
    $killed = proc_open(
        [PHP_BINARY, '-r', 'require $argv[1] . "/bootstrap.php"; require $argv[1] . "/Pest.php"; echo makeTemporaryProject(), "\n"; sleep(30);', $tests],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    $abandoned = trim((string) fgets($pipes[1]));
    proc_terminate($killed, 9);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($killed);

    // Killed, the process ran no cleanup of its own; the next process to
    // make a fixture finds its run abandoned. (Any other process starting
    // its first fixture meanwhile may remove it first, which is the same.)
    [, $errors, $status] = runWithTestHelpers('makeTemporaryDirectory("ichiloto-script-");');

    expect($abandoned)->toStartWith(rtrim(sys_get_temp_dir(), '/') . '/ichiloto-editor-fixtures-')
        ->and($status)->toBe(0)
        ->and($errors)->toBe('')
        ->and(file_exists($abandoned))->toBeFalse()
        ->and(file_exists(dirname($abandoned)))->toBeFalse()
        ->and(file_exists(dirname($abandoned) . '.lock'))->toBeFalse();
});

it('makes every fixture inside the run directory of the process that owns it', function () {
    $directory = makeTemporaryDirectory('ichiloto-fixture-owner-');
    $file = makeTemporaryFile('ichiloto-fixture-owner-', '.php');
    $reserved = reserveTemporaryPath('ichiloto-fixture-owner-');
    $run = dirname($directory);

    expect(basename($run))->toStartWith('ichiloto-editor-fixtures-')
        ->and(dirname($run))->toBe(rtrim(sys_get_temp_dir(), '/'))
        ->and(dirname($file))->toBe($run)
        ->and(dirname($reserved))->toBe($run)
        ->and(file_exists($reserved))->toBeFalse()
        ->and(is_file($run . '.lock'))->toBeTrue();
});

it('removes a run directory something wrote into after its owner had cleaned up', function () {
    $orphan = rtrim(sys_get_temp_dir(), '/') . '/' . uniqid('ichiloto-editor-fixtures-', true);
    mkdir($orphan . '/logs', 0o777, true);
    file_put_contents($orphan . '/logs/warning.log', 'written after the run ended');

    [, $errors, $status] = runWithTestHelpers('makeTemporaryDirectory("ichiloto-script-");');

    expect($status)->toBe(0)
        ->and($errors)->toBe('')
        ->and(file_exists($orphan))->toBeFalse();
});

it('keeps the Engine\'s and the Editor\'s diagnostics with the run, before and after a field preview', function () {
    $logs = TemporaryFixtures::getDiagnosticsDirectory();
    $root = makeTemporaryProject('ichiloto-diagnostics-');
    Ichiloto\Engine\Util\Debug::warn('before the preview');
    $field = Ichiloto\Editor\Cutscenes\Preview\PreviewField::open($root, 'test-map', new Ichiloto\Engine\Core\Vector2(1, 1), 20, 8, static fn(): float => 0.0);
    $field->dispose();
    Ichiloto\Engine\Util\Debug::warn('after the preview');
    Ichiloto\Editor\Debug\Debug::warn('from the Editor');

    expect(dirname($logs))->toStartWith(rtrim(sys_get_temp_dir(), '/') . '/ichiloto-editor-fixtures-')
        ->and(file_get_contents($logs . '/warning.log'))->toContain('before the preview')->toContain('after the preview')
        ->and(file_get_contents($logs . '/debug.log'))->toContain('from the Editor');
});
