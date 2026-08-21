<?php

declare(strict_types=1);

use Ichiloto\Editor\Editor;
use Ichiloto\Editor\Field\MapEncounters;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Editor\Validation\Severity;

/**
 * A map's runtime metadata — the music it plays and the fights it offers —
 * authored in the ordinary Map Inspector, against the engine's own
 * `EncounterManager` contract. What the engine defaults are shown as
 * defaults and never written by browsing; what an author sets is written to
 * that map's data file and to nothing else.
 */

/**
 * A project whose fixture map can carry music and encounters.
 */
function metadataProject(array $tracks = ['harbour-theme', 'crypt-theme']): string
{
    $root = makeTemporaryProject('ichiloto-map-metadata-');
    mkdir($root . '/assets/Audio/BGM', 0o777, true);

    foreach ($tracks as $track) {
        file_put_contents($root . '/assets/Audio/BGM/' . $track . '.ogg', '');
    }

    return $root;
}

/**
 * An editor over the project with the fixture map selected and the Inspector
 * focused, exactly as an author has it.
 */
function metadataEditor(string $root): Editor
{
    $editor = deletionEditor($root);
    setEditorProperty($editor, 'focusedPane', 'inspector');

    return $editor;
}

/**
 * The inspector field with the given label, and its index.
 *
 * @return array{0: array<string, mixed>, 1: int}
 */
function inspectorFieldNamed(Editor $editor, string $label): array
{
    foreach (callEditorMethod($editor, 'getInspectorFields') as $index => $field) {
        if (trim((string) ($field['label'] ?? '')) === $label) {
            return [$field, $index];
        }
    }

    throw new RuntimeException("No inspector field \"{$label}\": " . implode(', ', array_map(
        static fn(array $f): string => trim((string) ($f['label'] ?? '')),
        callEditorMethod($editor, 'getInspectorFields'),
    )));
}

/**
 * The value shown on the inspector row with the given label.
 */
function inspectorValueOf(Editor $editor, string $label): string
{
    [$field] = inspectorFieldNamed($editor, $label);

    return (string) ($field['value'] ?? '');
}

/**
 * Puts the inspector cursor on a row and returns its descriptor.
 *
 * @return array<string, mixed>
 */
function selectInspectorField(Editor $editor, string $label): array
{
    [$field, $index] = inspectorFieldNamed($editor, $label);
    setEditorProperty($editor, 'selectedInspectorFieldIndex', $index);

    return $field;
}

/**
 * Chooses a value from the picker the row opens.
 */
function pickInspectorReference(Editor $editor, string $label, string $value): void
{
    selectInspectorField($editor, $label);
    callEditorMethod($editor, 'activateInspectorField');
    $entries = getEditorProperty($editor, 'eventOptionDialogEntries');
    $index = null;

    foreach ($entries as $position => $entry) {
        if ((string) $entry['value'] === $value) {
            $index = $position;
        }
    }

    expect($index)->not->toBeNull("the picker offers {$value}");
    setEditorProperty($editor, 'selectedEventOptionIndex', $index);
    callEditorMethod($editor, 'applySelectedEventOption');
}

/**
 * The map's data as it now stands in the editor.
 */
function mapDataOf(Editor $editor): array
{
    return callEditorMethod($editor, 'getSelectedMap')->getEditableData();
}

/**
 * Every file of a project with its bytes and modification time.
 *
 * @return array<string, array{0: string, 1: int}>
 */
function projectFileState(string $root): array
{
    $state = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $state[substr($file->getPathname(), strlen($root) + 1)] = [
                (string) file_get_contents($file->getPathname()),
                (int) filemtime($file->getPathname()),
            ];
        }
    }

    ksort($state);

    return $state;
}

/**
 * Backdates every file so a rewrite is visible in its modification time.
 */
function backdateProject(string $root): void
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            touch($file->getPathname(), time() - 3600);
        }
    }
}

// -- Audio -------------------------------------------------------------------

