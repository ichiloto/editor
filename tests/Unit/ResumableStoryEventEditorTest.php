<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Events\EventTypeCatalog;
use Ichiloto\Editor\History\CommandHistory;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;

/** Writes a PHP-return-array file in a disposable project. */
function writePhase7ArrayFile(string $path, array $payload): void
{
    file_put_contents($path, "<?php\n\nreturn " . var_export($payload, true) . ";\n");
}

it('creates ScriptEventTrigger through the active event type catalog and inspector', function (): void {
    $root = makeTemporaryProject();
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
    setEditorProperty($editor, 'isRunning', true);
    setEditorProperty($editor, 'eventTypeDialogMarker', 'E');
    setEditorProperty($editor, 'selectedEventTypeIndex', EventTypeCatalog::indexOfClass(ScriptEventTrigger::class));

    ob_start();
    callEditorMethod($editor, 'applySelectedEventType');
    ob_end_clean();

    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    $definition = $workspace->maps[0]->getEventDefinition('E');

    expect($definition['class'] ?? null)->toBe(ScriptEventTrigger::class)
        ->and($definition['data'] ?? null)->toBe([
            'scriptId' => '',
            'mode' => 'action',
            'reusable' => false,
        ])
        ->and($definition)->toHaveKeys(['conditions', 'sets', 'whenBlocked', 'cue'])
        ->and($definition['cue'])->toBe(['symbol' => '', 'color' => 'bright-yellow']);

    $fields = callEditorMethod($editor, 'buildEventDataFields', 'E', $definition);
    $byPath = [];

    foreach ($fields as $field) {
        $byPath[implode('.', (array) ($field['path'] ?? []))] = $field;
    }

    expect($byPath['data.scriptId']['reference'] ?? null)->toBe('common_events')
        ->and($byPath['data.scriptId'])->not->toHaveKey('control')
        ->and($byPath['data.mode']['options'] ?? null)->toBe(['action', 'auto'])
        ->and($byPath['data.reusable']['control'] ?? null)->not->toBeNull()
        ->and($byPath)->toHaveKeys(['cue.symbol', 'cue.color']);

    $cueField = $byPath['cue.symbol'];
    callEditorMethod($editor, 'applyInspectorFieldValue', $cueField, '!');
    $definition = $workspace->maps[0]->getEventDefinition('E');

    expect($definition['cue'])->toBe(['symbol' => '!', 'color' => 'bright-yellow']);

    removeDirectoryRecursively($root);
});

it('exposes event cues on legacy definitions without changing them on inspection', function (): void {
    $root = makeTemporaryProject();
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    $definition = [
        'class' => ScriptEventTrigger::class,
        'data' => ['scriptId' => 'dresser-note', 'mode' => 'action', 'reusable' => false],
    ];
    $fields = callEditorMethod($editor, 'buildEventDataFields', 'E', $definition);
    $paths = array_map(static fn(array $field): string => implode('.', (array) ($field['path'] ?? [])), $fields);

    expect($paths)->toContain('cue.symbol', 'cue.color')
        ->and($definition)->not->toHaveKey('cue');

    removeDirectoryRecursively($root);
});

it('uses the runtime command vocabulary and exposes battle continuation fields', function (): void {
    $schema = RecordSchemaCatalog::forKey('common_events');
    $variants = $schema?->subList?->variants ?? [];
    $battleFields = [];

    foreach ($variants['start_battle'] ?? [] as $field) {
        $battleFields[$field->key] = $field;
    }

    expect(RecordSchemaCatalog::EVENT_COMMAND_TYPES)->toBe(EventInterpreter::COMMAND_TYPES)
        ->and($battleFields['troop']->reference)->toBe('troops')
        ->and($battleFields)->toHaveKeys(['resultVariable', 'defeatPolicy', 'escapePolicy'])
        ->and($battleFields['defeatPolicy']->options)->toBe(['game_over', 'continue'])
        ->and($battleFields['escapePolicy']->options)->toBe(['allowed', 'forbidden']);
});

it('accepts every runtime command type and still rejects vocabulary drift', function (): void {
    $root = makeTemporaryProject();
    writePhase7ArrayFile(
        $root . '/assets/Events/runtime-vocabulary.php',
        array_map(
            static fn(string $type): array => ['type' => $type],
            EventInterpreter::COMMAND_TYPES,
        ),
    );

    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));
    $vocabularyIssues = array_values(array_filter(
        $issues,
        static fn($issue): bool => str_contains($issue->where, 'runtime-vocabulary')
            && str_contains($issue->message, 'unknown event command type'),
    ));

    expect(RecordSchemaCatalog::EVENT_COMMAND_TYPES)->toBe(EventInterpreter::COMMAND_TYPES)
        ->and($vocabularyIssues)->toBe([]);

    removeDirectoryRecursively($root);
});

