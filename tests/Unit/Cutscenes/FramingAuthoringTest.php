<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneLaneOverview;
use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Cutscenes\CutsceneOutline;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\CutsceneSchemas;
use Ichiloto\Editor\Database\FramingFields;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Database\RecordSubList;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Events\Interpreter\EventCommandRequirements;
use Ichiloto\Engine\Events\Interpreter\EventExecutionScope;
use Ichiloto\Engine\Events\Interpreter\EventScriptContext;

/**
 * A cinematic's required-subject framing, as the Engine's
 * `framingRequirements` contract defines it: the subjects a cinematic binds
 * under stable keys (`presentation.framing.subjects`) and the `framing`
 * command that requires some of them, or the player, an NPC or a staged
 * actor directly, while its body runs. Both editors author it through the
 * session's record rows. The Engine judges what is saved, edits stay in the
 * author's own source with undo and redo byte for byte, and a reusable event
 * script offers direct subjects alone. Every project here is a synthetic
 * fixture.
 */

const FRAMING_CINEMATICS = 'cutscenes/cinematic';
const FRAMING_SCRIPTS = 'common_events';

/** The index of the `rescue` reusable event script. */
function findRescueScript(EditorSession $session): int
{
    $index = array_search('rescue', $session->listDatabaseRecords(FRAMING_SCRIPTS)['records'], true);

    return is_int($index) ? $index : throw new RuntimeException('The rescue script is not listed.');
}

/** Undoes or redoes a number of steps and saves the category, so the file shows where the history stands. */
function replayFramingHistory(EditorSession $session, string $category, int $steps, bool $redo = false): void
{
    for ($step = 0; $step < $steps; $step++) {
        $redo ? $session->redo() : $session->undo();
    }
    $session->saveDatabase($category);
}

it('reads the field, the command and the subject kinds it authors from the Engine\'s contract', function (): void {
    $contract = CinematicCommandSchema::export()['framingRequirements'];

    expect(FramingFields::getBindingsKey())->toBe($contract['definitionField'] . '.' . $contract['bindingField'])
        ->and(FramingFields::getCommandType())->toBe($contract['command'])
        ->and([FramingFields::getSelectionField(), ...CinematicCommandSchema::export()['nestedBlockShapes'][$contract['command']]])
        ->toEqualCanonicalizing($contract['commandFields'])
        ->and(CinematicCommandSchema::export()['blockTypes'])->toContain($contract['command'])
        ->and(FramingFields::getBodyLane())->toBe($contract['bodyLane']);

    // Each kind's id row is the field its reference source declares; a kind that declares none has no id row.
    $subjects = FramingFields::getLists()[0];
    foreach ($contract['referenceSources'] as $kind => $source) {
        $keys = array_column($subjects->fieldsFor(['key' => 'subject', 'kind' => $kind]), 'key');
        expect($keys)->toBe(['key', 'kind', ...($source['idField'] === null ? [] : [$source['idField']])]);
    }
    expect(array_find($subjects->fieldsFor(['key' => 'subject', 'kind' => 'player']), static fn($field): bool => $field->key === 'kind')->options)
        ->toBe(array_keys($contract['referenceSources']));
});

it('offers the framing command where the Engine says it can run: a cinematic\'s script and a reusable script, never a field or battle script', function (): void {
    $offers = static fn(RecordSubList $list): array => $list->fields[0]->options;

    expect($offers(CutsceneSchemas::cinematics()->commandLists['commands']))->toContain('framing')
        ->and($offers(RecordSchemaCatalog::forKey('common_events')->subList))->toContain('framing')
        // A map event's or an NPC's script runs on the field, where nothing can be framed.
        ->and($offers(RecordSchemaCatalog::eventCommandList('script')))->not->toContain('framing')->toContain('camera', 'sequence')
        ->and($offers(RecordSchemaCatalog::eventCommandList('commands', scope: EventExecutionScope::BATTLE)))->not->toContain('framing');
    foreach (CinematicCommandSchema::COMMAND_TYPES as $type) {
        expect(in_array($type, $offers(RecordSchemaCatalog::forKey('common_events')->subList), true))
            ->toBe(EventCommandRequirements::isAvailable($type, EventScriptContext::REUSABLE));
    }
});

