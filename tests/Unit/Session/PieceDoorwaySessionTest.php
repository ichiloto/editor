<?php

declare(strict_types=1);

use Ichiloto\Editor\Maps\PieceDoorwayCodec;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Field\Enumerations\DoorwayEntrance;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;

/** A synthetic 2x2 hut whose bottom row is a wall with an opening on its left. */
function createPieceDoorwayProject(array $extra = []): string
{
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: ['hut' => $extra + ['name' => 'Hut', 'layer' => 'fixtures', 'glyphs' => ['/\\', '∩|'],
        'tiles' => ['decor' => ['1 2', '9 10']]]]);
    $path = $root . '/assets/Data/Tilesets/home.php';
    file_put_contents($path, str_replace("<?php\n", "<?php\n// Keep the author's tileset notes.\n", (string) file_get_contents($path)));

    return $root;
}

it('reads and writes painted doors as one typed line, refusing cells it cannot read', function () {
    expect(PieceDoorwayCodec::decode('2,4; 4,4 front'))->toBe([['x' => 2, 'y' => 4], ['x' => 4, 'y' => 4, 'entrance' => 'front']])
        ->and(PieceDoorwayCodec::decode(' 0, 1 on ;'))->toBe([['x' => 0, 'y' => 1]])
        ->and(PieceDoorwayCodec::decode(''))->toBe([])
        ->and(PieceDoorwayCodec::encode([['x' => 2, 'y' => 4], ['x' => 4, 'y' => 4, 'entrance' => 'front']]))->toBe('2,4; 4,4 front')
        ->and(PieceDoorwayCodec::encode(null))->toBe('')
        ->and(PieceDoorwayCodec::encode([['x' => 2]]))->toContain("'x' => 2");
    expect(fn() => PieceDoorwayCodec::decode('2;4'))->toThrow(InvalidArgumentException::class, 'Painted doors are cells such as')
        ->and(fn() => PieceDoorwayCodec::decode('2,4 behind'))->toThrow(InvalidArgumentException::class, 'entrance must be one of')
        ->and(fn() => PieceDoorwayCodec::decode('2,4; 2,4'))->toThrow(InvalidArgumentException::class, 'distinct');
});

it('edits a piece\'s painted doors through its record row, keeping the source, and lists them with the piece', function () {
    $root = createPieceDoorwayProject();
    $path = $root . '/assets/Data/Tilesets/home.php';
    $session = EditorSession::open($root);
    $row = array_find($session->readDatabaseRecord('tilesets', 0)['rows'], static fn(array $row): bool =>
        ($row['key']['field'] ?? null) === 'piece0Doorway');
    expect($row)->not->toBeNull();

    $session->applyDatabaseRecord('tilesets', 0, $row['key'], '0,1');
    $session->saveDatabase('tilesets');

    expect(Tileset::load($root . '/assets', 'home')->pieces['hut']->doorway)->toBe([['x' => 0, 'y' => 1, 'entrance' => DoorwayEntrance::ON]])
        ->and(file_get_contents($path))->toContain('// Keep the author', "'doorway'")
        ->and(array_find(EditorSession::open($root)->listPieces('test-map')['pieces'], static fn(array $piece): bool => $piece['id'] === 'hut')['doorway'])
        ->toBe([['x' => 0, 'y' => 1]]);

    $session->applyDatabaseRecord('tilesets', 0, $row['key'], '');
    $session->saveDatabase('tilesets');
    expect(Tileset::load($root . '/assets', 'home')->pieces['hut']->doorway)->toBe([])
        ->and(file_get_contents($path))->not->toContain("'doorway'");
});

it('keeps a piece\'s painted doors when its glyph footprint and physical recipe are authored', function () {
    $root = createPieceDoorwayProject(['doorway' => [['x' => 0, 'y' => 1]]]);
    $session = EditorSession::open($root);
    $piece = array_find($session->readTilesetPreview(0)['authoring'], static fn(array $piece): bool => $piece['id'] === 'hut');
    expect($piece['issue'])->toBeNull()->and($piece['width'])->toBe(2);

    $session->setTilesetOccupancy(0, 'home', 'hut', null, [[1, 1], [0, 1]]);
    $session->saveDatabase('tilesets');
    expect(Tileset::load($root . '/assets', 'home')->pieces['hut']->doorway)->toHaveCount(1);
});
