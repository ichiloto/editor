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

/** Sets a setting through the Configuration category, as either editor does. */
function setConfiguration(ProjectWorkspace $workspace, string $path, string $value): void
{
    $database = $workspace->getRecordDatabase('configuration');
    $index = array_search($path, $database->getEntryLabels(), true);

    if ($index === false) {
        throw new RuntimeException("No setting {$path}.");
    }

    $database->setField($index, 'value', $value);
}

/** The field zoom a project's config.php holds, or null when it leaves it to the default. */
function savedZoom(string $root): mixed
{
    return (require $root . '/config.php')['graphics']['field']['zoom'] ?? null;
}

it('shares pending Terms and field zoom in one source-preserving atomic config save', function () {
    $root = createZoomProject();
    $original = file_get_contents($root . '/config.php');
    $workspace = ProjectWorkspace::fromProject($root);
    $config = $workspace->config;
    $terms = $workspace->getRecordDatabase('terms');
    $before = $config->getRecord(ProjectConfig::FIELD_ZOOM)->toArray();
    expect($before['default'])->toBe(1.0)->and($terms->isEditable())->toBeTrue();
    // Choosing the default for a setting the file leaves out writes nothing.
    setConfiguration($workspace, ProjectConfig::FIELD_ZOOM, '1');
    expect($workspace->hasUnsavedChanges())->toBeFalse();
    setConfiguration($workspace, ProjectConfig::FIELD_ZOOM, '2.5');
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
    $root = createZoomProject();
    $workspace = ProjectWorkspace::fromProject($root);
    expect(fn() => setConfiguration($workspace, ProjectConfig::FIELD_ZOOM, $value))->toThrow(InvalidArgumentException::class)
        ->and($workspace->config->isDirty())->toBeFalse()->and(savedZoom($root))->toBeNull();
})->with(['0', '0.5', '-1', '8.01', 'INF', 'NAN', '1e309', 'no', '']);

it('repairs explicit null or string zoom rather than mistaking them for an absent numeric default', function (string $literal) {
    $root = createZoomProject("  'graphics' => ['field' => ['zoom' => " . $literal . "]],\n");
    $workspace = ProjectWorkspace::fromProject($root);
    setConfiguration($workspace, ProjectConfig::FIELD_ZOOM, '1');
    expect($workspace->config->isDirty())->toBeTrue();
    $workspace->config->save();
    expect(savedZoom($root))->toBe(1.0);
})->with(['null', "'1'"]);

it('refuses opaque or ambiguous zoom containers without flattening unrelated config', function (string $graphics) {
    $root = createZoomProject($graphics);
    $workspace = ProjectWorkspace::fromProject($root);
    $original = file_get_contents($workspace->config->path);
    expect(fn() => setConfiguration($workspace, ProjectConfig::FIELD_ZOOM, '3'))->toThrow(RuntimeException::class)
        ->and(file_get_contents($workspace->config->path))->toBe($original)->and($workspace->config->isDirty())->toBeFalse();
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
    setConfiguration($workspace, ProjectConfig::FIELD_ZOOM, '8');
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
    setConfiguration($workspace, ProjectConfig::FIELD_ZOOM, '8');
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
    setConfiguration($workspace, ProjectConfig::FIELD_ZOOM, '2');
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
    setConfiguration($workspace, ProjectConfig::FIELD_ZOOM, '2');
    $workspace->config->save();
    expect((require $root . '/config.php')['vocab'])->toBe(['a.b' => 'Literal', 'a' => ['b' => 'Nested']]);
});

it('authors field zoom through the Configuration category and shares Terms undo save and reload', function () {
    $root = createZoomProject();
    $editor = createEditorForTesting($root);
    $workspace = ProjectWorkspace::fromProject($root);
    setEditorProperty($editor, 'workspace', $workspace);
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 140, 'height' => 40]);
    setEditorProperty($editor, 'isRunning', true);
    callEditorMethod($editor, 'openDatabaseWindow');
    setEditorProperty($editor, 'databaseCategoryIndex', DatabaseCatalog::indexOf('configuration'));
    $zoom = array_search(ProjectConfig::FIELD_ZOOM, $workspace->getRecordDatabase('configuration')->getEntryLabels(), true);
    setEditorProperty($editor, 'databaseSelectedRecordIndexes', ['configuration' => $zoom]);
    setEditorProperty($editor, 'databaseFocus', 'database_settings');
    foreach (callEditorMethod($editor, 'getDatabaseSettingsFields') as $index => $field) {
        if (($field['field'] ?? '') === 'value') { setEditorProperty($editor, 'databaseSelectedSettingIndex', $index); }
    }
    callEditorMethod($editor, 'dispatchInput', "\r");
    foreach (["\177", '2', '.', '5', "\r"] as $key) { callEditorMethod($editor, 'dispatchInput', $key); }
    expect($workspace->config->getRecord(ProjectConfig::FIELD_ZOOM)->get('value'))->toBe(2.5);
    $workspace->getRecordDatabase('terms')->setField(0, 'value', 'Concurrent term');
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect(savedZoom($root))->toBe(2.5);
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    callEditorMethod($editor, 'dispatchInput', "\x13");
    $saved = require $workspace->config->path;
    expect($saved)->not->toHaveKey('graphics')->and($saved['vocab']['title'])->toBe('Concurrent term');
    callEditorMethod($editor, 'dispatchInput', "\x19");
    callEditorMethod($editor, 'dispatchInput', "\x13");
    expect(savedZoom($root))->toBe(2.5);
});

it('edits an enum setting as a choice of its enum, written back as the enum case', function () {
    $root = createZoomProject("  'ui' => ['battle' => ['selection_color' => \\Ichiloto\\Engine\\IO\\Enumerations\\Color::LIGHT_BLUE]],\n");
    $workspace = ProjectWorkspace::fromProject($root);
    $database = $workspace->getRecordDatabase('configuration');
    $index = array_search('ui.battle.selection_color', $database->getEntryLabels(), true);
    $value = array_find($database->getSettingsFields($index), static fn(array $field): bool => ($field['field'] ?? null) === 'value');

    expect($value['options'] ?? [])->toContain('RED')->and($value['value'])->toBe('LIGHT_BLUE');

    $database->setField($index, 'value', 'RED');
    $workspace->config->save();

    expect((string) file_get_contents($root . '/config.php'))->toContain("'selection_color' => \\Ichiloto\\Engine\\IO\\Enumerations\\Color::RED")
        ->and((require $root . '/config.php')['ui']['battle']['selection_color'])->toBe(\Ichiloto\Engine\IO\Enumerations\Color::RED);
});

it('edits a setting authored as a case of an enum with no values, by the case\'s name', function () {
    $root = createZoomProject("  'ui' => ['dialogue' => ['window' => ['position' => \\Ichiloto\\Engine\\UI\\Windows\\Enumerations\\WindowPosition::TOP]]],\n");
    $workspace = ProjectWorkspace::fromProject($root);
    $database = $workspace->getRecordDatabase('configuration');
    $index = array_search('ui.dialogue.window.position', $database->getEntryLabels(), true);

    expect($index)->not->toBeFalse();

    $database->setField($index, 'value', 'BOTTOM');
    $workspace->config->save();

    expect((require $root . '/config.php')['ui']['dialogue']['window']['position'])
        ->toBe(\Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition::BOTTOM);
});
