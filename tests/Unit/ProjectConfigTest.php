<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\ProjectConfig;
use Ichiloto\Editor\ProjectWorkspace;

function createZoomProject(string $graphics = ''): string
{
    $root = makeTemporaryProject('editor-config-');
    file_put_contents($root . '/config.php', "<?php\n// Keep header.\nreturn [\n  'vocab' => [/* term */ 'title' => 'Hello'],\n  'messages' => ['bye' => 'Bye'],\n  'opaque' => new \\stdClass(),\n  'computed' => strtoupper('keep'), // Leave expression.\n" . $graphics . "];\n");
    return $root;
}

it('shares pending Terms and field zoom in one source-preserving atomic config save', function () {
    $root = createZoomProject();
    $original = file_get_contents($root . '/config.php');
    $workspace = ProjectWorkspace::fromProject($root);
    $config = $workspace->config;
    $terms = $workspace->getRecordDatabase('terms');
    $before = $config->getRecord(ProjectConfig::FIELD_ZOOM)->toArray();
    expect($config->getFieldZoom())->toBe(1.0)->and($terms->isEditable())->toBeTrue();
    $config->setFieldZoom('1');
    expect($workspace->hasUnsavedChanges())->toBeFalse();
    $config->setFieldZoom('2.5');
    $terms->setField(0, 'value', 'Welcome');
    expect(file_get_contents($config->path))->toBe($original);
    $terms->save();
    expect($config->isDirty())->toBeFalse()->and($terms->isDirty())->toBeFalse()
        ->and(file_get_contents($config->path))->toContain("/* term */ 'title' => 'Welcome'", "'opaque' => new \\stdClass()", "strtoupper('keep')", "'zoom' => 2.5");
    $config->getRecord(ProjectConfig::FIELD_ZOOM)->restorePayload($before);
    $terms->setField(0, 'value', 'Hello');
    $config->save();
    expect(file_get_contents($config->path))->toBe($original)->and($terms->isDirty())->toBeFalse();
    $mtime = filemtime($config->path);
    $config->save();
    expect(filemtime($config->path))->toBe($mtime);
});

it('refuses invalid zoom before mutation instead of clamping', function (string $value) {
    $config = new ProjectConfig(createZoomProject());
    expect(fn() => $config->setFieldZoom($value))->toThrow(RuntimeException::class)
        ->and($config->isDirty())->toBeFalse()->and($config->getFieldZoom())->toBe(1.0);
})->with(['0', '0.5', '-1', '8.01', 'INF', 'NAN', '1e309', 'no', '']);

it('repairs explicit null or string zoom rather than mistaking them for an absent numeric default', function (string $literal) {
    $config = new ProjectConfig(createZoomProject("  'graphics' => ['field' => ['zoom' => " . $literal . "]],\n"));
    $config->setFieldZoom('1');
    expect($config->isDirty())->toBeTrue();
    $config->save();
    expect((require $config->path)['graphics']['field']['zoom'])->toBe(1.0);
})->with(['null', "'1'"]);

it('refuses opaque or ambiguous zoom containers without flattening unrelated config', function (string $graphics) {
    $config = new ProjectConfig(createZoomProject($graphics));
    $original = file_get_contents($config->path);
    expect(fn() => $config->setFieldZoom('3'))->toThrow(RuntimeException::class)
        ->and(file_get_contents($config->path))->toBe($original)->and($config->isDirty())->toBeFalse();
})->with([
    "  'graphics' => array_merge([], ['field' => ['zoom' => 2]]),\n",
    "  'graphics' => ['field' => ['zoom' => 1 + 1]],\n",
    "  'graphics' => ['field' => ['zoom' => 2, 'zoom' => 2]],\n",
    "  'graphics' => [], 'graphics' => [],\n",
]);

it('refuses concurrent source changes while retaining all pending config edits', function () {
    $workspace = ProjectWorkspace::fromProject(createZoomProject());
    $config = $workspace->config;
    $workspace->getRecordDatabase('terms')->setField(0, 'value', 'Pending');
    $config->setFieldZoom('8');
    $newSource = str_replace('Keep header.', 'New user header.', file_get_contents($config->path));
    file_put_contents($config->path, $newSource);
    expect(fn() => $config->save())->toThrow(RuntimeException::class, 'changed on disk')
        ->and(file_get_contents($config->path))->toBe($newSource)->and($config->isDirty())->toBeTrue()
        ->and(glob($config->path . '.tmp-*'))->toBe([]);
});

