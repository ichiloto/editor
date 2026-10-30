<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayerSource;
use Ichiloto\Engine\IO\Console\TerminalText;

/*
 * The formatter delimiters < and > and the backslash are ordinary glyphs an
 * author paints, coloured or plain, beside coloured neighbours. Each edit
 * goes through the session as an interface sends it, is saved, read back by
 * the Engine and the Editor, and undone. Every project here is synthetic.
 */

/** A synthetic project whose map has coloured runs, a literal less-than and a backslash of its own. */
function createLiteralGlyphProject(): string
{
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Maps/test-map/test-map.map.php', MapGridSource::buildSource(implode("\n", [
        '############',
        '#  <fg=blue>~~~</>     #',
        '#  <fg=green>..</>  < \\ #',
        '#   \\ <fg=red>x</>    #',
        '############',
    ]), 'ICHILOTO_MAP'));

    return $root;
}

/** The glyphs of one row of a saved map, as the Engine composes the map from its files. */
function readEngineMapRow(string $root, int $y): array
{
    $directory = $root . '/assets/Maps/test-map';

    return array_map(TerminalText::stripAnsi(...), MapLayerSource::loadFromDirectory($directory)->getComposedGrid()[$y]);
}

it('paints, saves, reloads and undoes literal delimiters and backslashes beside styled neighbours on a map', function (string $glyph) {
    $root = createLiteralGlyphProject();
    $path = $root . '/assets/Maps/test-map/test-map.map.php';
    $authored = (string) file_get_contents($path);
    $authoredRows = explode("\n", $authored);
    $session = EditorSession::open($root);
    $map = $session->readMap('test-map');
    $layer = $map['baseLayer'];
    $before = readEngineMapRow($root, 1);

    // A coloured glyph beside the row's own coloured run, a plain one after it, and a coloured one on another row.
    $session->paint('test-map', $map['revision'], $layer, [[2, 1]], $glyph, 'red');
    $session->paint('test-map', $session->readMap('test-map')['revision'], $layer, [[6, 1]], $glyph, '');
    $session->paint('test-map', $session->readMap('test-map')['revision'], $layer, [[1, 2]], $glyph, '#5f87af');
    $session->saveMap('test-map');

    $expected = $before;
    $expected[2] = $glyph;
    $expected[6] = $glyph;
    $saved = (string) file_get_contents($path);
    $savedRows = explode("\n", $saved);
    expect(readEngineMapRow($root, 1))->toBe($expected)
        ->and(readEngineMapRow($root, 2)[1])->toBe($glyph)
        ->and(readEngineMapRow($root, 2)[3])->toBe('.')
        ->and(readEngineMapRow($root, 2)[7])->toBe('<')
        ->and(readEngineMapRow($root, 2)[9])->toBe('\\')
        // Rows no paint touched keep their authored bytes.
        ->and($savedRows[3])->toBe($authoredRows[3])
        ->and($savedRows[6])->toBe($authoredRows[6])
        ->and($savedRows[7])->toBe($authoredRows[7]);

    $reloaded = ProjectWorkspace::fromProject($root)->maps[0];
    expect($reloaded->getLayerSymbol($layer, 2, 1))->toBe($glyph)
        ->and($reloaded->getLayerColor($layer, 2, 1))->toBe('red')
        ->and($reloaded->getLayerSymbol($layer, 3, 1))->toBe('~')
        ->and($reloaded->getLayerColor($layer, 3, 1))->toBe('blue')
        ->and($reloaded->getLayerSymbol($layer, 6, 1))->toBe($glyph)
        ->and($reloaded->getLayerColor($layer, 6, 1))->toBeNull()
        ->and($reloaded->getLayerSymbol($layer, 1, 2))->toBe($glyph)
        ->and($reloaded->getLayerSymbol($layer, 3, 2))->toBe('.')
        ->and($reloaded->getLayerColor($layer, 3, 2))->toBe('green');

    // A reopened session edits beside the saved literals without disturbing them.
    $reopened = EditorSession::open($root);
    $reopened->paint('test-map', $reopened->readMap('test-map')['revision'], $layer, [[7, 1]], 'x', 'green');
    $reopened->saveMap('test-map');
    $expected[7] = 'x';
    expect(readEngineMapRow($root, 1))->toBe($expected);
    $reopened->undo();
    $reopened->saveMap('test-map');
    expect((string) file_get_contents($path))->toBe($saved);
})->with(['less-than' => '<', 'greater-than' => '>', 'backslash' => '\\']);

it('undoes saved literal paints back to the authored map bytes', function () {
    $root = createLiteralGlyphProject();
    $path = $root . '/assets/Maps/test-map/test-map.map.php';
    $authored = (string) file_get_contents($path);
    $session = EditorSession::open($root);
    $layer = $session->readMap('test-map')['baseLayer'];
    foreach ([[2, 1, '<', 'red'], [3, 1, '\\', 'red'], [4, 1, '>', '']] as [$x, $y, $glyph, $color]) {
        $session->paint('test-map', $session->readMap('test-map')['revision'], $layer, [[$x, $y]], $glyph, $color);
    }
    $session->saveMap('test-map');
    expect(readEngineMapRow($root, 1)[2])->toBe('<')
        ->and(readEngineMapRow($root, 1)[3])->toBe('\\')
        ->and(readEngineMapRow($root, 1)[4])->toBe('>');
    $session->undo();
    $session->undo();
    $session->undo();
    $session->saveMap('test-map');
    expect((string) file_get_contents($path))->toBe($authored);
    $session->redo();
    $session->redo();
    $session->redo();
    $session->saveMap('test-map');
    expect(array_slice(readEngineMapRow($root, 1), 2, 3))->toBe(['<', '\\', '>']);
});

it('draws and saves a coloured literal delimiter or backslash on a layered map as the Engine reads it', function (string $glyph) {
    $root = layeredMapProject();
    $session = EditorSession::open($root);
    $terrain = array_find($session->readMap('test-map')['layers'], static fn(array $layer): bool => $layer['name'] === 'terrain')['id'];
    $session->paint('test-map', $session->readMap('test-map')['revision'], $terrain, [[0, 1]], $glyph, 'blue');

    $unsaved = array_find($session->readMap('test-map')['layers'], static fn(array $layer): bool => $layer['id'] === $terrain);
    expect($unsaved['rows'][1][0])->toBe($glyph);
    // The terminal canvas draws a cell painted in this sitting, before it is saved, in its column too.
    $painting = ProjectWorkspace::fromProject($root)->getMapByIndex(0);
    $painting->setLayerCell($terrain, 0, 1, $glyph, '<fg=blue>', '</>');
    expect(TerminalText::stripAnsi($painting->renderPreview(4, 2)[1]))->toBe($glyph . 'xx.');
    $session->saveMap('test-map');
    $map = ProjectWorkspace::fromProject($root)->getMapByIndex(0);
    // The canvas draws the saved cell as one coloured glyph, in its column.
    expect(TerminalText::stripAnsi($map->renderPreview(4, 2)[1]))->toBe($glyph . 'xx.')
        ->and($map->getLayerColor($terrain, 0, 1))->toBe('blue')
        ->and(readEngineMapRow($root, 1))->toBe([$glyph, 'x', 'x', '.']);
})->with(['less-than' => '<', 'greater-than' => '>', 'backslash' => '\\']);
