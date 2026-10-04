<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\ProjectWorkspace;

/** A project whose skill catalogue holds a battle attack, a menu-only basic skill and a spell. */
function attackSkillProject(): string
{
    $root = makeTemporaryProject();
    writeSkillRecords(
        $root,
        new Ichiloto\Engine\Entities\Skills\BasicSkill('Attack', 'Strikes.', '', 0, 0, new Ichiloto\Engine\Entities\ItemScope(), Ichiloto\Engine\Entities\Enumerations\Occasion::BATTLE_SCREEN),
        new Ichiloto\Engine\Entities\Skills\BasicSkill('Tidy Up', 'Out of battle only.', '', 0, 0, new Ichiloto\Engine\Entities\ItemScope(), Ichiloto\Engine\Entities\Enumerations\Occasion::MENU_SCREEN),
        new Ichiloto\Engine\Entities\Skills\MagicSkill('Fire', 'Burns.', '', 4, 0),
    );

    return $root;
}

it('offers only the catalogue basic skills usable in battle as an actor attack', function () {
    $catalog = new ReferenceCatalog(ProjectWorkspace::fromProject(attackSkillProject()));

    expect($catalog->valuesFor('attack_skills'))->toBe(['Attack']);
});

it('authors an actor\'s attack skill as a picked reference, the built-in attack when unset', function () {
    $root = attackSkillProject();
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    callEditorMethod($editor, 'openDatabaseAtCategory', 'actors');
    $row = static fn(): array => array_find(callEditorMethod($editor, 'getDatabaseSettingsFields'),
        static fn(array $field): bool => ($field['field'] ?? null) === 'attackSkill');

    expect($row())->toMatchArray(['label' => 'Attack Skill', 'value' => '(Built-in attack)', 'reference' => 'attack_skills', 'allowsNone' => true])
        ->and($row())->not->toHaveKey('control');

    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $row(), 'Attack');
    $actors = getEditorProperty($editor, 'workspace')->actorDatabase;
    $actors->save();
    $path = glob($root . '/assets/Data/Actors/*.php')[0];

    expect($row()['value'])->toBe('Attack')
        ->and((require $path)['data']['attackSkill'])->toBe('Attack');

    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $row(), '');
    $actors->save();

    expect((require $path)['data'])->not->toHaveKey('attackSkill');
});

it('reports an attack skill that is not a basic skill usable in battle', function () {
    $root = attackSkillProject();
    $workspace = ProjectWorkspace::fromProject($root);
    $workspace->actorDatabase->setField(0, 'attackSkill', 'Fire');
    $issues = array_map(static fn(object $issue): string => $issue->message, new \Ichiloto\Editor\Validation\ProjectValidator()->validate($workspace));

    expect($issues)->toContain('Its attack skill "Fire" is not a basic skill usable in battle.');
});
