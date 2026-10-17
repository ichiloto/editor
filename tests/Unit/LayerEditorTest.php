<?php

declare(strict_types=1);

use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Maps\LayerEditor;
use Ichiloto\Editor\ProjectMap;

/**
 * Layer editing as every interface does it: glyph layers created, renamed,
 * reordered, made decoration and removed, and tile layers created, renamed,
 * reordered, removed and given settings, each one undo step that the map's
 * save writes as one file set, with the tile layer settings kept naming the
 * layers the map has. Every map here is a synthetic fixture.
 */

/** Writes the shared collision dictionary: `.` passes, `/` and `x` block. */
function writeLayerEditorCollisions(string $root, string $extra = ''): void
{
    file_put_contents($root . '/assets/Maps/collisions.php', '<?php use Ichiloto\Engine\Events\Enumerations\CollisionType;'
        . ' return ["." => CollisionType::NONE, "/" => CollisionType::SOLID, "x" => CollisionType::SOLID' . $extra . '];');
}

/** The graphics fixture map with tile layer settings in its data file. */
function layerEditorGraphicsMap(string $settings): array
{
    $root = mapGraphicsProject();
    $data = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($data, str_replace("'tileset' => 'home',", "'tileset' => 'home', 'tileLayers' => {$settings},", (string) file_get_contents($data)));

    return [loadLayeredMap($root), $root];
}

/** @return list<string> The map folder's layer and tile layer files. */
function layerEditorFiles(ProjectMap $map): array
{
    $files = [...(glob($map->directory . '/layers/*.php') ?: []), ...(glob($map->directory . '/graphics/*.php') ?: [])];

    return array_map(static fn(string $path): string => basename(dirname($path)) . '/' . basename($path), $files);
}

it('creates a decoration layer at the next order as one undo step that a save writes', function () {
    $map = loadLayeredMap(layeredMapProject());

    $edit = LayerEditor::createLayer($map, 'shadows', true);
    $created = array_find($map->getLayers(), static fn(array $layer): bool => $layer['id'] === $edit['layer']);

    expect($created)->toMatchArray(['name' => 'shadows', 'order' => 8, 'decoration' => true])
        ->and($edit['question'])->toBeNull();
    $edit['command']->undo();
    expect(array_column($map->getLayers(), 'name'))->not->toContain('shadows');
    $edit['command']->execute();
    $map->save();
    expect(layerEditorFiles($map))->toContain('layers/08.shadows.deco.php');
});

it('reorders a layer by exchanging orders, saving renamed files and removing the old ones together', function () {
    $root = layeredMapProject();
    $map = loadLayeredMap($root);
    $bytes = [file_get_contents($map->directory . '/layers/04.buildings.map.php'), file_get_contents($map->directory . '/layers/07.detail.deco.php')];

    expect(LayerEditor::findAdjacentLayerOrder($map, 'map:4', LayerEditor::ABOVE))->toBe(7);
    $edit = LayerEditor::moveLayer($map, 'map:4', 7);
    $map->save();

    expect(layerEditorFiles($map))->toBe(['layers/01.terrain.map.php', 'layers/04.detail.deco.php', 'layers/07.buildings.map.php'])
        ->and([file_get_contents($map->directory . '/layers/07.buildings.map.php'), file_get_contents($map->directory . '/layers/04.detail.deco.php')])->toBe($bytes)
        ->and(array_column(loadLayeredMap($root)->getLayers(), 'name'))->toBe(['terrain', 'detail', 'buildings', 'Events']);

    $edit['command']->undo();
    $map->save();
    expect(layerEditorFiles($map))->toBe(['layers/01.terrain.map.php', 'layers/04.buildings.map.php', 'layers/07.detail.deco.php']);
});

