<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ConversationFields;
use Ichiloto\Editor\Field\NpcBedFields;
use Ichiloto\Editor\Field\NpcInspector;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * An NPC's bed (`bed`) and a cinematic's field conversation
 * (`presentation.conversation`), as Engine bf49893 defines them: picked from
 * what the map and the cinematic hold, judged by the Engine's readers, edited
 * in their own source with undo and redo byte for byte, and validated against
 * the map's physical grid and the conversation's own speakers. Every project
 * here is a synthetic fixture.
 */

/**
 * The harbour fixture with a bed resource declaring an occupant attachment,
 * a bed object on its west wall, an inline lamp with no attachments, and the
 * keeper standing beside the bed.
 *
 * @return array{string, string} The project root and the map's data path.
 */
function bedProject(array $keeper = []): array
{
    $root = cutsceneProject();
    $mapPath = $root . '/assets/Maps/harbour/harbour.data.php';
    $map = require $mapPath;
    $map['worldObjects'] = [
        ['id' => 'cot', 'resource' => 'guest-bed', 'anchor' => ['x' => 0, 'y' => 5]],
        ['id' => 'lamp', 'anchor' => ['x' => 2, 'y' => 3], 'pivot' => ['x' => 0.5, 'y' => 1.0], 'sprites2d' => null],
    ];
    $map['npcs'] = [['id' => 'keeper', 'name' => 'Keeper', 'sprite' => 'K', 'x' => 1, 'y' => 5, ...$keeper]];
    file_put_contents($mapPath, "<?php\n\n// Harbour, authored by hand.\nreturn " . var_export($map, true) . ";\n");
    // Walls are solid, floor is clear.
    file_put_contents($root . '/assets/Maps/collisions.php', "<?php\n\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\n\n"
        . "return ['#' => CollisionType::SOLID, ' ' => CollisionType::NONE, '=' => CollisionType::NONE];\n");
    @mkdir($root . '/assets/Data/Presentation', 0o777, true);
    file_put_contents($root . '/assets/Data/Presentation/field.php', "<?php\nreturn " . var_export(['resources' => ['guest-bed' => [
        'name' => 'Synthetic bed', 'pivot' => ['x' => 0.5, 'y' => 1.0], 'sprites2d' => ['asset' => 'Graphics/bed.png'],
        'attachments' => [['id' => 'occupant', 'cell' => ['x' => 0, 'y' => 0], 'point' => ['x' => 0.5, 'y' => 0.5]],
            ['id' => 'headboard', 'cell' => ['x' => 0, 'y' => -1], 'point' => ['x' => 0.5, 'y' => 0.1]]],
    ]]], true) . ";\n");
    @mkdir($root . '/assets/Graphics/Characters', 0o777, true);
    writeTilesetTestPng($root . '/assets/Graphics/Characters/Keeper-Lying.png', 48, 48);
    writeTilesetTestPng($root . '/assets/Graphics/Characters/Keeper-Rising.png', 144, 48);

    return [$root, $mapPath];
}

/** One of the keeper's rows, by its field id. */
function bedRow(EditorSession $session, string $field): array
{
    return array_find($session->readNpc('harbour', 0)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === $field)
        ?? throw new RuntimeException('Missing NPC row ' . $field);
}

function applyBedRow(EditorSession $session, string $field, string $value): void
{
    $session->applyNpc('harbour', $session->readNpc('harbour', 0)['revision'], 0, bedRow($session, $field)['key'], $value);
}

/** @return list<string> The validation messages naming a rule's code. */
function bedIssueMessages(string $root, string $code): array
{
    return array_values(array_map(static fn($issue): string => $issue->message,
        array_filter(validateProject($root), static fn($issue): bool => $issue->code === $code)));
}

