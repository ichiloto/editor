<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Engine\Quests\Quest;

/** The row a session shows for a field id, or null. */
function waymarkRow(EditorSession $session, string $field): ?array
{
  return array_find($session->readDatabaseRecord('quests', 0)['rows'],
    static fn(array $row): bool => ($row['key']['field'] ?? null) === $field);
}

/** Adds a waymark to the first objective and picks its map. */
function addWaymarkOnTestMap(EditorSession $session): void
{
  $session->addDatabaseItem('quests', 0, waymarkRow($session, 'objective0Type')['key'], true);
  $session->applyDatabaseRecord('quests', 0, waymarkRow($session, 'objective0Waymark0Map')['key'], 'test-map');
}

it('authors a waymark as a picked map and a subject chosen from that map, saved in the Engine shape', function () {
  $root = makeTemporaryProject();
  $session = EditorSession::open($root);
  addWaymarkOnTestMap($session);
  $event = waymarkRow($session, 'objective0Waymark0Event');

  expect($event)->toMatchArray(['kind' => 'reference', 'reference' => 'event_markers', 'referenceMap' => 'test-map'])
    ->and($session->listReferences($event['referenceMap'], 'event_markers'))->toBe([['value' => 'E', 'label' => 'E · Chest']]);

  $session->applyDatabaseRecord('quests', 0, $event['key'], 'E');
  $session->saveDatabase('quests');
  $quests = require $root . '/assets/Data/quests.php';

  expect($quests[0]['objectives'][0]['waymarks'])->toBe([['map' => 'test-map', 'event' => 'E']])
    ->and(Quest::fromArray($quests[0])->objectives[0]->waymarks[0]->subjectId)->toBe('E');
});

it('keeps one subject per waymark: picking another kind replaces the first', function () {
  $root = makeTemporaryProject();
  $session = EditorSession::open($root);
  addWaymarkOnTestMap($session);
  $session->applyDatabaseRecord('quests', 0, waymarkRow($session, 'objective0Waymark0Event')['key'], 'E');
  $session->applyDatabaseRecord('quests', 0, waymarkRow($session, 'objective0Waymark0Landmark')['key'], 'gate');
  $session->saveDatabase('quests');
  $quests = require $root . '/assets/Data/quests.php';

  expect($quests[0]['objectives'][0]['waymarks'][0])->toBe(['map' => 'test-map', 'landmark' => 'gate']);
});

it('asks for the map before offering a waymark subject in the terminal editor', function () {
  $root = makeTemporaryProject();
  $session = EditorSession::open($root);
  $session->addDatabaseItem('quests', 0, waymarkRow($session, 'objective0Type')['key'], true);
  $session->saveDatabase('quests');
  $editor = createEditorForTesting($root);
  setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
  callEditorMethod($editor, 'openDatabaseAtCategory', 'quests');
  $field = array_find(callEditorMethod($editor, 'getDatabaseSettingsFields'),
    static fn(array $row): bool => ($row['field'] ?? null) === 'objective0Waymark0Event');

  expect($field['referenceMap'])->toBe('')
    ->and(callEditorMethod($editor, 'openReferencePicker', $field))->toBeTrue()
    ->and(getEditorProperty($editor, 'statusMessage'))->toContain('Choose a map for this entry first');
});

it('reports waymarks that name a missing map, a subject the map lacks, or a malformed shape', function () {
  $root = makeTemporaryProject();
  $file = $root . '/assets/Data/quests.php';
  $quests = require $file;
  $quests[0]['objectives'][0]['waymarks'] = [
    ['map' => 'no-such-map'],
    ['map' => 'test-map', 'event' => 'Z'],
    ['map' => 'test-map', 'event' => 'E', 'npc' => 'twice'],
    ['map' => 'test-map', 'event' => 'E', 'conditions' => [['type' => 'event', 'name' => 'chest_seen', 'negate' => true]]],
  ];
  file_put_contents($file, '<?php return ' . var_export($quests, true) . ';');
  $messages = array_map(static fn($issue): string => $issue->message,
    array_filter((new ProjectValidator())->validate(ProjectWorkspace::fromProject($root)),
      static fn($issue): bool => str_contains($issue->where, 'waymark')));

  expect($messages)->toHaveCount(3)
    ->and(implode("\n", $messages))->toContain('no-such-map')->toContain('event "Z"')->toContain('one subject at most');
});

it('authors the game waymark style in field.php with the Engine defaults and refusals', function () {
  $root = makeTemporaryProject();
  mkdir($root . '/assets/Data/Presentation', 0777, true);
  file_put_contents($root . '/assets/Data/Presentation/field.php', "<?php\n\n// Field bindings.\nreturn [\n  'cues' => [],\n];\n");
  $session = EditorSession::open($root);
  $records = $session->listDatabaseRecords('waymark_style')['records'];
  $symbol = array_search('waymarks.symbol', $records, true);
  $value = array_find($session->readDatabaseRecord('waymark_style', $symbol)['rows'],
    static fn(array $row): bool => ($row['key']['field'] ?? null) === 'value');

  expect($records)->toBe(['waymarks.color', 'waymarks.symbol', 'waymarks.inset'])->and($value['value'])->toBe('!');
  expect(fn() => $session->applyDatabaseRecord('waymark_style', $symbol, $value['key'], '!!'))
    ->toThrow('The waymark symbol must occupy exactly one terminal cell.');

  $session->applyDatabaseRecord('waymark_style', $symbol, $value['key'], '*');
  $session->saveDatabase('waymark_style');
  $source = file_get_contents($root . '/assets/Data/Presentation/field.php');

  expect($source)->toContain('// Field bindings.')
    ->and((require $root . '/assets/Data/Presentation/field.php')['waymarks'])->toBe(['symbol' => '*'])
    ->and(Ichiloto\Engine\Animations\Field\FieldPresentationCatalog::fromArray(require $root . '/assets/Data/Presentation/field.php',
      $root . '/assets')->waymarks->symbol)->toBe('*');
});

it('keeps Shift+O on an objective adding an objective, and adds a waymark from its Waymarks heading', function () {
  $root = makeTemporaryProject();
  $editor = createEditorForTesting($root);
  setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
  callEditorMethod($editor, 'openDatabaseAtCategory', 'quests');
  setEditorProperty($editor, 'databaseFocus', 'database_settings');
  $database = getEditorProperty($editor, 'workspace')->getRecordDatabase('quests');
  $select = static function (string $id) use ($editor): void {
    foreach (callEditorMethod($editor, 'getDatabaseSettingsFields') as $index => $field) {
      if (($field['field'] ?? null) === $id) { setEditorProperty($editor, 'databaseSelectedSettingIndex', $index); return; }
    }
    throw new RuntimeException("No row {$id}.");
  };

  ob_start();
  $select('objective0Type');
  callEditorMethod($editor, 'dispatchInput', 'O');
  $select('objective0WaymarkList');
  callEditorMethod($editor, 'dispatchInput', 'O');
  ob_end_clean();
  $objectives = $database->getSubItems(0);

  expect($objectives)->toHaveCount(3)
    ->and($objectives[0]['waymarks'] ?? [])->toHaveCount(1)
    ->and($objectives[1]['waymarks'] ?? [])->toBe([]);
});
