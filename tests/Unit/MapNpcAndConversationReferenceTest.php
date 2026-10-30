<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandRegistry;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;

/**
 * Registered script commands that name a map NPC (`set_bed_posture`) or a
 * conversation group of the running cinematic (`select_conversation_group`):
 * picked where the script runs, checked against the map or cinematic at that
 * point, edited in place with undo, and an Engine reference kind the Editor
 * does not know never stopping a project from opening. Every project here is
 * a synthetic fixture.
 */

afterEach(function (): void {
    ScriptCommandRegistry::reset();
});

/** Writes a small runtime-valid map holding the given NPCs on its top row. */
function writeReferenceMap(string $root, string $id, string ...$npcIds): void
{
    $folder = $root . '/assets/Maps/' . $id;
    @mkdir($folder, 0o777, true);
    $tiles = ['##########', '#        #', '#        #', '##########'];
    file_put_contents($folder . "/{$id}.map.php", "<?php\n\nreturn <<<'ICHILOTO_MAP'\n" . implode("\n", $tiles) . "\nICHILOTO_MAP;\n");
    file_put_contents($folder . "/{$id}.event.php", "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n" . implode("\n", array_fill(0, 4, str_repeat(' ', 10))) . "\nICHILOTO_EVENT_MAP;\n");
    $npcs = array_map(static fn(string $npc, int $index): array => ['id' => $npc, 'name' => ucfirst($npc), 'sprite' => 'N', 'x' => 2 + $index * 2, 'y' => 1],
        $npcIds, array_keys($npcIds));
    file_put_contents($folder . "/{$id}.data.php", "<?php\n\nreturn " . var_export([
        'name' => ucfirst($id), 'region' => '', 'description' => '', 'triggers' => [], 'events' => [], 'npcs' => $npcs,
    ], true) . ";\n");
}

/**
 * A cinematic that starts on the harbour, declares a conversation with two
 * groups, and travels to the inn.
 *
 * @param list<array<string, mixed>> $commands
 * @return array{string, string} The project root and the cinematic's script path.
 */
function conversationCinematicProject(array $commands): array
{
    $root = cutsceneProject();
    writeReferenceMap($root, 'inn', 'guest');
    $folder = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns';
    $data = (string) file_get_contents($folder . '/harbour-lanterns.data.php');
    // Authored skipping cannot prove a registered command safe, so the Engine runs these cinematics to the end.
    file_put_contents($folder . '/harbour-lanterns.data.php', str_replace(
        ["'skip' => ['policy' => 'authored'],", "'presentation' => ['initial' => 'hidden'],"],
        ["'skip' => ['policy' => 'forbidden'],", "'presentation' => ['initial' => 'field', 'conversation' => [\n"
        . "    'speakers' => ['hero' => ['kind' => 'player'], 'keeper' => ['kind' => 'npc', 'id' => 'keeper']],\n"
        . "    'groups' => ['quay' => ['speakers' => ['hero', 'keeper']], 'alone' => ['speakers' => []]],\n"
        . "    'initialGroup' => 'quay',\n  ]],"],
        $data,
    ));
    $script = $folder . '/harbour-lanterns.script.php';
    file_put_contents($script, "<?php\n\n// Written by hand.\nreturn " . var_export($commands, true) . ";\n");

    return [$root, $script];
}

/** One row of a cinematic command, by its label. */
function conversationCommandRow(EditorSession $session, string $name, int $command): array
{
    return array_find($session->readDatabaseRecord('cutscenes/cinematic', 0, ['commands'])['rows'],
        static fn(array $row): bool => ($row['entry'] ?? []) === [$command] && ($row['name'] ?? '') === $name)
        ?? throw new RuntimeException('Missing command field ' . $name);
}

/** @return list<string> The project's issue messages. */
function conversationIssueMessages(string $root): array
{
    return array_values(array_map(static fn($issue): string => $issue->message, validateProject($root)));
}

it('opens and validates a project whose Engine adds a reference kind this Editor does not know', function (): void {
    $engine = getenv('ICHILOTO_ENGINE_SRC') ?: dirname(__DIR__, 2) . '/vendor/ichiloto/engine';
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/' . ScriptCommandRegistry::PROJECT_FILE, <<<'PHP'
    <?php

    use Project\Commands\ConsultOracle;

    return [[
        'type' => 'consult_oracle',
        'class' => ConsultOracle::class,
        'label' => 'Consult Oracle',
        'fields' => [
            ['key' => 'oracle', 'label' => 'Oracle', 'kind' => 'reference', 'reference' => 'future_kind', 'required' => true],
        ],
    ]];
    PHP);
    file_put_contents($root . '/assets/Events/oracle.php', "<?php\n\nreturn [['type' => 'consult_oracle', 'oracle' => 'delphi']];\n");
    $scratch = rememberTemporaryProject(sys_get_temp_dir() . '/ichiloto-future-reference-' . bin2hex(random_bytes(4)));
    mkdir($scratch, 0o777, true);

    $output = (string) shell_exec(sprintf('%s -d memory_limit=512M %s %s %s %s 2>&1', escapeshellarg(PHP_BINARY),
        escapeshellarg(dirname(__DIR__) . '/Support/unknown-reference-runner.php'),
        escapeshellarg($engine), escapeshellarg($root), escapeshellarg($scratch)));
    $result = json_decode($output, true);

    expect($result)->toBeArray("The runner produced no result: {$output}")
        ->and($result['loaded'] ?? null)->toBeTrue($result['error'] ?? $output)
        // The field stays editable as plain text, labelled as such.
        ->and($result['field'])->toBe(['label' => 'Oracle (typed: unknown reference kind future_kind)', 'reference' => null, 'readOnly' => false])
        ->and($result['issues'])->toHaveCount(1)
        ->and($result['issues'][0]['message'])->toBe('Its consult_oracle command\'s oracle is an Engine "future_kind" reference this Editor does not know.')
        ->and($result['issues'][0]['hint'])->toContain('Update the Editor');
});