it('binds an NPC to a bed on its map, picked among bed objects and their attachments, with undo and redo byte for byte', function (): void {
    [$root, $mapPath] = bedProject();
    $original = (string) file_get_contents($mapPath);
    $session = EditorSession::open($root);

    // A bed is a world object whose resource names where its occupant lies; the inline lamp names none.
    expect(bedRow($session, NpcBedFields::OBJECT))->toMatchArray(['value' => '(not in a bed)', 'reference' => NpcBedFields::BED_OBJECTS])
        ->and(array_column($session->listReferences('harbour', NpcBedFields::BED_OBJECTS), 'value'))->toBe(['cot'])
        ->and(fn() => applyBedRow($session, NpcBedFields::OBJECT, 'lamp'))->toThrow(SessionRefusal::class)
        ->and($session->undo()['label'])->toBeNull();

    applyBedRow($session, NpcBedFields::OBJECT, 'cot');
    expect(bedRow($session, NpcBedFields::ATTACHMENT)['options'])->toBe(['', 'occupant', 'headboard'])
        ->and(bedRow($session, NpcBedFields::POSTURE)['options'] ?? null)->toBe(['lying', 'sitting', 'standing'])
        ->and(fn() => applyBedRow($session, NpcBedFields::ATTACHMENT, 'footboard'))->toThrow(SessionRefusal::class, 'not one this entry offers')
        // The Engine reads the occupied bed as one printable Terminal cell.
        ->and(fn() => applyBedRow($session, NpcBedFields::TERMINAL, '##'))->toThrow(SessionRefusal::class, 'one printable Terminal cell');

    applyBedRow($session, NpcBedFields::ATTACHMENT, 'occupant');
    applyBedRow($session, NpcBedFields::POSTURE, 'sitting');
    $session->saveMap('harbour');
    $edited = (string) file_get_contents($mapPath);

    expect($edited)->toContain('// Harbour, authored by hand.')
        ->and((require $mapPath)['npcs'][0])->toBe(['id' => 'keeper', 'name' => 'Keeper', 'sprite' => 'K', 'x' => 1, 'y' => 5,
            'bed' => ['object' => 'cot', 'attachment' => 'occupant', 'posture' => 'sitting']])
        ->and(bedIssueMessages($root, 'npc.bed'))->toBe([]);

    $session->undo();
    $session->undo();
    $session->undo();
    $session->saveMap('harbour');
    expect(file_get_contents($mapPath))->toBe($original);

    $session->redo();
    $session->redo();
    $session->redo();
    $session->saveMap('harbour');
    expect(file_get_contents($mapPath))->toBe($edited);

    // Clearing the bed object takes the NPC out of its bed.
    applyBedRow($session, NpcBedFields::OBJECT, '');
    expect($session->readNpc('harbour', 0)['rows'])->not->toContain(fn(array $row): bool => ($row['key']['field'] ?? null) === NpcBedFields::ATTACHMENT);
    $session->saveMap('harbour');
    expect((require $mapPath)['npcs'][0])->not->toHaveKey('bed');
});

it('drops an attachment chosen on another bed and keeps it when the same bed is picked again', function (): void {
    [$root, $mapPath] = bedProject(['bed' => ['object' => 'cot', 'attachment' => 'occupant']]);
    $mapData = require $mapPath;
    $mapData['worldObjects'][] = ['id' => 'bunk', 'resource' => 'guest-bed', 'anchor' => ['x' => 19, 'y' => 5]];
    file_put_contents($mapPath, "<?php\n\nreturn " . var_export($mapData, true) . ";\n");
    $session = EditorSession::open($root);

    applyBedRow($session, NpcBedFields::OBJECT, 'cot');
    expect(bedRow($session, NpcBedFields::ATTACHMENT)['value'])->toBe('occupant');
    applyBedRow($session, NpcBedFields::OBJECT, 'bunk');
    expect(bedRow($session, NpcBedFields::ATTACHMENT)['value'])->toBe('(choose an attachment)');
});

