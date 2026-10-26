<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Dialogue\DialogueCatalogFile;
use Ichiloto\Editor\Field\RegionFields;
use Ichiloto\Editor\Inspector\MapInspector;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Field\RegionCatalog;
use Ichiloto\Engine\Field\SkitBackdrop;

/**
 * A synthetic project: test-map in region Synthetic Vale, two replaceable backdrop images, and the fixture's skit
 * moved onto test-map.
 */
function createSkitBackdropProject(): string
{
    $root = makeTemporaryProject('ichiloto-skit-backdrop-');
    foreach (['Square', 'Vale'] as $name) {
        writeTilesetTestPng($root . '/assets/Graphics/Skits/' . $name . '.png', 32, 18);
    }
    $map = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($map, str_replace("'region' => '',", "'region' => 'Synthetic Vale',", (string) file_get_contents($map)));
    $skit = $root . '/assets/Data/Skits/breakfast-banter.php';
    file_put_contents($skit, str_replace("'where' => 'happyville/town-center',", "'where' => 'test-map',", (string) file_get_contents($skit)));

    return $root;
}

function findSkitBackdropMapRow(EditorSession $session): array
{
    return findLandmarkRow($session, ['target' => MapInspector::SKIT_BACKDROP_TARGET]);
}

function findSkitBackdropRecordRow(EditorSession $session, string $category, int $index, string $field): array
{
    return array_find($session->readDatabaseRecord($category, $index)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field)
        ?? throw new RuntimeException("{$category} {$index} has no {$field} row");
}

/** @return list<string> The validation messages about skit backdrops. */
function findSkitBackdropIssues(EditorSession $session): array
{
    return array_values(array_map(static fn(array $issue): string => $issue['message'], array_filter($session->validateProject()['issues'],
        static fn(array $issue): bool => str_contains($issue['message'], 'backdrop') || str_contains($issue['message'], 'No map is in region'))));
}

it('chooses a map\'s skit backdrop with the shared image picker, refusing a file the project lacks, as one undo step', function () {
    $root = createSkitBackdropProject();
    $session = EditorSession::open($root);
    $row = findSkitBackdropMapRow($session);
    expect($row['label'])->toBe('Skit Backdrop')
        ->and($row['value'])->toBe('None: skits here show no backdrop')
        ->and($row['reference'])->toBe('png_assets')
        // The GUI editor shows and picks the value as a picture under assets, from this row alone.
        ->and($row['media'])->toBe(['kind' => 'image', 'root' => 'assets'])
        ->and($row['noneLabel'])->toBe(MapInspector::SKIT_BACKDROP_NONE);

    expect(fn() => applyLandmarkRow($session, ['target' => MapInspector::SKIT_BACKDROP_TARGET], 'Graphics/Skits/Missing.png'))
        ->toThrow(SessionRefusal::class, 'is not a PNG in the project\'s assets');
    applyLandmarkRow($session, ['target' => MapInspector::SKIT_BACKDROP_TARGET], 'Graphics/Skits/Square.png');
    expect(getSessionMap($session)->getMapDataField([SkitBackdrop::MAP_KEY]))->toBe('Graphics/Skits/Square.png')
        ->and(findSkitBackdropMapRow($session)['value'])->toBe('Graphics/Skits/Square.png');
    $session->undo();
    expect(getSessionMap($session)->hasMapDataField([SkitBackdrop::MAP_KEY]))->toBeFalse();
    $session->redo();
    $session->saveMap('test-map');
    $saved = require $root . '/assets/Maps/test-map/test-map.data.php';
    expect($saved[SkitBackdrop::MAP_KEY])->toBe('Graphics/Skits/Square.png')
        ->and($saved['description'])->toBe('A tiny fixture map.');

    // Replacing the file keeps the binding: the path is the identity, never the bytes.
    writeTilesetTestPng($root . '/assets/Graphics/Skits/Square.png', 64, 40);
    expect(findSkitBackdropMapRow(EditorSession::open($root))['value'])->toBe('Graphics/Skits/Square.png');

    applyLandmarkRow($session, ['target' => MapInspector::SKIT_BACKDROP_TARGET], '');
    expect(getSessionMap($session)->hasMapDataField([SkitBackdrop::MAP_KEY]))->toBeFalse();
});

