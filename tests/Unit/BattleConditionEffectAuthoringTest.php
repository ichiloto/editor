<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\BattleConditionGroup;
use Ichiloto\Editor\Database\RecordAuthoring;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\Validation\EffectValidator;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\RecordReferenceValidator;

/**
 * The effects battle conditions play, chosen in Configuration from the state or stat and effect pickers and
 * checked by the Engine's rule for their group before they are written: looping auras for states and stat
 * stages, one-shot contact effects for interceptions. Synthetic fixtures only: the sample project's states and
 * synthetic timelines, two that play once and one that loops.
 */
function conditionEffectProject(): string
{
    $root = makeTemporaryProject('editor-condition-effects-');
    foreach (['contact' => 'once', 'second-contact' => 'once', 'looping-contact' => 'loop'] as $id => $playback) {
        mkdir($root . '/assets/Animations/' . $id, 0777, true);
        file_put_contents($root . '/assets/Animations/' . $id . '/' . $id . '.timeline.php', '<?php return ' . var_export([
            'fps' => 10, 'lengthFrames' => 2, 'restFrame' => 1, 'playback' => $playback,
            'tracks' => [['id' => 'contact', 'type' => 'glyph', 'anchor' => 'target',
                'keyframes' => [['frame' => 0, 'content' => 'a'], ['frame' => 1, 'content' => 'b']]]],
        ], true) . ';');
    }

    return $root;
}

/**
 * Writes a config.php whose battle conditions hold these group sources, beside authored comments and expressions.
 *
 * @param array<string, string> $groups Source of each group, by its key under ui.battle.conditions.
 */
function conditionEffectConfig(string $root, array $groups): string
{
    $entries = implode('', array_map(static fn(string $key, string $group): string => "\n      '{$key}' => {$group},", array_keys($groups), $groups));
    $source = "<?php\n// authored configuration\nreturn [\n  'vocab' => ['title' => 'Original'],\n  'untouched' => strtoupper('kept'),\n"
        . "  'ui' => ['battle' => ['conditions' => [\n      // condition effects stay as written{$entries}\n  ]]],\n];\n";
    file_put_contents($root . '/config.php', $source);

    return $source;
}

function conditionEffectIndex(ProjectWorkspace $workspace, BattleConditionGroup $group): int
{
    $labels = $workspace->getRecordDatabase('configuration')->getEntryLabels();
    expect(array_filter($labels, static fn(string $path): bool => str_starts_with($path, $group->getConfigPath())))->toHaveCount(1);
    $index = array_search($group->getConfigPath(), $labels, true);
    expect($index)->toBeInt();

    return $index;
}

function readConditionEffects(string $root, BattleConditionGroup $group): mixed
{
    return ProjectWorkspace::fromProject($root)->config->getRecord($group->getConfigPath())->get('value');
}

/** @return list<Issue> The issues the battle condition bindings raise. */
function conditionEffectIssues(ProjectWorkspace $workspace): array
{
    return array_values(array_filter(new EffectValidator()->validate($workspace),
        static fn(Issue $issue): bool => str_starts_with($issue->where, 'config.php: ' . BattleConditionGroup::ROOT)));
}

/** The refusal an edit meets, or a failure when it is accepted. */
function refuseConditionEffect(ProjectWorkspace $workspace, int $index, string $field, string $value): string
{
    try {
        new RecordAuthoring()->applyField($workspace->getRecordDatabase('configuration'), $index, [], $field, $value, 'Binding');
    } catch (RecordRefusal $refused) {
        return $refused->getMessage();
    }
    throw new RuntimeException("{$field} = {$value} was accepted.");
}

