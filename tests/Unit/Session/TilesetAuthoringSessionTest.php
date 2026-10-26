<?php

declare(strict_types=1);

use Ichiloto\Editor\Maps\ConnectedPiecePreview;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Rendering\Tilesets\AutotileShape;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;

function connectedAuthoringPiece(): array
{
    return ['name' => 'Fence', 'layer' => 'buildings', 'connects' => TilesetPiece::LINES,
        'glyphs' => ['horizontal' => '<fg=red>-</fg=red>', 'vertical' => '|', 'corner' => '+'],
        'tiles' => ['walls' => '2816', 'trim' => ['horizontal' => '5', 'vertical' => '6', 'corner' => '7']]];
}

it('previews every cardinal neighbourhood using shared piece shaping and Engine autotile resolution', function () {
    $piece = TilesetPiece::fromArray('fence', connectedAuthoringPiece(), 'Test');
    $samples = ConnectedPiecePreview::buildSamples($piece);
    expect(array_column($samples, 'mask'))->toBe(range(0, 15));
    foreach ($samples as $sample) {
        $mask = $sample['mask'];
        $shape = $piece->getLineShape((bool) ($mask & 1), (bool) ($mask & 2), (bool) ($mask & 4), (bool) ($mask & 8));
        $drawn = $sample['piece'];
        expect($sample['shape'])->toBe($shape)
            ->and($drawn->getSourceGrid()[2][2])->toBe($piece->getSourceShapeGrid()[$shape])
            ->and($drawn->tiles['trim'][2][2])->toBe($piece->shapeTiles['trim'][$shape])
            ->and($drawn->glyphs[0])->toBe(array_fill(0, 5, ' '))
            ->and($drawn->glyphs[4])->toBe(array_fill(0, 5, ' '));
        $raw = array_map(static fn(array $row): array => array_map(static fn(string $glyph): int => $glyph === ' ' ? 0 : 2816, $row), $drawn->glyphs);
        expect(array_map(static fn(array $row): array => array_map(intval(...), $row), $drawn->tiles['walls']))
            ->toBe(AutotileShape::resolveLayer($raw));
    }
    expect($samples[0]['piece']->tiles['walls'][2][2])->not->toBe($samples[15]['piece']->tiles['walls'][2][2]);
});

it('exports canonical shapes and live record keys for sheet authoring including incomplete tile rows', function () {
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: ['fence' => connectedAuthoringPiece(),
        'rug' => ['name' => 'Rug', 'layer' => 'fixtures', 'glyphs' => ['  ', '  '], 'tiles' => ['rugs' => ['0']]]]);
    $session = EditorSession::open($root);
    $preview = $session->readTilesetPreview(0);
    expect($preview['issue'])->toContain('must match its glyphs')
        ->and($preview['connectionShapes'])->toBe(TilesetPiece::LINE_SHAPES)
        ->and($preview['authoring'][1])->toMatchArray(['id' => 'rug', 'width' => 2, 'height' => 2, 'issue' => null]);
    $layer = $preview['authoring'][1]['layers'][0];
    $session->applyDatabaseRecord('tilesets', 0, $layer['key'], "0 5\n8 9");
    $repaired = $session->readTilesetPreview(0);
    expect($repaired['issue'])->toBeNull()
        ->and($repaired['pieces'][0]['connections'])->toHaveCount(16)
        ->and($repaired['pieces'][0]['connections'][0]['operations'])->not->toBeEmpty()
        ->and($repaired['pieces'][1]['connections'])->toBe([]);
});