it('preserves bytes mtimes and both pending config areas when installation fails', function () {
    $workspace = ProjectWorkspace::fromProject(createZoomProject());
    $config = $workspace->config;
    touch($config->path, 1000000000);
    $source = file_get_contents($config->path);
    $config->setFieldZoom('8');
    $terms = $workspace->getRecordDatabase('terms');
    $terms->setField(0, 'value', 'Pending');
    expect(fn() => $config->save(new FailingFileSetOperations(failures: ['move' => [$config->path]])))->toThrow(RuntimeException::class)
        ->and(file_get_contents($config->path))->toBe($source)->and(filemtime($config->path))->toBe(1000000000)
        ->and($config->isDirty())->toBeTrue()->and($terms->isDirty())->toBeTrue();
    $config->save();
    expect((require $config->path)['graphics']['field']['zoom'])->toBe(8.0)->and($terms->isDirty())->toBeFalse();
});

it('makes only an opaque term read-only and preserves it through zoom and literal term edits', function () {
    $root = createZoomProject();
    $path = $root . '/config.php';
    file_put_contents($path, str_replace("'bye' => 'Bye'", "'bye' => strtoupper('Bye')", file_get_contents($path)));
    $workspace = ProjectWorkspace::fromProject($root);
    $terms = $workspace->getRecordDatabase('terms');
    expect($terms->getSettingsFields(1)[2]['value'])->toContain('authored expression');
    expect(fn() => $terms->setField(1, 'value', 'Hidden rewrite'))->toThrow(RuntimeException::class);
    $terms->setField(0, 'value', 'Literal edit');
    $workspace->config->setFieldZoom('2');
    $terms->save();
    expect(file_get_contents($path))->toContain("'bye' => strtoupper('Bye')", "'title' => 'Literal edit'");
});

it('preserves the read-only Terms save refusal for nonliteral returned configuration', function () {
    $root = createZoomProject();
    file_put_contents($root . '/config.php', "<?php return array_merge([], ['vocab' => ['title' => 'Keep']]);\n");
    $terms = loadRecordDatabase($root, 'terms');
    expect($terms->isEditable())->toBeFalse()->and(fn() => $terms->save())->toThrow(RuntimeException::class, 'read-only');
});

it('refuses ambiguous dotted literal term keys without changing their nested neighbors', function () {
    $root = createZoomProject();
    file_put_contents($root . '/config.php', "<?php return ['vocab' => ['a.b' => 'Literal', 'a' => ['b' => 'Nested']]];\n");
    $workspace = ProjectWorkspace::fromProject($root);
    $terms = $workspace->getRecordDatabase('terms');
    foreach ([0, 1] as $index) {
        expect(fn() => $terms->setField($index, 'value', 'Wrong target'))->toThrow(RuntimeException::class, 'dotted literal key');
    }
    $workspace->config->setFieldZoom('2');
    $workspace->config->save();
    expect((require $root . '/config.php')['vocab'])->toBe(['a.b' => 'Literal', 'a' => ['b' => 'Nested']]);
});

it('authors field zoom through System numeric input and shares Terms undo save and reload', function () {
    $root = createZoomProject();
    $editor = createEditorForTesting($root);
    $workspace = ProjectWorkspace::fromProject($root);
    setEditorProperty($editor, 'workspace', $workspace);
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 140, 'height' => 40]);
    setEditorProperty($editor, 'isRunning', true);
    callEditorMethod($editor, 'openDatabaseWindow');
    setEditorProperty($editor, 'databaseCategoryIndex', DatabaseCatalog::indexOf('system'));
    setEditorProperty($editor, 'databaseFocus', 'database_settings');
    foreach (callEditorMethod($editor, 'getDatabaseSettingsFields') as $index => $field) {
        if (($field['field'] ?? '') === ProjectConfig::FIELD_ZOOM) { setEditorProperty($editor, 'databaseSelectedSettingIndex', $index); }
    }
    callEditorMethod($editor, 'dispatchInput', "\r");
    foreach (["\177", '2', '.', '5', "\r"] as $key) { callEditorMethod($editor, 'dispatchInput', $key); }
    expect($workspace->config->getFieldZoom())->toBe(2.5);
    $workspace->getRecordDatabase('terms')->setField(0, 'value', 'Concurrent term');
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect((require $workspace->config->path)['graphics']['field']['zoom'])->toBe(2.5);
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    callEditorMethod($editor, 'dispatchInput', "\x13");
    $saved = require $workspace->config->path;
    expect($saved)->not->toHaveKey('graphics')->and($saved['vocab']['title'])->toBe('Concurrent term');
    callEditorMethod($editor, 'dispatchInput', "\x19");
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect((new ProjectConfig($root))->getFieldZoom())->toBe(2.5);
});
