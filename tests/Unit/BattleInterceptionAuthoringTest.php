<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\RecordAuthoring;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\ProjectConfig;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\Validation\EffectValidator;
use Ichiloto\Editor\Validation\Issue;

/**
 * The contact effects states play when they intercept an attack, chosen in Configuration from the state and
 * effect pickers and checked by the Engine's interception rule before they are written. Synthetic fixtures only:
 * the sample project's states and synthetic timelines, two that play once and one that loops.
 */
function interceptionProject(): string
{
    $root = makeTemporaryProject('editor-interception-');
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

/** Writes a config.php whose battle conditions hold this interceptions source, beside authored comments and expressions. */
function interceptionConfig(string $root, ?string $interceptions): string
{
    $group = $interceptions === null ? '' : "\n      'interceptions' => $interceptions,";
    $source = "<?php\n// authored configuration\nreturn [\n  'vocab' => ['title' => 'Original'],\n  'untouched' => strtoupper('kept'),\n"
        . "  'ui' => ['battle' => ['conditions' => [\n      // looping auras stay as written\n      'states' => ['poison' => 'looping-contact'],$group\n  ]]],\n];\n";
    file_put_contents($root . '/config.php', $source);

    return $source;
}

function interceptionIndex(ProjectWorkspace $workspace): int
{
    $index = array_search(ProjectConfig::BATTLE_INTERCEPTIONS, $workspace->getRecordDatabase('configuration')->getEntryLabels(), true);
    expect($index)->toBeInt();

    return $index;
}

/** @return list<Issue> The issues the interception bindings raise. */
function interceptionIssues(ProjectWorkspace $workspace): array
{
    return array_values(array_filter(new EffectValidator()->validate($workspace),
        static fn(Issue $issue): bool => str_starts_with($issue->where, 'config.php: ' . ProjectConfig::BATTLE_INTERCEPTIONS)));
}

it('binds an interception effect from the state and effect pickers, preserving source through save, reopen and undo', function (bool $graphical) {
    $root = interceptionProject();
    $source = interceptionConfig($root, null);
    $workspace = ProjectWorkspace::fromProject($root, graphical: $graphical);
    $db = $workspace->getRecordDatabase('configuration');
    $index = interceptionIndex($workspace);
    expect(array_filter($db->getEntryLabels(), static fn(string $path): bool => str_starts_with($path, ProjectConfig::BATTLE_INTERCEPTIONS)))->toHaveCount(1);

    $author = new RecordAuthoring();
    $add = $author->addItem($db, $index, [], 'interceptionList');
    $rows = $db->getSettingsFields($index);
    $state = array_find($rows, static fn(array $row): bool => ($row['field'] ?? null) === 'interception0State');
    $effect = array_find($rows, static fn(array $row): bool => ($row['field'] ?? null) === 'interception0Effect');
    expect($state['reference'])->toBe('states')->and($effect['reference'])->toBe('effects')
        ->and(fn() => $workspace->config->save())->toThrow(InvalidArgumentException::class)
        ->and(file_get_contents($root . '/config.php'))->toBe($source);

    $pickState = $author->applyField($db, $index, [], 'interception0State', 'stun', 'State');
    $pickEffect = $author->applyField($db, $index, [], 'interception0Effect', 'contact', 'Effect');
    $workspace->config->save();
    $saved = (string) file_get_contents($root . '/config.php');
    expect($saved)->toContain('// authored configuration', '// looping auras stay as written', "strtoupper('kept')")
        ->and(ProjectWorkspace::fromProject($root)->config->getRecord(ProjectConfig::BATTLE_INTERCEPTIONS)->get('value'))->toBe(['stun' => 'contact'])
        ->and(ProjectWorkspace::fromProject($root)->config->getRecord('ui.battle.conditions.states.poison')->get('value'))->toBe('looping-contact')
        ->and(interceptionIssues(ProjectWorkspace::fromProject($root)))->toBe([]);

    $pickEffect->command->undo();
    $pickState->command->undo();
    $add->command->undo();
    expect($workspace->config->getSource())->toBe($source);
    $workspace->config->save();
    expect(file_get_contents($root . '/config.php'))->toBe($source);
    $add->command->execute();
    $pickState->command->execute();
    $pickEffect->command->execute();
    $workspace->config->save();
    expect(file_get_contents($root . '/config.php'))->toBe($saved);
})->with(['terminal editor' => false, 'graphical editor' => true]);

it('removes the group with its last binding and restores it byte for byte on undo', function () {
    $root = interceptionProject();
    $source = interceptionConfig($root, "[\n        // decoy miss\n        'stun' => 'contact',\n      ]");
    $workspace = ProjectWorkspace::fromProject($root);
    $db = $workspace->getRecordDatabase('configuration');
    $index = interceptionIndex($workspace);
    expect($db->getRecordByIndex($index)->get('value'))->toBe(['stun' => 'contact']);

    $removed = new RecordAuthoring()->removeItem($db, $index, [], 'interception0State');
    $workspace->config->save();
    $after = (string) file_get_contents($root . '/config.php');
    expect($after)->not->toContain('interceptions')->toContain('// looping auras stay as written', "'states' => ['poison' => 'looping-contact']")
        ->and(ProjectWorkspace::fromProject($root)->config->getRecord(ProjectConfig::BATTLE_INTERCEPTIONS)->get('value'))->toBeNull();
    $removed->command->undo();
    $workspace->config->save();
    expect(file_get_contents($root . '/config.php'))->toBe($source);
});

it('refuses a timeline the interception rule refuses, an unknown state and an unsaved timeline before any change', function () {
    $root = interceptionProject();
    $source = interceptionConfig($root, "['stun' => 'contact']");
    $workspace = ProjectWorkspace::fromProject($root);
    $db = $workspace->getRecordDatabase('configuration');
    $index = interceptionIndex($workspace);
    $author = new RecordAuthoring();
    $before = $db->getRecordByIndex($index)->toArray();
    $version = $db->getContentVersion();
    $refusal = static function (string $field, string $value) use ($author, $db, $index): string {
        try {
            $author->applyField($db, $index, [], $field, $value, 'Binding');
        } catch (RecordRefusal $refused) {
            return $refused->getMessage();
        }
        throw new RuntimeException("{$field} = {$value} was accepted.");
    };

    expect($refusal('interception0Effect', 'looping-contact'))->toContain('Effect looping-contact cannot play as an interception for the terminal presentation',
        'One-shot interception effects require fixed presentation')
        ->and($refusal('interception0State', 'missing-state'))->toContain('Unknown state "missing-state"');
    $workspace->cutscenes->find(CutsceneType::EFFECT, 'contact')->markDeleted(true);
    expect($refusal('interception0State', 'poison'))->toContain('Effect timeline contact has unsaved edits')
        ->and($db->getRecordByIndex($index)->toArray())->toBe($before)
        ->and($db->getContentVersion())->toBe($version)
        ->and($workspace->config->isDirty())->toBeFalse()
        ->and(file_get_contents($root . '/config.php'))->toBe($source);
});

it('refuses editing bindings the file authors as expressions, leaving the source as written', function (string $interceptions, string $field, string $value) {
    $root = interceptionProject();
    $source = interceptionConfig($root, $interceptions);
    $workspace = ProjectWorkspace::fromProject($root);
    $db = $workspace->getRecordDatabase('configuration');
    $index = interceptionIndex($workspace);
    $before = $db->getRecordByIndex($index)->toArray();
    expect(fn() => new RecordAuthoring()->applyField($db, $index, [], $field, $value, 'Binding'))->toThrow(RecordRefusal::class)
        ->and($db->getRecordByIndex($index)->toArray())->toBe($before)
        ->and(file_get_contents($root . '/config.php'))->toBe($source);
})->with([
    'whole group' => ["array_merge(['stun' => 'contact'])", 'interception0State', 'poison'],
    'one effect' => ["['stun' => strtolower('CONTACT')]", 'interception0Effect', 'second-contact'],
]);

it('moves a binding to another state in place, keeping its authored effect expression and comments', function () {
    $root = interceptionProject();
    interceptionConfig($root, "[\n        // decoy miss\n        'stun' => strtolower('CONTACT'),\n      ]");
    $workspace = ProjectWorkspace::fromProject($root);
    $db = $workspace->getRecordDatabase('configuration');
    $index = interceptionIndex($workspace);
    new RecordAuthoring()->applyField($db, $index, [], 'interception0State', 'poison', 'State');
    $workspace->config->save();
    expect(file_get_contents($root . '/config.php'))->toContain("// decoy miss\n        'poison' => strtolower('CONTACT'),")
        ->and(ProjectWorkspace::fromProject($root)->config->getRecord(ProjectConfig::BATTLE_INTERCEPTIONS)->get('value'))->toBe(['poison' => 'contact']);
});

it('reports bindings to unknown states, refused timelines and malformed groups', function () {
    $root = interceptionProject();
    interceptionConfig($root, "['ghost' => 'contact', 'stun' => 'looping-contact']");
    $issues = interceptionIssues(ProjectWorkspace::fromProject($root));
    $where = 'config.php: ' . ProjectConfig::BATTLE_INTERCEPTIONS;
    expect(array_map(static fn(Issue $issue): array => [$issue->severity->value, $issue->where, $issue->message], $issues))->toEqualCanonicalizing([
        ['error', $where . '.ghost', 'Its interception effect contact is bound to state ghost, which the project does not define.'],
        ['error', $where . '.stun', 'Effect looping-contact cannot play as an interception for the terminal presentation: One-shot interception effects require fixed presentation without cues or a stage.'],
        ['error', $where . '.stun', 'Effect looping-contact cannot play as an interception for the graphical presentation: One-shot interception effects require fixed presentation without cues or a stage.'],
    ]);

    interceptionConfig($root, "['stun' => 5]");
    $issues = interceptionIssues(ProjectWorkspace::fromProject($root));
    expect($issues)->toHaveCount(1)
        ->and($issues[0]->message)->toBe('Its interception effects are invalid: Condition effects reference timeline IDs.');

    interceptionConfig($root, "['stun' => 'contact']");
    $workspace = ProjectWorkspace::fromProject($root);
    $workspace->cutscenes->find(CutsceneType::EFFECT, 'contact')->markDeleted(true);
    $issues = interceptionIssues($workspace);
    expect($issues)->toHaveCount(1)->and($issues[0]->severity->value)->toBe('warning')
        ->and($issues[0]->message)->toBe('Effect contact has unsaved edits, so its interception is checked once it is saved.');
});

it('binds an interception effect through the shared session with undo, as the graphical editor does', function () {
    $root = interceptionProject();
    $source = interceptionConfig($root, null);
    $session = EditorSession::open($root);
    $index = array_search(ProjectConfig::BATTLE_INTERCEPTIONS, $session->listDatabaseRecords('configuration')['records'], true);
    expect($index)->toBeInt();
    $row = static fn(string $field): array => array_find($session->readDatabaseRecord('configuration', $index)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field) ?? throw new RuntimeException("No row {$field}.");

    $session->addDatabaseItem('configuration', $index, $row('interceptionList')['key']);
    expect($session->applyDatabaseRecord('configuration', $index, $row('interception0State')['key'], 'stun')['changed'])->toBeTrue()
        ->and($session->applyDatabaseRecord('configuration', $index, $row('interception0Effect')['key'], 'contact')['changed'])->toBeTrue();
    $session->undo();
    expect($row('interception0Effect')['value'])->not->toBe('contact');
    $session->redo();
    expect($session->saveAll()['failures'])->toBe([])
        ->and(ProjectWorkspace::fromProject($root)->config->getRecord(ProjectConfig::BATTLE_INTERCEPTIONS)->get('value'))->toBe(['stun' => 'contact'])
        ->and(file_get_contents($root . '/config.php'))->toContain('// authored configuration');

    $saved = file_get_contents($root . '/config.php');
    $shown = $row('interception0Effect')['value'];
    expect(fn() => $session->applyDatabaseRecord('configuration', $index, $row('interception0Effect')['key'], 'looping-contact'))
        ->toThrow(SessionRefusal::class, 'One-shot interception')
        ->and($row('interception0Effect')['value'])->toBe($shown)
        ->and($session->saveAll()['failures'])->toBe([])
        ->and(file_get_contents($root . '/config.php'))->toBe($saved)->not->toBe($source);
});