it('authors bed poses and a once-only getting-up clip in the graphical editor alone', function (): void {
    [$root, $mapPath] = bedProject(['bed' => ['object' => 'cot', 'attachment' => 'occupant']]);
    $session = EditorSession::open($root);
    $lying = 'bed.poses2d.lying.asset';
    $rising = 'bed.transitions2d.standing';

    expect(bedRow($session, $lying)['label'])->toContain('Lying Pose Image / Pose Sheet')
        ->and(bedRow($session, "$rising.asset")['label'])->toContain('Into Standing Clip Image / Pose Sheet');

    applyBedRow($session, $lying, 'Graphics/Characters/Keeper-Lying.png');
    applyBedRow($session, "$rising.asset", 'Graphics/Characters/Keeper-Rising.png');
    applyBedRow($session, "$rising.animation.columns", '3');
    applyBedRow($session, "$rising.animation.frames", '0, 1, 2');
    // A clip plays once on the way into its posture: the Engine refuses one that loops.
    expect(fn() => applyBedRow($session, "$rising.animation.loop", 'true'))->toThrow(SessionRefusal::class, 'do not loop');
    $session->saveMap('harbour');

    expect((require $mapPath)['npcs'][0]['bed'])->toBe(['object' => 'cot', 'attachment' => 'occupant',
        'poses2d' => ['lying' => ['asset' => 'Graphics/Characters/Keeper-Lying.png']],
        'transitions2d' => ['standing' => ['asset' => 'Graphics/Characters/Keeper-Rising.png',
            'animation' => ['columns' => 3, 'loop' => false, 'frames' => [0, 1, 2]]]]]);

    // The Terminal Inspector edits the bed and leaves graphical poses to the graphical editor.
    $workspace = ProjectWorkspace::fromProject($root);
    $map = array_find($workspace->maps, static fn($map): bool => $map->mapId === 'harbour');
    $terminal = new NpcInspector($map, references: new \Ichiloto\Editor\Database\ReferenceCatalog($workspace, $map));
    $rows = $terminal->getFields(0);
    $fields = array_column($rows, 'field');
    expect($fields)->toContain(NpcBedFields::OBJECT, NpcBedFields::ATTACHMENT, NpcBedFields::POSTURE, NpcBedFields::TERMINAL)
        ->and(array_filter($fields, static fn(mixed $field): bool => is_string($field) && str_contains($field, '2d.')))->toBe([])
        ->and(array_column($rows, 'label'))->toContain('Bed')
        ->and(array_find($rows, static fn(array $row): bool => ($row['field'] ?? null) === NpcBedFields::ATTACHMENT)['options'])
        ->toBe(['', 'occupant', 'headboard']);

    $authoring = new \Ichiloto\Editor\Field\NpcAuthoring($workspace);
    $posture = array_find($rows, static fn(array $row): bool => ($row['field'] ?? null) === NpcBedFields::POSTURE);
    $authoring->applyField($terminal, 0, [], $posture, 'standing');
    $map->save();
    expect((require $mapPath)['npcs'][0]['bed']['posture'])->toBe('standing')
        ->and((require $mapPath)['npcs'][0]['bed']['poses2d'])->toBe(['lying' => ['asset' => 'Graphics/Characters/Keeper-Lying.png']]);
});

it('reports a bed bound to a missing object or to an object that is not a bed, on a map without declared occupancy', function (): void {
    [$root, $mapPath] = bedProject(['bed' => ['object' => 'cot', 'attachment' => 'occupant']]);
    // The legacy map's walls are its solid cells; the bed lies on one and its exit is clear floor.
    expect(bedIssueMessages($root, 'npc.bed'))->toBe([])
        // The game reads the bed, so it is not a preserved unknown field.
        ->and(array_map(static fn($issue): string => $issue->message, validateProject($root)))
        ->not->toContain('It carries the field bed, which the game does not read.');

    $mapData = require $mapPath;
    $mapData['npcs'][0]['bed'] = ['object' => 'hammock', 'attachment' => 'occupant'];
    $mapData['npcs'][] = ['id' => 'porter', 'name' => 'Porter', 'sprite' => 'P', 'x' => 3, 'y' => 3,
        'bed' => ['object' => 'lamp', 'attachment' => 'occupant']];
    file_put_contents($mapPath, "<?php\n\nreturn " . var_export($mapData, true) . ";\n");

    expect(bedIssueMessages($root, 'npc.bed'))->toEqualCanonicalizing([
        "NPC bed object 'hammock' is missing.",
        "NPC bed 'lamp' needs attachment 'occupant'.",
    ]);
});

/**
 * The harbour cinematic with a conversation between the player, the keeper
 * and a staged boat, and text lines that select them.
 *
 * @param list<array<string, mixed>> $commands
 * @return array{string, string, string} The project root, the data path and the script path.
 */
