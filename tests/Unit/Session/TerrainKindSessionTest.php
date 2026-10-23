<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Session\ChangeBatch;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Events\Enumerations\CollisionType;

/**
 * Terrain kinds painted through the session, as both editors paint them, on
 * the 4 x 2 graphics test map: terrain (`map:1`) holds `....` over `....`,
 * buildings (`map:4`) ` /  ` over ` xx `, and the `floor` tile layer 2816
 * everywhere. Synthetic kinds only: meadow and path are ground kinds on
 * `floor`, grove a feature on `canopy` standing on meadow, peaks a solid
 * feature awaiting its art.
 */

function terrainKindMap(EditorSession $session): ProjectMap
{
    return new ReflectionProperty(EditorSession::class, 'workspace')->getValue($session)->getMapByIndex(0);
}

/** @return list<string> A layer's rows. */
function terrainKindRows(EditorSession $session, string $layerId = 'map:1'): array
{
    $layer = array_find($session->readMap('test-map')['layers'], static fn(array $layer): bool => $layer['id'] === $layerId);

    return array_map(static fn(array $row): string => implode('', $row), $layer['rows']);
}

/** @return array<string, list<list<int>>> Tile rows by tile layer name. */
function terrainKindTiles(EditorSession $session): array
{
    return array_column(array_map(static fn(array $layer): array => [$layer['name'], $layer['rows']], $session->readTiles('test-map')['layers']), 1, 0);
}

function convertTerrainKindMap(EditorSession $session, array $mapping = ['.' => 'meadow']): array
{
    return $session->convertToKinds('test-map', $session->readMap('test-map')['revision'], $mapping);
}

function paintTerrainKind(EditorSession $session, string $kind, array $from, array $to): array
{
    return $session->paintKind('test-map', $session->readMap('test-map')['revision'], $kind, $from, $to);
}

describe('before conversion', function () {
    it('lists the tileset\'s kinds with their glyph, colour, passage and art, and says the map must be converted first', function () {
        $listed = EditorSession::open(terrainKindProject())->listKinds('test-map');

        expect($listed['painted'])->toBeFalse()
            ->and($listed['issue'])->toContain('not painted in terrain kinds yet')
            ->and($listed['issue'])->toContain('map:convert-kinds test-map')
            ->and(array_column($listed['kinds'], 'id'))->toBe(['meadow', 'path', 'grove', 'peaks'])
            ->and($listed['kinds'][2])->toMatchArray(['glyph' => '♣', 'color' => 'green', 'passage' => 'walk', 'encounters' => 'grove',
                'ground' => 'Meadow', 'atlas' => 'vegetation', 'awaitingArt' => false, 'tileLayers' => ['canopy', 'floor'], 'layerIssue' => null])
            ->and($listed['kinds'][3])->toMatchArray(['passage' => 'solid', 'encounters' => null, 'awaitingArt' => true]);
    });

    it('refuses to paint a kind on a map that is not painted in kinds', function () {
        $session = EditorSession::open(terrainKindProject());

        expect(fn() => paintTerrainKind($session, 'meadow', [0, 0], [0, 0]))->toThrow(SessionRefusal::class, 'Convert it first');
    });
});