it('offers the project\'s own tracks, writes the one chosen, and clears the key rather than emptying it', function () {
    $root = metadataProject();
    $editor = metadataEditor($root);

    expect(inspectorValueOf($editor, 'Background Music'))->toBe('(None)')
        ->and(mapDataOf($editor))->not->toHaveKey('bgm');

    // The picker offers what the project has, and silence.
    selectInspectorField($editor, 'Background Music');
    callEditorMethod($editor, 'activateInspectorField');
    $entries = getEditorProperty($editor, 'eventOptionDialogEntries');
    expect(array_column($entries, 'value'))->toBe(['', 'crypt-theme', 'harbour-theme']);
    callEditorMethod($editor, 'closeEventOptionDialog', 'cancelled');

    pickInspectorReference($editor, 'Background Music', 'harbour-theme');
    expect(mapDataOf($editor)['bgm'])->toBe('harbour-theme')
        ->and(inspectorValueOf($editor, 'Background Music'))->toBe('harbour-theme')
        ->and(callEditorMethod($editor, 'getSelectedMap')->isDirty())->toBeTrue();

    // Saving and reopening hydrates the same selection.
    callEditorMethod($editor, 'getSelectedMap')->save();
    $reopened = metadataEditor($root);
    expect(inspectorValueOf($reopened, 'Background Music'))->toBe('harbour-theme');

    // (None) removes the key; it never writes an empty track.
    pickInspectorReference($reopened, 'Background Music', '');
    expect(mapDataOf($reopened))->not->toHaveKey('bgm')
        ->and(inspectorValueOf($reopened, 'Background Music'))->toBe('(None)');
    callEditorMethod($reopened, 'getSelectedMap')->save();
    $saved = require $root . '/assets/Maps/test-map/test-map.data.php';
    expect($saved)->not->toHaveKey('bgm');
});

it('keeps a missing track visible and diagnosable rather than jumping to a valid one', function () {
    $root = metadataProject();
    editTestMapData($root, static fn(string $source): string => str_replace(
        "return [",
        "return [\n  'bgm' => 'a-track-that-was-deleted',",
        $source,
    ));
    $editor = metadataEditor($root);

    expect(inspectorValueOf($editor, 'Background Music'))->toBe('a-track-that-was-deleted · not in assets/Audio/BGM')
        ->and(mapDataOf($editor)['bgm'])->toBe('a-track-that-was-deleted')
        ->and(callEditorMethod($editor, 'getSelectedMap')->isDirty())->toBeFalse();

    $issues = issuesMentioning(validateProject($root), 'a-track-that-was-deleted');
    expect($issues)->toHaveCount(1);
});

it('undoes a music edit back to pristine, and redoes it', function () {
    $root = metadataProject();
    $editor = metadataEditor($root);
    $map = callEditorMethod($editor, 'getSelectedMap');

    pickInspectorReference($editor, 'Background Music', 'crypt-theme');
    expect($map->isDirty())->toBeTrue();

    callEditorMethod($editor, 'performUndo');
    expect(mapDataOf($editor))->not->toHaveKey('bgm')
        ->and($map->isDirty())->toBeFalse('undoing to the saved state is clean again');

    callEditorMethod($editor, 'performRedo');
    expect(mapDataOf($editor)['bgm'])->toBe('crypt-theme')
        ->and($map->isDirty())->toBeTrue();

    // A save after an undo writes the restored value, not the abandoned edit.
    callEditorMethod($editor, 'performUndo');
    $map->save();
    expect(require $root . '/assets/Maps/test-map/test-map.data.php')->not->toHaveKey('bgm');
});

// -- Encounters --------------------------------------------------------------

it('reads the engine\'s defaults without writing them, and shows them as defaults', function () {
    $root = metadataProject();
    editTestMapData($root, static fn(string $source): string => str_replace(
        "return [",
        "return [\n  'encounters' => ['troops' => ['Bat x 2' => 3]],",
        $source,
    ));
    $editor = metadataEditor($root);

    expect(inspectorValueOf($editor, 'Rate'))->toBe('(engine default: 15)')
        ->and(inspectorValueOf($editor, 'Tiles'))->toBe('(engine default: encounter)')
        ->and(inspectorValueOf($editor, 'Encounters'))->toBe('1 troop, every ~15 steps, danger tiles')
        ->and(mapDataOf($editor)['encounters'])->toBe(['troops' => ['Bat x 2' => 3]])
        ->and(callEditorMethod($editor, 'getSelectedMap')->isDirty())->toBeFalse('browsing writes no default');
});