it('binds each group from its pickers, preserving source through save, reopen and byte-restoring undo', function (BattleConditionGroup $group, array $picks, mixed $expected, bool $graphical) {
    $root = conditionEffectProject();
    $source = conditionEffectConfig($root, ['states' => "['poison' => 'looping-contact']"]);
    $before = readConditionEffects($root, BattleConditionGroup::STATES);
    $workspace = ProjectWorkspace::fromProject($root, graphical: $graphical);
    $db = $workspace->getRecordDatabase('configuration');
    $index = conditionEffectIndex($workspace, $group);
    $prefix = $group->getBindingList('value')->prefix;

    $author = new RecordAuthoring();
    $changes = [$author->addItem($db, $index, [], $prefix . 'List')];
    $rows = $db->getSettingsFields($index);
    foreach (array_keys($picks) as $field) {
        $row = array_find($rows, static fn(array $row): bool => ($row['field'] ?? null) === $field);
        expect($row)->not->toBeNull()->and($row['reference'] ?? $row['options'] ?? null)->not->toBeNull();
    }
    foreach ($picks as $field => $value) {
        $changes[] = $author->applyField($db, $index, [], $field, $value, 'Binding');
    }
    $workspace->config->save();
    $saved = (string) file_get_contents($root . '/config.php');
    expect($saved)->toContain('// authored configuration', '// condition effects stay as written', "strtoupper('kept')")
        ->and(readConditionEffects($root, $group))->toBe($expected)
        ->and(conditionEffectIssues(ProjectWorkspace::fromProject($root)))->toBe([]);
    if ($group !== BattleConditionGroup::STATES) {
        expect(readConditionEffects($root, BattleConditionGroup::STATES))->toBe($before);
    }

    foreach (array_reverse($changes) as $change) { $change->command->undo(); }
    expect($workspace->config->getSource())->toBe($source);
    $workspace->config->save();
    expect(file_get_contents($root . '/config.php'))->toBe($source);
    foreach ($changes as $change) { $change->command->execute(); }
    $workspace->config->save();
    expect(file_get_contents($root . '/config.php'))->toBe($saved);
})->with([
    'interception' => [BattleConditionGroup::INTERCEPTIONS, ['interception0State' => 'stun', 'interception0Effect' => 'contact'], ['stun' => 'contact']],
    'state aura' => [BattleConditionGroup::STATES, ['conditionState0State' => 'stun', 'conditionState0Effect' => 'looping-contact'],
        ['stun' => 'looping-contact', 'poison' => 'looping-contact']],
    'stat stage aura' => [BattleConditionGroup::STAT_STAGES, ['conditionStat0Stat' => 'speed', 'conditionStat0Negative' => 'looping-contact'],
        ['speed' => ['negative' => 'looping-contact']]],
])->with(['terminal editor' => false, 'graphical editor' => true]);

it('removes a group with its last binding and restores it byte for byte on undo', function () {
    $root = conditionEffectProject();
    $source = conditionEffectConfig($root, ['states' => "['poison' => 'looping-contact']",
        'interceptions' => "[\n        // decoy miss\n        'stun' => 'contact',\n      ]"]);
    $workspace = ProjectWorkspace::fromProject($root);
    $db = $workspace->getRecordDatabase('configuration');
    $index = conditionEffectIndex($workspace, BattleConditionGroup::INTERCEPTIONS);
    expect($db->getRecordByIndex($index)->get('value'))->toBe(['stun' => 'contact']);

    $removed = new RecordAuthoring()->removeItem($db, $index, [], 'interception0State');
    $workspace->config->save();
    expect(file_get_contents($root . '/config.php'))->not->toContain('interceptions', 'decoy miss')->toContain('// condition effects stay as written', "'states' => ['poison' => 'looping-contact']")
        ->and(readConditionEffects($root, BattleConditionGroup::INTERCEPTIONS))->toBeNull();
    $removed->command->undo();
    $workspace->config->save();
    expect(file_get_contents($root . '/config.php'))->toBe($source);
});

it('refuses timelines a group\'s rule refuses and subjects the Engine cannot bind before any change', function () {
    $root = conditionEffectProject();
    $source = conditionEffectConfig($root, ['states' => "['poison' => 'looping-contact']", 'statStages' => "['speed' => ['positive' => 'looping-contact']]",
        'interceptions' => "['stun' => 'contact']"]);
    $workspace = ProjectWorkspace::fromProject($root);
    $db = $workspace->getRecordDatabase('configuration');
    $indexes = array_map(static fn(BattleConditionGroup $group): int => conditionEffectIndex($workspace, $group), BattleConditionGroup::cases());
    [$states, $stats, $interceptions] = $indexes;
    $before = array_map(static fn(int $index): mixed => $db->getRecordByIndex($index)->toArray(), $indexes);
    $version = $db->getContentVersion();

    expect(refuseConditionEffect($workspace, $interceptions, 'interception0Effect', 'looping-contact'))->toContain(
        'Effect looping-contact cannot play as an interception for the terminal presentation', 'One-shot interception effects require fixed presentation')
        ->and(refuseConditionEffect($workspace, $states, 'conditionState0Effect', 'contact'))->toContain(
            'Effect contact cannot play as a condition effect for the terminal presentation', 'Looping condition effects require fixed presentation')
        ->and(refuseConditionEffect($workspace, $stats, 'conditionStat0Positive', 'contact'))->toContain('Looping condition effects')
        ->and(refuseConditionEffect($workspace, $interceptions, 'interception0State', 'missing-state'))->toContain('Unknown state "missing-state"')
        ->and(refuseConditionEffect($workspace, $states, 'conditionState0State', 'missing-state'))->toContain('Unknown state "missing-state"')
        ->and(refuseConditionEffect($workspace, $stats, 'conditionStat0Stat', 'luck'))->not->toBe('')
        ->and(array_map(static fn(int $index): mixed => $db->getRecordByIndex($index)->toArray(), $indexes))->toBe($before)
        ->and($db->getContentVersion())->toBe($version)
        ->and($workspace->config->isDirty())->toBeFalse()
        ->and(file_get_contents($root . '/config.php'))->toBe($source);
});

