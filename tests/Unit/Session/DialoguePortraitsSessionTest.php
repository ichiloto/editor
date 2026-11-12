<?php

declare(strict_types=1);

use Ichiloto\Editor\Dialogue\DialoguePortraitFields;
use Ichiloto\Editor\Dialogue\DialogueSpeakerFields;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;

/** The row of a record whose key names a field. */
function findDialogueRow(EditorSession $session, string $category, int $index, string $field, array $frame = []): array
{
    $row = array_find($session->readDatabaseRecord($category, $index, $frame)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field);
    expect($row)->not->toBeNull("{$category} {$index} has a {$field} row");

    return $row;
}

it('lists actor and character identities as one page and edits their artwork in the file\'s own source', function () {
    [$root, $file, $session] = createDialogueProject();
    $listing = $session->listDatabaseRecords(DialoguePortraitFields::CATEGORY);
    expect($listing['editable'])->toBeTrue()
        ->and($listing['records'])->toBe(['Kaelion (actor)', 'Mira (character)']);

    $portrait = findDialogueRow($session, DialoguePortraitFields::CATEGORY, 0, 'portrait');
    expect($portrait['kind'])->toBe('reference')->and($portrait['reference'])->toBe('png_assets')
        ->and($portrait['media'])->toBe(['kind' => 'image', 'root' => 'assets']);
    $id = findDialogueRow($session, DialoguePortraitFields::CATEGORY, 0, 'id');
    expect($id['reference'])->toBe('actor_ids');

    // A bust for the actor and a new emotion, chosen as project images.
    $session->applyDatabaseRecord(DialoguePortraitFields::CATEGORY, 0, findDialogueRow($session, DialoguePortraitFields::CATEGORY, 0, 'bust')['key'], 'Graphics/Faces/Hero.png');
    $heading = array_find($session->readDatabaseRecord(DialoguePortraitFields::CATEGORY, 0)['rows'],
        static fn(array $row): bool => ($row['label'] ?? null) === 'Emotions');
    $session->addDatabaseItem(DialoguePortraitFields::CATEGORY, 0, $heading['key']);
    $session->applyDatabaseRecord(DialoguePortraitFields::CATEGORY, 0, findDialogueRow($session, DialoguePortraitFields::CATEGORY, 0, 'emotion0Name')['key'], 'Sad');
    $session->applyDatabaseRecord(DialoguePortraitFields::CATEGORY, 0, findDialogueRow($session, DialoguePortraitFields::CATEGORY, 0, 'emotion0Portrait')['key'], 'Graphics/Faces/HeroSad.png');
    $session->saveDatabase(DialoguePortraitFields::CATEGORY);

    $source = (string) file_get_contents($file);
    expect($source)->toContain('// Synthetic dialogue artwork for editor tests.', '// The innkeeper.')
        ->and($source)->not->toContain('base64');
    $catalogue = DialoguePresentationCatalog::load($root . '/assets');
    expect($catalogue->actors['Kaelion']['bust'])->toBe('Graphics/Faces/Hero.png')
        ->and($catalogue->actors['Kaelion']['emotions']['Sad'])->toBe('Graphics/Faces/HeroSad.png')
        // Untouched entries keep their authored shape.
        ->and($catalogue->resources['Mira']['emotions'])->toBe(['Worried' => 'Graphics/Faces/MiraWorried.png']);
});

it('moves an identity between actors and characters by what it speaks as', function () {
    [$root, $file, $session] = createDialogueProject();
    $session->applyDatabaseRecord(DialoguePortraitFields::CATEGORY, 0, findDialogueRow($session, DialoguePortraitFields::CATEGORY, 0, 'kind')['key'], DialoguePortraitFields::CHARACTER);
    // A character's identity is typed; an actor's is chosen among the actors.
    expect(findDialogueRow($session, DialoguePortraitFields::CATEGORY, 0, 'id')['kind'])->toBe('text');
    $session->saveDatabase(DialoguePortraitFields::CATEGORY);
    $catalogue = DialoguePresentationCatalog::load($root . '/assets');
    expect($catalogue->actors)->toBe([])
        ->and(array_keys($catalogue->resources))->toBe(['Kaelion', 'Mira'])
        ->and($catalogue->resources['Kaelion']['emotions']['Angry']['portrait'])->toBe('Graphics/Faces/HeroAngry.png')
        ->and((string) file_get_contents($file))->toContain('// The innkeeper.');
});

