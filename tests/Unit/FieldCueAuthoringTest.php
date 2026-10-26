<?php

declare(strict_types=1);

use Ichiloto\Editor\FieldPresentationSettings;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;

/** A project whose field.php binds one cue colour with old baked edge images, beside a comment. */
function makeCueProject(bool $edges = true): string
{
  $root = makeTemporaryProject();
  @mkdir($root . '/assets/Graphics/System', 0777, true);
  foreach (['Edge', 'EdgeDiagonal', 'Pointer', 'PointerDiagonal', 'Plate'] as $name) {
    $image = imagecreatetruecolor(4, 4);
    imagepng($image, $root . "/assets/Graphics/System/Cue{$name}.png");
  }
  @mkdir($root . '/assets/Animations/cue-mark', 0777, true);
  file_put_contents($root . '/assets/Animations/cue-mark/cue-mark.timeline.php', '<?php return ' . var_export(['fps' => 5,
    'lengthFrames' => 1, 'playback' => 'loop', 'restFrame' => 0, 'tracks' => [['id' => 'mark', 'type' => 'image',
      'asset' => 'Graphics/System/CuePlate.png', 'sheet' => ['columns' => 1, 'rows' => 1], 'cells' => ['width' => 1, 'height' => 1],
      'depth' => 'front', 'keyframes' => [['frame' => 0, 'sourceFrame' => 0]]]]], true) . ';');
  $turns = ['east' => 0, 'southeast' => 1, 'south' => 1, 'southwest' => 2, 'west' => 2, 'northwest' => 3, 'north' => 3, 'northeast' => 0];
  $lines = [];
  foreach ($turns as $direction => $turn) {
    $asset = str_contains(substr($direction, 1), 'east') || str_contains(substr($direction, 1), 'west') ? 'CueEdgeDiagonal' : 'CueEdge';
    $lines[] = "        '{$direction}' => ['asset' => 'Graphics/System/{$asset}.png', 'quarterTurns' => {$turn}],";
  }
  $edgesSource = $edges ? "      'edges' => [\n" . implode("\n", $lines) . "\n      ],\n" : '';
  @mkdir($root . '/assets/Data/Presentation', 0777, true);
  file_put_contents($root . '/assets/Data/Presentation/field.php', "<?php\n\n// Colour selects replaceable artwork.\nreturn [\n"
    . "  'cues' => [\n    'bright-yellow' => [\n      'effect' => 'cue-mark',\n{$edgesSource}    ],\n  ],\n];\n");

  return $root;
}

function cueRow(EditorSession $session, string $path): array
{
  $records = $session->listDatabaseRecords('field_cues')['records'];
  $index = array_search($path, $records, true);
  expect($index)->not->toBeFalse("No {$path} setting.");
  $value = array_find($session->readDatabaseRecord('field_cues', $index)['rows'],
    static fn(array $row): bool => ($row['key']['field'] ?? null) === 'value');

  return ['index' => $index, 'row' => $value];
}

it('splits baked edge images into pointer images in place, keeping turns and comments', function () {
  $root = makeCueProject();
  $settings = new FieldPresentationSettings($root);

  expect(fn() => FieldPresentationCatalog::load($root . '/assets'))->toThrow(InvalidArgumentException::class)
    ->and($settings->replaceEdgesWithPointers(['Graphics/System/CueEdge.png' => 'Graphics/System/CuePointer.png',
      'Graphics/System/CueEdgeDiagonal.png' => 'Graphics/System/CuePointerDiagonal.png']))->toBeTrue();
  $source = file_get_contents($root . '/assets/Data/Presentation/field.php');
  $catalog = FieldPresentationCatalog::load($root . '/assets');

  expect($source)->toContain('// Colour selects replaceable artwork.')->not->toContain("'edges'")
    ->and($catalog->cues['bright-yellow']['pointer']['south'])->toBe(['asset' => 'Graphics/System/CuePointer.png', 'quarterTurns' => 1])
    ->and($catalog->cues['bright-yellow']['pointer']['northwest'])->toBe(['asset' => 'Graphics/System/CuePointerDiagonal.png', 'quarterTurns' => 3])
    ->and((new FieldPresentationSettings($root))->replaceEdgesWithPointers([]))->toBeFalse();
});

