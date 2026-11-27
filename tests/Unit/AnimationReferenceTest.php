<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Database\CutsceneSchemas;
use Ichiloto\Editor\Database\ReferencePicker;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\AnimationReferenceValidator;
use Ichiloto\Editor\Validation\Severity;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Magic\MagicEffectType;
use Ichiloto\Engine\Cutscenes\Summons\SummonEffectTiming;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;

it('displays the engine default summon effect timing in the editor', function () {
    $timing = array_values(array_filter(CutsceneSchemas::summons()->fields,
        static fn($field): bool => $field->key === 'effectTiming.mode'))[0];
    expect($timing->displayDefault)->toBe(SummonEffectTiming::DEFAULT_MODE)
        ->and($timing->options)->toBe(SummonEffectTiming::AUTHORING_MODES);
});

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
    writeSkillRecords($root, new $class('Renamed', '', '', 0, 0, animationId: 1));
    $path = $root . '/assets/Data/Skills/0001-renamed.php';
    $loaded = loadRecordDatabase($root, 'skills');
    expect($loaded->getRecordByIndex(0)?->get('animationId'))->toBe(1);
    $loaded->setField(0, 'animationId', '');
    $loaded->save();
    expect(SkillCatalog::load($root . '/assets')->findSkill('Renamed')?->animationId)->toBeNull()
        ->and(file_get_contents($path))->not->toContain('animationId');
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

it('reports a legacy animation that the current role does not select and stale explicit ids', function () {
    $root = makeTemporaryProject();
    writeSkillRecords(
        $root,
        new SpecialSkill('Slash', '', '', 0, 0),
        new SpecialSkill('Renamed', '', '', 0, 0, animationId: 1),
        new SpecialSkill('Missing', '', '', 0, 0, animationId: 999),
    );
    $issues = new AnimationReferenceValidator()->validate(ProjectWorkspace::fromProject($root));
    expect($issues)->toHaveCount(2)
        ->and($issues[0]->severity)->toBe(Severity::WARNING)
        ->and($issues[0]->message)->toBe('The skill role has no playable animation. The legacy match was "Slash".')
        ->and($issues[1]->severity)->toBe(Severity::WARNING)
        ->and($issues[1]->message)->toContain('animationId');
});

it('warns when an old magic fallback has no role and accepts it once assigned to the current role', function () {
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Data/animations.php', "<?php return [['id' => 2, 'name' => 'Healing Aura']];");
    writeSkillRecords(
        $root,
        new MagicSkill('Cure', '', '', 0, 0, effectType: MagicEffectType::RESTORATIVE),
        new MagicSkill('Flare', '', '', 0, 0, effectType: MagicEffectType::DESTRUCTIVE),
    );
    // Only the skills' diagnostics; this project binds no roles, which is
    // reported separately.
    $workspace = ProjectWorkspace::fromProject($root);
    $validator = new AnimationReferenceValidator();
    $skillIssues = static fn(): array => array_values(array_filter(
        $validator->validate($workspace),
        static fn($issue): bool => str_starts_with($issue->where, 'assets/Data/Skills/'),
    ));
    $issues = $skillIssues();
    expect($issues)->toHaveCount(1)
        ->and($issues[0]->where)->toContain('Cure');

    $workspace->getRecordDatabase('animations')->setField(0, 'roles', 'restorative');
    expect($skillIssues())->toBe([]);
});

it('uses the live summon link instead of warning about a legacy animation with the same name', function (bool $graphical) {
    $root = makeTemporaryProject();
    writeSkillRecords($root, new SpecialSkill('Slash', '', '', 0, 0));
    $workspace = ProjectWorkspace::fromProject($root, graphical: $graphical);
    $validator = new AnimationReferenceValidator();
    $skillIssues = static fn(): array => array_values(array_filter(
        $validator->validate($workspace),
        static fn($issue): bool => str_starts_with($issue->where, 'assets/Data/Skills/'),
    ));
    expect($skillIssues())->toHaveCount(1);

    $summon = $workspace->cutscenes->createAsset(CutsceneType::SUMMON, 'test-summon')['asset'];
    $summon->apply([...$summon->payload(), 'linkedActionId' => 'Slash']);
    expect($skillIssues())->toBe([]);

    $workspace->getRecordDatabase('skills')->setField(0, 'animationId', '999');
    expect($skillIssues()[0]->message)->toContain('animationId');
})->with([false, true]);

