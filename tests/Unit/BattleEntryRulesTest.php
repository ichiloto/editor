<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\BattleEntryPredicateCodec;
use Ichiloto\Editor\Database\BattleEntryRuleContract;
use Ichiloto\Editor\Database\WorldWriteEditor;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;

it('writes nothing when the absent optional file is browsed or validated', function (): void {
    $root = makeTemporaryProject();

    try {
        $dataDirectory = $root . '/assets/Data';
        $before = [];

        foreach (glob($dataDirectory . '/*.php') ?: [] as $file) {
            $before[$file] = [md5((string) file_get_contents($file)), filemtime($file)];
        }

        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'battle_entry_rules');
        callEditorMethod($editor, 'getDatabaseSettingsFields');
        callEditorMethod($editor, 'saveActiveDatabase');

        expect(battleEntryIssues($root))->toBe([])
            ->and(is_file(battleEntryRulesPath($root)))->toBeFalse();

        clearstatcache();

        foreach ($before as $file => [$hash, $mtime]) {
            expect(md5((string) file_get_contents($file)))->toBe($hash)
                ->and(filemtime($file))->toBe($mtime);
        }
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('creates one valid engine-shaped file on the first authored save', function (): void {
    $root = makeTemporaryProject();

    try {
        $database = loadRecordDatabase($root, 'battle_entry_rules');
        $index = $database->addRecord();
        $database->setField($index, 'id', 'formation.entry-speed');
        $database->setField($index, 'priority', '20');
        $database->setField($index, 'classification', 'boss');
        $database->setField($index, 'actors', 'Kaelion:active');
        $database->setField($index, 'conditions', 'switch:formation_ready');
        $database->setField($index, 'writes', 'variable:formation_step:add:1');
        $database->setField($index, 'effect0Actor', 'Kaelion');
        $database->setField($index, 'effect0Stat', 'speed');
        $database->setField($index, 'effect0Delta', '2');
        $database->save();

        $source = (string) file_get_contents(battleEntryRulesPath($root));

        expect($source)->toContain("'rules' =>")
            ->and(BattleEntryRuleContract::problems(require battleEntryRulesPath($root), 'the file'))->toBe([]);

        $reloaded = loadRecordDatabase($root, 'battle_entry_rules');

        expect($reloaded->getRecordByIndex(0)?->toArray())->toBe([
            'id' => 'formation.entry-speed',
            'actors' => [['actor' => 'Kaelion', 'presence' => 'active']],
            'effects' => [['type' => 'stat_stage', 'actor' => 'Kaelion', 'stat' => 'speed', 'delta' => 2]],
            'priority' => 20,
            'classification' => 'boss',
            'conditions' => [['type' => 'switch', 'name' => 'formation_ready']],
            'writes' => [['type' => 'variable', 'name' => 'formation_step', 'op' => 'add', 'value' => 1]],
        ]);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('authors classifications explicitly and defaults them silently', function (): void {
    $root = makeTemporaryProject();

    try {
        $database = loadRecordDatabase($root, 'battle_entry_rules');
        $index = $database->addRecord();
        $database->setField($index, 'id', 'quiet-rule');
        $database->setField($index, 'actors', 'Kaelion:any');
        $database->setField($index, 'effect0Actor', 'Kaelion');
        $database->save();

        $payload = require battleEntryRulesPath($root);

        // Browsing and saving never forced the optional keys into the data.
        expect(array_key_exists('classification', $payload['rules'][0]))->toBeFalse()
            ->and(array_key_exists('priority', $payload['rules'][0]))->toBeFalse();

        $fields = $database->getSettingsFields(0);
        $byField = array_column($fields, 'value', 'field');

        // The pane reads absence as what the runtime will do with it.
        expect($byField['classification'])->toBe('ordinary')
            ->and($byField['priority'])->toBe('0');
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('builds predicates through the editor with the actor picker and presence cycle', function (): void {
    $root = makeTemporaryProject();
    addProjectActor($root, 'Mira', 'Mira', 'actor.mira');

    try {
        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'battle_entry_rules');
        callEditorMethod($editor, 'createDatabaseEntry');

        $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
        $actorsIndex = array_search('actors', array_column($fields, 'field'), true);

        expect($fields[$actorsIndex]['actorPredicates'] ?? null)->toBeTrue();

        setEditorProperty($editor, 'databaseSelectedSettingIndex', $actorsIndex);
        callEditorMethod($editor, 'beginDatabaseEdit');

        $predicateEditor = getEditorProperty($editor, 'battleEntryPredicateEditor');
        expect($predicateEditor->isOpen())->toBeTrue()
            ->and($predicateEditor->count())->toBe(0);

        // A fresh predicate is completed by picking a durable identity.
        callEditorMethod($editor, 'handleBattleEntryPredicateEditorInput', 'a');
        callEditorMethod($editor, 'handleBattleEntryPredicateEditorInput', 'n');
        $picker = getEditorProperty($editor, 'referencePicker');
        expect($picker->isOpen())->toBeTrue();

        // The picker offers durable ids, labelled with the display name.
        callEditorMethod($editor, 'handleReferencePickerInput', 'a');
        callEditorMethod($editor, 'handleReferencePickerInput', 'c');
        callEditorMethod($editor, 'handleReferencePickerInput', "\r");
        expect($predicateEditor->selected()['actor'] ?? null)->toBe('actor.mira');

        // Presence cycles through the engine's rosters.
        callEditorMethod($editor, 'handleBattleEntryPredicateEditorInput', 'x');
        expect($predicateEditor->selected()['presence'] ?? null)->toBe('reserve');
        callEditorMethod($editor, 'handleBattleEntryPredicateEditorInput', 'x');
        expect($predicateEditor->selected()['presence'] ?? null)->toBe('any');

        // A second predicate rides the same list.
        callEditorMethod($editor, 'handleBattleEntryPredicateEditorInput', 'a');
        $picked = getEditorProperty($editor, 'battleEntryPredicateEditor');
        $picked->setActor('Kaelion');

        callEditorMethod($editor, 'handleBattleEntryPredicateEditorInput', "\r");

        $database = getEditorProperty($editor, 'workspace')->getRecordDatabase('battle_entry_rules');
        $record = $database->getRecordByIndex(0)?->toArray();

        expect($record['actors'])->toBe([
            ['actor' => 'actor.mira', 'presence' => 'any'],
            ['actor' => 'Kaelion', 'presence' => 'active'],
        ]);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('persists every stage-capable stat and exact signed deltas', function (): void {
    $root = makeTemporaryProject();

    try {
        $database = loadRecordDatabase($root, 'battle_entry_rules');
        $index = $database->addRecord();
        $database->setField($index, 'id', 'all-stats');
        $database->setField($index, 'actors', 'Kaelion:any');

        $stats = ['attack', 'defence', 'magicAttack', 'magicDefence', 'speed', 'grace', 'evasion'];
        $deltas = [1, -1, 2, -2, 3, -4, 4];

        foreach ($stats as $statIndex => $stat) {
            if ($statIndex > 0) {
                $database->addSubItem($index);
            }

            $database->setField($index, sprintf('effect%dActor', $statIndex), 'Kaelion');
            $database->setField($index, sprintf('effect%dStat', $statIndex), $stat);
            $database->setField($index, sprintf('effect%dDelta', $statIndex), strval($deltas[$statIndex]));
        }

        $database->save();

        $payload = require battleEntryRulesPath($root);
        $effects = $payload['rules'][0]['effects'];

        expect(array_column($effects, 'stat'))->toBe($stats)
            ->and(array_column($effects, 'delta'))->toBe($deltas)
            ->and(battleEntryIssues($root))->toBe([]);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('round-trips shared conditions and reversible writes', function (): void {
    $root = makeTemporaryProject();

    try {
        $database = loadRecordDatabase($root, 'battle_entry_rules');
        $index = $database->addRecord();
        $database->setField($index, 'id', 'conditioned');
        $database->setField($index, 'actors', 'Kaelion:active');
        $database->setField($index, 'effect0Actor', 'Kaelion');
        $database->setField($index, 'conditions', 'switch:gate_open; !variable:donations:>=:100');
        $database->setField($index, 'writes', 'switch:gate_open:false; event:met_the_king; variable:step:add:2');
        $database->save();

        $rule = (require battleEntryRulesPath($root))['rules'][0];

        expect($rule['conditions'])->toBe([
            ['type' => 'switch', 'name' => 'gate_open'],
            ['type' => 'variable', 'name' => 'donations', 'op' => '>=', 'value' => 100, 'negate' => true],
        ])->and($rule['writes'])->toBe([
            ['type' => 'switch', 'name' => 'gate_open', 'value' => false],
            ['type' => 'event', 'name' => 'met_the_king'],
            ['type' => 'variable', 'name' => 'step', 'op' => 'add', 'value' => 2],
        ]);

        $reloaded = loadRecordDatabase($root, 'battle_entry_rules');
        $fields = $reloaded->getSettingsFields(0);
        $byField = array_column($fields, 'value', 'field');

        expect($byField['conditions'])->toBe('switch:gate_open; !variable:donations:>=:100')
            ->and($byField['writes'])->toBe('switch:gate_open:false; event:met_the_king; variable:step:add:2');
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('never offers quest writes here, and diagnoses one found in source', function (): void {
    $root = makeTemporaryProject();

    try {
        // The field itself restricts the vocabulary.
        $database = loadRecordDatabase($root, 'battle_entry_rules');
        $index = $database->addRecord();
        $fields = $database->getSettingsFields($index);
        $writesField = $fields[array_search('writes', array_column($fields, 'field'), true)];

        expect($writesField['writeTypes'] ?? null)->toBe(['switch', 'event', 'variable']);

        // A restricted write editor cycles without ever reaching quest.
        $writeEditor = new WorldWriteEditor();
        $writeEditor->open('writes', 'World Writes', [['type' => 'switch', 'name' => 'x']], ['switch', 'event', 'variable']);
        $seen = [];

        for ($step = 0; $step < 6; $step++) {
            $writeEditor->cycleType(1);
            $seen[] = $writeEditor->selected()['type'];
        }

        expect(array_unique($seen))->not->toContain('quest');

        // An unrestricted surface keeps the full historical vocabulary.
        $writeEditor->open('sets', 'After Talking', [['type' => 'switch', 'name' => 'x']]);
        $seen = [];

        for ($step = 0; $step < 4; $step++) {
            $writeEditor->cycleType(1);
            $seen[] = $writeEditor->selected()['type'];
        }

        expect($seen)->toContain('quest');

        // A quest write already in the source is diagnosed, not rewritten.
        writeBattleEntryRules($root, <<<'PHP'
        <?php

        return [
          'rules' => [
            [
              'id' => 'granting',
              'actors' => [['actor' => 'Kaelion', 'presence' => 'any']],
              'effects' => [['type' => 'stat_stage', 'actor' => 'Kaelion', 'stat' => 'speed', 'delta' => 1]],
              'writes' => [['type' => 'quest', 'name' => 'main-quest']],
            ],
          ],
        ];
        PHP);

        expect(battleEntryProblemMessages($root))->toBe([
            'assets/Data/battle-entry-rules.php rule "granting" field "writes"[0] field "type" cannot use quest acceptance in an atomic transaction; write a reversible switch, event, or variable instead.',
        ]);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('fails duplicate stable rule ids', function (): void {
    $root = makeTemporaryProject();

    try {
        writeBattleEntryRules($root, <<<'PHP'
        <?php

        return [
          'rules' => [
            [
              'id' => 'twin',
              'actors' => [['actor' => 'Kaelion', 'presence' => 'any']],
              'effects' => [['type' => 'stat_stage', 'actor' => 'Kaelion', 'stat' => 'speed', 'delta' => 1]],
            ],
            [
              'id' => 'twin',
              'actors' => [['actor' => 'Kaelion', 'presence' => 'any']],
              'effects' => [['type' => 'stat_stage', 'actor' => 'Kaelion', 'stat' => 'grace', 'delta' => 1]],
            ],
          ],
        ];
        PHP);

        expect(battleEntryProblemMessages($root))->toBe([
            'assets/Data/battle-entry-rules.php rule "twin" duplicates a stable rule ID.',
        ]);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('keeps missing, ambiguous, and contested actor identities visible', function (): void {
    $root = makeTemporaryProject();

    try {
        writeBattleEntryRules($root, <<<'PHP'
        <?php

        return [
          'rules' => [
            [
              'id' => 'ghost-rule',
              'actors' => [['actor' => 'Nobody', 'presence' => 'any']],
              'effects' => [['type' => 'stat_stage', 'actor' => 'Kaelion', 'stat' => 'speed', 'delta' => 1]],
            ],
          ],
        ];
        PHP);

        // An unknown actor is reported with the engine's wording, never
        // silently substituted.
        expect(battleEntryProblemMessages($root))->toBe([
            'assets/Data/battle-entry-rules.php rule "ghost-rule" field "actors[0]" field "actor" references unknown actor "Nobody".',
        ]);

        // A reference two definitions claim is contested, and the rules are
        // then checked shape-only, exactly as the runtime never reaches them.
        addProjectActor($root, 'Impostor', 'Kaelion', 'actor.impostor');

        $messages = battleEntryProblemMessages($root);

        expect($messages)->toContain('Actor reference "kaelion" is ambiguous.')
            ->and($messages)->not->toContain(
                'assets/Data/battle-entry-rules.php rule "ghost-rule" field "actors[0]" field "actor" references unknown actor "Nobody".',
            );

        // Two definitions sharing one identity are duplicates.
        unlink($root . '/assets/Data/Actors/Impostor.php');
        addProjectActor($root, 'EchoOne', 'Echo', 'actor.echo');
        addProjectActor($root, 'EchoTwo', 'Echo Again', 'actor.echo');

        expect(battleEntryProblemMessages($root))->toContain('Duplicate actor definition identity: actor.echo.');
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('fails unsupported classifications, presences, effect types, stats and deltas with the engine wording', function (): void {
    $root = makeTemporaryProject();

    try {
        writeBattleEntryRules($root, <<<'PHP'
        <?php

        return [
          'rules' => [
            [
              'id' => 'broken',
              'classification' => 'epic',
              'actors' => [['actor' => 'Kaelion', 'presence' => 'benched']],
              'effects' => [
                ['type' => 'heal', 'actor' => 'Kaelion'],
                ['type' => 'stat_stage', 'actor' => 'Kaelion', 'stat' => 'luck', 'delta' => 1],
                ['type' => 'stat_stage', 'actor' => 'Kaelion', 'stat' => 'maxHp', 'delta' => 1],
                ['type' => 'stat_stage', 'actor' => 'Kaelion', 'stat' => 'speed', 'delta' => 'fast'],
              ],
            ],
          ],
        ];
        PHP);

        expect(battleEntryProblemMessages($root))->toBe([
            'assets/Data/battle-entry-rules.php rule "broken" field "classification" has unsupported value "epic"; expected one of: ordinary, boss.',
            'assets/Data/battle-entry-rules.php rule "broken" field "actors[0]" field "presence" has unsupported value "benched"; expected one of: active, reserve, any.',
            'assets/Data/battle-entry-rules.php rule "broken" field "effects[0]" field "type" has unsupported effect "heal"; expected "stat_stage".',
            'assets/Data/battle-entry-rules.php rule "broken" field "effects[1]" field "stat" is invalid: Unknown stat key: luck.',
            'assets/Data/battle-entry-rules.php rule "broken" field "effects[2]" field "stat" references "maxHp", which does not support temporary battle stages.',
            'assets/Data/battle-entry-rules.php rule "broken" field "effects[3]" field "delta" must be a signed integer.',
        ]);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('fails empty required predicate and effect lists', function (): void {
    $root = makeTemporaryProject();

    try {
        writeBattleEntryRules($root, <<<'PHP'
        <?php

        return [
          'rules' => [
            ['id' => 'hollow', 'actors' => [], 'effects' => []],
          ],
        ];
        PHP);

        expect(battleEntryProblemMessages($root))->toBe([
            'assets/Data/battle-entry-rules.php rule "hollow" field "actors" must be a non-empty list.',
            'assets/Data/battle-entry-rules.php rule "hollow" field "effects" must be a non-empty list.',
        ]);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('validates a troop classification with the runtime wording', function (): void {
    $root = makeTemporaryProject();

    try {
        $troops = loadRecordDatabase($root, 'troops');
        $fields = $troops->getSettingsFields(0);
        $byField = array_column($fields, 'value', 'field');

        // The optional picker reads absence as the runtime default.
        expect($byField['classification'] ?? null)->toBe('ordinary');

        $troops->setField(0, 'classification', 'boss');
        $troops->save();

        $troopsPath = $root . '/assets/Data/troops.php';

        expect((require $troopsPath)[0]['classification'])->toBe('boss');

        $source = (string) file_get_contents($troopsPath);
        file_put_contents($troopsPath, str_replace("'boss'", "'epic'", $source));

        $workspace = ProjectWorkspace::fromProject($root);
        $issues = new ProjectValidator()->validate($workspace);
        $messages = array_map(static fn(Issue $issue): string => $issue->message, $issues);

        expect($messages)->toContain(
            'Data/troops.php troop "Bat x 2" field "classification" has unsupported value "epic"; expected one of: ordinary, boss.',
        );
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('presents the cue in priority then declaration order, deterministically', function (): void {
    $root = makeTemporaryProject();

    try {
        writeBattleEntryRules($root, <<<'PHP'
        <?php

        return [
          'rules' => [
            [
              'id' => 'third-by-priority',
              'priority' => 20,
              'actors' => [['actor' => 'Kaelion', 'presence' => 'any']],
              'effects' => [['type' => 'stat_stage', 'actor' => 'Kaelion', 'stat' => 'speed', 'delta' => 1]],
            ],
            [
              'id' => 'first-declared-of-ties',
              'actors' => [['actor' => 'Kaelion', 'presence' => 'any']],
              'effects' => [['type' => 'stat_stage', 'actor' => 'Kaelion', 'stat' => 'grace', 'delta' => 1]],
            ],
            [
              'id' => 'second-declared-of-ties',
              'actors' => [['actor' => 'Kaelion', 'presence' => 'any']],
              'effects' => [['type' => 'stat_stage', 'actor' => 'Kaelion', 'stat' => 'evasion', 'delta' => 1]],
            ],
          ],
        ];
        PHP);

        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'battle_entry_rules');

        $cue = callEditorMethod($editor, 'getDatabaseBattleEntryCueLines');

        expect($cue[0])->toBe('Runs in this order:')
            ->and($cue[1])->toContain('1. first-declared-of-ties')
            ->and($cue[2])->toContain('2. second-declared-of-ties')
            ->and($cue[3])->toContain('3. third-by-priority')
            ->and($cue[3])->toContain('(p 20)')
            ->and($cue[3])->toStartWith('>')
            ->and($cue[1])->toStartWith(' ');
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('adds, duplicates, reorders, deletes, undoes and redoes against the durable rule', function (): void {
    $root = makeTemporaryProject();

    try {
        // Authored through the editor, so the on-disk form is the canonical
        // one the writer itself produces.
        $seed = loadRecordDatabase($root, 'battle_entry_rules');

        foreach ([['alpha', 'speed'], ['beta', 'grace']] as $position => [$id, $stat]) {
            $index = $seed->addRecord();
            $seed->setField($index, 'id', $id);
            $seed->setField($index, 'actors', 'Kaelion:any');
            $seed->setField($index, 'effect0Actor', 'Kaelion');
            $seed->setField($index, 'effect0Stat', $stat);
        }

        $seed->save();

        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'battle_entry_rules');
        $database = getEditorProperty($editor, 'workspace')->getRecordDatabase('battle_entry_rules');

        // Duplicate: a fresh unique identity, placed below its source.
        callEditorMethod($editor, 'dispatchInput', 'D');
        expect($database->getEntryLabels())->toBe(['alpha', 'alpha-2', 'beta']);

        callEditorMethod($editor, 'dispatchInput', "\x1a");
        expect($database->getEntryLabels())->toBe(['alpha', 'beta']);

        // Reorder: declaration order is the priority tiebreaker, so the move
        // is an undoable edit.
        callEditorMethod($editor, 'setSelectedRecordIndex', 0);
        callEditorMethod($editor, 'dispatchInput', ']');
        expect($database->getEntryLabels())->toBe(['beta', 'alpha']);

        callEditorMethod($editor, 'dispatchInput', "\x1a");
        expect($database->getEntryLabels())->toBe(['alpha', 'beta']);

        callEditorMethod($editor, 'dispatchInput', "\x19");
        expect($database->getEntryLabels())->toBe(['beta', 'alpha']);

        callEditorMethod($editor, 'dispatchInput', "\x1a");

        // Save, then edit-undo-save: the writer targets alpha by identity
        // and puts back exactly what the file held.
        callEditorMethod($editor, 'saveActiveDatabase');
        $savedSource = (string) file_get_contents(battleEntryRulesPath($root));

        callEditorMethod($editor, 'setSelectedRecordIndex', 0);
        $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
        $priorityIndex = array_search('priority', array_column($fields, 'field'), true);
        callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $fields[$priorityIndex], '55');
        expect($database->getRecordByIndex(0)?->get('priority'))->toBe(55);

        callEditorMethod($editor, 'saveActiveDatabase');
        expect((require battleEntryRulesPath($root))['rules'][0]['priority'])->toBe(55);

        callEditorMethod($editor, 'dispatchInput', "\x1a");
        callEditorMethod($editor, 'saveActiveDatabase');

        expect((string) file_get_contents(battleEntryRulesPath($root)))->toBe($savedSource);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('preserves unknown future-compatible fields when editing supported siblings', function (): void {
    $root = makeTemporaryProject();

    try {
        writeBattleEntryRules($root, <<<'PHP'
        <?php

        return [
          'annotations' => ['reviewed' => true],
          'rules' => [
            [
              'id' => 'future-proof',
              'futureFlag' => ['phase' => 2],
              'actors' => [['actor' => 'Kaelion', 'presence' => 'any']],
              'effects' => [['type' => 'stat_stage', 'actor' => 'Kaelion', 'stat' => 'speed', 'delta' => 1]],
            ],
          ],
        ];
        PHP);

        $database = loadRecordDatabase($root, 'battle_entry_rules');
        $database->setField(0, 'priority', '5');
        $database->save();

        $payload = require battleEntryRulesPath($root);

        expect($payload['annotations'])->toBe(['reviewed' => true])
            ->and($payload['rules'][0]['futureFlag'])->toBe(['phase' => 2])
            ->and($payload['rules'][0]['priority'])->toBe(5);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('refuses an opaque targeted source without mutating it', function (): void {
    $root = makeTemporaryProject();

    try {
        writeBattleEntryRules($root, <<<'PHP'
        <?php

        return [
          'rules' => [
            [
              'id' => 'constructed',
              'actors' => [['actor' => 'Kaelion', 'presence' => 'any']],
              'effects' => [['type' => 'stat_stage', 'actor' => 'Kaelion', 'stat' => 'speed', 'delta' => 1]],
              'futureHandle' => new DateTimeImmutable('2026-01-01'),
            ],
          ],
        ];
        PHP);

        $before = (string) file_get_contents(battleEntryRulesPath($root));
        $database = loadRecordDatabase($root, 'battle_entry_rules');

        expect($database->isEditable())->toBeFalse()
            ->and((string) $database->getReadOnlyReason())->toContain('battle-entry-rules.php');

        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'battle_entry_rules');
        callEditorMethod($editor, 'saveActiveDatabase');

        expect((string) file_get_contents(battleEntryRulesPath($root)))->toBe($before);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('writes nothing on a no-op save and touches no unrelated file', function (): void {
    $root = makeTemporaryProject();

    try {
        $database = loadRecordDatabase($root, 'battle_entry_rules');
        $index = $database->addRecord();
        $database->setField($index, 'id', 'steady');
        $database->setField($index, 'actors', 'Kaelion:any');
        $database->setField($index, 'effect0Actor', 'Kaelion');
        $database->save();

        $watched = [battleEntryRulesPath($root), $root . '/assets/Data/troops.php', $root . '/assets/Data/states.php'];
        $backdated = strtotime('2020-01-01 00:00:00');

        foreach ($watched as $file) {
            touch($file, $backdated);
        }

        clearstatcache();
        $before = [];

        foreach ($watched as $file) {
            $before[$file] = [md5((string) file_get_contents($file)), filemtime($file)];
        }

        $editor = deletionEditor($root);
        openDatabaseCategory($editor, 'battle_entry_rules');
        callEditorMethod($editor, 'saveActiveDatabase');
        callEditorMethod($editor, 'saveAllAssets');

        clearstatcache();

        foreach ($before as $file => [$hash, $mtime]) {
            expect(md5((string) file_get_contents($file)))->toBe($hash)
                ->and(filemtime($file))->toBe($mtime);
        }
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('keeps source, dirty state and checkpoint when the atomic write is refused', function (): void {
    $root = makeTemporaryProject();

    try {
        $database = loadRecordDatabase($root, 'battle_entry_rules');
        $index = $database->addRecord();
        $database->setField($index, 'id', 'blocked');
        $database->setField($index, 'actors', 'Kaelion:any');
        $database->setField($index, 'effect0Actor', 'Kaelion');
        $database->save();

        $database->setField($index, 'priority', '9');
        $before = (string) file_get_contents(battleEntryRulesPath($root));
        $mtime = filemtime(battleEntryRulesPath($root));
        $dataDirectory = $root . '/assets/Data';
        chmod($dataDirectory, 0555);

        try {
            $failed = false;

            try {
                $database->save();
            } catch (Throwable) {
                $failed = true;
            }

            clearstatcache();

            expect($failed)->toBeTrue()
                ->and((string) file_get_contents(battleEntryRulesPath($root)))->toBe($before)
                ->and(filemtime(battleEntryRulesPath($root)))->toBe($mtime)
                ->and($database->isDirty())->toBeTrue();
        } finally {
            chmod($dataDirectory, 0755);
        }

        // The intact checkpoint saves cleanly once the refusal clears.
        $database->save();

        expect((require battleEntryRulesPath($root))['rules'][0]['priority'])->toBe(9);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('encodes and decodes predicates through colons in identities', function (): void {
    $predicates = [
        ['actor' => 'actor.scout', 'presence' => 'active'],
        ['actor' => 'house:of:dawn', 'presence' => 'reserve'],
    ];
    $line = BattleEntryPredicateCodec::encodeAll($predicates);

    expect($line)->toBe('actor.scout:active; house:of:dawn:reserve')
        ->and(BattleEntryPredicateCodec::decodeAll($line))->toBe($predicates)
        ->and(BattleEntryPredicateCodec::decodeAll('nonsense'))->toBe([])
        ->and(BattleEntryPredicateCodec::decodeAll('ghost:benched'))->toBe([]);
});