describe('conversion', function () {
    it('previews each mapped glyph\'s cells and the collision it changes, changing nothing', function () {
        $root = terrainKindProject("...=\n....");
        $session = EditorSession::open($root);
        $hashes = sourceHashTree($root);

        $preview = $session->previewKindConversion('test-map', ['.' => 'meadow', '=' => 'path']);

        // The empty dictionary leaves every glyph solid; the kinds open the terrain cells nothing stands on.
        expect($preview['declares'])->toBeTrue()
            ->and($preview['mappings'])->toBe([
                ['glyph' => '.', 'kind' => 'meadow', 'name' => 'Meadow', 'layer' => 'terrain', 'cells' => 7, 'awaitingArt' => false],
                ['glyph' => '=', 'kind' => 'path', 'name' => 'Path', 'layer' => 'terrain', 'cells' => 1, 'awaitingArt' => false],
            ])
            ->and($preview['unmapped'])->toBe([])
            ->and($preview['collision'])->toMatchArray(['changed' => 5, 'blocked' => 0, 'opened' => 5])
            ->and($preview['collision']['cells'])->toBe([[0, 0], [2, 0], [3, 0], [0, 1], [3, 1]])
            ->and(terrainKindMap($session)->isDirty())->toBeFalse()
            ->and(sourceHashTree($root))->toBe($hashes);
    });

    it('lists what a map holds, and refuses to leave a glyph that already names a kind unmapped, so nothing is reread unseen', function () {
        $session = EditorSession::open(terrainKindProject("...=\n...."));

        $preview = $session->previewKindConversion('test-map', []);
        expect($preview['unmapped'])->toBe([['glyph' => '.', 'layer' => 'terrain', 'cells' => 7], ['glyph' => '=', 'layer' => 'terrain', 'cells' => 1]])
            // Both already name kinds, so both must be mapped before converting.
            ->and($preview['reread'])->toBe($preview['unmapped'])
            ->and(fn() => convertTerrainKindMap($session, ['.' => 'meadow']))
            ->toThrow(SessionRefusal::class, "'=' on terrain (1 cells), which name terrain kinds")
            ->and(fn() => convertTerrainKindMap($session, []))->toThrow(SessionRefusal::class, 'Map at least one old glyph');
    });

    it('opts the map in and paints every mapped cell with its kind as one undo step, clearing the old glyph\'s tiles', function () {
        $session = EditorSession::open(terrainKindProject("..x.\n...."));
        // An old glyph's own tile on a layer the terrain owns, which the kind does not draw.
        $session->paintTiles('test-map', $session->readMap('test-map')['revision'], 'canopy', [[2, 0]], 3008, 'Old tile');

        $result = convertTerrainKindMap($session, ['.' => 'meadow', 'x' => 'peaks']);

        $map = terrainKindMap($session);
        expect($result)->toMatchArray(['status' => 'applied', 'changed' => true, 'cells' => 8])
            ->and($map->getMapDataField(['terrainKinds']))->toBeTrue()
            ->and(terrainKindRows($session))->toBe(['..^.', '....'])
            ->and($map->getLayerColor('map:1', 2, 0))->toBe('#a0522d')
            // Peaks await their art, so they draw nothing, not even their meadow: the glyph shows graphically.
            ->and(terrainKindTiles($session)['canopy'][0])->toBe([0, 0, 0, 0])
            ->and(terrainKindTiles($session)['floor'][0])->toBe([2816, 2816, 0, 2816])
            ->and($session->listKinds('test-map')['painted'])->toBeTrue();

        $session->undo();
        expect($map->hasMapDataField(['terrainKinds']))->toBeFalse()
            ->and(terrainKindRows($session))->toBe(['..x.', '....'])
            ->and(terrainKindTiles($session)['canopy'][0][2])->toBe(3008)
            ->and(terrainKindTiles($session)['floor'][0][2])->toBe(2816);
    });

    it('lets the kinds take over the map\'s atlas classifications of the glyphs they convert or name, keeping the rest', function () {
        $root = terrainKindProject("..x.\n..##");
        $data = $root . '/assets/Maps/test-map/test-map.data.php';
        // The old map classifies its glyphs itself: '.' and 'x' convert to kinds, '^' names one, '#' stays as it is.
        file_put_contents($data, str_replace("'events' => [],", "'events' => [], 'atlas' => ['terrain' => ['terrain' => ['symbols' => "
            . "['.' => 'ground', 'x' => 'obstacle', '^' => 'obstacle', '#' => 'boundary']]]],", (string) file_get_contents($data)));
        $session = EditorSession::open($root);

        expect(array_column($session->previewKindConversion('test-map', ['.' => 'meadow', 'x' => 'peaks'])['atlasBindings'], 'glyph'))
            ->toBe(['.', 'x', '^']);
        convertTerrainKindMap($session, ['.' => 'meadow', 'x' => 'peaks']);

        $map = terrainKindMap($session);
        expect($map->getMapDataField(['atlas']))->toBe(['terrain' => ['terrain' => ['symbols' => ['#' => 'boundary']]]]);
        $session->undo();
        expect($map->getMapDataField(['atlas', 'terrain', 'terrain', 'symbols']))->toBe(['.' => 'ground', 'x' => 'obstacle', '^' => 'obstacle', '#' => 'boundary']);
    });

    it('converts through a change batch, as agents and scripts change maps, writing nothing when only checked', function () {
        $root = terrainKindProject();
        $hashes = sourceHashTree($root);

        $checked = ChangeBatch::apply($root, [['method' => 'kinds.convert', 'params' => ['map' => 'test-map', 'mapping' => ['.' => 'meadow']]]],
            checkOnly: true);

        // The fixture's own unrelated findings refuse the commit; the conversion itself applied.
        expect($checked['status'])->not->toBe('failed')
            ->and($checked['maps'])->toBe(['test-map'])
            ->and($checked['written'])->toBe([])
            ->and(sourceHashTree($root))->toBe($hashes);
    });
});