function conversationProject(array $commands = []): array
{
    $root = cutsceneProject();
    $folder = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns';
    $data = (string) file_get_contents($folder . '/harbour-lanterns.data.php');
    file_put_contents($folder . '/harbour-lanterns.data.php', str_replace(
        // Authored skipping cannot prove a registered command safe, so the Engine runs these cinematics to the end.
        ["'skip' => ['policy' => 'authored'],", "'presentation' => ['initial' => 'hidden'],"],
        ["'skip' => ['policy' => 'forbidden'],", "'presentation' => ['initial' => 'hidden', 'conversation' => [\n"
        . "    // Who speaks.\n"
        . "    'speakers' => ['hero' => ['kind' => 'player'], 'keeper' => ['kind' => 'npc', 'id' => 'keeper']],\n"
        . "    'groups' => ['quay' => ['speakers' => ['hero', 'keeper']], 'alone' => ['speakers' => []]],\n"
        . "    'initialGroup' => 'quay',\n  ]],"],
        $data,
    ));
    $script = $folder . '/harbour-lanterns.script.php';
    file_put_contents($script, "<?php\n\n// Lines by hand.\nreturn " . var_export($commands === [] ? [
        ['type' => 'text', 'name' => 'Keeper', 'speaker' => 'keeper', 'addressee' => 'hero', 'text' => 'Evening.'],
        ['type' => 'select_conversation_group', 'group' => 'alone'],
    ] : $commands, true) . ";\n");

    return [$root, $folder . '/harbour-lanterns.data.php', $script];
}

/** One root or list row of the cinematic, by its field id. */
function conversationRow(EditorSession $session, string $field, array $frame = []): array
{
    return array_find($session->readDatabaseRecord('cutscenes/cinematic', 0, $frame)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field)
        ?? throw new RuntimeException('Missing cinematic row ' . $field);
}

function applyConversationRow(EditorSession $session, string $field, string $value, array $frame = []): void
{
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, conversationRow($session, $field, $frame)['key'], $value);
}

it('authors conversation speakers, groups and the initial group in place, with undo and redo byte for byte', function (): void {
    [$root, $dataPath, $scriptPath] = conversationProject();
    $original = (string) file_get_contents($dataPath);
    $session = EditorSession::open($root);

    expect(conversationRow($session, ConversationFields::INITIAL_GROUP_KEY))->toMatchArray(['value' => 'quay', 'reference' => ConversationFields::GROUPS])
        ->and(conversationRow($session, 'conversationSpeaker1Kind')['options'])->toBe(['player', 'npc', 'staged_actor'])
        ->and(conversationRow($session, 'conversationSpeaker1Id'))->toMatchArray(['value' => 'keeper', 'reference' => 'map_npcs'])
        ->and(conversationRow($session, 'conversationGroup0Speakers'))->toMatchArray(['reference' => ConversationFields::SPEAKERS])
        ->and(array_column($session->listReferences(null, ConversationFields::SPEAKERS, ['category' => 'cutscenes/cinematic', 'index' => 0]), 'value'))
        ->toBe(['hero', 'keeper']);

    // A staged boat joins as a third speaker, added at the head of the list and picked from the cast.
    $session->addDatabaseItem('cutscenes/cinematic', 0, conversationRow($session, ConversationFields::SPEAKER_PREFIX . 'List')['key']);
    applyConversationRow($session, 'conversationSpeaker0Key', 'boatman');
    applyConversationRow($session, 'conversationSpeaker0Kind', 'staged_actor');
    expect(conversationRow($session, 'conversationSpeaker0Id'))->toMatchArray(['reference' => 'cinematic_cast'])
        ->and(array_column($session->listReferences(null, 'cinematic_cast', ['category' => 'cutscenes/cinematic', 'index' => 0]), 'value'))
        ->toBe(['boat-east', 'boat-west']);
    applyConversationRow($session, 'conversationSpeaker0Id', 'boat-east');
    applyConversationRow($session, 'conversationGroup1Speakers', 'boatman');
    applyConversationRow($session, ConversationFields::INITIAL_GROUP_KEY, 'alone');
    $session->saveDatabase('cutscenes/cinematic');
    $edited = (string) file_get_contents($dataPath);

    expect($edited)->toContain('// Harbour Lanterns: an original test cinematic.', '// Who speaks.')
        ->and((require $dataPath)['presentation']['conversation'])->toBe([
            'speakers' => ['boatman' => ['kind' => 'staged_actor', 'id' => 'boat-east'], 'hero' => ['kind' => 'player'],
                'keeper' => ['kind' => 'npc', 'id' => 'keeper']],
            'groups' => ['quay' => ['speakers' => ['hero', 'keeper']], 'alone' => ['speakers' => ['boatman']]],
            'initialGroup' => 'alone',
        ]);

    for ($step = 0; $step < 6; $step++) { $session->undo(); }
    $session->saveDatabase('cutscenes/cinematic');
    expect(file_get_contents($dataPath))->toBe($original);
    for ($step = 0; $step < 6; $step++) { $session->redo(); }
    $session->saveDatabase('cutscenes/cinematic');
    expect(file_get_contents($dataPath))->toBe($edited)
        ->and(file_get_contents($scriptPath))->toContain('// Lines by hand.');
});

