<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;

/**
 * A piece's kept tile layers: the layers its stamp leaves in place, as a
 * glyph-less base piece keeps the ground and water drawn over it. The
 * graphical editor authors them on the piece; the terminal editor has no such
 * field and keeps them as written. Synthetic pieces only.
 */

function pieceKeptLayersProject(): string
{
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: ['base' => ['name' => 'Base', 'layer' => 'terrain', 'glyphs' => [' '], 'tiles' => ['substrate' => ['5']]]]);
    $path = $root . '/assets/Data/Tilesets/home.php';
    file_put_contents($path, str_replace("<?php\n", "<?php\n// Keep the author's tileset notes.\n", (string) file_get_contents($path)));

    return $root;
}

/** @return array<string, mixed>|null The piece's kept-layers row. */
function findPieceKeptLayersRow(EditorSession $session): ?array
{
    return array_find($session->readDatabaseRecord('tilesets', 0)['rows'], static fn(array $row): bool =>
        ($row['key']['field'] ?? null) === 'piece0Keeps');
}

it('authors the layers a piece keeps in the graphical editor, and the terminal editor keeps them when it saves the tileset', function () {
    $root = pieceKeptLayersProject();
    $path = $root . '/assets/Data/Tilesets/home.php';
    $session = EditorSession::open($root);
    $row = findPieceKeptLayersRow($session);
    expect($row)->not->toBeNull()->and($row['value'])->toBe('');

    $session->applyDatabaseRecord('tilesets', 0, $row['key'], 'ground, water');
    $session->saveDatabase('tilesets');
    expect(Tileset::load($root . '/assets', 'home')->pieces['base']->keeps)->toBe(['ground', 'water'])
        ->and(file_get_contents($path))->toContain('// Keep the author')
        ->and(findPieceKeptLayersRow(EditorSession::open($root))['value'])->toBe('ground, water');

    // The terminal editor has no kept-layers field and keeps it byte for byte.
    $source = (string) file_get_contents($path);
    $terminal = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::all()['tilesets']);
    $terminal->setField(0, 'name', 'Home renamed');
    $terminal->save();
    expect(Tileset::load($root . '/assets', 'home')->pieces['base']->keeps)->toBe(['ground', 'water'])
        ->and(file_get_contents($path))->toBe(str_replace("'name' => 'Home'", "'name' => 'Home renamed'", $source));
});

it('removes the kept layers when the author clears them', function () {
    $root = pieceKeptLayersProject();
    $session = EditorSession::open($root);
    $session->applyDatabaseRecord('tilesets', 0, findPieceKeptLayersRow($session)['key'], 'ground');
    $session->applyDatabaseRecord('tilesets', 0, findPieceKeptLayersRow($session)['key'], '');
    $session->saveDatabase('tilesets');

    expect(Tileset::load($root . '/assets', 'home')->pieces['base']->keeps)->toBe([])
        ->and(file_get_contents($root . '/assets/Data/Tilesets/home.php'))->not->toContain("'keeps'");
});