it('asks before a reorder that changes collisions and changes nothing until confirmed', function () {
    $root = layeredMapProject();
    writeLayerEditorCollisions($root);
    $map = loadLayeredMap($root);
    $before = $map->captureLayerSnapshot();
    $revision = $map->stateVersion();
    $order = LayerEditor::findAdjacentLayerOrder($map, 'map:4', LayerEditor::BELOW);

    $asked = LayerEditor::moveLayer($map, 'map:4', $order);

    expect($asked['command'])->toBeNull()
        ->and($asked['question'])->toContain('Moving buildings to order 01 changes collision at 3 cell(s)')
        ->and($map->captureLayerSnapshot())->toBe($before)
        ->and($map->stateVersion())->toBe($revision)
        ->and(fn() => $map->moveLayer('map:4', $order))->toThrow(MapSourceRefusal::class, 'Explicit confirmation is required');

    $moved = LayerEditor::moveLayer($map, 'map:4', $order, true);
    expect(array_column($map->getLayers(), 'name'))->toBe(['buildings', 'terrain', 'detail', 'Events']);
    $moved['command']->undo();
    expect($map->captureLayerSnapshot())->toBe($before);
});

it('refuses moving past the ends, the event layer and orders outside 00-99', function () {
    $map = loadLayeredMap(layeredMapProject());

    expect(fn() => LayerEditor::findAdjacentLayerOrder($map, 'map:7', LayerEditor::ABOVE))->toThrow(MapSourceRefusal::class, 'detail is already the top layer')
        ->and(fn() => LayerEditor::findAdjacentLayerOrder($map, 'map:1', LayerEditor::BELOW))->toThrow(MapSourceRefusal::class, 'terrain is already the bottom layer')
        ->and(fn() => LayerEditor::findAdjacentLayerOrder($map, 'map:1', 'sideways'))->toThrow(MapSourceRefusal::class, "'above' or 'below'")
        ->and(fn() => LayerEditor::moveLayer($map, 'event', 3))->toThrow(MapSourceRefusal::class, 'Only authored layers')
        ->and(fn() => LayerEditor::moveLayer($map, 'map:4', 100))->toThrow(MapSourceRefusal::class, '00-99')
        ->and($map->isDirty())->toBeFalse();
});

it('keeps layer ids unique when a created layer takes an order a moved layer was loaded with', function () {
    $map = loadLayeredMap(layeredMapProject());
    LayerEditor::moveLayer($map, 'map:4', 9);

    $id = $map->createLayer('fixtures', false, 4);

    expect($id)->not->toBe('map:4')
        ->and(array_column($map->getLayers(), 'name'))->toBe(['terrain', 'fixtures', 'detail', 'buildings', 'Events']);
});

it('makes a layer decoration after asking, keeps the last gameplay layer, and drops tile settings that moved with it', function () {
    [$map, $root] = layerEditorGraphicsMap("['floor' => ['movesWith' => 'buildings'], 'decor' => ['offset' => [0, -0.5], 'movesWith' => 'buildings']]");
    writeLayerEditorCollisions($root);
    $data = file_get_contents($map->dataPath);

    expect(LayerEditor::setLayerDecoration($map, 'map:4', true)['question'])->toContain('Making buildings decoration changes collision at 3 cell(s)');
    $edit = LayerEditor::setLayerDecoration($map, 'map:4', true, true);

    expect(array_find($map->getLayers(), static fn(array $layer): bool => $layer['id'] === 'map:4')['decoration'])->toBeTrue()
        ->and($map->getMapDataField(['tileLayers']))->toBe(['decor' => ['offset' => [0, -0.5]]])
        ->and(fn() => LayerEditor::setLayerDecoration($map, 'map:1', true, true))->toThrow(MapSourceRefusal::class, 'The last gameplay layer cannot become decoration.');
    $map->save();
    expect(layerEditorFiles($map))->toContain('layers/04.buildings.deco.php')->not->toContain('layers/04.buildings.map.php')
        ->and($map->loadGraphics()->offsets)->toBe(['decor' => [0.0, -0.5]]);

    $edit['command']->undo();
    $map->save();
    expect(file_get_contents($map->dataPath))->toBe($data)
        ->and(layerEditorFiles($map))->toContain('layers/04.buildings.map.php');
});