it('edits route commands and nested steps without flattening their payload', function (): void {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'common_events');
    $commandIndex = $database->addSubItem(0, [
        'type' => 'move_route',
        'subject' => 'npc',
        'npcId' => 'guide',
        'wait' => true,
        'secondsPerStep' => 0.2,
        'technicalMetadata' => ['preserve' => true],
        'steps' => [
            ['direction' => 'left', 'count' => 2, 'faceOnly' => false],
        ],
    ]);

    expect($commandIndex)->toBe(4);

    $fields = array_column($database->getSettingsFields(0), null, 'field');
    expect($fields)->toHaveKeys([
        'command4Subject',
        'command4NpcId',
        'command4Step0Direction',
        'command4Step0Count',
        'command4Step0FaceOnly',
    ]);

    $database->setField(0, 'command4Step0Direction', 'right');
    $database->setField(0, 'command4Step0Count', '3');
    $stepIndex = $database->addNestedSubItem(0, 4);
    expect($stepIndex)->toBe(1);
    $database->setField(0, 'command4Step1Direction', 'up');
    $database->setField(0, 'command4Step1Count', '1');
    $database->setField(0, 'command4Step1FaceOnly', 'true');
    $database->save();

    $payload = require $root . '/assets/Events/dresser-note.php';
    expect($payload[4]['steps'])->toBe([
        ['direction' => 'right', 'count' => 3, 'faceOnly' => false],
        ['direction' => 'up', 'count' => 1, 'faceOnly' => true],
    ])
        ->and($payload[4]['technicalMetadata'])->toBe(['preserve' => true])
        ->and($payload[4]['wait'])->toBeTrue();

    $reloaded = loadRecordDatabase($root, 'common_events');
    $reloadedFields = array_column($reloaded->getSettingsFields(0), 'value', 'field');
    expect($reloadedFields['command4Step1Direction'])->toBe('up')
        ->and($reloadedFields['command4Step1FaceOnly'])->toBe('true');

    removeDirectoryRecursively($root);
});

it('records route step add and remove in editor undo and redo history', function (): void {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'common_events');
    $database->addSubItem(0, [
        'type' => 'move_route',
        'subject' => 'player',
        'wait' => true,
        'steps' => [['direction' => 'right', 'count' => 1, 'faceOnly' => false]],
    ]);
    $database->save();

    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
    setEditorProperty($editor, 'isRunning', true);
    setEditorProperty($editor, 'databaseCategoryIndex', DatabaseCatalog::indexOf('common_events'));
    setEditorProperty($editor, 'databaseFocus', 'database_settings');
    $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
    $settingIndex = array_search('command4Step0Direction', array_column($fields, 'field'), true);
    expect($settingIndex)->not->toBeFalse();
    setEditorProperty($editor, 'databaseSelectedSettingIndex', $settingIndex);

    ob_start();
    callEditorMethod($editor, 'addDatabaseNestedSubItem');
    ob_end_clean();

    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    /** @var ProjectRecordDatabase $liveDatabase */
    $liveDatabase = $workspace->getRecordDatabase('common_events');
    expect($liveDatabase->countNestedSubItems(0, 4))->toBe(2)
        ->and($liveDatabase->isDirty())->toBeTrue();

    /** @var CommandHistory $history */
    $history = getEditorProperty($editor, 'history');
    $history->undo();
    expect($liveDatabase->countNestedSubItems(0, 4))->toBe(1);

    $history->redo();
    expect($liveDatabase->countNestedSubItems(0, 4))->toBe(2);

    ob_start();
    callEditorMethod($editor, 'removeDatabaseNestedSubItem');
    ob_end_clean();
    expect($liveDatabase->countNestedSubItems(0, 4))->toBe(1);

    $history->undo();
    expect($liveDatabase->countNestedSubItems(0, 4))->toBe(2);

    removeDirectoryRecursively($root);
});