it('offers the NPCs of the map an event runs on, or of every map a cinematic runs on', function (): void {
    [$root] = conversationCinematicProject([
        ['type' => 'transfer', 'map' => 'inn', 'x' => 2, 'y' => 2],
    ]);
    writeReferenceMap($root, 'cellar', 'rat');
    $session = EditorSession::open($root);
    $cinematic = ['category' => 'cutscenes/cinematic', 'index' => 0];

    expect(array_column($session->listReferences('harbour', 'map_npcs'), 'value'))->toBe(['keeper'])
        // The cinematic starts on the harbour and travels to the inn; it never reaches the cellar.
        ->and(array_column($session->listReferences(null, 'map_npcs', $cinematic), 'value'))->toBe(['keeper', 'guest'])
        // A map selected beside the cinematic is not where its commands run.
        ->and(array_column($session->listReferences('cellar', 'map_npcs', $cinematic), 'value'))->toBe(['keeper', 'guest'])
        ->and(array_column($session->listReferences(null, ReferenceCatalog::CINEMATIC_CONVERSATION_GROUPS, $cinematic), 'value'))->toBe(['quay', 'alone'])
        // Outside a cinematic there are no groups, and outside a map any NPC could be meant.
        ->and($session->listReferences(null, ReferenceCatalog::CINEMATIC_CONVERSATION_GROUPS))->toBe([])
        ->and(new ReferenceCatalog(ProjectWorkspace::fromProject($root))->listScopedChoices('map_npcs'))->toBeNull()
        ->and(new ReferenceCatalog(ProjectWorkspace::fromProject($root))->listScopedChoices(ReferenceCatalog::CINEMATIC_CONVERSATION_GROUPS))->toBeNull();
});

it('edits bed posture and conversation group commands in place, with undo and redo byte for byte', function (): void {
    [$root, $script] = conversationCinematicProject([
        ['type' => 'set_bed_posture', 'npc' => 'keeper', 'posture' => 'lying', 'note' => ['keep' => true]],
        ['type' => 'select_conversation_group', 'group' => 'quay'],
    ]);
    $original = (string) file_get_contents($script);
    $session = EditorSession::open($root);

    $occupant = conversationCommandRow($session, 'Occupant', 0);
    $group = conversationCommandRow($session, 'Group', 1);
    expect($occupant)->toMatchArray(['value' => 'keeper', 'reference' => 'map_npcs'])
        ->and($group)->toMatchArray(['value' => 'quay', 'reference' => ReferenceCatalog::CINEMATIC_CONVERSATION_GROUPS])
        ->and(conversationCommandRow($session, 'Posture', 0)['options'] ?? null)->toBe(['lying', 'sitting', 'standing']);

    $session->applyDatabaseRecord('cutscenes/cinematic', 0, conversationCommandRow($session, 'Posture', 0)['key'], 'sitting');
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, conversationCommandRow($session, 'Transition Seconds', 0)['key'], '1.5');
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, $group['key'], 'alone');
    $session->saveDatabase('cutscenes/cinematic');
    $edited = (string) file_get_contents($script);

    expect($edited)->toContain('// Written by hand.')
        ->and(require $script)->toBe([
            ['type' => 'set_bed_posture', 'npc' => 'keeper', 'posture' => 'sitting', 'note' => ['keep' => true], 'seconds' => 1.5],
            ['type' => 'select_conversation_group', 'group' => 'alone'],
        ]);

    $session->undo();
    $session->undo();
    $session->undo();
    $session->saveDatabase('cutscenes/cinematic');
    expect(file_get_contents($script))->toBe($original);

    $session->redo();
    $session->redo();
    $session->redo();
    $session->saveDatabase('cutscenes/cinematic');
    expect(file_get_contents($script))->toBe($edited);
});