it('adds and removes weighted troop rows, and the last removal takes the block with it', function () {
    $root = metadataProject();
    $editor = metadataEditor($root);
    $map = callEditorMethod($editor, 'getSelectedMap');

    expect(inspectorValueOf($editor, 'Encounters'))->toBe('off');

    // Shift+O on the list row adds a troop the table does not already use.
    selectInspectorField($editor, 'Troops · 0');
    callEditorMethod($editor, 'addInspectorListItem');
    expect(mapDataOf($editor)['encounters'])->toBe(['troops' => ['Bat x 2' => 1]]);

    selectInspectorField($editor, 'Troops · 1');
    callEditorMethod($editor, 'addInspectorListItem');
    expect(array_keys(mapDataOf($editor)['encounters']['troops']))->toBe(['Bat x 2', 'Lone Rat']);

    // Weights are edited in place.
    $weightFields = array_values(array_filter(
        callEditorMethod($editor, 'getInspectorFields'),
        static fn(array $field): bool => trim((string) ($field['label'] ?? '')) === 'Weight',
    ));
    callEditorMethod($editor, 'applyInspectorFieldValue', $weightFields[1], '4');
    expect(mapDataOf($editor)['encounters']['troops'])->toBe(['Bat x 2' => 1, 'Lone Rat' => 4]);

    // Removing rows, last one takes the block.
    selectInspectorField($editor, 'Troops · 2');
    callEditorMethod($editor, 'removeInspectorListItem');
    expect(array_keys(mapDataOf($editor)['encounters']['troops']))->toBe(['Bat x 2']);

    selectInspectorField($editor, 'Troops · 1');
    callEditorMethod($editor, 'removeInspectorListItem');
    expect(mapDataOf($editor))->not->toHaveKey('encounters')
        ->and(inspectorValueOf($editor, 'Encounters'))->toBe('off');

    // Every step of that is one undo.
    callEditorMethod($editor, 'performUndo');
    expect(array_keys(mapDataOf($editor)['encounters']['troops']))->toBe(['Bat x 2']);

    while ($map->isDirty()) {
        callEditorMethod($editor, 'performUndo');
    }

    expect(mapDataOf($editor))->not->toHaveKey('encounters');
});

it('refuses a duplicate troop rather than letting PHP discard a row', function () {
    $root = metadataProject();
    editTestMapData($root, static fn(string $source): string => str_replace(
        "return [",
        "return [\n  'encounters' => ['troops' => ['Bat x 2' => 3, 'Lone Rat' => 2], 'rate' => 20],",
        $source,
    ));
    $editor = metadataEditor($root);
    $troopRows = array_values(array_filter(
        callEditorMethod($editor, 'getInspectorFields'),
        static fn(array $field): bool => trim((string) ($field['label'] ?? '')) === 'Troop',
    ));

    // Pointing the second row at the first row's troop would collapse two
    // rows into one key and lose a weight.
    expect(fn() => callEditorMethod($editor, 'applyInspectorFieldValue', $troopRows[1], 'Bat x 2'))
        ->toThrow(InvalidArgumentException::class, 'already row 1');
    expect(mapDataOf($editor)['encounters']['troops'])->toBe(['Bat x 2' => 3, 'Lone Rat' => 2]);

    // The picker reports it instead of writing it.
    pickInspectorReference($editor, 'Troop', 'Lone Rat');
    expect(mapDataOf($editor)['encounters']['troops'])->toBe(['Bat x 2' => 3, 'Lone Rat' => 2])
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('already row 2');
});

it('renames a troop row in place, keeping its position and weight', function () {
    $root = metadataProject();
    editTestMapData($root, static fn(string $source): string => str_replace(
        "return [",
        "return [\n  'encounters' => ['troops' => ['Bat x 2' => 3], 'rate' => 20],",
        $source,
    ));
    $editor = metadataEditor($root);
    pickInspectorReference($editor, 'Troop', 'Lone Rat');

    expect(mapDataOf($editor)['encounters']['troops'])->toBe(['Lone Rat' => 3], 'the weight rode along with the rename')
        ->and(mapDataOf($editor)['encounters']['rate'])->toBe(20);
});

