<?php

declare(strict_types=1);

use Ichiloto\Editor\Field\MapCategoryFields;
use Ichiloto\Editor\Inspector\MapLandmarkFields;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Field\MapCategoryCatalog;

/**
 * A record edited earlier in a session reaches every reader after it, in a
 * change set or not, before anything is written: authoring, validation and
 * previews read the workspace's current records, falling back to the files
 * only for records with no unsaved edit. Synthetic fixtures only: the 4 x 2
 * graphics test map, whose buildings layer (`map:4`) holds ` /  ` over ` xx `.
 */

function currentRecordsSession(): array
{
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: [
        'chest' => ['name' => 'Chest', 'layer' => 'buildings', 'glyphs' => ['m'], 'tiles' => ['furniture' => ['60']]],
    ]);
    // The fixture paints marker E; give it a definition so a change set on the map can be written.
    $data = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($data, str_replace("'events' => [],", "'events' => ['E' => ['class' => 'Ichiloto\\Engine\\Events\\Triggers\\DialogueEventTrigger', "
        . "'data' => ['dialogue' => [['name' => '', 'text' => 'A sign.']]]]],", (string) file_get_contents($data)));

    return [$root, EditorSession::open($root)];
}

/** Sets one of the Home tileset's rows by field id. */
function applyTilesetRow(EditorSession $session, string $field, string $value): void
{
    $index = array_search('Home', $session->listDatabaseRecords('tilesets')['records'], true);
    $row = array_find($session->readDatabaseRecord('tilesets', $index)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === $field)
        ?? throw new RuntimeException('No tileset row ' . $field);
    $session->applyDatabaseRecord('tilesets', $index, $row['key'], $value);
}

/** @return list<string> The buildings layer, row by row. */
function readCurrentBuildingRows(EditorSession $session): array
{
    $layer = array_find($session->readMap('test-map')['layers'], static fn(array $layer): bool => $layer['id'] === 'map:4');

    return array_map(static fn(array $row): string => implode('', $row), $layer['rows']);
}

it('paints with a tileset piece as edited earlier in the same change set, and lists a piece added there', function () {
    [$root, $session] = currentRecordsSession();
    $tileset = (string) file_get_contents($root . '/assets/Data/Tilesets/home.php');
    $session->beginChanges();

    // The chest becomes a visual surface: its tile stands for no glyph.
    applyTilesetRow($session, 'piece0Glyphs', ' ');
    applyTilesetRow($session, 'piece0Id', 'surface');
    $painted = $session->stampTiles('test-map', $session->readMap('test-map')['revision'], 'furniture', [[0, 0, 60]], 'Place tiles');

    expect($painted)->toMatchArray(['status' => 'applied', 'changed' => 1, 'glyphs' => 0])
        ->and(readCurrentBuildingRows($session)[0])->toBe(' /  ')
        ->and(array_column($session->listPieces('test-map')['pieces'], 'id'))->toBe(['surface'])
        // The map world draws with the tileset as edited, not as saved.
        ->and(array_keys(getSessionMap($session)->loadGraphics()?->tileset->pieces ?? []))->toBe(['surface'])
        ->and((string) file_get_contents($root . '/assets/Data/Tilesets/home.php'))->toBe($tileset);

    expect($session->commitChanges()['status'])->toBe('saved')
        ->and((require $root . '/assets/Data/Tilesets/home.php')['pieces'])->toHaveKey('surface');
});

it('undoes a refused change set\'s tileset edit with the rest, writing nothing', function () {
    [$root, $session] = currentRecordsSession();
    $before = sourceHashTree($root . '/assets');
    $session->beginChanges();
    applyTilesetRow($session, 'piece0Glyphs', ' ');
    $session->stampTiles('test-map', $session->readMap('test-map')['revision'], 'furniture', [[0, 0, 60]], 'Place tiles');
    // A marker painted with no event: the Engine refuses the map.
    $map = $session->readMap('test-map');
    $session->paint('test-map', $map['revision'], 'event', [[2, 1]], 'Z');

    expect($session->commitChanges()['status'])->toBe('refused')
        ->and(sourceHashTree($root . '/assets'))->toBe($before)
        ->and($session->listUnsavedChanges())->toBe([])
        ->and(array_column($session->listPieces('test-map')['pieces'], 'id'))->toBe(['chest']);
});

/** Creates the place category bard-school on its page and names it from test-map's landmark at E. */
function nameUnsavedPlaceCategory(EditorSession $session): void
{
    applyLandmarkRow($session, ['target' => 'event-landmark', 'marker' => 'E'], 'building', 'E');
    $index = $session->createDatabaseRecord(MapCategoryFields::CATEGORY)['index'];
    foreach (['id' => 'bard-school', 'name.default' => 'Bard School', 'base' => 'organization'] as $field => $value) {
        $row = array_find($session->readDatabaseRecord(MapCategoryFields::CATEGORY, $index)['rows'],
            static fn(array $row): bool => ($row['key']['field'] ?? null) === $field) ?? throw new RuntimeException('No category row ' . $field);
        $session->applyDatabaseRecord(MapCategoryFields::CATEGORY, $index, $row['key'], $value);
    }
    applyLandmarkRow($session, ['target' => 'map-landmarks', 'field' => 'category', 'index' => 0], 'bard-school');
}

it('validates a landmark against a place category added earlier in the same change set', function () {
    [$root, , $session] = createAtlasProject();
    $session->beginChanges();
    nameUnsavedPlaceCategory($session);

    // The saved file does not have the category yet, so a check reading it would refuse the landmark.
    $entries = readLandmarkEntries($session);
    expect(MapLandmarkFields::findProblem(getSessionMap($session), $entries, MapCategoryCatalog::load($root . '/assets')))->not->toBeNull()
        ->and(MapLandmarkFields::findProblem(getSessionMap($session), $entries, getSessionMap($session)->loadMapCategories()))->toBeNull();

    $outcome = $session->commitChanges();
    expect(array_filter(array_column($outcome['issues'], 'message'), static fn(string $message): bool => str_contains($message, 'Minimap landmarks')))->toBe([])
        ->and($outcome['status'])->toBe('saved')
        ->and(MapCategoryCatalog::load($root . '/assets')->getIds())->toContain('bard-school');
});

it('undoes a refused change set\'s place category with the landmark naming it, writing nothing', function () {
    [$root, , $session] = createAtlasProject();
    $before = sourceHashTree($root . '/assets');
    $session->beginChanges();
    nameUnsavedPlaceCategory($session);
    $session->paint('test-map', $session->readMap('test-map')['revision'], 'event', [[1, 1]], 'Z');

    expect($session->commitChanges()['status'])->toBe('refused')
        ->and(sourceHashTree($root . '/assets'))->toBe($before)
        ->and($session->listUnsavedChanges())->toBe([])
        ->and(getSessionMap($session)->loadMapCategories()->getIds())->not->toContain('bard-school');
});