it('binds framing subjects under stable keys, each picked where every subject is, with undo and redo byte for byte', function (): void {
    $root = cutsceneProject();
    [$dataPath] = getHarbourPaths($root);
    $original = (string) file_get_contents($dataPath);
    $session = EditorSession::open($root);
    $row = static fn(string $field): array => getRecordRow($session, FRAMING_CINEMATICS, 0, $field);
    $set = static fn(string $field, string $value): array => setRecordRow($session, FRAMING_CINEMATICS, 0, $field, $value);
    $add = static fn(): array => $session->addDatabaseItem(FRAMING_CINEMATICS, 0, $row(FramingFields::SUBJECT_PREFIX . 'List')['key']);

    // A cinematic that binds none shows the empty list, where the first is added.
    expect($row('framingSubjectList'))->toMatchArray(['value' => '0', 'label' => 'Framing Subjects']);

    // A new subject is the player; an NPC is picked among the NPCs of the maps the cinematic runs on.
    $add();
    expect($row('framingSubject0Kind'))->toMatchArray(['value' => 'player', 'options' => CinematicCommandSchema::export()['framingRequirements']['subjectKinds']]);
    $set('framingSubject0Key', 'keeper');
    $set('framingSubject0Kind', 'npc');
    expect($row('framingSubject0Id'))->toMatchArray(['reference' => 'map_npcs'])
        ->and(fn() => $set('framingSubject0Id', 'stranger'))->toThrow(SessionRefusal::class, 'is not one this script can name here');
    $set('framingSubject0Id', 'keeper');

    // A staged actor is picked from the cast; a second subject is added at the head of the list.
    $add();
    $set('framingSubject0Key', 'boat');
    $set('framingSubject0Kind', 'staged_actor');
    expect($row('framingSubject0Id'))->toMatchArray(['reference' => 'cinematic_cast'])
        ->and(array_column($session->listReferences(null, 'cinematic_cast', ['category' => FRAMING_CINEMATICS, 'index' => 0]), 'value'))
        ->toBe(['boat-east', 'boat-west']);
    $set('framingSubject0Id', 'boat-east');
    $add();
    $set('framingSubject0Key', 'lead');
    // Two subjects never share a key.
    expect(fn() => $set('framingSubject0Key', 'boat'))->toThrow(SessionRefusal::class)
        ->and(array_column($session->listReferences(null, FramingFields::SUBJECTS, ['category' => FRAMING_CINEMATICS, 'index' => 0]), 'value'))
        ->toBe(['lead', 'boat', 'keeper']);
    $session->saveDatabase(FRAMING_CINEMATICS);
    $edited = (string) file_get_contents($dataPath);

    expect($edited)->toContain('// Harbour Lanterns: an original test cinematic.')
        ->and((require $dataPath)['presentation'])->toBe(['initial' => 'hidden', 'framing' => ['subjects' => [
            'lead' => ['kind' => 'player'],
            'boat' => ['kind' => 'staged_actor', 'id' => 'boat-east'],
            'keeper' => ['kind' => 'npc', 'id' => 'keeper'],
        ]]]);

    replayFramingHistory($session, FRAMING_CINEMATICS, 11);
    expect(file_get_contents($dataPath))->toBe($original);
    replayFramingHistory($session, FRAMING_CINEMATICS, 11, redo: true);
    expect(file_get_contents($dataPath))->toBe($edited);

    // Removing a subject no phase selects takes its binding out of the file.
    $session->removeDatabaseItem(FRAMING_CINEMATICS, 0, $row('framingSubject1Key')['key']);
    $session->saveDatabase(FRAMING_CINEMATICS);
    expect(array_keys((require $dataPath)['presentation']['framing']['subjects']))->toBe(['lead', 'keeper']);
});

it('creates a framing command in a cinematic\'s own script, requiring bound keys or direct subjects, with its body as a frame', function (): void {
    [$root, , $scriptPath] = framedHarbourProject(harbourCinematicScript());
    $original = (string) file_get_contents($scriptPath);
    $session = EditorSession::open($root);
    $frame = ['commands'];
    $row = static fn(string $field, array $in = ['commands']): array => getRecordRow($session, FRAMING_CINEMATICS, 0, $field, $in);
    $set = static fn(string $field, string $value, array $in = ['commands']): array => setRecordRow($session, FRAMING_CINEMATICS, 0, $field, $value, $in);

    // A framing phase is a command type like any block: its body opens as a frame and its subjects are a list.
    $session->addDatabaseItem(FRAMING_CINEMATICS, 0, ['frame' => $frame]);
    expect($row('command5Type')['options'])->toContain('framing');
    $set('command5Type', 'framing');
    expect($row('command5Commands'))->toMatchArray(['name' => 'Framing Commands', 'value' => '0', 'frame' => ['commands', 5, 'commands']])
        ->and($row('command5SubjectList'))->toMatchArray(['name' => 'Required Subjects', 'value' => '0']);

    // A new subject is the player, directly: added from the list's heading, or beneath the command from any of its
    // rows. Clearing its kind selects one of the cinematic's bound keys instead.
    expect($row('command5SubjectList'))->toMatchArray(['item' => true, 'itemNoun' => 'required subject'])
        ->and($row('command5Type'))->toMatchArray(['childNoun' => 'required subject']);
    $session->addDatabaseItem(FRAMING_CINEMATICS, 0, $row('command5SubjectList')['key']);
    $session->addDatabaseItem(FRAMING_CINEMATICS, 0, $row('command5Type')['key'], true);
    expect($row('command5Subject0Kind'))->toMatchArray(['value' => 'player', 'options' => ['', 'player', 'npc', 'staged_actor']]);
    $set('command5Subject1Kind', '');
    expect($row('command5Subject1Kind')['value'])->toBe('(a bound framing subject)')
        ->and($row('command5Subject1Key'))->toMatchArray(['reference' => FramingFields::SUBJECTS])
        ->and(fn() => $set('command5Subject1Key', 'ghost'))->toThrow(SessionRefusal::class, 'is not one this script can name here');
    $set('command5Subject1Key', 'keeper');

    // A third names a staged actor directly, picked from the cast.
    $session->addDatabaseItem(FRAMING_CINEMATICS, 0, $row('command5Subject1Key')['key']);
    $set('command5Subject2Kind', 'staged_actor');
    expect($row('command5Subject2Id'))->toMatchArray(['reference' => 'cinematic_cast']);
    $set('command5Subject2Id', 'boat-west');

    // The body holds the script's own commands, a nested phase among them.
    $body = ['commands', 5, 'commands'];
    expect($session->readDatabaseRecord(FRAMING_CINEMATICS, 0, $body)['frameLabel'])->toBe('Commands › Framing 6 › Commands');
    $session->addDatabaseItem(FRAMING_CINEMATICS, 0, ['frame' => $body]);
    $session->addDatabaseItem(FRAMING_CINEMATICS, 0, ['frame' => $body]);
    $set('command1Type', 'framing', $body);
    $session->addDatabaseItem(FRAMING_CINEMATICS, 0, $row('command1SubjectList', $body)['key']);
    $session->addDatabaseItem(FRAMING_CINEMATICS, 0, ['frame' => [...$body, 1, 'commands']]);
    $session->saveDatabase(FRAMING_CINEMATICS);
    $edited = (string) file_get_contents($scriptPath);

    expect((require $scriptPath)[5])->toBe(['type' => 'framing',
        'subjects' => [['kind' => 'player'], 'keeper', ['kind' => 'staged_actor', 'id' => 'boat-west']],
        'commands' => [
            ['type' => 'wait', 'seconds' => 0.5],
            ['type' => 'framing', 'subjects' => [['kind' => 'player']], 'commands' => [['type' => 'wait', 'seconds' => 0.5]]],
        ]])
        ->and(array_slice(require $scriptPath, 0, 5))->toBe(array_slice((static fn(): array => eval('?>' . harbourCinematicScript()))(), 0, 5));

    replayFramingHistory($session, FRAMING_CINEMATICS, 14);
    expect(file_get_contents($scriptPath))->toBe($original);
    replayFramingHistory($session, FRAMING_CINEMATICS, 14, redo: true);
    expect(file_get_contents($scriptPath))->toBe($edited);
});