it('sets the rate and the tile mode, and cycles the tile mode with the enum idiom', function () {
    $root = metadataProject();
    editTestMapData($root, static fn(string $source): string => str_replace(
        "return [",
        "return [\n  'encounters' => ['troops' => ['Bat x 2' => 3]],",
        $source,
    ));
    $editor = metadataEditor($root);

    $rate = selectInspectorField($editor, 'Rate');
    callEditorMethod($editor, 'applyInspectorFieldValue', $rate, '24');
    expect(mapDataOf($editor)['encounters'])->toBe(['troops' => ['Bat x 2' => 3], 'rate' => 24])
        ->and(inspectorValueOf($editor, 'Rate'))->toBe('24');

    selectInspectorField($editor, 'Tiles');
    callEditorMethod($editor, 'adjustInspectorOptionField', 1);
    expect(mapDataOf($editor)['encounters']['tiles'])->toBe('any')
        ->and(inspectorValueOf($editor, 'Encounters'))->toBe('1 troop, every ~24 steps, any tile');

    // The Left/Right idiom steps and stops at the ends, as on every enum row.
    callEditorMethod($editor, 'adjustInspectorOptionField', -1);
    expect(mapDataOf($editor)['encounters']['tiles'])->toBe('encounter');
});

it('keeps a future key inside the block, and keeps the block alive when only that is left', function () {
    $root = metadataProject();
    editTestMapData($root, static fn(string $source): string => str_replace(
        "return [",
        "return [\n  'encounters' => ['troops' => ['Bat x 2' => 3], 'rate' => 9, 'weatherBias' => ['storm' => 2]],",
        $source,
    ));
    $editor = metadataEditor($root);

    $rate = selectInspectorField($editor, 'Rate');
    callEditorMethod($editor, 'applyInspectorFieldValue', $rate, '12');
    expect(mapDataOf($editor)['encounters'])->toBe([
        'troops' => ['Bat x 2' => 3],
        'rate' => 12,
        'weatherBias' => ['storm' => 2],
    ], 'the key the editor does not own is untouched and keeps its place');

    // Removing the last troop cannot remove a block that still carries the
    // author's own field.
    selectInspectorField($editor, 'Troops · 1');
    callEditorMethod($editor, 'removeInspectorListItem');
    expect(mapDataOf($editor)['encounters'])->toBe(['weatherBias' => ['storm' => 2]]);
});

it('refuses to edit an encounter block it cannot hold, and says which shape', function (mixed $block, string $reason) {
    $root = metadataProject();
    editTestMapData($root, static fn(string $source): string => str_replace(
        "return [",
        "return [\n  'encounters' => " . var_export($block, true) . ",",
        $source,
    ));
    $editor = metadataEditor($root);

    expect(inspectorValueOf($editor, 'Encounters'))->toBe('unsupported shape')
        ->and(inspectorValueOf($editor, '! Read-only'))->toContain($reason);

    // Nothing on the block can be edited, and nothing became dirty.
    selectInspectorField($editor, '! Read-only');
    callEditorMethod($editor, 'addInspectorListItem');
    expect(callEditorMethod($editor, 'getSelectedMap')->isDirty())->toBeFalse();

    $issues = issuesMentioning(validateProject($root), 'encounters block cannot be read');
    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Severity::ERROR);
})->with([
    'a block that is not an array' => ['every few steps', 'is string, not an array'],
    'troops that are not troop weights' => [['troops' => 'Bat x 2'], 'troops are string'],
    'a troop keyed by number' => [['troops' => [5 => 'Bat x 2']], 'keyed by int'],
    'a weight that is an array' => [['troops' => ['Bat x 2' => ['weight' => 3]]], 'weight that is array'],
]);

// -- Validation --------------------------------------------------------------