it('refuses to split edges without a pointer image for each, writing nothing', function () {
  $root = makeCueProject();
  $before = file_get_contents($root . '/assets/Data/Presentation/field.php');

  expect(fn() => (new FieldPresentationSettings($root))->replaceEdgesWithPointers(['Graphics/System/CueEdge.png' => 'Graphics/System/CuePointer.png']))
    ->toThrow(InvalidArgumentException::class)
    ->and(file_get_contents($root . '/assets/Data/Presentation/field.php'))->toBe($before);
});

it('authors the cue treatment and pointer images as rows with the Engine defaults and refusals', function () {
  $root = makeCueProject(edges: false);
  $session = EditorSession::open($root);
  $records = $session->listDatabaseRecords('field_cues')['records'];

  expect($records)->toContain('cues.bright-yellow.plate', 'cueStyle.bob.height', 'cueStyle.bob.seconds', 'cueStyle.pointerSpacing.x', 'cueStyle.pointerSpacing.y',
    'cueStyle.terminal.background', 'cueStyle.terminal.bold', 'cues.bright-yellow.effect',
    'cues.bright-yellow.pointer.east.asset', 'cues.bright-yellow.pointer.east.quarterTurns', 'waymarks.color')
    ->and(cueRow($session, 'cues.bright-yellow.plate')['row'])->toMatchArray(['kind' => 'reference', 'reference' => 'png_assets'])
    ->and($records)->not->toContain('cueStyle.plate')
    ->and(cueRow($session, 'cueStyle.terminal.background')['row']['value'])->toBe('black')
    ->and(cueRow($session, 'cues.bright-yellow.effect')['row'])->toMatchArray(['kind' => 'reference', 'reference' => 'effects']);

  $height = cueRow($session, 'cueStyle.bob.height');
  expect(fn() => $session->applyDatabaseRecord('field_cues', $height['index'], $height['row']['key'], '13'))
    ->toThrow('A cue bob height must be 0..12 logical pixels.');
  $session->applyDatabaseRecord('field_cues', $height['index'], $height['row']['key'], '3');
  $plate = cueRow($session, 'cues.bright-yellow.plate');
  $session->applyDatabaseRecord('field_cues', $plate['index'], $plate['row']['key'], 'Graphics/System/CuePlate.png');
  foreach (FieldPresentationCatalog::DIRECTIONS as $direction) {
    $asset = cueRow($session, "cues.bright-yellow.pointer.{$direction}.asset");
    $session->applyDatabaseRecord('field_cues', $asset['index'], $asset['row']['key'], 'Graphics/System/CuePointer.png');
  }
  $turns = cueRow($session, 'cues.bright-yellow.pointer.south.quarterTurns');
  $session->applyDatabaseRecord('field_cues', $turns['index'], $turns['row']['key'], '1');
  $session->saveDatabase('field_cues');
  $catalog = FieldPresentationCatalog::load($root . '/assets');

  expect(file_get_contents($root . '/assets/Data/Presentation/field.php'))->toContain('// Colour selects replaceable artwork.')
    ->and($catalog->cueStyle->bobHeight)->toBe(3)->and($catalog->cues['bright-yellow']['plate'])->toBe('Graphics/System/CuePlate.png')
    ->and($catalog->cues['bright-yellow']['pointer']['south'])->toBe(['asset' => 'Graphics/System/CuePointer.png', 'quarterTurns' => 1])
    ->and(count($catalog->cues['bright-yellow']['pointer']))->toBe(8);
});