it('renames a bound key in every framing command that selects it and refuses removing one still selected', function (): void {
    [$root, $dataPath, $scriptPath] = framedHarbourProject();
    $session = EditorSession::open($root);

    setRecordRow($session, FRAMING_CINEMATICS, 0, 'framingSubject1Key', 'warden');
    expect(fn() => $session->removeDatabaseItem(FRAMING_CINEMATICS, 0, getRecordRow($session, FRAMING_CINEMATICS, 0, 'framingSubject0Key')['key']))
        ->toThrow(SessionRefusal::class, 'Framing subject "lead" is still selected by a framing command in commands');
    $session->saveDatabase(FRAMING_CINEMATICS);

    // The script's closures, loop and comments stay; only the key it names changed.
    expect(array_keys((require $dataPath)['presentation']['framing']['subjects']))->toBe(['lead', 'warden'])
        ->and(file_get_contents($dataPath))->toContain('// Who a phase keeps in view.')
        ->and(file_get_contents($scriptPath))->toBe(str_replace("'subjects' => ['keeper', ", "'subjects' => ['warden', ", framedHarbourScript()));
});

it('edits a framed script built with closures and spreads in place and refuses what would flatten them, with undo and redo byte for byte', function (): void {
    [$root, , $scriptPath] = framedHarbourProject();
    $session = EditorSession::open($root);
    $phase = ['commands'];
    $body = ['commands', 1, 'commands'];

    // The phase's own plain data is the author's to edit: its subjects, and the plain commands of its body.
    setRecordRow($session, FRAMING_CINEMATICS, 0, 'command1Subject1Id', 'boat-west', $phase);
    $session->addDatabaseItem(FRAMING_CINEMATICS, 0, getRecordRow($session, FRAMING_CINEMATICS, 0, 'command1Subject1Kind', $phase)['key']);
    setRecordRow($session, FRAMING_CINEMATICS, 0, 'command1Subject2Kind', '', $phase);
    setRecordRow($session, FRAMING_CINEMATICS, 0, 'command1Subject2Key', 'lead', $phase);
    setRecordRow($session, FRAMING_CINEMATICS, 0, 'command0Seconds', '0.4', [...$body, 3, 'commands']);

    // What a helper call returns, and what the loop's spread adds, stay the code's.
    expect(fn() => setRecordRow($session, FRAMING_CINEMATICS, 0, 'command0Text', 'Rewritten.', $body))
        ->toThrow(SessionRefusal::class, 'written as an expression the editor cannot rewrite')
        ->and(fn() => setRecordRow($session, FRAMING_CINEMATICS, 0, 'command1ActorId', 'boat-west', $body))
        ->toThrow(SessionRefusal::class, 'spread');
    $session->saveDatabase(FRAMING_CINEMATICS);
    $edited = (string) file_get_contents($scriptPath);

    expect($edited)->toBe(str_replace(
        ["'subjects' => ['keeper', ['kind' => 'staged_actor', 'id' => 'boat-east']]", "['type' => 'wait', 'seconds' => 0.2]"],
        ["'subjects' => ['keeper', ['kind' => 'staged_actor', 'id' => 'boat-west'], 'lead']", "['type' => 'wait', 'seconds' => 0.4]"],
        framedHarbourScript(),
    ));

    replayFramingHistory($session, FRAMING_CINEMATICS, 5);
    expect(file_get_contents($scriptPath))->toBe(framedHarbourScript());
    replayFramingHistory($session, FRAMING_CINEMATICS, 5, redo: true);
    expect(file_get_contents($scriptPath))->toBe($edited);
});