it('gives a region the backdrop its maps show when they choose none, on the Regions page', function () {
    $root = createSkitBackdropProject();
    $session = EditorSession::open($root);
    $index = $session->createDatabaseRecord(RegionFields::CATEGORY)['index'];
    $region = findSkitBackdropRecordRow($session, RegionFields::CATEGORY, $index, 'id');
    expect($region['reference'])->toBe('map_regions');
    $session->applyDatabaseRecord(RegionFields::CATEGORY, $index, $region['key'], 'Synthetic Vale');
    $backdrop = findSkitBackdropRecordRow($session, RegionFields::CATEGORY, $index, SkitBackdrop::MAP_KEY);
    expect($backdrop['reference'])->toBe('png_assets')->and($backdrop['media'])->toBe(['kind' => 'image', 'root' => 'assets'])
        ->and($backdrop['value'])->toBe(RegionFields::BACKDROP_NONE);
    $session->applyDatabaseRecord(RegionFields::CATEGORY, $index, $backdrop['key'], 'Graphics/Skits/Vale.png');

    // Unsaved, the map already says what its skits show.
    expect(findSkitBackdropMapRow($session)['value'])->toBe('None: region Synthetic Vale\'s, Graphics/Skits/Vale.png');
    $session->saveDatabase(RegionFields::CATEGORY);
    $regions = RegionCatalog::load($root . '/assets');
    expect($regions->getNames())->toBe(['Synthetic Vale'])->and($regions->getSkitBackdrop('Synthetic Vale'))->toBe('Graphics/Skits/Vale.png');

    // A map's own backdrop wins over its region's.
    applyLandmarkRow($session, ['target' => MapInspector::SKIT_BACKDROP_TARGET], 'Graphics/Skits/Square.png');
    expect(findSkitBackdropMapRow($session)['value'])->toBe('Graphics/Skits/Square.png');
});

it('gives a skit its own backdrop only as an optional override in Database > Skits', function () {
    $root = createSkitBackdropProject();
    $session = EditorSession::open($root);
    $row = findSkitBackdropRecordRow($session, 'skits', 0, SkitBackdrop::SKIT_KEY);
    expect($row['label'])->toBe('Backdrop')->and($row['reference'])->toBe('png_assets')
        ->and($row['media'])->toBe(['kind' => 'image', 'root' => 'assets'])
        ->and($row['value'])->toBe(RecordSchemaCatalog::SKIT_BACKDROP_NONE);
    $session->applyDatabaseRecord('skits', 0, $row['key'], 'Graphics/Skits/Square.png');
    $session->saveDatabase('skits');
    expect((require $root . '/assets/Data/Skits/breakfast-banter.php')[SkitBackdrop::SKIT_KEY])->toBe('Graphics/Skits/Square.png');

    $session->applyDatabaseRecord('skits', 0, findSkitBackdropRecordRow($session, 'skits', 0, SkitBackdrop::SKIT_KEY)['key'], '');
    $session->saveDatabase('skits');
    expect(require $root . '/assets/Data/Skits/breakfast-banter.php')->not->toHaveKey(SkitBackdrop::SKIT_KEY);
});

