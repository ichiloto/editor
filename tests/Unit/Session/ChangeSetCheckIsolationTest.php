<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\ChangeBatch;

/**
 * A check or preview of a change set never touches the project. Edits that
 * write at once (an imported asset, for one) land on a scratch copy, so even a
 * process that dies part way leaves the project as it was.
 */

/** Every file of a project, by path, with its contents hashed. @return array<string, string> */
function hashProjectFiles(string $root): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $files[substr($file->getPathname(), strlen($root))] = hash_file('sha256', $file->getPathname());
    }
    ksort($files);

    return $files;
}

/** An importable PNG outside the project. */
function writeIncomingPng(): string
{
    $path = sys_get_temp_dir() . '/' . uniqid('ichiloto-incoming-', true) . '.png';
    $image = imagecreatetruecolor(48, 48);
    imagepng($image, $path);

    return $path;
}

it('leaves the project byte-identical after a checked change set that imports an asset', function () {
    $root = mapGraphicsProject();
    $source = writeIncomingPng();
    $before = hashProjectFiles($root);

    $outcome = ChangeBatch::apply($root, [
        ['method' => 'assets.import', 'params' => ['source' => $source, 'destination' => 'Graphics/Checked.png']],
    ], checkOnly: true);

    expect($outcome['status'])->toBe('checked')
        ->and(hashProjectFiles($root))->toBe($before);
    unlink($source);
});

it('leaves the project byte-identical when the process dies part way through a check', function () {
    $root = mapGraphicsProject();
    $source = writeIncomingPng();
    $before = hashProjectFiles($root);
    $bootstrap = dirname(__DIR__, 2) . '/bootstrap.php';
    $script = sys_get_temp_dir() . '/' . uniqid('ichiloto-check-dies-', true) . '.php';
    file_put_contents($script, '<?php require ' . var_export($bootstrap, true) . ';'
        . ' $dies = new class implements JsonSerializable { public function jsonSerialize(): mixed { exit(9); } };'
        . ' Ichiloto\Editor\Session\ChangeBatch::apply(' . var_export($root, true) . ', ['
        . " ['method' => 'assets.import', 'params' => ['source' => " . var_export($source, true) . ", 'destination' => 'Graphics/Checked.png']],"
        . " ['method' => 'maps.list', 'params' => ['dies' => \$dies]],"
        . ' ], checkOnly: true);');

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>/dev/null', $output, $status);

    expect($status)->toBe(9)
        ->and(hashProjectFiles($root))->toBe($before);
    unlink($script);
    unlink($source);
});

it('still writes an applied change set to the project itself', function () {
    $root = mapGraphicsProject();
    $source = writeIncomingPng();

    $outcome = ChangeBatch::apply($root, [
        ['method' => 'assets.import', 'params' => ['source' => $source, 'destination' => 'Graphics/Applied.png']],
    ]);

    expect($outcome['status'])->toBe('saved')
        ->and(is_file($root . '/assets/Graphics/Applied.png'))->toBeTrue();
    unlink($source);
});