it('offers a reusable event script direct subjects alone, its own staged actors among them, in blocks that hold its staging commands', function (): void {
    [$root, $path] = framedRescueProject();
    $session = EditorSession::open($root);
    $index = findRescueScript($session);
    $then = [0, 'then'];
    $body = [0, 'then', 1, 'commands'];
    $owner = ['category' => FRAMING_SCRIPTS, 'index' => $index];

    // A reusable script cannot know which cinematic runs it: a subject is the player, an NPC or a staged actor.
    expect(getRecordRow($session, FRAMING_SCRIPTS, $index, 'command1Subject0Kind', $then))
        ->toMatchArray(['value' => 'staged_actor', 'options' => ['player', 'npc', 'staged_actor']])
        ->and(getRecordRow($session, FRAMING_SCRIPTS, $index, 'command1Subject0Id', $then))->toMatchArray(['value' => 'crate', 'reference' => 'cinematic_cast'])
        // It names the actors it stages itself, in any block, and those of the cinematic that runs it.
        ->and(array_column($session->listReferences(null, 'cinematic_cast', $owner), 'value'))->toBe(['crate', 'helper', 'boat-east', 'boat-west'])
        // The cinematic names the actors its script and the scripts it runs stage.
        ->and(array_column($session->listReferences(null, 'cinematic_cast', ['category' => FRAMING_CINEMATICS, 'index' => 0]), 'value'))
        ->toBe(['boat-east', 'boat-west', 'crate', 'helper'])
        ->and($session->listReferences(null, FramingFields::SUBJECTS, $owner))->toBe([]);

    // The phase's body is a frame of the script's own vocabulary: staging commands keep their rows there.
    $view = $session->readDatabaseRecord(FRAMING_SCRIPTS, $index, $body);
    expect($view['frameLabel'])->toBe('Commands › Branch 1 › Then › Framing 2 › Commands')
        ->and(array_column(array_column($view['rows'], 'key'), 'field'))->toContain('command0Text', 'command1Id', 'command1Sprite', 'command2Subject');
    $session->addDatabaseItem(FRAMING_SCRIPTS, $index, ['frame' => $body]);
    setRecordRow($session, FRAMING_SCRIPTS, $index, 'command4Type', 'camera', $body);
    setRecordRow($session, FRAMING_SCRIPTS, $index, 'command4Operation', 'detach', $body);
    $session->addDatabaseItem(FRAMING_SCRIPTS, $index, ['frame' => $body]);
    setRecordRow($session, FRAMING_SCRIPTS, $index, 'command5Type', 'framing', $body);
    $session->addDatabaseItem(FRAMING_SCRIPTS, $index, getRecordRow($session, FRAMING_SCRIPTS, $index, 'command5SubjectList', $body)['key']);
    setRecordRow($session, FRAMING_SCRIPTS, $index, 'command5Subject0Kind', 'staged_actor', $body);
    setRecordRow($session, FRAMING_SCRIPTS, $index, 'command5Subject0Id', 'helper', $body);
    $session->addDatabaseItem(FRAMING_SCRIPTS, $index, ['frame' => [...$body, 5, 'commands']]);

    // A second subject of the outer phase, an NPC of the map its one caller runs it on.
    $session->addDatabaseItem(FRAMING_SCRIPTS, $index, getRecordRow($session, FRAMING_SCRIPTS, $index, 'command1Subject0Kind', $then)['key']);
    setRecordRow($session, FRAMING_SCRIPTS, $index, 'command1Subject1Kind', 'npc', $then);
    expect(fn() => setRecordRow($session, FRAMING_SCRIPTS, $index, 'command1Subject1Id', 'stranger', $then))
        ->toThrow(SessionRefusal::class, 'is not one this script can name here');
    setRecordRow($session, FRAMING_SCRIPTS, $index, 'command1Subject1Id', 'keeper', $then);
    $session->saveDatabase(FRAMING_SCRIPTS);
    $edited = (string) file_get_contents($path);

    // Its closures, loop, spread and comment are as the author wrote them: the new commands follow the spread.
    $authored = str_replace("'subjects' => [['kind' => 'staged_actor', 'id' => 'crate']]",
        "'subjects' => [['kind' => 'staged_actor', 'id' => 'crate'], ['kind' => 'npc', 'id' => 'keeper']]", framedRescueScript());
    [$head, $tail] = explode("        ...\$walk,\n", $authored);
    expect($edited)->toStartWith($head . "        ...\$walk,\n")->toEndWith($tail)
        ->and(array_slice((require $path)[0]['then'][1]['commands'], 4))->toBe([
            ['type' => 'camera', 'operation' => 'detach'],
            ['type' => 'framing', 'subjects' => [['kind' => 'staged_actor', 'id' => 'helper']],
                'commands' => [['type' => 'text', 'name' => '', 'text' => 'Something happens.']]],
        ]);

    replayFramingHistory($session, FRAMING_SCRIPTS, 12);
    expect(file_get_contents($path))->toBe(framedRescueScript());
    replayFramingHistory($session, FRAMING_SCRIPTS, 12, redo: true);
    expect(file_get_contents($path))->toBe($edited);
});