it('binds speaker names to identities chosen from the catalogue, its unsaved identities included', function () {
    [$root, , $session] = createDialogueProject();
    expect($session->listDatabaseRecords(DialogueSpeakerFields::CATEGORY)['records'])->toBe(['Kaelion -> Kaelion', 'Mom -> Mira']);
    $identity = findDialogueRow($session, DialogueSpeakerFields::CATEGORY, 1, 'identity');
    expect($identity['reference'])->toBe('dialogue_identities');

    $created = $session->createDatabaseRecord(DialoguePortraitFields::CATEGORY, 'Elder Tov');
    $choices = $session->listReferences(null, 'dialogue_identities');
    expect(array_column($choices, 'value'))->toBe(['Kaelion', 'Mira', 'Elder Tov'])
        ->and(array_column($choices, 'label'))->toBe(['Kaelion (actor)', 'Mira (character)', 'Elder Tov (character)']);

    $speaker = $session->createDatabaseRecord(DialogueSpeakerFields::CATEGORY, 'The Elder')['index'];
    $session->applyDatabaseRecord(DialogueSpeakerFields::CATEGORY, $speaker, findDialogueRow($session, DialogueSpeakerFields::CATEGORY, $speaker, 'identity')['key'], 'Elder Tov');
    $session->saveAll();
    $catalogue = DialoguePresentationCatalog::load($root . '/assets');
    expect($catalogue->speakers)->toBe(['Kaelion' => 'Kaelion', 'Mom' => 'Mira', 'The Elder' => 'Elder Tov'])
        ->and(array_keys($catalogue->resources))->toBe(['Mira', 'Elder Tov'])
        ->and($created['index'])->toBe(2);
});

it('leaves identities the file builds with code read-only, saying why, while its literal speaker names stay editable', function () {
    [, $file, $session] = createDialogueProject(<<<'PHP'
<?php

$actors = [];
foreach (['Kaelion'] as $id) {
  $actors[$id] = ['portrait' => 'Graphics/Faces/Hero.png'];
}

return [
  'schema' => 'ichiloto.dialogue/1',
  'actors' => $actors,
  'speakers' => ['Kaelion' => 'Kaelion'],
];
PHP);
    $before = (string) file_get_contents($file);
    $portraits = $session->listDatabaseRecords(DialoguePortraitFields::CATEGORY);
    expect($portraits['editable'])->toBeFalse()
        ->and($portraits['readOnly'])->toContain('builds "actors" with its own PHP code in $actors')
        ->and($portraits['records'])->toBe(['Kaelion (actor)']);
    expect(fn() => $session->applyDatabaseRecord(DialoguePortraitFields::CATEGORY, 0,
        findDialogueRow($session, DialoguePortraitFields::CATEGORY, 0, 'bust')['key'], 'Graphics/Faces/Hero.png'))->toThrow(SessionRefusal::class);

    expect($session->listDatabaseRecords(DialogueSpeakerFields::CATEGORY)['editable'])->toBeTrue();
    $session->applyDatabaseRecord(DialogueSpeakerFields::CATEGORY, 0, findDialogueRow($session, DialogueSpeakerFields::CATEGORY, 0, 'name')['key'], 'Kael');
    $session->saveDatabase(DialogueSpeakerFields::CATEGORY);
    expect((string) file_get_contents($file))->toBe(str_replace("'speakers' => ['Kaelion' => 'Kaelion']", "'speakers' => ['Kael' => 'Kaelion']", $before));
});


