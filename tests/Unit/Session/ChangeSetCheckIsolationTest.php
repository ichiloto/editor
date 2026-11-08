<?php

declare(strict_types=1);

use Ichiloto\Editor\Assets\AssetImport;
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

it('redirects an internal asset link into the scratch project during preview', function () {
    $root = mapGraphicsProject();
    $source = writeIncomingPng();
    $graphics = $root . '/assets/Graphics';
    $link = $graphics . '/Linked';
    $physical = $graphics . '/Physical';
    mkdir($physical, 0777, true);
    symlink($physical, $link);

    try {
        $outcome = ChangeBatch::preview($root, [
            ['method' => 'assets.import', 'params' => ['source' => $source, 'destination' => 'Graphics/Linked/Checked.png']],
        ], static function ($session) use ($physical): array {
            $scratch = new ReflectionProperty($session, 'workspace')->getValue($session)->projectRoot;

            return [is_file($scratch . '/assets/Graphics/Physical/Checked.png'), is_file($physical . '/Checked.png')];
        });

        expect($outcome)->toBe(['status' => 'previewed', 'result' => [true, false]])
            ->and(is_file($physical . '/Checked.png'))->toBeFalse();
    } finally {
        unlink($link);
        unlink($source);
    }
});

it('refuses a project link outside scratch ownership before a checked import runs', function () {
    $root = mapGraphicsProject();
    $source = writeIncomingPng();
    $external = sys_get_temp_dir() . '/' . uniqid('ichiloto-scratch-external-', true);
    $link = $root . '/assets/Graphics/Linked';
    mkdir($external);
    symlink($external, $link);

    try {
        expect(fn() => ChangeBatch::apply($root, [
            ['method' => 'assets.import', 'params' => ['source' => $source, 'destination' => 'Graphics/Linked/Checked.png']],
        ], checkOnly: true))->toThrow(RuntimeException::class, 'cannot be isolated in a scratch copy');
        $outcome = ChangeBatch::apply($root, [
            ['method' => 'assets.import', 'params' => ['source' => $source, 'destination' => 'Graphics/Linked/Checked.png']],
        ]);
        expect($outcome['status'])->toBe('failed')
            ->and($outcome['message'])->toContain('inside the assets folder')
            ->and(scandir($external))->toBe(['.', '..']);
    } finally {
        unlink($link);
        unlink($source);
        rmdir($external);
    }
});

it('only removes directories created by an asset import when undoing the import', function () {
    $root = mapGraphicsProject();
    $source = writeIncomingPng();
    $existing = $root . '/assets/Graphics/Existing';
    mkdir($existing);

    try {
        $withinExisting = AssetImport::plan($root, $source, 'Graphics/Existing/Checked.png')->plan;
        $withinExisting->apply();
        $withinExisting->revert();
        expect(scandir($existing))->toBe(['.', '..']);

        $nested = AssetImport::plan($root, $source, 'Graphics/Incoming/Nested/Checked.png')->plan;
        $nested->apply();
        $nested->revert();
        expect(is_dir($root . '/assets/Graphics/Incoming'))->toBeFalse();
    } finally {
        unlink($source);
    }
});

it('checks and previews an absolute project asset destination before applying the same batch', function () {
    $root = mapGraphicsProject();
    $source = writeIncomingPng();
    $target = $root . '/assets/Graphics/Imported/Checked.png';
    $changes = [['method' => 'assets.import', 'params' => ['source' => $source, 'destination' => $target]]];

    try {
        $outside = $root . '/../' . basename($root) . '-outside.png';
        $refusal = ChangeBatch::apply($root, [
            ['method' => 'assets.import', 'params' => ['source' => $source, 'destination' => $outside]],
        ], checkOnly: true);
        expect($refusal['status'])->toBe('failed')
            ->and($refusal['method'])->toBe('assets.import')
            ->and($refusal['message'])->toContain('inside it')
            ->and(is_file($outside))->toBeFalse();

        expect(ChangeBatch::apply($root, $changes, checkOnly: true)['status'])->toBe('checked')
            ->and(is_file($target))->toBeFalse();
        $outcome = ChangeBatch::preview($root, $changes, static function ($session) use ($target): array {
            $scratch = new ReflectionProperty($session, 'workspace')->getValue($session)->projectRoot;

            return [is_file($scratch . '/assets/Graphics/Imported/Checked.png'), is_file($target)];
        });
        expect($outcome)->toBe(['status' => 'previewed', 'result' => [true, false]])
            ->and(is_file($target))->toBeFalse();
        expect(ChangeBatch::apply($root, $changes)['status'])->toBe('saved')
            ->and(file_get_contents($target))->toBe(file_get_contents($source));
    } finally {
        unlink($source);
    }
});

it('rebinds every link in a project before a preview runs', function () {
    $root = mapGraphicsProject();
    $physical = $root . '/assets/Graphics/Physical';
    $links = $root . '/assets/Graphics/Links';
    mkdir($physical, 0777, true);
    mkdir($links, 0777, true);
    foreach (range(1, 128) as $number) {
        symlink($physical, "{$links}/{$number}");
    }

    $outcome = ChangeBatch::preview($root, [], static function ($session): array {
        $scratch = new ReflectionProperty($session, 'workspace')->getValue($session)->projectRoot;

        return array_values(array_filter(glob($scratch . '/assets/Graphics/Links/*'),
            static fn(string $link): bool => readlink($link) !== $scratch . '/assets/Graphics/Physical'));
    });

    expect($outcome)->toBe(['status' => 'previewed', 'result' => []]);
    foreach (range(1, 128) as $number) {
        unlink("{$links}/{$number}");
    }
});

it('isolates links the same way when the project is copied file by file', function () {
    $root = mapGraphicsProject();
    $source = writeIncomingPng();
    $physical = $root . '/assets/Graphics/Physical';
    mkdir($physical, 0777, true);
    symlink($physical, $root . '/assets/Graphics/Linked');
    $bootstrap = dirname(__DIR__, 2) . '/bootstrap.php';
    $script = sys_get_temp_dir() . '/' . uniqid('ichiloto-check-copies-', true) . '.php';
    file_put_contents($script, '<?php require ' . var_export($bootstrap, true) . ';'
        . ' echo json_encode(Ichiloto\Editor\Session\ChangeBatch::preview(' . var_export($root, true) . ', ['
        . " ['method' => 'assets.import', 'params' => ['source' => " . var_export($source, true) . ", 'destination' => 'Graphics/Linked/Checked.png']],"
        . ' ], static function ($session) { $scratch = (new ReflectionProperty($session, "workspace"))->getValue($session)->projectRoot;'
        . ' return is_file($scratch . "/assets/Graphics/Physical/Checked.png"); }));');

    exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=proc_open ' . escapeshellarg($script) . ' 2>/dev/null', $output, $status);

    expect($status)->toBe(0)
        ->and(json_decode(implode('', $output), true))->toBe(['status' => 'previewed', 'result' => true])
        ->and(is_file($physical . '/Checked.png'))->toBeFalse();
    unlink($root . '/assets/Graphics/Linked');
    unlink($script);
    unlink($source);
});
