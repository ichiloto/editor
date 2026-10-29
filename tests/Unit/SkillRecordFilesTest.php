<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordAuthoring;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Battle\Insight\RevealFact;
use Ichiloto\Engine\Entities\Effects\SkillEffects\RevealSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;

/**
 * Skills as one record per numbered file, authored through the shared record
 * service and read back by the Engine's catalogue. Synthetic fixtures only.
 */
function skillRecordsProject(): string
{
    $root = makeTemporaryProject();
    writeSkillRecords(
        $root,
        new BasicSkill('Attack', 'Strikes.', '', 0, 0),
        new SpecialSkill('Lunge', 'Strikes far.', '', 4, 0, effects: [new HPDamageSkillEffect('$user->stats->attack * 3')]),
        new MagicSkill('Ember', 'Burns.', '', 5, 0),
    );

    return $root;
}

it('authors a guaranteed state application through the shared effect fields', function () {
    $root = skillRecordsProject();
    $skills = loadRecordDatabase($root, 'skills');
    $field = static fn(string $name): string => ProjectRecordDatabase::subFieldId('effect', 0, $name);
    $skills->setField(1, $field('type'), 'add_state');
    $skills->setField(1, $field('stateId'), 'stun');
    $skills->setField(1, $field('guaranteed'), 'true');
    $skills->save();
    $effect = SkillCatalog::load($root . '/assets')->findSkill('Lunge')->effects[0];
    expect($effect->guaranteed)->toBeTrue()->and($effect->stateId)->toBe('stun');
    expect(array_find(loadRecordDatabase($root, 'skills')->getSettingsFields(1),
        static fn(array $row): bool => ($row['field'] ?? '') === $field('guaranteed'))['value'])->toBe('true');
});

it('round trips a prepared response through skill and state pickers', function () {
    $root = skillRecordsProject();
    $skills = loadRecordDatabase($root, 'skills');
    $skills->setField(1, 'occasion', \Ichiloto\Engine\Entities\Enumerations\Occasion::BATTLE_SCREEN->value);
    $field = static fn(string $name): string => ProjectRecordDatabase::subFieldId('effect', 0, $name);
    $skills->setField(1, $field('type'), 'prepare_counter');
    $skills->setField(1, $field('skill'), 'Lunge');
    $skills->setField(1, $field('stateId'), 'stance');
    $skills->save();
    $effect = SkillCatalog::load($root . '/assets')->findSkill('Lunge')->effects[0];
    expect($effect)->toBeInstanceOf(\Ichiloto\Engine\Entities\Effects\SkillEffects\PrepareCounterSkillEffect::class)
        ->and($effect->skill)->toBe('Lunge')->and($effect->stateId)->toBe('stance');
    $fields = loadRecordDatabase($root, 'skills')->getSettingsFields(1);
    expect(array_find($fields, static fn(array $row): bool => ($row['field'] ?? '') === $field('skill'))['reference'])
        ->toBe('counter_skills')
        ->and(array_find($fields, static fn(array $row): bool => ($row['field'] ?? '') === $field('stateId'))['reference'])
        ->toBe('states');
});

it('authors ATB reset effects and state-owned pace through shared records', function () {
    $root = skillRecordsProject();
    $skills = loadRecordDatabase($root, 'skills');
    $skills->setField(1, ProjectRecordDatabase::subFieldId('effect', 0, 'type'), 'reset_atb');
    $skills->save();
    expect(SkillCatalog::load($root . '/assets')->findSkill('Lunge')->effects[0])
        ->toBeInstanceOf(\Ichiloto\Engine\Entities\Effects\SkillEffects\ResetAtbSkillEffect::class);
    file_put_contents($root . '/assets/Data/states.php', "<?php return [['id'=>'slow','name'=>'Slow']];");
    $states = loadRecordDatabase($root, 'states');
    $states->setField(0, 'atbRate', '0.5');
    $states->save();
    expect(\Ichiloto\Engine\Entities\States\State::fromArray((require $root . '/assets/Data/states.php')[0])->atbRate)->toBe(.5);
});

it('lists every skill record in file order, numbered, with its kind and effects as data', function () {
    $skills = loadRecordDatabase(skillRecordsProject(), 'skills');

    expect($skills->getEntryLabels())->toBe(['Attack', 'Lunge', 'Ember'])
        ->and(array_map(static fn($record): string => $record->recordId, $skills->getRecords()))->toBe(['0001-attack', '0002-lunge', '0003-ember'])
        ->and($skills->getRecordByIndex(1)?->get('kind'))->toBe('special')
        ->and($skills->getRecordByIndex(1)?->get('effects'))->toBe([['type' => 'hp_damage', 'formula' => '$user->stats->attack * 3', 'variance' => 0.2]]);
});