it('assigns sheet identities through generic record edits with undo redo and source preserving save reload', function () {
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: ['fence' => connectedAuthoringPiece(),
        'rug' => ['name' => 'Rug', 'layer' => 'fixtures', 'glyphs' => ['  ', '  '],
            'tiles' => ['rugs' => ['1 2', '3 4'], 'details' => ['0 0', '0 5']]]]);
    $path = $root . '/assets/Data/Tilesets/home.php';
    $original = str_replace("<?php\n", "<?php\n// Preserve authored notes.\n", (string) file_get_contents($path));
    file_put_contents($path, $original);
    $mapBefore = sourceHashTree($root . '/assets/Maps');
    $session = EditorSession::open($root);
    $authors = $session->readTilesetPreview(0)['authoring'];
    $session->applyDatabaseRecord('tilesets', 0, $authors[1]['layers'][0]['key'], "0 17\n2816 2864");
    $session->applyDatabaseRecord('tilesets', 0, $authors[0]['layers'][1]['key'], 'horizontal: 5, vertical: 42, corner: 7');
    $session->undo();
    expect((array) $session->readTilesetPreview(0)['authoring'][0]['layers'][1]['shapes'])
        ->toBe(['horizontal' => '5', 'vertical' => '6', 'corner' => '7']);
    $session->undo();
    $session->saveDatabase('tilesets');
    expect(file_get_contents($path))->toBe($original);
    $session->redo();
    $session->redo();
    $session->saveDatabase('tilesets');
    $saved = (string) file_get_contents($path);
    $tileset = Tileset::load($root . '/assets', 'home');
    expect($saved)->toContain('// Preserve authored notes.', '<fg=red>-</fg=red>')
        ->and($tileset->pieces['rug']->tiles)->toBe(['rugs' => [['0', '17'], ['2816', '2864']], 'details' => [['0', '0'], ['0', '5']]])
        ->and($tileset->pieces['rug']->glyphs)->toBe([[' ', ' '], [' ', ' ']])
        ->and($tileset->pieces['fence']->shapeTiles['trim'])->toBe(['horizontal' => '5', 'vertical' => '42', 'corner' => '7'])
        ->and($tileset->pieces['fence']->shapeTiles['walls'])->toBe(array_fill_keys(TilesetPiece::LINE_SHAPES, '2816'))
        ->and(sourceHashTree($root . '/assets/Maps'))->toBe($mapBefore);
    $reloaded = EditorSession::open($root)->readTilesetPreview(0);
    expect($reloaded['issue'])->toBeNull()
        ->and($reloaded['authoring'][0]['id'])->toBe('fence')
        ->and($reloaded['pieces'][0]['connections'][5]['shape'])->toBe('vertical');
});

it('creates a piece and graphical layer through the existing add paths without deriving gameplay from artwork', function () {
    $root = mapGraphicsProject();
    writeTestTileset($root);
    $session = EditorSession::open($root);
    $session->addDatabaseItem('tilesets', 0, ['frame' => []]);
    $piece = $session->readTilesetPreview(0)['authoring'][0];
    $session->applyDatabaseRecord('tilesets', 0, ['frame' => [], 'field' => 'piece0Id'], 'mat');
    $session->applyDatabaseRecord('tilesets', 0, ['frame' => [], 'field' => 'piece0Glyphs'], "  \n  ");
    $session->addDatabaseItem('tilesets', 0, $piece['key'], true);
    $layer = $session->readTilesetPreview(0)['authoring'][0]['layers'][0];
    $session->applyDatabaseRecord('tilesets', 0, $layer['key'], "1 2\n9 10");
    $session->saveDatabase('tilesets');
    $piece = Tileset::load($root . '/assets', 'home')->pieces['mat'];
    expect($piece->glyphs)->toBe([[' ', ' '], [' ', ' ']])
        ->and($piece->tiles)->toBe(['tiles' => [['1', '2'], ['9', '10']]])
        ->and($piece->layer)->toBe('fixtures');
});

it('refuses unsupported tileset source edits without rewriting authored code', function () {
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: ['fence' => connectedAuthoringPiece()]);
    $path = $root . '/assets/Data/Tilesets/home.php';
    $source = (string) file_get_contents($path);
    $source = str_replace("'walls' => '2816'", "'walls' => (string) (2800 + 16)", $source);
    file_put_contents($path, $source);
    $session = EditorSession::open($root);
    $key = $session->readTilesetPreview(0)['authoring'][0]['layers'][0]['key'];
    expect($key)->toBeArray()->and($session->readTilesetPreview(0)['issue'])->toBeNull();
    expect(fn() => $session->applyDatabaseRecord('tilesets', 0, $key, '2864'))->toThrow(SessionRefusal::class)
        ->and(file_get_contents($path))->toBe($source)
        ->and($session->listDatabaseRecords('tilesets')['dirty'])->toBeFalse();
});

it('reports unreadable connected artwork while preserving every glyph topology and authoring target', function () {
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: ['fence' => connectedAuthoringPiece()]);
    unlink($root . '/assets/Graphics/Tilesets/Home_A2.png');
    $preview = EditorSession::open($root)->readTilesetPreview(0);
    expect($preview['unreadable'])->not->toBeEmpty()
        ->and($preview['pieces'][0]['connections'])->toHaveCount(16)
        ->and($preview['authoring'][0]['layers'][0]['key'])->toBeArray();
    foreach ($preview['pieces'][0]['connections'] as $connection) {
        expect($connection['issue'])->not->toBeNull()
            // The usable B-sheet trim remains visible; a missing A2 sheet must not hide it.
            ->and($connection['operations'])->not->toBeEmpty()
            ->and($connection['picture'][2][2]['symbol'])->not->toBe(' ');
    }
});