it('offers a line the emotions its speaker\'s identity has, through actor ids and speaker names alike', function () {
    [$root, , $session] = createDialogueProject();
    file_put_contents($root . '/assets/Data/Skits/breakfast-banter.php', <<<'PHP'
<?php

return [
  'id' => 'breakfast-banter',
  'title' => 'Breakfast Banter',
  'where' => 'happyville/town-center',
  'beats' => [
    ['actor' => 'Kaelion', 'text' => 'Again?', 'emotion' => 'Angry'],
    ['speaker' => 'Mom', 'text' => 'Eat first.', 'emotion' => 'Furious'],
    ['speaker' => 'A Stranger', 'text' => 'Hm.'],
  ],
];
PHP);
    $session = EditorSession::open($root);
    $actorLine = findDialogueRow($session, 'skits', 0, 'beat0Emotion');
    expect($actorLine['kind'])->toBe('options')->and($actorLine['options'])->toBe(['Neutral', 'Angry'])->and($actorLine['value'])->toBe('Angry');
    $rows = $session->readDatabaseRecord('skits', 0)['rows'];
    $momLine = findDialogueRow($session, 'skits', 0, 'beat1Emotion');
    expect($momLine['options'])->toBe(['Neutral', 'Worried']);
    // Kept as written, and said: the identity has no such emotion.
    $warning = array_values(array_filter($rows, static fn(array $row): bool => ($row['label'] ?? '') === 'Beat 2 Emotion Warning'));
    expect($warning)->toHaveCount(1)->and($warning[0]['kind'])->toBe('info')->and($warning[0]['tone'])->toBe('warning')
        ->and($warning[0]['value'])->toContain('Mira has no "Furious" emotion');
    // A speaker the catalogue does not bind shows no portrait: Neutral is all there is.
    expect(findDialogueRow($session, 'skits', 0, 'beat2Emotion')['options'])->toBe(['Neutral'])
        ->and(findDialogueRow($session, 'skits', 0, 'beat2Emotion')['value'])->toBe('Neutral');

    $session->applyDatabaseRecord('skits', 0, $momLine['key'], 'Worried');
    $session->saveDatabase('skits');
    expect((require $root . '/assets/Data/Skits/breakfast-banter.php')['beats'][1]['emotion'])->toBe('Worried');
});

it('chooses a text command\'s emotion for the speaker its name resolves to, following a speaker change', function () {
    [$root, , $session] = createDialogueProject();
    $command = findDialogueRow($session, 'common_events', 0, 'command0Emotion');
    // The dresser note is read by nobody, so it shows no portrait.
    expect($command['options'])->toBe(['Neutral']);
    $session->applyDatabaseRecord('common_events', 0, findDialogueRow($session, 'common_events', 0, 'command0Name')['key'], 'Mom');
    $command = findDialogueRow($session, 'common_events', 0, 'command0Emotion');
    expect($command['options'])->toBe(['Neutral', 'Worried']);
    $session->applyDatabaseRecord('common_events', 0, $command['key'], 'Worried');
    $session->saveDatabase('common_events');
    expect((require $root . '/assets/Events/dresser-note.php')[0])->toBe(['type' => 'text', 'name' => 'Mom', 'text' => 'A note is tucked under the lamp.', 'emotion' => 'Worried']);

    // The branch's own text command, in its frame, follows the same rule.
    $nested = findDialogueRow($session, 'common_events', 0, 'command0Emotion', [3, 'then']);
    expect($nested['options'])->toBe(['Neutral']);
});

it('chooses an NPC line\'s emotion for its own speaker, or the NPC\'s name when the line names none', function () {
    [, , $session] = createDialogueProject();
    $revision = $session->readMap('test-map')['revision'];
    $index = $session->createNpc('test-map', $revision, 1, 1, 'Mom')['index'];
    $emotion = array_find($session->readNpc('test-map', $index)['rows'], static fn(array $row): bool => str_ends_with((string) ($row['key']['field'] ?? ''), 'Emotion'));
    expect($emotion)->not->toBeNull()
        ->and($emotion['options'])->toBe(['Neutral', 'Worried']);
    $session->applyNpc('test-map', $session->readMap('test-map')['revision'], $index, $emotion['key'], 'Worried');
    $line = getSessionMap($session)->getNpcs()->toMapData()[$index]['dialogue'][0];
    expect($line['emotion'])->toBe('Worried');
});