it('routes skill animation edits through the existing picker and undo transaction', function () {
    $root = makeTemporaryProject();
    writeSkillRecords($root, new SpecialSkill('Skill', '', '', 0, 0));
    $editor = deletionEditor($root);
    openDatabaseCategory($editor, 'skills');
    $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
    $field = array_values(array_filter($fields, static fn(array $field): bool => ($field['field'] ?? null) === 'animationId'))[0];
    expect($field['reference'])->toBe('animation_ids')->and($field['allowsNone'])->toBeTrue()
        ->and(isset($field['control']))->toBeFalse();
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $field, '1');
    $skills = getEditorProperty($editor, 'workspace')->getRecordDatabase('skills');
    $animation = static fn(): mixed => $skills->getRecordByIndex(0)?->get('animationId');
    expect($animation())->toBe(1);
    callEditorMethod($editor, 'performUndo');
    expect($animation())->toBeNull();
    callEditorMethod($editor, 'performRedo');
    expect($animation())->toBe(1);
    expect($field['noneLabel'])->toBe('(Automatic)');
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $field, '(Automatic)');
    expect($animation())->toBeNull();
});

it('offers a spell its effect type alone, and leaves a no-op pick unchanged', function (string $class) {
    $root = makeTemporaryProject();
    writeSkillRecords($root, new $class('Skill', '', '', 0, 0));
    $editor = deletionEditor($root);
    openDatabaseCategory($editor, 'skills');
    $byName = array_column(array_filter(callEditorMethod($editor, 'getDatabaseSettingsFields'), static fn(array $field): bool => isset($field['field'])), null, 'field');
    $skills = getEditorProperty($editor, 'workspace')->getRecordDatabase('skills');
    $before = $skills->getRecordByIndex(0)?->toArray();
    expect($byName)->toHaveKey('kind');
    if ($class === MagicSkill::class) {
        expect($byName)->toHaveKey('effectType');
        callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $byName['effectType'], $byName['effectType']['value']);
    } else {
        expect($byName)->not->toHaveKey('effectType');
    }
    expect($skills->getRecordByIndex(0)?->toArray())->toBe($before)->and($skills->isDirty())->toBeFalse();
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $byName['animationId'], '1');
    $skills->save();
    expect(SkillCatalog::load($root . '/assets')->findSkill('Skill')?->animationId)->toBe(1);
})->with([BasicSkill::class, MagicSkill::class, SpecialSkill::class]);

it('drops a spell\'s effect type when it becomes another kind of skill', function () {
    $root = makeTemporaryProject();
    writeSkillRecords($root, new MagicSkill('Mend', '', '', 0, 0, effectType: MagicEffectType::BUFF));
    $skills = loadRecordDatabase($root, 'skills');

    $skills->setField(0, 'kind', 'special');
    $skills->save();

    expect($skills->getRecordByIndex(0)?->get('effectType'))->toBeNull()
        ->and(SkillCatalog::load($root . '/assets')->findSkill('Mend'))->toBeInstanceOf(SpecialSkill::class);
});

it('does not enter text editing for an established actor id', function () {
    $root = makeTemporaryProject();
    $editor = deletionEditor($root);
    openDatabaseCategory($editor, 'actors');
    setEditorProperty($editor, 'databaseFocus', 'database_settings');
    $fields = callEditorMethod($editor, 'getDatabaseSettingsFields');
    $index = array_search('id', array_column($fields, 'field'), true);
    expect($index)->not->toBeFalse()->and($fields[$index]['editable'])->toBeFalse();
    setEditorProperty($editor, 'databaseSelectedSettingIndex', $index);
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect(getEditorProperty($editor, 'isDatabaseEditing'))->toBeFalse();
});