describe('painting', function () {
    it('paints a feature with its glyph, its autotile and its ground\'s, as previewed, in one undo step', function () {
        $session = EditorSession::open(terrainKindProject());
        convertTerrainKindMap($session);

        expect($session->previewKind('test-map', 'grove', [0, 0], [1, 0])['cells'])->toBe([[0, 0, '♣', 'green'], [1, 0, '♣', 'green']])
            ->and(paintTerrainKind($session, 'grove', [0, 0], [1, 0]))->toMatchArray(['changed' => true, 'cells' => 2])
            ->and(terrainKindRows($session))->toBe(['♣♣..', '....'])
            ->and(terrainKindTiles($session)['canopy'][0])->toBe([2912, 2912, 0, 0])
            ->and(terrainKindTiles($session)['floor'][0])->toBe([2816, 2816, 2816, 2816])
            ->and($session->readKindAt('test-map', 0, 0))->toBe(['kind' => 'grove'])
            // The buildings' slash stands over a meadow cell; the kind beneath is still found.
            ->and($session->readKindAt('test-map', 1, 0))->toBe(['kind' => 'grove']);

        // A kind painted over another takes the old one's own tiles with it.
        paintTerrainKind($session, 'path', [1, 0], [1, 0]);
        expect(terrainKindRows($session))->toBe(['♣=..', '....'])
            ->and(terrainKindTiles($session)['canopy'][0])->toBe([2912, 0, 0, 0])
            ->and(terrainKindTiles($session)['floor'][0])->toBe([2816, 2864, 2816, 2816]);

        $session->undo();
        $session->undo();
        expect(terrainKindRows($session))->toBe(['....', '....'])
            ->and(terrainKindTiles($session))->not->toHaveKey('canopy');
    });

    it('takes passage from the kind, and on declared occupancy writes it where the kind is the top layer', function () {
        $session = EditorSession::open(terrainKindProject());
        convertTerrainKindMap($session);
        $map = terrainKindMap($session);
        expect($map->getResolvedCollisionMap()[0])->toBe([7, 1, 7, 7]);

        $session->migratePhysicalOccupancy('test-map', $session->readMap('test-map')['revision']);
        paintTerrainKind($session, 'peaks', [0, 1], [3, 1]);

        // The buildings' x keeps its own cells; the peaks' collision lands beside them.
        expect($map->getMapDataField(['occupancy', 1]))->toBe([CollisionType::SOLID, CollisionType::SOLID, CollisionType::SOLID, CollisionType::SOLID])
            ->and($map->getMapDataField(['occupancy', 0, 0]))->toBe(CollisionType::ENCOUNTER);
        paintTerrainKind($session, 'path', [0, 1], [3, 1]);
        expect($map->getMapDataField(['occupancy', 1]))->toBe([CollisionType::NONE, CollisionType::SOLID, CollisionType::SOLID, CollisionType::NONE]);

        $session->undo();
        expect($map->getMapDataField(['occupancy', 1, 0]))->toBe(CollisionType::SOLID)
            ->and(terrainKindRows($session)[1])->toBe('^^^^');
    });

    it('reports cells whose glyph and tiles part company, and cells of kinds awaiting art, as warnings', function () {
        $root = terrainKindProject();
        $data = $root . '/assets/Maps/test-map/test-map.data.php';
        file_put_contents($data, str_replace("'tileset' => 'home',", "'tileset' => 'home', 'terrainKinds' => true,", (string) file_get_contents($data)));
        // Path's autotile under a meadow glyph, as an edit outside the editors leaves it.
        writeTileLayer($root . '/assets/Maps/test-map', '01.floor.tiles.php', "2816 2816 2816 2816\n2864 2816 2816 2816");
        $session = EditorSession::open($root);
        paintTerrainKind($session, 'peaks', [3, 0], [3, 0]);

        $issues = array_values(array_filter($session->validateProject(['test-map'])['issues'],
            static fn(array $issue): bool => str_starts_with((string) $issue['code'], 'terrain.')));

        expect(array_map(static fn(array $issue): array => [$issue['code'], $issue['severity']], $issues))->toBe([
            ['terrain.disagreement', 'warning'],
            ['terrain.awaiting-art', 'warning'],
        ])
            ->and($issues[0]['message'])->toContain('At (0, 1)')
            ->and($issues[1]['message'])->toContain('Peaks is awaiting its art');
    });
});

