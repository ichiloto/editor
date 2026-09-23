<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Database\ReferencePicker;
use Ichiloto\Editor\ProjectSkill;
use Ichiloto\Editor\ProjectSkillDatabase;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\AnimationReferenceValidator;
use Ichiloto\Editor\Validation\Severity;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;

it('offers animation names while storing stable numeric ids', function () {
    $catalog = new ReferenceCatalog(ProjectWorkspace::fromProject(makeTemporaryProject()));
    expect($catalog->valuesFor('animation_ids'))->toBe(['1'])
        ->and($catalog->labelsFor('animation_ids'))->toBe([1 => 'Slash (1)'])
        ->and($catalog->valuesFor('animations'))->toBe(['Slash']);

    $picker = new ReferencePicker();
    $picker->open('animationId', 'Animation', 'animation_ids', $catalog->valuesFor('animation_ids'), '1', $catalog->labelsFor('animation_ids'));
    expect($picker->selected())->toBe('1')->and($picker->labelFor('1'))->toBe('Slash (1)');
});

it('round trips explicit animation ids for every skill subtype and allows clearing them', function (string $class) {
    $root = makeTemporaryProject();
    $skill = ProjectSkill::fromSkill(new $class('Renamed', '', '', 0, 0, animationId: 1), 1);
    $database = new ProjectSkillDatabase($root . '/assets/Data/skills.php', [$skill], true);
    $database->save();
    $loaded = ProjectSkillDatabase::fromProject($root);
    expect($loaded->getSkillByIndex(0)->animationId)->toBe(1);
    $loaded->setField(0, 'animationId', '');
    $loaded->save();
    expect(ProjectSkillDatabase::fromProject($root)->getSkillByIndex(0)->animationId)->toBeNull();
})->with([BasicSkill::class, MagicSkill::class, SpecialSkill::class]);

it('round trips item animation references through the existing typed resource picker schema', function () {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'items');
    $database->setField(0, 'animationId', '1');
    expect($database->getRecords()[0]->get('animationId'))->toBe(1);
    $database->save();
    $loaded = loadRecordDatabase($root, 'items');
    expect($loaded->getRecords()[0]->get('animationId'))->toBe(1);
    $loaded->setField(0, 'animationId', '(None)');
    $loaded->save();
    expect(loadRecordDatabase($root, 'items')->getRecords()[0]->get('animationId'))->toBeNull();
});

it('reports deprecated name fallback and stale ids without rejecting gameplay', function () {
    $root = makeTemporaryProject();
    $database = new ProjectSkillDatabase($root . '/assets/Data/skills.php', [
        ProjectSkill::fromSkill(new SpecialSkill('Slash', '', '', 0, 0), 1),
        ProjectSkill::fromSkill(new SpecialSkill('Renamed', '', '', 0, 0, animationId: 1), 2),
        ProjectSkill::fromSkill(new SpecialSkill('Missing', '', '', 0, 0, animationId: 999), 3),
    ], true);
    $database->save();
    $issues = new AnimationReferenceValidator()->validate(ProjectWorkspace::fromProject($root));
    expect($issues)->toHaveCount(2)
        ->and($issues[0]->severity)->toBe(Severity::WARNING)
        ->and($issues[0]->message)->toContain('deprecated')
        ->and($issues[1]->severity)->toBe(Severity::WARNING)
        ->and($issues[1]->message)->toContain('animationId');
});

it('routes skill animation edits through the existing picker and undo transaction', function () {
    $root = makeTemporaryProject();
    $database = new ProjectSkillDatabase($root . '/assets/Data/skills.php', [
        ProjectSkill::fromSkill(new SpecialSkill('Skill', '', '', 0, 0), 1),
    ], true);
    $database->save();
    $editor = deletionEditor($root);
    openDatabaseCategory($editor, 'skills');
    $fields = callEditorMethod($editor, 'getDatabaseSkillSettingsFields');
    $field = array_values(array_filter($fields, static fn(array $field): bool => $field['field'] === 'animationId'))[0];
    expect($field['reference'])->toBe('animation_ids')->and($field['allowsNone'])->toBeTrue()
        ->and(isset($field['control']))->toBeFalse();
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $field, '1');
    $workspace = getEditorProperty($editor, 'workspace');
    expect($workspace->skillDatabase->getSkillByIndex(0)->animationId)->toBe(1);
    callEditorMethod($editor, 'performUndo');
    expect($workspace->skillDatabase->getSkillByIndex(0)->animationId)->toBeNull();
    callEditorMethod($editor, 'performRedo');
    expect($workspace->skillDatabase->getSkillByIndex(0)->animationId)->toBe(1);
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $field, '(Legacy fallback)');
    expect($workspace->skillDatabase->getSkillByIndex(0)->animationId)->toBeNull();
});