it('refuses an NPC or group the script cannot name there before anything is written', function (): void {
    [$root, $script] = conversationCinematicProject([
        ['type' => 'set_bed_posture', 'npc' => 'keeper', 'posture' => 'lying'],
        ['type' => 'select_conversation_group', 'group' => 'quay'],
    ]);
    writeReferenceMap($root, 'cellar', 'rat');
    $original = (string) file_get_contents($script);
    $session = EditorSession::open($root);

    expect(fn() => $session->applyDatabaseRecord('cutscenes/cinematic', 0, conversationCommandRow($session, 'Occupant', 0)['key'], 'rat'))
        ->toThrow(SessionRefusal::class, 'Occupant "rat" is not one this script can name here')
        ->and(fn() => $session->applyDatabaseRecord('cutscenes/cinematic', 0, conversationCommandRow($session, 'Group', 1)['key'], 'parlour'))
        ->toThrow(SessionRefusal::class, 'Group "parlour" is not one this script can name here')
        ->and($session->undo()['label'])->toBeNull();

    $session->saveDatabase('cutscenes/cinematic');
    expect(file_get_contents($script))->toBe($original);
});

it('checks each NPC against the map the script is on and each group against the running cinematic', function (): void {
    [$root] = conversationCinematicProject([
        ['type' => 'set_bed_posture', 'npc' => 'keeper', 'posture' => 'sitting'],
        ['type' => 'select_conversation_group', 'group' => 'alone'],
        ['type' => 'select_conversation_group', 'group' => 'parlour'],
        ['type' => 'transfer', 'map' => 'inn', 'x' => 2, 'y' => 2],
        // The keeper stayed on the harbour.
        ['type' => 'set_bed_posture', 'npc' => 'keeper', 'posture' => 'lying'],
        ['type' => 'set_bed_posture', 'npc' => 'guest', 'posture' => 'standing'],
    ]);
    $mapPath = $root . '/assets/Maps/harbour/harbour.data.php';
    $map = require $mapPath;
    $map['events']['S'] = ['class' => ScriptEventTrigger::class, 'data' => ['script' => [
        ['type' => 'set_bed_posture', 'npc' => 'keeper', 'posture' => 'sitting'],
        ['type' => 'set_bed_posture', 'npc' => 'guest', 'posture' => 'sitting'],
        ['type' => 'select_conversation_group', 'group' => 'quay'],
    ]]];
    file_put_contents($mapPath, "<?php\n\nreturn " . var_export($map, true) . ";\n");

    $messages = conversationIssueMessages($root);
    $named = static fn(string $needle): array => array_values(array_filter($messages, static fn(string $message): bool => str_contains($message, $needle)));

    expect($named('NPC "keeper"'))->toBe(['Its set_bed_posture command names NPC "keeper", which is not on inn.'])
        ->and($named('NPC "guest"'))->toBe(['Its set_bed_posture command names NPC "guest", which is not on harbour.'])
        ->and($named('conversation group'))->toEqualCanonicalizing([
            'Its select_conversation_group command selects conversation group "parlour", which this cinematic does not declare.',
            'Its select_conversation_group command selects conversation group "quay" outside a cinematic.',
        ]);
});

it('picks a bed occupant among the event map\'s NPCs and refuses another map\'s before writing', function (): void {
    $root = cutsceneProject();
    writeReferenceMap($root, 'inn', 'guest');
    $mapPath = $root . '/assets/Maps/harbour/harbour.data.php';
    $map = require $mapPath;
    $map['npcs'][] = ['id' => 'porter', 'name' => 'Porter', 'sprite' => 'P', 'x' => 8, 'y' => 1];
    $map['events']['S'] = ['class' => ScriptEventTrigger::class, 'data' => ['script' => [
        ['type' => 'set_bed_posture', 'npc' => 'keeper', 'posture' => 'lying'],
    ]]];
    file_put_contents($mapPath, "<?php\n\n// Preserve map source.\nreturn " . var_export($map, true) . ";\n");
    $original = (string) file_get_contents($mapPath);
    $session = EditorSession::open($root);
    $read = $session->readInspector('harbour', 'S', ['script']);
    $occupant = array_find($read['rows'], static fn(array $row): bool => ($row['name'] ?? '') === 'Occupant')
        ?? throw new RuntimeException('No occupant row.');

    expect($occupant)->toMatchArray(['value' => 'keeper', 'reference' => 'map_npcs'])
        ->and(array_column($session->listReferences('harbour', 'map_npcs'), 'value'))->toBe(['keeper', 'porter'])
        ->and(fn() => $session->applyInspector('harbour', $read['revision'], $occupant['key'], 'guest'))
        ->toThrow(SessionRefusal::class, 'Occupant "guest" is not one this script can name here')
        ->and($session->undo()['label'])->toBeNull();

    $session->applyInspector('harbour', $read['revision'], $occupant['key'], 'porter');
    $session->saveMap('harbour');
    $edited = (string) file_get_contents($mapPath);
    expect($edited)->toContain('// Preserve map source.')
        ->and((require $mapPath)['events']['S']['data']['script'][0])->toBe(['type' => 'set_bed_posture', 'npc' => 'porter', 'posture' => 'lying']);

    $session->undo();
    $session->saveMap('harbour');
    expect(file_get_contents($mapPath))->toBe($original);
    $session->redo();
    $session->saveMap('harbour');
    expect(file_get_contents($mapPath))->toBe($edited);
});