it('warns where a skit would show no backdrop and about backdrops and regions the game cannot use', function () {
    $root = createSkitBackdropProject();
    $session = EditorSession::open($root);
    expect(findSkitBackdropIssues($session))->toBe(['It plays on map test-map, which shows it no backdrop.']);

    applyLandmarkRow($session, ['target' => MapInspector::SKIT_BACKDROP_TARGET], 'Graphics/Skits/Square.png');
    expect(findSkitBackdropIssues($session))->toBe([]);

    // A file removed after it was chosen, and a region record no map is in.
    unlink($root . '/assets/Graphics/Skits/Square.png');
    $index = $session->createDatabaseRecord(RegionFields::CATEGORY)['index'];
    $session->applyDatabaseRecord(RegionFields::CATEGORY, $index, findSkitBackdropRecordRow($session, RegionFields::CATEGORY, $index, 'id')['key'], 'Old Region');
    $issues = findSkitBackdropIssues($session);
    sort($issues);
    expect($issues)->toHaveCount(2)
        ->and($issues[0])->toBe('No map is in region Old Region.')
        ->and($issues[1])->toContain('Skits here show no backdrop', 'Graphics/Skits/Square.png cannot be drawn');
});

it('refuses the retired per-skit backgrounds of the dialogue catalogue, naming the upgrade', function () {
    expect(DialogueCatalogFile::checkCatalog(['schema' => DialogueCatalogFile::SCHEMA, 'skits' => ['a' => ['background' => 'a.png']]], '/nowhere'))
        ->toContain('Run `ichiloto upgrade`');
});

// -- Terminal editor ---------------------------------------------------------

it('picks a map\'s skit backdrop by path in the Terminal inspector and clears it with None', function () {
    $root = createSkitBackdropProject();
    $editor = metadataEditor($root);
    expect(inspectorValueOf($editor, 'Skit Backdrop'))->toBe('None: skits here show no backdrop');

    selectInspectorField($editor, 'Skit Backdrop');
    callEditorMethod($editor, 'activateInspectorField');
    $values = array_column(getEditorProperty($editor, 'eventOptionDialogEntries'), 'value');
    expect($values[0])->toBe('')->and($values)->toContain('Graphics/Skits/Square.png', 'Graphics/Skits/Vale.png');
    callEditorMethod($editor, 'closeEventOptionDialog', 'cancelled');

    pickInspectorReference($editor, 'Skit Backdrop', 'Graphics/Skits/Square.png');
    expect(mapDataOf($editor)[SkitBackdrop::MAP_KEY])->toBe('Graphics/Skits/Square.png')
        ->and(inspectorValueOf($editor, 'Skit Backdrop'))->toBe('Graphics/Skits/Square.png');
    callEditorMethod($editor, 'getSelectedMap')->save();
    expect((require $root . '/assets/Maps/test-map/test-map.data.php')[SkitBackdrop::MAP_KEY])->toBe('Graphics/Skits/Square.png');

    pickInspectorReference($editor, 'Skit Backdrop', '');
    expect(mapDataOf($editor))->not->toHaveKey(SkitBackdrop::MAP_KEY);
});

it('opens the shared image picker for a skit\'s Backdrop and a region\'s Skit Backdrop in the Terminal database', function (string $category, string $field) {
    $root = createSkitBackdropProject();
    if ($category === RegionFields::CATEGORY) {
        file_put_contents($root . '/assets/' . RegionCatalog::FILE, '<?php return ' . var_export(['schema' => RegionCatalog::SCHEMA,
            'regions' => ['Synthetic Vale' => []]], true) . ';');
    }
    $editor = deletionEditor($root);
    openDatabaseCategory($editor, $category);
    $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
    $row = $fields[array_search($field, array_column($fields, 'field'), true)];
    expect($row['reference'])->toBe('png_assets')
        ->and(callEditorMethod($editor, 'openReferencePicker', $row))->toBeTrue()
        ->and(getEditorProperty($editor, 'referencePicker')->isOpen())->toBeTrue();
})->with([
    'skit override' => ['skits', SkitBackdrop::SKIT_KEY],
    'region backdrop' => [RegionFields::CATEGORY, SkitBackdrop::MAP_KEY],
]);