it('chooses default addressees for the conversation among the bound speakers and keeps a group\'s own as authored', function (): void {
    [$root, $dataPath] = conversationProject();
    file_put_contents($dataPath, str_replace("'quay' => ['speakers' => ['hero', 'keeper']]",
        "'quay' => ['speakers' => ['hero', 'keeper'], 'addressees' => ['keeper' => 'hero']]", (string) file_get_contents($dataPath)));
    $session = EditorSession::open($root);

    $session->addDatabaseItem('cutscenes/cinematic', 0, conversationRow($session, ConversationFields::ADDRESSEE_PREFIX . 'List')['key']);
    expect(conversationRow($session, 'conversationAddressee0Speaker'))->toMatchArray(['reference' => ConversationFields::SPEAKERS])
        ->and(fn() => applyConversationRow($session, 'conversationAddressee0Speaker', 'stranger'))->toThrow(SessionRefusal::class);
    applyConversationRow($session, 'conversationAddressee0Speaker', 'hero');
    applyConversationRow($session, 'conversationAddressee0Addressee', 'keeper');
    $session->saveDatabase('cutscenes/cinematic');

    expect((require $dataPath)['presentation']['conversation'])->toMatchArray([
        'addressees' => ['hero' => 'keeper'],
        'groups' => ['quay' => ['speakers' => ['hero', 'keeper'], 'addressees' => ['keeper' => 'hero']], 'alone' => ['speakers' => []]],
    ]);
    // A group's own addressees have no rows of their own.
    expect(array_filter(array_column(array_column($session->readDatabaseRecord('cutscenes/cinematic', 0)['rows'], 'key'), 'field'),
        static fn(mixed $field): bool => is_string($field) && str_starts_with($field, 'conversationGroup0Addressee')))->toBe([]);
});

it('selects a line\'s speaker and addressee among the bound speakers and refuses others before writing', function (): void {
    [$root, , $scriptPath] = conversationProject();
    $original = (string) file_get_contents($scriptPath);
    $session = EditorSession::open($root);
    $frame = ['commands'];
    $speaker = array_find($session->readDatabaseRecord('cutscenes/cinematic', 0, $frame)['rows'],
        static fn(array $row): bool => ($row['entry'] ?? []) === [0] && ($row['name'] ?? '') === 'Conversation Speaker');

    expect($speaker)->toMatchArray(['value' => 'keeper', 'reference' => ConversationFields::SPEAKERS])
        ->and(fn() => $session->applyDatabaseRecord('cutscenes/cinematic', 0, $speaker['key'], 'stranger'))
        ->toThrow(SessionRefusal::class, 'is not one this script can name here')
        ->and($session->undo()['label'])->toBeNull();
    $session->saveDatabase('cutscenes/cinematic');
    expect(file_get_contents($scriptPath))->toBe($original);

    // A reusable script cannot know which cinematic runs it, so its lines offer no conversation keys.
    expect(array_column(\Ichiloto\Editor\Database\CutsceneSchemas::cinematicCommandList('commands')->variants['text'], 'key'))
        ->not->toContain('speaker', 'addressee');
});