it('keeps a bound key a reusable event script already selects as written, and turns it into a direct subject when a kind is chosen', function (): void {
    [$root, $path] = framedRescueProject(str_replace("'subjects' => [['kind' => 'staged_actor', 'id' => 'crate']]", "'subjects' => ['lead']", framedRescueScript()));
    $session = EditorSession::open($root);
    $index = findRescueScript($session);
    $then = [0, 'then'];
    $key = getRecordRow($session, FRAMING_SCRIPTS, $index, 'command1Subject0Key', $then);

    expect($key)->toMatchArray(['value' => 'lead'])
        ->and(getRecordRow($session, FRAMING_SCRIPTS, $index, 'command1Subject0Kind', $then))
        ->toMatchArray(['value' => '(a bound framing subject)', 'options' => ['player', 'npc', 'staged_actor']])
        ->and(fn() => $session->applyDatabaseRecord(FRAMING_SCRIPTS, $index, $key['key'], 'keeper'))->toThrow(SessionRefusal::class, 'cannot be edited here');

    // A phase changed while it still selects the key is the Engine's to refuse: a reusable script inherits no
    // caller's bound keys.
    setRecordRow($session, FRAMING_SCRIPTS, $index, 'command0Text', 'The crate tips.', [0, 'then', 1, 'commands']);
    expect(fn() => $session->saveDatabase(FRAMING_SCRIPTS))
        ->toThrow(InvalidArgumentException::class, 'common_event:rescue[1]: The framing subject key is not bound in this cinematic.')
        ->and(file_get_contents($path))->toContain("'subjects' => ['lead']", 'The crate shifts.');

    setRecordRow($session, FRAMING_SCRIPTS, $index, 'command1Subject0Kind', 'player', $then);
    $session->saveDatabase(FRAMING_SCRIPTS);
    expect((require $path)[0]['then'][1]['subjects'])->toBe([['kind' => 'player']])
        ->and((require $path)[0]['then'][1]['commands'][0]['text'])->toBe('The crate tips.');
});

/** The harbour cinematic's and the rescue script's issues, as `where: message`. */
function listFramingIssues(string $root): array
{
    return array_values(array_map(static fn($issue): string => $issue->where . ': ' . $issue->message,
        array_filter(validateProject($root), static fn($issue): bool => str_starts_with($issue->where, 'cinematic harbour-lanterns'))));
}

it('refuses saving a cinematic whose framing the Engine refuses, in the Engine\'s words, and writes nothing', function (string $phase, string $reason): void {
    [$root, $dataPath, $scriptPath] = framedHarbourProject("<?php\n\nreturn [\n  ['type' => 'camera', 'operation' => 'detach'],\n  {$phase},\n];\n");
    $before = [file_get_contents($dataPath), file_get_contents($scriptPath)];
    $session = EditorSession::open($root);

    setRecordRow($session, FRAMING_CINEMATICS, 0, 'name', 'Harbour Lanterns Framed');
    expect(fn() => $session->saveDatabase(FRAMING_CINEMATICS))->toThrow(RuntimeException::class, $reason)
        ->and([file_get_contents($dataPath), file_get_contents($scriptPath)])->toBe($before)
        // Validation reports the same refusal.
        ->and(implode("\n", listFramingIssues($root)))->toContain($reason);
})->with([
    'a phase that requires nobody' => ["['type' => 'framing', 'subjects' => [], 'commands' => [['type' => 'wait', 'seconds' => 1]]]",
        'A framing phase must select at least one required subject.'],
    'a phase with no body' => ["['type' => 'framing', 'subjects' => ['lead'], 'commands' => []]",
        'Framing needs required subjects and a non-empty command body.'],
    'a key the cinematic does not bind' => ["['type' => 'framing', 'subjects' => ['ghost'], 'commands' => [['type' => 'wait', 'seconds' => 1]]]",
        'The framing subject key is not bound in this cinematic.'],
    'one subject selected twice' => ["['type' => 'framing', 'subjects' => ['lead', ['kind' => 'player']], 'commands' => [['type' => 'wait', 'seconds' => 1]]]",
        'A framing phase selects each field identity once.'],
    'a place where a subject belongs' => ["['type' => 'framing', 'subjects' => [['kind' => 'position', 'x' => 1, 'y' => 2]], 'commands' => [['type' => 'wait', 'seconds' => 1]]]",
        'Framing selects the player or a stable NPC/staged actor id.'],
    'a key in a nested phase' => ["['type' => 'framing', 'subjects' => ['lead'], 'commands' => [['type' => 'sequence', 'commands' => "
        . "[['type' => 'framing', 'subjects' => ['ghost'], 'commands' => [['type' => 'wait', 'seconds' => 1]]]]]]]",
        'The framing subject key is not bound in this cinematic.'],
    'an irreversible action in a phase a skip can abandon' => ["['type' => 'framing', 'subjects' => ['lead'], 'commands' => [['type' => 'give_gold', 'amount' => 1]]]",
        'give_gold'],
]);

it('refuses saving drafts the rows leave the Engine to complete: a phase emptied of its subjects, a bound NPC without its id', function (): void {
    [$root, $dataPath, $scriptPath] = framedHarbourProject();
    $before = [file_get_contents($dataPath), file_get_contents($scriptPath)];
    $session = EditorSession::open($root);
    $nested = ['commands', 1, 'commands'];

    // The nested phase's one subject goes: the Engine refuses a phase that requires nobody.
    $session->removeDatabaseItem(FRAMING_CINEMATICS, 0, getRecordRow($session, FRAMING_CINEMATICS, 0, 'command3Subject0Key', $nested)['key']);
    expect(getRecordRow($session, FRAMING_CINEMATICS, 0, 'command3SubjectList', $nested)['value'])->toBe('0')
        ->and(fn() => $session->saveDatabase(FRAMING_CINEMATICS))
        ->toThrow(RuntimeException::class, 'Framing needs required subjects and a non-empty command body.');
    $session->undo();

    // A subject turned into an NPC waits for the NPC to be picked.
    setRecordRow($session, FRAMING_CINEMATICS, 0, 'framingSubject0Kind', 'npc');
    expect(fn() => $session->saveDatabase(FRAMING_CINEMATICS))
        ->toThrow(RuntimeException::class, 'Framing subject: npc subject requires an id.')
        ->and([file_get_contents($dataPath), file_get_contents($scriptPath)])->toBe($before);
    setRecordRow($session, FRAMING_CINEMATICS, 0, 'framingSubject0Id', 'keeper');

    // The same NPC under two keys is two selections of one identity in the phase that requires both.
    setRecordRow($session, FRAMING_CINEMATICS, 0, 'command1Subject1Kind', '', ['commands']);
    setRecordRow($session, FRAMING_CINEMATICS, 0, 'command1Subject1Key', 'lead', ['commands']);
    expect(fn() => $session->saveDatabase(FRAMING_CINEMATICS))
        ->toThrow(RuntimeException::class, 'harbour-lanterns:script[2]: A framing phase selects each field identity once.')
        ->and([file_get_contents($dataPath), file_get_contents($scriptPath)])->toBe($before);
});