it('offers each page of a map Dialogue event the emotions of its speaker, kept out of the event until chosen', function () {
    [$root] = createDialogueProject();
    $data = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($data, str_replace("  'events' => [\n", <<<'PHP'
  'events' => [
    'D' => [
      'class' => 'Ichiloto\Engine\Events\Triggers\DialogueEventTrigger',
      'data' => [
        'dialogue' => [
          ['name' => 'Mom', 'text' => 'Supper is ready.'],
          ['name' => 'Kaelion', 'text' => 'Coming!', 'emotion' => 'Sulky'],
        ],
      ],
    ],

PHP, (string) file_get_contents($data)));
    $session = EditorSession::open($root);
    $rows = $session->readInspector('test-map', 'D')['rows'];
    // Plain pages are one dialogue variant's lines, edited as an NPC's are.
    $first = array_find($rows, static fn(array $row): bool => ($row['label'] ?? '') === 'Dialogue variant 1 Line 1 Emotion');
    expect($first['kind'])->toBe('options')->and($first['options'])->toBe(['Neutral', 'Worried'])->and($first['value'])->toBe('Neutral');
    $warning = array_find($rows, static fn(array $row): bool => ($row['label'] ?? '') === 'Dialogue variant 1 Line 2 Emotion Warning');
    expect($warning['value'])->toContain('Kaelion has no "Sulky" emotion');
    expect(getSessionMap($session)->getEventDefinition('D')['data']['dialogue'][0])->not->toHaveKey('emotion');

    $session->applyInspector('test-map', $session->readMap('test-map')['revision'], $first['key'], 'Worried');
    expect(getSessionMap($session)->getEventDefinition('D')['data']['dialogue'][0])->toBe(['name' => 'Mom', 'text' => 'Supper is ready.', 'emotion' => 'Worried']);
});

it('reports dangling speaker names, artwork the game cannot draw, and line emotions their speaker lacks', function () {
    [$root] = createDialogueProject();
    unlink($root . '/assets/Graphics/Faces/MiraWorried.png');
    file_put_contents($root . '/assets/Data/Skits/breakfast-banter.php', <<<'PHP'
<?php

return [
  'id' => 'breakfast-banter',
  'title' => 'Breakfast Banter',
  'where' => 'happyville/town-center',
  'beats' => [
    ['actor' => 'Kaelion', 'text' => 'Again?', 'emotion' => 'Angry'],
    ['speaker' => 'Mom', 'text' => 'Eat first.', 'emotion' => 'Furious'],
  ],
];
PHP);
    $workspace = Ichiloto\Editor\ProjectWorkspace::fromProject($root);
    $speaker = $workspace->getRecordDatabase(DialogueSpeakerFields::CATEGORY)->requireNewRecord('Guard');
    $workspace->getRecordDatabase(DialogueSpeakerFields::CATEGORY)->getRecordByIndex($speaker)->set('identity', 'Nobody');

    $issues = new Ichiloto\Editor\Validation\DialoguePresentationValidator()->validate($workspace);
    $messages = array_map(static fn($issue): string => $issue->where . ' | ' . $issue->message, $issues);
    expect($messages)->toHaveCount(1)->and($messages[0])->toContain('speakers.Guard', 'Speaker name "Guard" shows "Nobody"');

    $workspace->getRecordDatabase(DialogueSpeakerFields::CATEGORY)->getRecordByIndex($speaker)->set('identity', 'Mira');
    $messages = array_map(static fn($issue): string => $issue->where . ' | ' . $issue->message,
        new Ichiloto\Editor\Validation\DialoguePresentationValidator()->validate($workspace));
    expect($messages)->toHaveCount(2)
        ->and($messages[0])->toContain('Mira Worried\'s dialogue portrait Graphics/Faces/MiraWorried.png cannot be drawn')
        ->and($messages[1])->toContain('assets/Data/Skits/breakfast-banter.php:beat 2', 'Mira has no "Furious" emotion');
});

it('chooses a cinematic text line\'s emotion as an event text command\'s', function () {
    $root = cutsceneProject();
    mkdir($root . '/assets/Data/Presentation', 0o777, true);
    file_put_contents($root . '/' . Ichiloto\Editor\Dialogue\DialogueCatalogFile::RELATIVE_PATH, syntheticDialogueCatalogue());
    $script = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    file_put_contents($script, str_replace("  ['type' => 'title_card'", "  ['type' => 'text', 'name' => 'Mom', 'text' => 'Look at them.'],\n  ['type' => 'title_card'", (string) file_get_contents($script)));
    $session = EditorSession::open($root);
    $category = Ichiloto\Editor\Cutscenes\CutsceneType::CINEMATIC->getRecordCategory();
    $emotion = findDialogueRow($session, $category, 0, 'command4Emotion', ['commands']);
    expect($emotion['options'])->toBe(['Neutral', 'Worried']);
    $session->applyDatabaseRecord($category, 0, $emotion['key'], 'Worried');
    $session->saveAll();
    expect((require $script)[4])->toBe(['type' => 'text', 'name' => 'Mom', 'text' => 'Look at them.', 'emotion' => 'Worried']);
});

it('warns of every line naming no emotion when its speaker has more than Neutral to show, in validation and beside the line', function () {
    $catalogue = str_replace("    'Mom' => 'Mira',\n", "    'Mom' => 'Mira',\n    'Guard' => 'Sentry',\n",
        str_replace("  'resources' => [\n", "  'resources' => [\n    'Sentry' => ['portrait' => 'Graphics/Faces/Mira.png'],\n", syntheticDialogueCatalogue()));
    [$root] = createDialogueProject($catalogue);
    file_put_contents($root . '/assets/Data/Skits/breakfast-banter.php', <<<'PHP'
<?php

return [
  'id' => 'breakfast-banter',
  'title' => 'Breakfast Banter',
  'where' => 'happyville/town-center',
  'beats' => [
    ['actor' => 'Kaelion', 'text' => 'Again?'],
    ['actor' => 'Kaelion', 'text' => 'Fine.', 'emotion' => 'Neutral'],
    ['speaker' => 'Mom', 'text' => 'Eat first.'],
    ['speaker' => 'Guard', 'text' => 'Move along.'],
    ['speaker' => 'A Stranger', 'text' => 'Hm.'],
    ['speaker' => '', 'text' => 'The kettle whistles.'],
  ],
];
PHP);
    $data = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($data, str_replace("  'events' => [\n", <<<'PHP'
  'events' => [
    'D' => [
      'class' => 'Ichiloto\Engine\Events\Triggers\DialogueEventTrigger',
      'data' => ['dialogue' => [['name' => 'Mom', 'text' => 'Supper is ready.']]],
    ],

PHP, (string) file_get_contents($data)));
    $events = $root . '/assets/Events/dresser-note.php';
    file_put_contents($events, str_replace("'name' => '',", "'name' => 'Kaelion',", (string) file_get_contents($events), $named));
    expect($named)->toBeGreaterThan(0);

    $issues = new Ichiloto\Editor\Validation\DialoguePresentationValidator()->validate(Ichiloto\Editor\ProjectWorkspace::fromProject($root));
    $messages = array_map(static fn($issue): string => $issue->severity->value . ' | ' . $issue->where . ' | ' . $issue->message, $issues);
    $skit = array_values(array_filter($messages, static fn(string $message): bool => str_contains($message, 'breakfast-banter.php')));
    // Kaelion and Mom have emotions to show; Neutral chosen, an identity with only Neutral, no identity and narration do not warn.
    expect($skit)->toHaveCount(2)
        ->and($skit[0])->toContain('warning | assets/Data/Skits/breakfast-banter.php:beat 1 "Again?"', 'Kaelion always shows Neutral', 'Neutral, Angry')
        ->and($skit[1])->toContain(':beat 3 "Eat first."', 'Mira always shows Neutral', 'Neutral, Worried');
    expect(array_filter($messages, static fn(string $message): bool => str_contains($message, ':event D page 1 "Supper is ready."')
        && str_contains($message, 'Mira always shows Neutral')))->toHaveCount(1);
    expect(array_filter($messages, static fn(string $message): bool => str_contains($message, 'dresser-note.php:text')
        && str_contains($message, 'Kaelion always shows Neutral')))->not->toBeEmpty();

    // The same warning sits beside the line in the record, and goes once an emotion is chosen.
    $session = EditorSession::open($root);
    $warnings = static fn(): array => array_values(array_map(static fn(array $row): string => (string) $row['label'],
        array_filter($session->readDatabaseRecord('skits', 0)['rows'], static fn(array $row): bool => ($row['tone'] ?? null) === 'warning')));
    expect($warnings())->toBe(['Beat 1 Emotion Warning', 'Beat 3 Emotion Warning']);
    $session->applyDatabaseRecord('skits', 0, findDialogueRow($session, 'skits', 0, 'beat0Emotion')['key'], 'Angry');
    expect($warnings())->toBe(['Beat 3 Emotion Warning']);
});