it('creates and copies a skill under the next number, last in the list as reopening shows it', function () {
    $root = skillRecordsProject();
    $skills = loadRecordDatabase($root, 'skills');
    $authoring = new RecordAuthoring();

    $created = $authoring->createRecord($skills);
    $copied = $authoring->duplicateRecord($skills, 0);
    $skills->save();

    expect([$created->index, $copied->index])->toBe([3, 4])
        ->and(array_map(basename(...), glob($root . '/assets/Data/Skills/*.php') ?: []))
        ->toBe(['0001-attack.php', '0002-lunge.php', '0003-ember.php', '0004-new-skill.php', '0005-attack-2.php'])
        ->and(array_keys(SkillCatalog::load($root . '/assets')->getSkills()))->toBe(['Attack', 'Lunge', 'Ember', 'New Skill', 'Attack-2'])
        ->and(loadRecordDatabase($root, 'skills')->getEntryLabels())->toBe(['Attack', 'Lunge', 'Ember', 'New Skill', 'Attack-2']);
});

it('authors effects by type, keeping only what each type reads', function () {
    $root = skillRecordsProject();
    $skills = loadRecordDatabase($root, 'skills');
    $field = static fn(string $name): string => ProjectRecordDatabase::subFieldId('effect', 0, $name);

    $skills->setField(1, $field('element'), 'Fire');
    $skills->setField(1, $field('type'), 'add_state');
    $skills->setField(1, $field('stateId'), 'poison');
    $skills->save();

    $lunge = SkillCatalog::load($root . '/assets')->findSkill('Lunge');

    expect($skills->getRecordByIndex(1)?->get('effects'))->toBe([['type' => 'add_state', 'stateId' => 'poison']])
        ->and($lunge?->effects[0]?->stateId)->toBe('poison')
        ->and(SkillCatalog::load($root . '/assets')->getProblems())->toBe([]);
});

it('authors a reveal effect the Engine reads back, with its facts chosen from the Engine\'s own', function () {
    $root = skillRecordsProject();
    $skills = loadRecordDatabase($root, 'skills');
    $field = static fn(string $name): string => ProjectRecordDatabase::subFieldId('effect', 0, $name);

    $skills->setField(1, $field('type'), 'reveal');
    $skills->setField(1, $field('facts'), 'hp, stats');
    $skills->setField(1, $field('chancePercent'), '75');
    $skills->save();

    $effect = SkillCatalog::load($root . '/assets')->findSkill('Lunge')?->effects[0];

    expect($skills->getRecordByIndex(1)?->get('effects'))->toBe([['type' => 'reveal', 'facts' => ['hp', 'stats'], 'chancePercent' => 75]])
        ->and($effect)->toBeInstanceOf(RevealSkillEffect::class)
        ->and($effect?->facts)->toBe([RevealFact::HP, RevealFact::STATS])
        ->and((new ReferenceCatalog(ProjectWorkspace::fromProject($root)))->valuesFor('reveal_facts'))
        ->toBe(['hp', 'mp', 'stats', 'affinities']);
});

it('authors a state\'s confusion chance and leaves it out when cleared', function () {
    $states = loadRecordDatabase(makeTemporaryProject(), 'states');

    $states->setField(0, 'confusionChancePercent', '100');
    expect($states->getRecordByIndex(0)?->get('confusionChancePercent'))->toBe(100);
    $states->setField(0, 'confusionChancePercent', '');
    expect(array_key_exists('confusionChancePercent', $states->getRecordByIndex(0)?->toArray() ?? []))->toBeFalse();
});

it('seeds a new skill with the action-name battle message and previews what each message shows, as a battle formats it', function () {
    $root = skillRecordsProject();
    $skills = loadRecordDatabase($root, 'skills');
    $created = (new RecordAuthoring())->createRecord($skills);
    $skills->setField(0, 'invocation.message', '%1 swings at the foe!');
    $skills->setField(1, 'invocation.message', '');
    $skills->save();
    $workspace = Ichiloto\Editor\ProjectWorkspace::fromProject($root);
    $lines = static fn(int $index): string => implode("\n", array_merge(...array_map(
        static fn(array $pane): array => array_map('strval', (array) ($pane['lines'] ?? $pane[1] ?? [])),
        array_values(Ichiloto\Editor\Database\RecordPanes::describe($workspace, 'skills', $index)),
    )));

    expect(loadRecordDatabase($root, 'skills')->getRecordByIndex($created->index)?->get('invocation')['message'])->toBe('%2')
        ->and(array_find(Ichiloto\Editor\Database\RecordSchemaCatalog::forKey('skills')->fields,
            static fn($field): bool => $field->key === 'invocation.message')->label)->toBe('Battle Message')
        ->and($lines(0))->toContain('Shows: Actor swings at the foe!')
        ->and($lines(1))->toContain('Shows: nothing, the skill is used silently')
        ->and($lines(2))->toContain('Shows: Ember')
        ->and($lines(2))->toContain('%1 = who acts, %2 = this skill, blank = no message');
});