it('has the Engine judge a framing command a reusable event script adds or changes before the script is written', function (): void {
    [$root, $path] = framedRescueProject();
    $session = EditorSession::open($root);
    $index = findRescueScript($session);
    $then = [0, 'then'];

    // A new phase with a subject and no body yet.
    $session->addDatabaseItem(FRAMING_SCRIPTS, $index, ['frame' => $then]);
    setRecordRow($session, FRAMING_SCRIPTS, $index, 'command3Type', 'framing', $then);
    $session->addDatabaseItem(FRAMING_SCRIPTS, $index, getRecordRow($session, FRAMING_SCRIPTS, $index, 'command3SubjectList', $then)['key']);
    expect(fn() => $session->saveDatabase(FRAMING_SCRIPTS))
        ->toThrow(InvalidArgumentException::class, 'Event script rescue has a framing command requiring player · 0 commands that the Engine refuses:')
        ->and(fn() => $session->saveDatabase(FRAMING_SCRIPTS))->toThrow(InvalidArgumentException::class, 'Framing needs required subjects and a non-empty command body.')
        ->and(file_get_contents($path))->toBe(framedRescueScript());

    // Its first command completes it.
    $session->addDatabaseItem(FRAMING_SCRIPTS, $index, ['frame' => [...$then, 3, 'commands']]);
    $session->saveDatabase(FRAMING_SCRIPTS);
    expect((require $path)[0]['then'][3])->toBe(['type' => 'framing', 'subjects' => [['kind' => 'player']],
        'commands' => [['type' => 'text', 'name' => '', 'text' => 'Something happens.']]]);
});

it('reports subjects a framing phase cannot reach where the cinematic runs it, a reusable script\'s phases included', function (): void {
    // A cinematic that runs a reusable script whose phase selects its own staged actor directly has nothing to report.
    [$root] = framedRescueProject();
    expect(listFramingIssues($root))->toBe([]);

    // The script selects a key, an actor nothing stages and an NPC of another map.
    [$root] = framedRescueProject(str_replace(
        "'subjects' => [['kind' => 'staged_actor', 'id' => 'crate']]",
        "'subjects' => ['lead', ['kind' => 'staged_actor', 'id' => 'crate']]",
        framedRescueScript(),
    ) );
    // The Engine judges the scripts a cinematic runs: a reusable script inherits none of its caller's bound keys.
    expect(listFramingIssues($root))->toContain(
        'cinematic harbour-lanterns: harbour-lanterns:script[3]/common_event:rescue[1]/branch:then[2]: The framing subject key is not bound in this cinematic.');

    [$root] = framedRescueProject(str_replace(
        "'subjects' => [['kind' => 'staged_actor', 'id' => 'crate']]",
        "'subjects' => [['kind' => 'staged_actor', 'id' => 'raft'], ['kind' => 'npc', 'id' => 'stranger'], ['kind' => 'player']]",
        framedRescueScript(),
    ));
    expect(listFramingIssues($root))->toContain(
        'cinematic harbour-lanterns common event rescue: It addresses staged actor "raft", which neither the cast nor an earlier stage_actor command defines.',
        'cinematic harbour-lanterns common event rescue: It addresses NPC "stranger", which is not on harbour.',
    );

    // A bound key is checked where a phase selects it: the porter is on no map the scene is on.
    [$root, $dataPath] = framedHarbourProject();
    expect(listFramingIssues($root))->toBe([]);
    file_put_contents($dataPath, str_replace("'id' => 'keeper'],", "'id' => 'porter'],", (string) file_get_contents($dataPath)));
    expect(listFramingIssues($root))->toBe(['cinematic harbour-lanterns: It addresses NPC "porter", which is not on harbour.']);
});