it('reports every encounter shape the engine reads differently from the author', function (string $block, string $expected, Severity $severity) {
    $root = metadataProject();
    editTestMapData($root, static fn(string $source): string => str_replace(
        "return [",
        "return [\n  'encounters' => " . $block . ",",
        $source,
    ));

    $issues = issuesMentioning(validateProject($root), $expected);

    expect($issues)->toHaveCount(1, $expected)
        ->and($issues[0]->severity)->toBe($severity);
})->with([
    'an unknown tile mode' => [
        "['troops' => ['Bat x 2' => 3], 'tiles' => 'Any']",
        "count 'Any' tiles, which the engine does not recognise",
        Severity::ERROR,
    ],
    'a fractional rate' => [
        "['troops' => ['Bat x 2' => 3], 'rate' => 12.5]",
        'reads as 12 steps',
        Severity::WARNING,
    ],
    'a rate that is not a number' => [
        "['troops' => ['Bat x 2' => 3], 'rate' => 'often']",
        "rate is 'often', which is not a number of steps",
        Severity::ERROR,
    ],
    'a rate the engine clamps' => [
        "['troops' => ['Bat x 2' => 3], 'rate' => -4]",
        'which the engine clamps to one step',
        Severity::ERROR,
    ],
    'a fractional weight' => [
        "['troops' => ['Bat x 2' => 2.5], 'rate' => 20]",
        'weight of 2.5, which the engine reads as 2',
        Severity::WARNING,
    ],
    'a zero weight' => [
        "['troops' => ['Bat x 2' => 0], 'rate' => 20]",
        'so it can never be picked',
        Severity::WARNING,
    ],
    'an unknown troop' => [
        "['troops' => ['Wyverns' => 3], 'rate' => 20]",
        'troop "Wyverns", which the project does not define',
        Severity::ERROR,
    ],
    'a missing rate' => [
        "['troops' => ['Bat x 2' => 3]]",
        'no rate, so the engine uses its default of 15 steps',
        Severity::WARNING,
    ],
]);

it('reports a troop written twice in the table, which PHP would silently collapse', function () {
    $root = metadataProject();
    editTestMapData($root, static fn(string $source): string => str_replace(
        "return [",
        "return [\n  'encounters' => [\n    'troops' => [\n      'Bat x 2' => 3,\n      'Lone Rat' => 2,\n      'Bat x 2' => 9,\n    ],\n    'rate' => 20,\n  ],",
        $source,
    ));

    $issues = issuesMentioning(validateProject($root), 'listed 2 times in the encounter table');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Severity::ERROR)
        ->and($issues[0]->message)->toContain('"Bat x 2"');
});

// -- Persistence -------------------------------------------------------------

it('writes only the selected map\'s data file, and only when something changed', function () {
    $root = metadataProject();
    $editor = metadataEditor($root);
    backdateProject($root);
    $before = projectFileState($root);

    // Opening, browsing every row, filtering and validating write nothing.
    foreach (callEditorMethod($editor, 'getInspectorFields') as $index => $field) {
        setEditorProperty($editor, 'selectedInspectorFieldIndex', $index);
        callEditorMethod($editor, 'getInspectorLines');
    }

    validateProject($root);
    callEditorMethod($editor, 'saveAllAssets');
    expect(projectFileState($root))->toBe($before, 'a no-op Save All writes nothing');

    // One music edit touches one file.
    pickInspectorReference($editor, 'Background Music', 'harbour-theme');
    callEditorMethod($editor, 'saveAllAssets');
    $after = projectFileState($root);
    $changed = array_keys(array_diff_assoc(
        array_map(static fn(array $entry): string => $entry[0], $after),
        array_map(static fn(array $entry): string => $entry[0], $before),
    ));

    expect($changed)->toBe(['assets/Maps/test-map/test-map.data.php'])
        ->and($after['assets/Maps/test-map/test-map.map.php'])->toBe($before['assets/Maps/test-map/test-map.map.php'])
        ->and($after['assets/Maps/test-map/test-map.event.php'])->toBe($before['assets/Maps/test-map/test-map.event.php']);

    // And one encounter edit touches the same one file, and nothing else.
    $between = projectFileState($root);
    selectInspectorField($editor, 'Troops · 0');
    callEditorMethod($editor, 'addInspectorListItem');
    callEditorMethod($editor, 'saveAllAssets');
    $final = projectFileState($root);
    $changed = array_keys(array_diff_assoc(
        array_map(static fn(array $entry): string => $entry[0], $final),
        array_map(static fn(array $entry): string => $entry[0], $between),
    ));

    expect($changed)->toBe(['assets/Maps/test-map/test-map.data.php']);

    // Reloading hydrates the same model.
    $reopened = metadataEditor($root);
    expect(inspectorValueOf($reopened, 'Background Music'))->toBe('harbour-theme')
        ->and(mapDataOf($reopened)['encounters'])->toBe(['troops' => ['Bat x 2' => 1]]);
});