it('validates script references, stable NPC identities, routes, and battle continuation metadata', function (): void {
    $root = makeTemporaryProject();
    $mapPath = $root . '/assets/Maps/test-map/test-map.data.php';
    $map = require $mapPath;
    $map['npcs'] = [
        ['id' => 'guide', 'name' => 'Guide', 'sprite' => 'G', 'x' => 2, 'y' => 2],
        ['id' => 'guide', 'name' => 'Other Guide', 'sprite' => 'g', 'x' => 3, 'y' => 2],
    ];
    $map['events']['E'] = [
        'class' => ScriptEventTrigger::class,
        'data' => ['scriptId' => 'missing-script', 'mode' => 'sometimes', 'reusable' => 'no'],
        'unsupported' => true,
    ];
    $map['events']['F'] = [
        'class' => ScriptEventTrigger::class,
        'data' => ['scriptId' => '', 'mode' => 'action', 'reusable' => false],
    ];
    writePhase7ArrayFile($mapPath, $map);
    writePhase7ArrayFile($root . '/assets/Events/invalid-phase7.php', [
        ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'missing-npc', 'wait' => 'true', 'speed' => 0, 'steps' => [
            ['direction' => 'diagonal', 'count' => -1, 'faceOnly' => 'false'],
        ]],
        ['type' => 'start_battle', 'troop' => '', 'resultVariable' => '', 'defeatPolicy' => 'always_win', 'escapePolicy' => 'sometimes'],
        ['type' => 'parallel_cutscene'],
    ]);
    writePhase7ArrayFile($root . '/assets/Data/troops.php', [
        ['name' => 'Malformed Policy Troop', 'escapePolicy' => 'sometimes', 'enemies' => []],
    ]);

    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));
    $messages = array_map(static fn($issue): string => $issue->where . ': ' . $issue->message, $issues);
    $joined = implode("\n", $messages);

    expect($joined)->toContain('NPC id "guide" is used more than once')
        ->and($joined)->toContain('event script "missing-script"')
        ->and($joined)->toContain('names no scriptId and has no inline script')
        ->and($joined)->toContain('unsupported mode "sometimes"')
        ->and($joined)->toContain('reusable must be boolean')
        ->and($joined)->toContain('unsupported root field "unsupported"')
        ->and($joined)->toContain('unknown event command type "parallel_cutscene"')
        ->and($joined)->toContain('invalid wait value')
        ->and($joined)->toContain('speed must be greater than zero')
        ->and($joined)->toContain('invalid escapePolicy "sometimes"')
        ->and($joined)->toContain('troop Malformed Policy Troop')
        ->and($joined)->toContain('unsupported direction "diagonal"')
        ->and($joined)->toContain('invalid count')
        ->and($joined)->toContain('invalid faceOnly value')
        ->and($joined)->toContain('names no troop')
        ->and($joined)->toContain('resultVariable is empty or malformed')
        ->and($joined)->toContain('invalid defeatPolicy "always_win"');

    removeDirectoryRecursively($root);
});

it('ships a valid technical fixture for trigger, player route, NPC route, and battle continuation', function (): void {
    $root = fixturePath('phase7-project');
    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));
    $phase7Issues = array_values(array_filter(
        $issues,
        static fn($issue): bool => str_contains($issue->where, 'event S')
            || str_contains($issue->where, 'phase7-technical')
            || str_contains($issue->message, 'phase7_fixture')
            || str_contains($issue->message, 'technical-guide'),
    ));

    expect($phase7Issues)->toBe([]);

    $script = require $root . '/assets/Events/phase7-technical.php';
    expect(array_column($script, 'type'))->toBe(['move_route', 'move_route', 'start_battle'])
        ->and($script[0]['subject'])->toBe('player')
        ->and($script[1]['npcId'])->toBe('technical-guide')
        ->and($script[2])->toMatchArray([
            'resultVariable' => 'phase7_fixture_battle_result',
            'defeatPolicy' => 'continue',
        ]);
});

it('uses map context to validate a referenced script NPC route target', function (): void {
    $root = makeTemporaryProject();
    $mapPath = $root . '/assets/Maps/test-map/test-map.data.php';
    $map = require $mapPath;
    $map['npcs'] = [
        ['id' => 'guide', 'name' => 'Guide', 'sprite' => 'G', 'x' => 2, 'y' => 2],
    ];
    $map['events']['E'] = [
        'class' => ScriptEventTrigger::class,
        'data' => ['scriptId' => 'phase7-technical', 'mode' => 'action', 'reusable' => false],
        'sets' => [['type' => 'event', 'name' => 'technical_complete']],
    ];
    writePhase7ArrayFile($mapPath, $map);
    writePhase7ArrayFile($root . '/assets/Events/phase7-technical.php', [
        ['type' => 'move_route', 'subject' => 'player', 'wait' => true, 'steps' => [
            ['direction' => 'right', 'count' => 2],
        ]],
        ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'wrong-guide', 'wait' => true, 'steps' => [
            ['direction' => 'left', 'count' => 1],
        ]],
        [
            'type' => 'start_battle',
            'troop' => 'Bat x 2',
            'resultVariable' => 'technical_result',
            'defeatPolicy' => 'continue',
        ],
    ]);

    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));
    $messages = implode("\n", array_map(static fn($issue): string => $issue->message, $issues));

    expect($messages)->toContain('targets NPC id "wrong-guide", which is not on map "test-map"')
        ->and($messages)->not->toContain('unknown event command type')
        ->and($messages)->not->toContain('invalid defeatPolicy');

    removeDirectoryRecursively($root);
});