it('shows the framing subjects under their own heading in the Terminal Cutscenes pane and opens a phase\'s body as a frame', function (): void {
    [$root] = framedHarbourProject();
    $editor = cutscenesEditor($root);
    $asset = libraryOf($editor)->find(CutsceneType::CINEMATIC, 'harbour-lanterns');
    $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
    $labels = array_map(static fn(array $field): string => (string) $field['label'], $fields);
    $heading = array_search('Framing', $labels, true);
    $script = array_search('Script', $labels, true);

    expect($heading)->not->toBeFalse()
        ->and($script)->toBeGreaterThan($heading)
        ->and(array_slice(cutsceneFieldIds($editor), 0))->toContain('framingSubjectList', 'framingSubject0Key', 'framingSubject1Id')
        ->and(array_map(static fn(array $field): string => (string) ($field['field'] ?? ''), array_slice($fields, (int) $heading + 1, (int) $script - (int) $heading - 1)))
        ->toBe(['framingSubjectList', 'framingSubject0Key', 'framingSubject0Kind', 'framingSubject1Key', 'framingSubject1Kind', 'framingSubject1Id']);

    // Shift+O on the list's heading binds another subject, at its head; Delete on one of its rows removes it. The
    // cast keeps its two members.
    addCutsceneAfter($editor, 'framingSubjectList');
    expect(array_keys($asset->data()['presentation']['framing']['subjects']))->toBe(['subject', 'lead', 'keeper'])
        ->and(callEditorMethod($editor, 'getDatabaseSettingsFields')[getEditorProperty($editor, 'databaseSelectedSettingIndex')]['field'])->toBe('framingSubject0Key');
    selectCutsceneField($editor, 'framingSubject0Kind');
    callEditorMethod($editor, 'removeCutsceneSubItem', false);
    expect(array_keys($asset->data()['presentation']['framing']['subjects']))->toBe(['lead', 'keeper'])
        ->and($asset->data()['cast'])->toHaveCount(2);
    // A subject a phase still selects stays, and the pane says why.
    selectCutsceneField($editor, 'framingSubject0Key');
    callEditorMethod($editor, 'removeCutsceneSubItem', false);
    expect(array_keys($asset->data()['presentation']['framing']['subjects']))->toBe(['lead', 'keeper'])
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('still selected by a framing command');

    // The script, the phase's body, and the nested phase's body are frames inside one another.
    openCutsceneFrame($editor, 'commandListCommands');
    expect(cutsceneFieldIds($editor))->toContain('command1Commands', 'command1SubjectList', 'command1Subject0Key', 'command1Subject1Id');
    addCutsceneAfter($editor, 'command1SubjectList');
    expect($asset->commands()[1]['subjects'])->toHaveCount(3);
    callEditorMethod($editor, 'performUndo');
    openCutsceneFrame($editor, 'command1Commands');
    openCutsceneFrame($editor, 'command3Commands');
    expect(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe(['commands', 1, 'commands', 3, 'commands'])
        ->and(libraryOf($editor)->records(CutsceneType::CINEMATIC)->describeFramePath(['commands', 1, 'commands', 3, 'commands'], 0))
        ->toBe('Commands › Framing 2 › Commands › Framing 4 › Commands');

    // Esc leaves one frame at a time, landing on the phase each frame belongs to.
    pressKeys($editor, "\033");
    expect(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe(['commands', 1, 'commands'])
        ->and(callEditorMethod($editor, 'getDatabaseSettingsFields')[getEditorProperty($editor, 'databaseSelectedSettingIndex')]['field'])->toBe('command3Type');
    pressKeys($editor, "\033");
    expect(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe(['commands'])
        ->and(callEditorMethod($editor, 'getDatabaseSettingsFields')[getEditorProperty($editor, 'databaseSelectedSettingIndex')]['field'])->toBe('command1Type');
});

it('lists a framing phase in the command tree with who it requires, its body nested under it, and counts its body in the scene\'s length', function (): void {
    [$root] = framedHarbourProject();
    $asset = CutsceneLibrary::fromProject($root)->find(CutsceneType::CINEMATIC, 'harbour-lanterns');
    $rows = CutsceneOutline::of(CutsceneType::CINEMATIC, $asset->payload())->rows();
    $phase = array_find($rows, static fn(array $row): bool => $row['key'] === 'commands.1');
    $nested = array_find($rows, static fn(array $row): bool => $row['key'] === 'commands.1.commands.3');

    expect($phase['text'])->toContain('framing', 'keeper, staged actor boat-east · 4 commands')
        ->and($phase)->toMatchArray(['depth' => 1, 'foldable' => true, 'children' => true])
        ->and($nested['text'])->toContain('framing', 'lead · 1 commands')
        ->and($nested)->toMatchArray(['depth' => 2, 'frame' => ['commands', 1, 'commands'], 'index' => 3])
        ->and(array_find($rows, static fn(array $row): bool => $row['key'] === 'commands.1.commands.3.commands.0'))
        ->toMatchArray(['depth' => 3, 'frame' => ['commands', 1, 'commands', 3, 'commands'], 'index' => 0])
        // A command dragged onto a phase goes into its body.
        ->and(CutsceneOutline::nestingTarget($asset->commands()[1], ['commands', 1]))->toBe(['commands', 1, 'commands']);

    $overview = CutsceneLaneOverview::of($asset->commands());
    $block = array_find($overview->rows, static fn(array $row): bool => $row['key'] === 'commands.1');
    $inner = array_find($overview->rows, static fn(array $row): bool => $row['key'] === 'commands.1.commands.3');
    expect($block)->toMatchArray(['label' => 'framing', 'kind' => 'block', 'depth' => 0])
        ->and($inner)->toMatchArray(['label' => 'framing', 'kind' => 'block', 'depth' => 1, 'seconds' => 0.2])
        // The narration, the two boats' routes and the nested phase's wait, one after another.
        ->and($block['seconds'])->toEqualWithDelta(0.8, 0.0001)
        ->and($overview->totalSeconds)->toEqualWithDelta(0.8, 0.0001);
});

it('offers the Terminal Database a reusable event script\'s own staged actors and its phases\' bodies as frames of staging commands', function (): void {
    [$root] = framedRescueProject();
    $editor = deletionEditor($root);
    openDatabaseCategory($editor, FRAMING_SCRIPTS);
    $records = getEditorProperty($editor, 'workspace')->getRecordDatabase(FRAMING_SCRIPTS);
    $index = array_search('rescue', array_map(static fn($record): string => $record->recordId, $records->getRecords()), true);
    setEditorProperty($editor, 'databaseSelectedRecordIndexes', [FRAMING_SCRIPTS => $index]);

    expect(callEditorMethod($editor, 'referenceCatalog')->valuesFor('cinematic_cast'))->toBe(['crate', 'helper', 'boat-east', 'boat-west'])
        ->and($records->describeFramePath([0, 'then', 1, 'commands'], $index))->toBe('Commands › Branch 1 › Then › Framing 2 › Commands')
        ->and($records->describeEnclosingFrame([0, 'then', 1, 'commands']))->toBe(['frame' => [0, 'then'], 'landing' => 'command1'])
        ->and(array_column($records->getFrameSettingsFields($index, [0, 'then', 1, 'commands']), 'field'))
        ->toContain('command1Id', 'command1Sprite', 'command2Subject');

    // In the branch's arm: Shift+O on the phase's Required Subjects adds a subject, Enter on its Commands row
    // opens its body, and Esc lands back on the phase.
    $select = static function (string $field) use ($editor): void {
        $position = array_search($field, array_map(static fn(array $row): string => (string) ($row['field'] ?? ''), callEditorMethod($editor, 'getDatabaseSettingsFields')), true);
        setEditorProperty($editor, 'databaseSelectedSettingIndex', is_int($position) ? $position : throw new RuntimeException('No row ' . $field));
    };
    setEditorProperty($editor, 'databaseFocus', 'database_settings');
    setEditorProperty($editor, 'databaseCommandFramePath', [0, 'then']);
    $select('command1SubjectList');
    pressKeys($editor, 'O');
    expect($records->getRecordByIndex($index)->getSubList('commands')[0]['then'][1]['subjects'])
        ->toBe([['kind' => 'staged_actor', 'id' => 'crate'], ['kind' => 'player']]);
    $select('command1Commands');
    pressKeys($editor, "\n");
    expect(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe([0, 'then', 1, 'commands']);
    pressKeys($editor, "\033");
    expect(getEditorProperty($editor, 'databaseCommandFramePath'))->toBe([0, 'then'])
        ->and(callEditorMethod($editor, 'getDatabaseSettingsFields')[getEditorProperty($editor, 'databaseSelectedSettingIndex')]['field'])->toBe('command1Type');
});

it('reports a framing phase a field script would reach, and a key a reusable script nothing runs selects, in the Engine\'s words', function (): void {
    // The keeper's own script runs on the field: a phase there, and one in a reusable script it calls, cannot run.
    [$root] = framedRescueProject();
    $mapPath = $root . '/assets/Maps/harbour/harbour.data.php';
    $map = require $mapPath;
    $map['npcs'][0]['script'] = [
        ['type' => 'common_event', 'id' => 'rescue'],
        ['type' => 'framing', 'subjects' => [['kind' => 'player']], 'commands' => [['type' => 'wait', 'seconds' => 1]]],
    ];
    file_put_contents($mapPath, "<?php\n\nreturn " . var_export($map, true) . ";\n");
    $issues = array_values(array_map(static fn($issue): string => $issue->where . ': ' . $issue->message,
        array_filter(validateProject($root), static fn($issue): bool => str_contains($issue->message, 'requires an active field cinematic'))));

    expect($issues)->toBe(['harbour NPC Keeper: script[1]/common_event:rescue[1]/branch:then[2]: "framing" requires an active field cinematic.']);

    // Without the call, the phase in the keeper's own script is the first the Engine meets.
    $map['npcs'][0]['script'] = [$map['npcs'][0]['script'][1]];
    file_put_contents($mapPath, "<?php\n\nreturn " . var_export($map, true) . ";\n");
    expect(implode("\n", array_map(static fn($issue): string => $issue->where . ': ' . $issue->message, validateProject($root))))
        ->toContain('harbour NPC Keeper: script[1]: "framing" requires an active field cinematic.');

    // The same judgement covers every command of a field script: a battle command cannot run there either.
    $map['npcs'][0]['script'] = [['type' => 'concede']];
    file_put_contents($mapPath, "<?php\n\nreturn " . var_export($map, true) . ";\n");
    expect(implode("\n", array_map(static fn($issue): string => $issue->where . ': ' . $issue->message, validateProject($root))))
        ->toContain('harbour NPC Keeper: script[1]: "concede" cannot run in field scripts.');

    // A reusable script nothing runs is judged awaiting its caller: its phases select subjects directly.
    $root = cutsceneProject();
    file_put_contents($root . '/assets/Events/lonely.php', "<?php\n\nreturn [\n  ['type' => 'framing', 'subjects' => ['lead'], 'commands' => [['type' => 'wait', 'seconds' => 1]]],\n];\n");
    expect(implode("\n", array_map(static fn($issue): string => $issue->where . ': ' . $issue->message, validateProject($root))))
        ->toContain('event script lonely: script[1]: The framing subject key is not bound in this cinematic.');
    file_put_contents($root . '/assets/Events/lonely.php', "<?php\n\nreturn [\n  ['type' => 'framing', 'subjects' => [['kind' => 'player']], 'commands' => [['type' => 'wait', 'seconds' => 1]]],\n];\n");
    expect(array_filter(validateProject($root), static fn($issue): bool => str_starts_with($issue->where, 'event script lonely')))->toBe([]);
});