it('leaves every unrelated map key exactly as it was', function () {
    $root = metadataProject();
    editTestMapData($root, static fn(string $source): string => str_replace(
        "return [",
        "return [\n  'stationCode' => 'HV-4',\n  'customMetadata' => ['owner' => 'field team', 'tags' => ['coastal', 'quiet']],",
        $source,
    ));
    $editor = metadataEditor($root);
    $before = require $root . '/assets/Maps/test-map/test-map.data.php';

    pickInspectorReference($editor, 'Background Music', 'crypt-theme');
    selectInspectorField($editor, 'Troops · 0');
    callEditorMethod($editor, 'addInspectorListItem');
    callEditorMethod($editor, 'getSelectedMap')->save();
    $after = require $root . '/assets/Maps/test-map/test-map.data.php';

    foreach ($before as $key => $value) {
        expect($after[$key])->toBe($value, "the map's own {$key} survived");
    }

    expect($after['bgm'])->toBe('crypt-theme')
        ->and($after['encounters'])->toBe(['troops' => ['Bat x 2' => 1]]);
});

it('leaves the map untouched when the write is refused', function () {
    $root = metadataProject();
    $editor = metadataEditor($root);
    pickInspectorReference($editor, 'Background Music', 'harbour-theme');
    backdateProject($root);
    $before = projectFileState($root);
    $map = callEditorMethod($editor, 'getSelectedMap');
    chmod($map->directory, 0o555);

    $failed = false;

    try {
        $map->save();
    } catch (Throwable) {
        $failed = true;
    }

    chmod($map->directory, 0o777);

    expect($failed)->toBeTrue('a folder that cannot be written refuses the save')
        ->and(projectFileState($root))->toBe($before, 'no byte and no timestamp moved')
        ->and(glob($map->directory . '/*.tmp*'))->toBe([])
        ->and($map->isDirty())->toBeTrue('the work is still there to save');
});

// -- The model ---------------------------------------------------------------

it('reads what the engine will make of a block without changing it', function () {
    $absent = MapEncounters::of(null);
    expect($absent->isDeclared())->toBeFalse()
        ->and($absent->summary())->toBe('off')
        ->and($absent->rate())->toBe(15)
        ->and($absent->tiles())->toBe('encounter');

    $authored = MapEncounters::of(['troops' => ['Bat x 2' => 3, 'Lone Rat' => 1], 'rate' => 22, 'tiles' => 'any']);
    expect($authored->rows())->toBe([
        ['name' => 'Bat x 2', 'weight' => 3],
        ['name' => 'Lone Rat', 'weight' => 1],
    ])
        ->and($authored->rate())->toBe(22)
        ->and($authored->authoredRate())->toBe(22)
        ->and($authored->tiles())->toBe('any')
        ->and($authored->summary())->toBe('2 troops, every ~22 steps, any tile');

    // The engine clamps a rate below one; the model says what will happen.
    $clamped = MapEncounters::of(['troops' => ['Bat x 2' => 3], 'rate' => -5]);
    expect($clamped->rate())->toBe(1)
        ->and($clamped->rawRate())->toBe(-5);

    // A tile mode the engine does not know means danger tiles only.
    $typo = MapEncounters::of(['troops' => ['Bat x 2' => 3], 'tiles' => 'Any']);
    expect($typo->tiles())->toBe('encounter')
        ->and($typo->authoredTiles())->toBe('Any');
});