it('renames a speaker or group wherever the cinematic names it and refuses removing one still named', function (): void {
    [$root, $dataPath, $scriptPath] = conversationProject();
    $session = EditorSession::open($root);

    applyConversationRow($session, 'conversationSpeaker0Key', 'lead');
    applyConversationRow($session, 'conversationGroup1Id', 'solo');
    expect(fn() => $session->removeDatabaseItem('cutscenes/cinematic', 0, conversationRow($session, 'conversationSpeaker1Key')['key']))
        ->toThrow(SessionRefusal::class, 'Speaker "keeper" is still named by group "quay", a text line in commands')
        ->and(fn() => $session->removeDatabaseItem('cutscenes/cinematic', 0, conversationRow($session, 'conversationGroup0Id')['key']))
        ->toThrow(SessionRefusal::class, 'Group "quay" is still selected by the initial group');
    $session->saveDatabase('cutscenes/cinematic');

    expect((require $dataPath)['presentation']['conversation'])->toBe([
        'speakers' => ['lead' => ['kind' => 'player'], 'keeper' => ['kind' => 'npc', 'id' => 'keeper']],
        'groups' => ['quay' => ['speakers' => ['lead', 'keeper']], 'solo' => ['speakers' => []]],
        'initialGroup' => 'quay',
    ])->and(require $scriptPath)->toBe([
        ['type' => 'text', 'name' => 'Keeper', 'speaker' => 'keeper', 'addressee' => 'lead', 'text' => 'Evening.'],
        ['type' => 'select_conversation_group', 'group' => 'solo'],
    ]);
});

it('reports groups naming unknown speakers and speakers or lines the cinematic cannot resolve', function (): void {
    [$root, $dataPath] = conversationProject([
        ['type' => 'text', 'name' => 'Keeper', 'speaker' => 'ghost', 'text' => 'Evening.'],
    ]);
    $messages = static fn(): array => array_values(array_map(static fn($issue): string => $issue->message,
        array_filter(validateProject($root), static fn($issue): bool => str_starts_with($issue->where, 'cinematic harbour-lanterns'))));
    $hints = static fn(): array => array_values(array_map(static fn($issue): string => $issue->hint,
        array_filter(validateProject($root), static fn($issue): bool => str_starts_with($issue->where, 'cinematic harbour-lanterns'))));

    expect($messages())->toContain('A text line selects speaker "ghost", which its conversation does not bind.');

    $data = (string) file_get_contents($dataPath);
    file_put_contents($dataPath, str_replace(
        ["'keeper' => ['kind' => 'npc', 'id' => 'keeper']]", "'quay' => ['speakers' => ['hero', 'keeper']]"],
        ["'keeper' => ['kind' => 'npc', 'id' => 'stranger'], 'boatman' => ['kind' => 'staged_actor', 'id' => 'ferry']]",
            "'quay' => ['speakers' => ['hero', 'keeper']], 'pier' => ['speakers' => ['hero', 'pilot']]"],
        $data,
    ));
    // The Engine refuses the cinematic; the hint says which group names whom.
    expect(implode("\n", $hints()))->toContain('Group "pier" names "pilot", which no conversation speaker binds.');

    file_put_contents($dataPath, str_replace(", 'pier' => ['speakers' => ['hero', 'pilot']]", '', (string) file_get_contents($dataPath)));
    expect($messages())->toContain(
        'Its conversation speaker "keeper" is NPC "stranger", which this cinematic cannot reach.',
        'Its conversation speaker "boatman" is staged actor "ferry", which this cinematic cannot reach.',
    );
});

it('shows the conversation under its own heading in the Terminal Cutscenes pane', function (): void {
    [$root] = conversationProject();
    $fields = callEditorMethod(cutscenesEditor($root), 'getDatabaseSettingsFields');
    $labels = array_map(static fn(array $field): string => (string) $field['label'], $fields);
    $ids = array_map(static fn(array $field): string => (string) ($field['field'] ?? ''), $fields);
    $heading = array_search('Conversation', $labels, true);
    $cast = array_search('Cast', $labels, true);
    $section = array_slice($ids, (int) $heading + 1, (int) $cast - (int) $heading - 1);

    expect($heading)->not->toBeFalse()
        ->and($cast)->toBeGreaterThan($heading)
        ->and($section)->toContain(ConversationFields::INITIAL_GROUP_KEY, 'conversationSpeakerList', 'conversationSpeaker0Kind',
            'conversationGroupList', 'conversationGroup0Speakers')
        ->and(array_slice($ids, (int) $cast))->not->toContain('conversationSpeaker0Kind');
});