it('checks a timeline as the editor holds it, unsaved edits included, by the same rule', function () {
    $root = conditionEffectProject();
    conditionEffectConfig($root, ['interceptions' => "['poison' => 'second-contact']"]);
    $workspace = ProjectWorkspace::fromProject($root);
    $db = $workspace->getRecordDatabase('configuration');
    $interceptions = conditionEffectIndex($workspace, BattleConditionGroup::INTERCEPTIONS);
    $states = conditionEffectIndex($workspace, BattleConditionGroup::STATES);
    $contact = $workspace->cutscenes->find(CutsceneType::EFFECT, 'contact');
    // The saved timeline plays once; the unsaved edit makes it loop.
    $contact->apply([...$contact->payload(), 'playback' => 'loop']);

    expect(refuseConditionEffect($workspace, $interceptions, 'interception0Effect', 'contact'))->toContain('One-shot interception effects');
    $author = new RecordAuthoring();
    $author->addItem($db, $states, [], 'conditionStateList');
    $author->applyField($db, $states, [], 'conditionState0State', 'stun', 'State');
    $author->applyField($db, $states, [], 'conditionState0Effect', 'contact', 'Effect');
    expect($db->getRecordByIndex($states)->get('value'))->toBe(['stun' => 'contact'])
        ->and(conditionEffectIssues($workspace))->toBe([]);

    $workspace->cutscenes->find(CutsceneType::EFFECT, 'second-contact')->markDeleted(true);
    $issues = conditionEffectIssues($workspace);
    expect(array_column(array_map(static fn(Issue $issue): array => $issue->describe(), $issues), 'where'))
        ->toBe(array_fill(0, 2, BattleConditionGroup::INTERCEPTIONS->describeBinding('poison', null)))
        ->and($issues[0]->message)->toContain('second-contact was not found');
});

it('refuses editing bindings the file authors as expressions, leaving the source as written', function (array $groups, BattleConditionGroup $group, string $field, string $value) {
    $root = conditionEffectProject();
    $source = conditionEffectConfig($root, $groups);
    $workspace = ProjectWorkspace::fromProject($root);
    $index = conditionEffectIndex($workspace, $group);
    $before = $workspace->getRecordDatabase('configuration')->getRecordByIndex($index)->toArray();
    expect(refuseConditionEffect($workspace, $index, $field, $value))->not->toBe('')
        ->and($workspace->getRecordDatabase('configuration')->getRecordByIndex($index)->toArray())->toBe($before)
        ->and(file_get_contents($root . '/config.php'))->toBe($source);
})->with([
    'whole interception group' => [['interceptions' => "array_merge(['stun' => 'contact'])"], BattleConditionGroup::INTERCEPTIONS, 'interception0State', 'poison'],
    'one interception effect' => [['interceptions' => "['stun' => strtolower('CONTACT')]"], BattleConditionGroup::INTERCEPTIONS, 'interception0Effect', 'second-contact'],
    'whole state group' => [['states' => "array_merge(['stun' => 'looping-contact'])"], BattleConditionGroup::STATES, 'conditionState0State', 'poison'],
    'whole stat stage group' => [['statStages' => "array_merge(['speed' => ['positive' => 'looping-contact']])"], BattleConditionGroup::STAT_STAGES,
        'conditionStat0Stat', 'attack'],
]);

it('moves a binding to another state in place, keeping its authored effect expression and comments', function () {
    $root = conditionEffectProject();
    conditionEffectConfig($root, ['interceptions' => "[\n        // decoy miss\n        'stun' => strtolower('CONTACT'),\n      ]"]);
    $workspace = ProjectWorkspace::fromProject($root);
    $index = conditionEffectIndex($workspace, BattleConditionGroup::INTERCEPTIONS);
    new RecordAuthoring()->applyField($workspace->getRecordDatabase('configuration'), $index, [], 'interception0State', 'poison', 'State');
    $workspace->config->save();
    expect(file_get_contents($root . '/config.php'))->toContain("// decoy miss\n        'poison' => strtolower('CONTACT'),")
        ->and(readConditionEffects($root, BattleConditionGroup::INTERCEPTIONS))->toBe(['poison' => 'contact']);
});