it('refuses making a layer decoration while the collision dictionary names it', function () {
    $root = layeredMapProject();
    writeLayerEditorCollisions($root, ', "buildings" => ["x" => CollisionType::NONE]');
    $map = loadLayeredMap($root);

    expect(fn() => LayerEditor::setLayerDecoration($map, 'map:4', true, false))
        ->toThrow(MapSourceRefusal::class, 'must not be named in the collision dictionary. Nothing was changed.')
        ->and($map->isDirty())->toBeFalse();
});

it('renames a gameplay layer and the tile layers that move with it, asking first when collisions change', function () {
    [$map, $root] = layerEditorGraphicsMap("['floor' => ['movesWith' => 'buildings']]");
    writeLayerEditorCollisions($root, ', "buildings" => ["x" => CollisionType::NONE]');

    expect(LayerEditor::renameLayer($map, 'map:4', 'houses')['question'])
        ->toBe('Rename buildings to houses changes collision at 2 cell(s). Shared collisions.php stays unchanged.');
    $edit = LayerEditor::renameLayer($map, 'map:4', 'houses', true);
    $map->save();

    expect($edit['layer'])->toBe('map:4')
        ->and(loadLayeredMap($root)->getMapDataField(['tileLayers']))->toBe(['floor' => ['movesWith' => 'houses']])
        ->and($map->getTileLayersMovingWith('map:4'))->toBe(['floor']);
});

it('removes a gameplay layer after asking, leaving the base layer and the tiles that moved with it moving with none', function () {
    [$map, $root] = layerEditorGraphicsMap("['floor' => ['movesWith' => 'buildings']]");
    writeLayerEditorCollisions($root);

    expect(LayerEditor::removeLayer($map, 'map:4')['question'])->toContain('Removing buildings changes collision at 3 cell(s)');
    $edit = LayerEditor::removeLayer($map, 'map:4', true);

    expect($edit['layer'])->toBe('map:1')
        ->and($map->hasMapDataField(['tileLayers']))->toBeFalse()
        ->and(fn() => LayerEditor::removeLayer($map, 'map:1', true))->toThrow(MapSourceRefusal::class, 'The last gameplay layer cannot be removed.');
    $edit['command']->undo();
    expect($map->getMapDataField(['tileLayers']))->toBe(['floor' => ['movesWith' => 'buildings']]);
});

it('creates an empty tile layer at a free order, refusing a taken or invalid name', function () {
    $root = mapGraphicsProject();
    $map = loadLayeredMap($root);

    $edit = LayerEditor::createTileLayer($map, 'rugs');

    expect(array_column($map->describeTileLayers()['layers'], 'name', 'order'))->toBe([1 => 'floor', 2 => 'decor', 3 => 'rugs'])
        ->and(fn() => LayerEditor::createTileLayer($map, 'rugs'))->toThrow(MapSourceRefusal::class, 'Tile layer rugs already exists.')
        ->and(fn() => LayerEditor::createTileLayer($map, '9lives'))->toThrow(MapSourceRefusal::class, "'9lives' is not a tile layer name.");
    $map->save();
    expect(readTileRows($map->directory . '/graphics/03.rugs.tiles.php'))->toBe([[0, 0, 0, 0], [0, 0, 0, 0]]);
    $edit['command']->undo();
    $map->save();
    expect(layerEditorFiles($map))->not->toContain('graphics/03.rugs.tiles.php');
});

it('renames a tile layer with its settings, saving the same tiles under the new name', function () {
    [$map, $root] = layerEditorGraphicsMap("['decor' => ['offset' => [0, -0.5]], 'floor' => ['movesWith' => 'buildings']]");
    $bytes = file_get_contents($map->directory . '/graphics/02.decor.tiles.php');

    LayerEditor::renameTileLayer($map, 'decor', 'props');
    $map->save();
    $reloaded = loadLayeredMap($root);

    expect(layerEditorFiles($map))->toContain('graphics/02.props.tiles.php')->not->toContain('graphics/02.decor.tiles.php')
        ->and(file_get_contents($map->directory . '/graphics/02.props.tiles.php'))->toBe($bytes)
        ->and($reloaded->getMapDataField(['tileLayers']))->toBe(['floor' => ['movesWith' => 'buildings'], 'props' => ['offset' => [0, -0.5]]])
        ->and($reloaded->loadGraphics()->offsets)->toBe(['props' => [0.0, -0.5]])
        ->and(fn() => LayerEditor::renameTileLayer($map, 'props', 'floor'))->toThrow(MapSourceRefusal::class, 'Tile layer floor already exists.')
        ->and(fn() => LayerEditor::renameTileLayer($map, 'nothing', 'else'))->toThrow(MapSourceRefusal::class, 'There is no tile layer nothing.');
});

