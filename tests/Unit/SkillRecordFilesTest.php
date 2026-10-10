<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordAuthoring;
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
