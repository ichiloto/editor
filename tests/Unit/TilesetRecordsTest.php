<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordAuthoring;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;

/**
 * Tilesets as one record per file, edited through the shared record service
 * and read back by the Engine's Tileset. Synthetic fixtures only.
 */
function tilesetProject(): string
{
    $root = makeTemporaryProject();
    mkdir($root . '/assets/Data/Tilesets', 0777, true);
    file_put_contents($root . '/assets/Data/Tilesets/inside.php', <<<'PHP'
    <?php

    // The rooms everything indoors is built from.
    return [
      'name' => 'Inside',
      'sheets' => [
        'A4' => 'Graphics/Tilesets/Inside/A4.png',
        'B' => 'Graphics/Tilesets/Inside/B.png',
      ],
      'missingArt' => 255,
      'shadows' => ['casters' => ['A4'], 'width' => 0.5, 'opacity' => 0.5],
      'pieces' => [
        // A bed stands two cells long.
        'bed' => ['name' => 'Bed', 'layer' => 'fixtures', 'glyphs' => ['O', 'U'], 'tiles' => ['furniture' => ['1', '9']]],
        'wall' => ['name' => 'Wall', 'layer' => 'buildings', 'connects' => 'lines',
          'glyphs' => ['horizontal' => '-', 'vertical' => '|', 'corner' => '+'], 'tiles' => ['walls' => '5888']],
      ],
    ];

    PHP);

    return $root;
}

function loadTileset(string $root): Tileset
{
    return Tileset::load($root . '/assets', 'inside');
}

it('reads a tileset\'s sheets, flags, shadow and pieces as rows, a piece\'s tiles by layer', function () {
    $tilesets = loadRecordDatabase(tilesetProject(), 'tilesets');
    $fields = array_column(array_filter($tilesets->getFrameSettingsFields(0, []), static fn(array $field): bool => isset($field['field'])), 'value', 'field');

    expect($tilesets->getEntryLabels())->toBe(['Inside'])
        ->and($fields)->toMatchArray([
            'sheets.A4' => 'Graphics/Tilesets/Inside/A4.png',
            'missingArt' => '255',
            'shadows.casters' => 'A4',
            'piece0Id' => 'bed',
            'piece0Tiles0Layer' => 'furniture',
            'piece1Id' => 'wall',
            'piece1Connects' => 'lines',
            'piece1Glyphscorner' => '+',
            'piece1Tiles0Tile' => '5888',
        ])
        ->and($tilesets->getRecordByIndex(0)?->get('pieces')['bed']['tiles'])->toBe(['furniture' => ['1', '9']]);
});

it('edits pieces in their own source, keeping comments, keys and order', function () {
    $root = tilesetProject();
    $path = $root . '/assets/Data/Tilesets/inside.php';
    $source = (string) file_get_contents($path);
    $tilesets = loadRecordDatabase($root, 'tilesets');

    $tilesets->setField(0, 'piece0Tiles0Rows', "2\n10");
    $tilesets->setField(0, 'piece1Tiles0Tile', 'horizontal: 5888, vertical: 5890, corner: 5892');
    $tilesets->setField(0, 'above', '4736, 4784');
    $tilesets->save();

    $tileset = loadTileset($root);

    $saved = (string) file_get_contents($path);

    expect($saved)->toContain("// The rooms everything indoors is built from.", "// A bed stands two cells long.", "'tiles' => ['furniture' => ['2', '10']]]")
        ->and(substr($saved, 0, (int) strpos($saved, "  'pieces'")))->toBe(substr($source, 0, (int) strpos($source, "  'pieces'")))
        ->and($tileset->pieces['bed']->tiles['furniture'])->toBe([['2'], ['10']])
        ->and($tileset->pieces['wall']->shapeTiles['walls']['vertical'])->toBe('5890')
        ->and($tileset->above)->toBe([4736, 4784]);
});

it('adds pieces under ids of their own and refuses an id another piece has', function () {
    $root = tilesetProject();
    $tilesets = loadRecordDatabase($root, 'tilesets');
    $authoring = new RecordAuthoring();

    $authoring->addItem($tilesets, 0, [], 'piece1Name');
    $authoring->addItem($tilesets, 0, [], 'piece2Name');

    expect(array_keys($tilesets->getRecordByIndex(0)?->get('pieces') ?? []))->toBe(['bed', 'wall', 'new-piece', 'new-piece-2'])
        ->and(fn() => $authoring->applyField($tilesets, 0, [], 'piece2Id', 'bed', 'Id'))->toThrow(Ichiloto\Editor\Database\RecordRefusal::class, 'Another piece already has id "bed".')
        ->and(fn() => $authoring->applyField($tilesets, 0, [], 'piece2Id', ' ', 'Id'))->toThrow(Ichiloto\Editor\Database\RecordRefusal::class, 'A piece needs id.');

    $authoring->applyField($tilesets, 0, [], 'piece2Id', 'lamp', 'Id');
    $tilesets->save();

    expect(array_keys(loadTileset($root)->pieces))->toBe(['bed', 'wall', 'lamp', 'new-piece-2']);
});

it('turns a stamped piece into a connected one without keeping its rows', function () {
    $root = tilesetProject();
    $tilesets = loadRecordDatabase($root, 'tilesets');

    $tilesets->setField(0, 'piece0Connects', 'lines');
    $bed = $tilesets->getRecordByIndex(0)?->get('pieces')['bed'];

    expect($bed)->toBe(['name' => 'Bed', 'layer' => 'fixtures', 'connects' => 'lines']);
});

it('reports a tileset the Engine would refuse, by its file', function () {
    $root = tilesetProject();
    $tilesets = loadRecordDatabase($root, 'tilesets');
    $tilesets->setField(0, 'sheets.A4', '');
    $tilesets->setField(0, 'sheets.B', '');
    $tilesets->save();

    $issues = array_filter(
        new Ichiloto\Editor\Validation\ProjectValidator()->validate(Ichiloto\Editor\ProjectWorkspace::fromProject($root)),
        static fn(Issue $issue): bool => str_contains($issue->where, 'Tilesets'),
    );

    expect(array_map(static fn(Issue $issue): string => $issue->where . ': ' . $issue->message, array_values($issues)))
        ->toBe(['assets/Data/Tilesets/inside.php: Tileset inside needs at least one sheet.']);
});