it('authors a treatment chance on a cure and the Engine reads it back', function () {
    $root = skillRecordsProject();
    $skills = loadRecordDatabase($root, 'skills');
    $field = static fn(string $name): string => ProjectRecordDatabase::subFieldId('effect', 0, $name);

    $skills->setField(1, $field('type'), 'remove_state');
    $skills->setField(1, $field('stateIds'), 'poison');
    $skills->setField(1, $field('chancePercent'), '75');
    $skills->save();

    expect($skills->getRecordByIndex(1)?->get('effects'))->toBe([['type' => 'remove_state', 'stateIds' => ['poison'], 'chancePercent' => 75]])
        ->and(SkillCatalog::load($root . '/assets')->findSkill('Lunge')?->effects[0]?->chancePercent)->toBe(75)
        ->and(SkillCatalog::load($root . '/assets')->getProblems())->toBe([]);
});


it('authors battle geometry atomically and refuses conflicting field scope before writing', function () {
    $root = skillRecordsProject();
    $skills = loadRecordDatabase($root, 'skills');
    $before = sourceHashTree($root . '/assets/Data/Skills');
    $skills->setField(1, 'battleTargeting.shape', 'line');
    expect(fn() => $skills->save())->toThrow(\Ichiloto\Editor\Database\RecordRefusal::class);
    expect(sourceHashTree($root . '/assets/Data/Skills'))->toBe($before);
    $skills->setField(1, 'occasion', \Ichiloto\Engine\Entities\Enumerations\Occasion::BATTLE_SCREEN->value);
    $skills->setField(1, 'battleTargeting.shape', 'line');
    $skills->setField(1, 'battleTargeting.ignoreCover', 'true');
    $skills->save();
    $line = SkillCatalog::load($root . '/assets')->findSkill('Lunge');
    expect($line->battleTargeting->toArray())->toBe(['shape' => 'line', 'ignoreCover' => true]);
    $skills = loadRecordDatabase($root, 'skills');
    $skills->setField(1, 'battleTargeting.shape', 'back_row');
    $skills->save();
    $row = SkillCatalog::load($root . '/assets')->findSkill('Lunge');
    expect($row->scope->number)->toBe(\Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber::ALL)
        ->and($row->battleTargeting->shape)->toBe(\Ichiloto\Engine\Battle\Formation\BattleTargetShape::BACK_ROW);
    $skills->setField(1, 'battleTargeting.shape', 'line');
    expect($skills->getRecordByIndex(1)->get('scope.number'))->toBe('One');
    $before = sourceHashTree($root . '/assets/Data/Skills');
    $skills->setField(1, 'occasion', \Ichiloto\Engine\Entities\Enumerations\Occasion::ALWAYS->value);
    expect(fn() => $skills->save())->toThrow(\Ichiloto\Editor\Database\RecordRefusal::class);
    expect(sourceHashTree($root . '/assets/Data/Skills'))->toBe($before);
    $fields = $skills->getSettingsFields(1);
    expect(array_find($fields, static fn(array $row): bool => ($row['field'] ?? '') === 'battleTargeting.shape')['options'])
        ->toBe(['scope', 'line', 'front_row', 'back_row']);
});


it('authors one command-level formation change through a constrained operation field', function () {
    $root = skillRecordsProject();
    $skills = loadRecordDatabase($root, 'skills');
    $skills->setField(1, 'occasion', \Ichiloto\Engine\Entities\Enumerations\Occasion::BATTLE_SCREEN->value);
    $type = ProjectRecordDatabase::subFieldId('effect', 0, 'type');
    $operation = ProjectRecordDatabase::subFieldId('effect', 0, 'operation');
    $skills->setField(1, $type, 'change_formation');
    $skills->setField(1, $operation, 'move_to_front');
    $skills->save();
    $effect = SkillCatalog::load($root . '/assets')->findSkill('Lunge')->effects[0];
    expect($effect->operation)->toBe(\Ichiloto\Engine\Battle\Formation\BattleFormationChange::MOVE_TO_FRONT);
    expect(array_find(loadRecordDatabase($root, 'skills')->getSettingsFields(1),
        static fn(array $row): bool => ($row['field'] ?? '') === $operation)['options'])->toBe(['shuffle', 'move_to_front']);
});