it('reports bindings to unknown states, refused timelines and malformed groups once each', function () {
    $root = conditionEffectProject();
    conditionEffectConfig($root, ['states' => "['ghost' => 'looping-contact', 'stun' => 'contact']",
        'statStages' => "['speed' => ['positive' => 'contact']]", 'interceptions' => "['ghost' => 'contact', 'stun' => 'looping-contact']"]);
    $workspace = ProjectWorkspace::fromProject($root);
    $issue = static fn(BattleConditionGroup $group, string $subject, ?string $role, string $message): array
        => ['error', $group->describeBinding($subject, $role), $message];
    $refused = static fn(string $effect, string $as, string $rule): array => array_map(static fn(string $presentation): string
        => sprintf('Effect %s cannot play as %s for the %s presentation: %s effects require fixed presentation without cues or a stage.', $effect, $as, $presentation, $rule),
        ['terminal', 'graphical']);
    [$looping, $loopingGraphical] = $refused('contact', 'a condition effect', 'Looping condition');
    [$oneShot, $oneShotGraphical] = $refused('looping-contact', 'an interception', 'One-shot interception');
    expect(array_map(static fn(Issue $issue): array => [$issue->severity->value, $issue->where, $issue->message], conditionEffectIssues($workspace)))->toEqualCanonicalizing([
        $issue(BattleConditionGroup::STATES, 'ghost', null, 'Its effect looping-contact is bound to state ghost, which the project does not define.'),
        $issue(BattleConditionGroup::STATES, 'stun', null, $looping),
        $issue(BattleConditionGroup::STATES, 'stun', null, $loopingGraphical),
        $issue(BattleConditionGroup::STAT_STAGES, 'speed', 'positive', $looping),
        $issue(BattleConditionGroup::STAT_STAGES, 'speed', 'positive', $loopingGraphical),
        $issue(BattleConditionGroup::INTERCEPTIONS, 'ghost', null, 'Its effect contact is bound to state ghost, which the project does not define.'),
        $issue(BattleConditionGroup::INTERCEPTIONS, 'stun', null, $oneShot),
        $issue(BattleConditionGroup::INTERCEPTIONS, 'stun', null, $oneShotGraphical),
    ])->and(array_filter(new RecordReferenceValidator()->validate($workspace), static fn(Issue $issue): bool => str_contains($issue->where, BattleConditionGroup::ROOT)))->toBe([]);

    conditionEffectConfig($root, ['statStages' => "['luck' => ['positive' => 'looping-contact']]", 'interceptions' => "['stun' => 5]"]);
    expect(array_map(static fn(Issue $issue): array => [$issue->where, $issue->message], conditionEffectIssues(ProjectWorkspace::fromProject($root))))->toEqualCanonicalizing([
        ['config.php: ' . BattleConditionGroup::STAT_STAGES->getConfigPath(), 'Its battle condition effects are invalid: Stat effects require a buffable stat and positive/negative roles.'],
        ['config.php: ' . BattleConditionGroup::INTERCEPTIONS->getConfigPath(), 'Its battle condition effects are invalid: Condition effects reference timeline IDs.'],
    ]);
});

it('binds a condition effect through the shared session with undo, as the graphical editor does', function () {
    $root = conditionEffectProject();
    $source = conditionEffectConfig($root, []);
    $session = EditorSession::open($root);
    $path = BattleConditionGroup::INTERCEPTIONS->getConfigPath();
    $index = array_search($path, $session->listDatabaseRecords('configuration')['records'], true);
    expect($index)->toBeInt();
    $row = static fn(string $field): array => array_find($session->readDatabaseRecord('configuration', $index)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field) ?? throw new RuntimeException("No row {$field}.");
    // Only the group's own list is offered on its row.
    expect(array_filter(array_column(array_column($session->readDatabaseRecord('configuration', $index)['rows'], 'key'), 'field'),
        static fn(mixed $field): bool => is_string($field) && str_starts_with($field, 'conditionState')))->toBe([]);

    $session->addDatabaseItem('configuration', $index, $row('interceptionList')['key']);
    expect($session->applyDatabaseRecord('configuration', $index, $row('interception0State')['key'], 'stun')['changed'])->toBeTrue()
        ->and($session->applyDatabaseRecord('configuration', $index, $row('interception0Effect')['key'], 'contact')['changed'])->toBeTrue();
    $session->undo();
    expect($row('interception0Effect')['value'])->not->toBe('contact');
    $session->redo();
    expect($session->saveAll()['failures'])->toBe([])
        ->and(readConditionEffects($root, BattleConditionGroup::INTERCEPTIONS))->toBe(['stun' => 'contact'])
        ->and(file_get_contents($root . '/config.php'))->toContain('// authored configuration');

    $saved = file_get_contents($root . '/config.php');
    $shown = $row('interception0Effect')['value'];
    expect(fn() => $session->applyDatabaseRecord('configuration', $index, $row('interception0Effect')['key'], 'looping-contact'))
        ->toThrow(SessionRefusal::class, 'One-shot interception')
        ->and($row('interception0Effect')['value'])->toBe($shown)
        ->and($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($root . '/config.php'))->toBe($saved)->not->toBe($source);
});