it('authors how see-through a mark turns under the player, with the Engine default and refusals', function () {
  $root = makeCueProject(edges: false);
  $session = EditorSession::open($root);
  $opacity = cueRow($session, 'cueStyle.overlap.opacity');
  $seconds = cueRow($session, 'cueStyle.overlap.seconds');

  expect($opacity['row']['value'])->toBe('0.4')->and($seconds['row']['value'])->toBe('0.2')
    ->and(fn() => $session->applyDatabaseRecord('field_cues', $opacity['index'], $opacity['row']['key'], '1.5'))
    ->toThrow('A cue overlap opacity must be 0..1.')
    ->and(fn() => $session->applyDatabaseRecord('field_cues', $seconds['index'], $seconds['row']['key'], '3'))
    ->toThrow('A cue overlap fade must last 0..2 seconds.');
  $session->applyDatabaseRecord('field_cues', $opacity['index'], $opacity['row']['key'], '0.25');
  $seconds = cueRow($session, 'cueStyle.overlap.seconds');
  $session->applyDatabaseRecord('field_cues', $seconds['index'], $seconds['row']['key'], '0.5');
  $session->saveDatabase('field_cues');
  $catalog = FieldPresentationCatalog::load($root . '/assets');

  expect($catalog->cueStyle->overlapOpacity)->toBe(0.25)->and($catalog->cueStyle->overlapSeconds)->toBe(0.5);
});

it('authors an NPC cue on the page talking runs, stored on the NPC while its lines are its only page', function () {
  $root = makeTemporaryProject();
  $session = EditorSession::open($root);
  $index = $session->createNpc('test-map', $session->readMap('test-map')['revision'], 2, 2, 'Halen', 'halen')['index'];
  $rows = $session->readNpc('test-map', $index)['rows'];
  $row = static fn(string $field): array => array_find($session->readNpc('test-map', $index)['rows'],
    static fn(array $row): bool => ($row['key']['field'] ?? null) === $field);

  // One place to author it: the page's own rows, never a second NPC-level Cue group.
  expect(array_column($rows, 'label'))->toContain('Page 1')->not->toContain('Cue')
    ->and($row('variant0Cuecolor')['kind'])->toBe('options');
  $session->applyNpc('test-map', $session->readMap('test-map')['revision'], $index, $row('variant0Cuesymbol')['key'], '!');
  $session->applyNpc('test-map', $session->readMap('test-map')['revision'], $index, $row('variant0Cuecolor')['key'], 'bright-cyan');
  $session->applyNpc('test-map', $session->readMap('test-map')['revision'], $index, $row('variant0Cueconditions')['key'], '!event:halen_rescued');
  $session->saveMap('test-map');
  $npc = (require $root . '/assets/Maps/test-map/test-map.data.php')['npcs'][$index];
  $cue = Ichiloto\Engine\Events\Triggers\EventCue::fromArray($npc['cue']);

  expect($cue->symbol)->toBe('!')->and($cue->color)->toBe('bright-cyan')
    ->and($cue->conditions)->toBe([['type' => 'event', 'name' => 'halen_rescued', 'negate' => true]])
    ->and($npc['dialogue'])->toBe([['text' => 'Hello.']]);

  // A condition makes the lines a page list: the cue moves onto that page, the one the Engine reads.
  $session->applyNpc('test-map', $session->readMap('test-map')['revision'], $index, $row('variant0Conditions')['key'], 'event:halen_found');
  $session->saveMap('test-map');
  $npc = (require $root . '/assets/Maps/test-map/test-map.data.php')['npcs'][$index];

  expect($npc)->not->toHaveKey('cue')
    ->and($npc['dialogue'][0]['cue']['symbol'])->toBe('!')
    ->and(Ichiloto\Engine\Field\NpcPages::fromEntry($npc)->pages[0]->cue?->color)->toBe('bright-cyan');
});

it('reports an NPC cue the Engine would refuse, as it does an event cue', function () {
  $root = makeTemporaryProject();
  $file = $root . '/assets/Maps/test-map/test-map.data.php';
  $data = require $file;
  $data['npcs'] = [['id' => 'halen', 'name' => 'Halen', 'x' => 1, 'y' => 1, 'sprite' => '@', 'cue' => ['symbol' => '!!', 'color' => 'bright-yellow']]];
  file_put_contents($file, '<?php return ' . var_export($data, true) . ';');
  $messages = array_map(static fn($issue): string => $issue->message,
    (new Ichiloto\Editor\Validation\ProjectValidator())->validate(Ichiloto\Editor\ProjectWorkspace::fromProject($root)));

  expect(implode("\n", $messages))->toContain('does not occupy exactly one terminal cell');
});