it('reorders and removes tile layers, keeping settings naming only the layers the map has', function () {
    [$map, $root] = layerEditorGraphicsMap("['decor' => ['offset' => [0.5, 0]]]");
    $data = file_get_contents($map->dataPath);

    LayerEditor::moveTileLayer($map, 'decor', LayerEditor::findAdjacentTileLayerOrder($map, 'decor', LayerEditor::BELOW));
    expect(array_column($map->describeTileLayers()['layers'], 'name'))->toBe(['decor', 'floor']);
    $removed = LayerEditor::removeTileLayer($map, 'decor');
    $map->save();

    expect(layerEditorFiles($map))->toContain('graphics/02.floor.tiles.php')->not->toContain('graphics/01.decor.tiles.php', 'graphics/02.decor.tiles.php')
        ->and(loadLayeredMap($root)->hasMapDataField(['tileLayers']))->toBeFalse()
        ->and(fn() => LayerEditor::findAdjacentTileLayerOrder($map, 'floor', LayerEditor::ABOVE))->toThrow(MapSourceRefusal::class, 'floor is already the top layer');
    $removed['command']->undo();
    $map->save();
    expect(file_get_contents($map->dataPath))->toBe($data)
        ->and(layerEditorFiles($map))->toContain('graphics/01.decor.tiles.php', 'graphics/02.floor.tiles.php');
});

it('sets tile layer settings exactly as the Engine reads them, removing what returns to none', function () {
    $root = mapGraphicsProject();
    $map = loadLayeredMap($root);
    $data = $map->getEditableData();

    $edit = LayerEditor::setTileLayerSettings($map, 'decor', [0.5, -0.5], 'buildings');
    $map->save();
    $layers = loadLayeredMap($root)->describeTileLayers();

    expect($edit['command'])->not->toBeNull()
        ->and(array_find($layers['layers'], static fn(array $layer): bool => $layer['name'] === 'decor'))
            ->toMatchArray(['order' => 2, 'file' => 'graphics/02.decor.tiles.php', 'offset' => [0.5, -0.5], 'movesWith' => 'buildings', 'owner' => 'buildings'])
        ->and($layers['issue'])->toBeNull()
        ->and(LayerEditor::setTileLayerSettings($map, 'decor', [0.5, -0.5], 'buildings')['command'])->toBeNull()
        ->and(fn() => LayerEditor::setTileLayerSettings($map, 'decor', [0.25, 0], null))->toThrow(MapSourceRefusal::class, 'offsets by -0.5, 0 or 0.5 field cells')
        ->and(fn() => LayerEditor::setTileLayerSettings($map, 'decor', [0, 0], 'detail'))->toThrow(MapSourceRefusal::class, 'must name one of its gameplay layers')
        ->and(fn() => LayerEditor::setTileLayerSettings($map, 'missing', [0, 0], null))->toThrow(MapSourceRefusal::class, 'There is no tile layer missing.');

    LayerEditor::setTileLayerSettings($map, 'decor', [0, 0], null);
    $map->save();
    expect(loadLayeredMap($root)->getEditableData())->toBe($data);
});

it('reports tile layer settings the Engine cannot read instead of guessing them', function () {
    [$map] = layerEditorGraphicsMap("['gone' => ['offset' => [0, 0.5]]]");

    $layers = $map->describeTileLayers();

    expect($layers['issue'])->toContain("names 'gone', which is not one of its tile layers")
        ->and(array_column($layers['layers'], 'offset'))->toBe([null, null]);
});