describe('authoring kinds', function () {
    /** @return array<string, array<string, mixed>> The tileset record's rows by field id. */
    function terrainKindRecordRows(EditorSession $session): array
    {
        $rows = [];
        foreach ($session->readDatabaseRecord('tilesets', 0)['rows'] as $row) {
            if (is_string($row['key']['field'] ?? null)) {
                $rows[$row['key']['field']] = $row;
            }
        }

        return $rows;
    }

    it('adds and edits a kind in Database > Tilesets, choosing its ground among the tileset\'s ground kinds, and saves it source-preserving', function () {
        $root = terrainKindProject();
        $path = $root . '/assets/Data/Tilesets/home.php';
        file_put_contents($path, str_replace("<?php\n", "<?php\n// Kept by the editor.\n", (string) file_get_contents($path)));
        $session = EditorSession::open($root);

        $rows = terrainKindRecordRows($session);
        // Ground choices are ground kinds, never the kind itself or a feature.
        expect($rows['kind0Ground']['options'])->toBe(['', 'path'])
            ->and($rows['kind2Ground']['options'])->toBe(['', 'meadow', 'path']);

        // The heading adds an entry at the head of the list.
        $session->addDatabaseItem('tilesets', 0, $rows['kindList']['key']);
        $set = static fn(string $field, string $value) => $session->applyDatabaseRecord('tilesets', 0, terrainKindRecordRows($session)[$field]['key'], $value);
        $set('kind0Id', 'thicket');
        $set('kind0Name', 'Thicket');
        $set('kind0Glyph', '<fg=green>%</>');
        $set('kind0Ground', 'meadow');
        $set('kind0Encounters', 'grove');
        $set('kind0Atlas', 'vegetation');
        $session->saveDatabase('tilesets');

        $thicket = Ichiloto\Engine\Rendering\Tilesets\Tileset::load($root . '/assets', 'home')->kinds['thicket'];
        expect((string) file_get_contents($path))->toContain('// Kept by the editor.')
            ->and($thicket->ground)->toBe('meadow')
            ->and($thicket->awaitingArt)->toBeTrue()
            ->and($thicket->encounters)->toBe('grove')
            // Awaiting its art, it draws nothing until its autotile is bound.
            ->and($thicket->piece->tiles)->toBe([]);
    });

    it('reports a tileset\'s kinds awaiting art, and refuses a kind the Engine would refuse', function () {
        $session = EditorSession::open(terrainKindProject());
        $warning = array_values(array_filter($session->validateProject()['issues'],
            static fn(array $issue): bool => str_contains($issue['message'], 'awaiting art:')));
        expect($warning)->toHaveCount(1)
            ->and($warning[0]['message'])->toContain('1 terrain kind is awaiting art: Peaks');

        $session->applyDatabaseRecord('tilesets', 0, terrainKindRecordRows($session)['kind0Glyph']['key'], '.');
        expect(implode(' ', array_column($session->validateProject()['issues'], 'message')))->toContain('glyph needs its terminal colour');
    });
});
