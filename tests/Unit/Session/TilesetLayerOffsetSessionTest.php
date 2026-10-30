<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;

/**
 * A tileset's layer offsets, authored in the GUI editor's Database >
 * Tilesets: where the art of a tile layer draws on every map drawn with the
 * tileset, chosen among the tile layers its pieces write and validated as
 * the Engine reads them. Every project here is a synthetic fixture.
 */

/** A graphics project whose tileset has a piece that draws a synthetic tabletop layer. */
function layerOffsetProject(): string
{
    $root = mapGraphicsProject();
    writeTestTileset($root, pieces: ['lamp' => ['name' => 'Lamp', 'layer' => 'fixtures', 'glyphs' => ['i'], 'tiles' => ['tabletop' => ['5']]]]);
    $path = $root . '/assets/Data/Tilesets/home.php';
    file_put_contents($path, str_replace("<?php\n", "<?php\n// Kept by the editor.\n", (string) file_get_contents($path)));

    return $root;
}

/** @return array<string, array<string, mixed>> The tileset record's rows, by field. */
function layerOffsetRows(EditorSession $session): array
{
    $rows = [];
    foreach ($session->readDatabaseRecord('tilesets', 0)['rows'] as $row) {
        if (is_string($row['key']['field'] ?? null)) {
            $rows[$row['key']['field']] = $row;
        }
    }

    return $rows;
}

it('gives a tile layer a fractional offset on its tileset, chosen among the layers it writes, with undo byte for byte', function () {
    $root = layerOffsetProject();
    $path = $root . '/assets/Data/Tilesets/home.php';
    $original = (string) file_get_contents($path);
    $session = EditorSession::open($root);

    $session->addDatabaseItem('tilesets', 0, layerOffsetRows($session)['layerOffsetList']['key']);
    $set = static fn(string $field, string $value) => $session->applyDatabaseRecord('tilesets', 0, layerOffsetRows($session)[$field]['key'], $value);
    expect(layerOffsetRows($session)['layerOffset0Layer']['options'])->toBe(['', 'tabletop']);
    $set('layerOffset0Layer', 'tabletop');
    $set('layerOffset0Offset', '0, -1/3');
    expect(layerOffsetRows($session)['layerOffset0Offset']['value'])->toBe('0, ' . (-1 / 3));
    $session->saveDatabase('tilesets');

    expect((string) file_get_contents($path))->toContain('// Kept by the editor.')
        ->and(Tileset::load($root . '/assets', 'home')->layerOffsets)->toBe(['tabletop' => [0.0, -1 / 3]]);

    // A tile layer the tileset does not write, and an offset beyond half a cell, are refused.
    expect(fn() => $set('layerOffset0Layer', 'nowhere'))->toThrow(SessionRefusal::class)
        ->and(fn() => $set('layerOffset0Offset', '0, 0.75'))->toThrow(SessionRefusal::class, 'between -0.5 and 0.5 field cells')
        ->and(fn() => $set('layerOffset0Offset', 'up'))->toThrow(SessionRefusal::class, 'two numbers, across and down');

    $session->undo();
    $session->undo();
    $session->undo();
    $session->saveDatabase('tilesets');
    expect(file_get_contents($path))->toBe($original);
});

it('keeps tileset layer offsets the terminal Database does not show', function () {
    $root = layerOffsetProject();
    $path = $root . '/assets/Data/Tilesets/home.php';
    $data = require $path;
    $data['layerOffsets'] = ['tabletop' => [0, -0.25]];
    file_put_contents($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");

    $terminal = loadRecordDatabase($root, 'tilesets');
    $fields = array_column(array_filter($terminal->getFrameSettingsFields(0, []), static fn(array $field): bool => isset($field['field'])), 'field');
    expect(array_filter($fields, static fn(string $field): bool => str_contains($field, 'layerOffset')))->toBe([]);
    $terminal->setField(0, 'name', 'Home tiles');
    $terminal->save();

    expect(Tileset::load($root . '/assets', 'home')->layerOffsets)->toBe(['tabletop' => [0.0, -0.25]]);
});
