<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Validation\CounterAttackValidator;
use Ichiloto\Engine\Battle\CounterAttackRule;
use Ichiloto\Engine\Entities\Magic\MagicEffectType;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;

/** Granting a counter attack from the Database: off unless chosen, picked from what the Engine accepts. Synthetic fixtures only. */

/** The enemy preview project with a basic skill a counter may use and a spell it may not. */
function counterAttackProject(): string
{
    $root = enemyPreviewProject();
    writeSkillRecords($root,
        new BasicSkill('Riposte', '', '', 0, 0),
        new MagicSkill('Flare', '', '', 0, 0, effectType: MagicEffectType::DESTRUCTIVE),
    );

    return $root;
}

/** A record's row, by its field. */
function counterRow(EditorSession $session, string $category, int $index, string $field): ?array
{
    return array_find($session->readDatabaseRecord($category, $index)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === $field);
}

it('offers only the skills the Engine accepts for a counter', function () {
    $root = counterAttackProject();
    $session = EditorSession::open($root);
    [$map] = $session->describeMaps();
    $offered = array_column($session->listReferences($map['id'], 'counter_skills'), 'value');
    $catalog = SkillCatalog::load($root . '/assets');

    expect($offered)->toBe(['Riposte'])
        ->and(new CounterAttackRule('Riposte')->resolveSkill($catalog)->name)->toBe('Riposte');
});

it('grants an enemy a counter attack and takes it away, leaving no empty grant behind', function () {
    $root = counterAttackProject();
    $session = EditorSession::open($root);
    $file = $root . '/assets/Data/Enemies/regular-bat.php';
    $before = (string) file_get_contents($file);

    expect(counterRow($session, 'enemies', 0, 'counterAttack.skill'))->toMatchArray(['kind' => 'reference', 'reference' => 'counter_skills']);

    $session->applyDatabaseRecord('enemies', 0, counterRow($session, 'enemies', 0, 'counterAttack.skill')['key'], 'Riposte');
    $session->saveDatabase('enemies');
    $granted = require $file;

    expect($granted['data']['counterAttack'])->toBe(['skill' => 'Riposte'])
        ->and(CounterAttackRule::fromArray($granted['data']['counterAttack'])->skill)->toBe('Riposte');

    $session->applyDatabaseRecord('enemies', 0, counterRow($session, 'enemies', 0, 'counterAttack.skill')['key'], '');
    $session->saveDatabase('enemies');

    expect((require $file)['data'])->not->toHaveKey('counterAttack')
        ->and(file_get_contents($file))->toBe($before);
});

it('lets states and non-magic skills grant one, and never spells', function () {
    $root = counterAttackProject();
    file_put_contents($root . '/assets/Data/states.php', "<?php\n\nreturn [['id' => 'parry-stance', 'name' => 'Parry Stance', 'icon' => '', 'description' => '']];\n");
    $session = EditorSession::open($root);
    $skills = $session->listDatabaseRecords('skills')['records'];

    expect(counterRow($session, 'states', 0, 'counterAttack.skill'))->not->toBeNull()
        ->and(counterRow($session, 'skills', array_search('Riposte', $skills, true), 'counterAttack.skill'))->not->toBeNull()
        ->and(counterRow($session, 'skills', array_search('Flare', $skills, true), 'counterAttack.skill'))->toBeNull();

    $session->applyDatabaseRecord('states', 0, counterRow($session, 'states', 0, 'counterAttack.skill')['key'], 'Riposte');
    $session->saveDatabase('states');

    expect((require $root . '/assets/Data/states.php')[0]['counterAttack'])->toBe(['skill' => 'Riposte']);
});

it('grants an actor a counter attack through the actor service', function () {
    $root = counterAttackProject();
    $session = EditorSession::open($root);
    $actor = array_search('Kaelion', $session->listDatabaseRecords('actors')['records'], true);
    $row = counterRow($session, 'actors', $actor, 'counterAttack');

    expect($row)->toMatchArray(['kind' => 'reference', 'reference' => 'counter_skills', 'value' => '(no counter)']);

    $session->applyDatabaseRecord('actors', $actor, $row['key'], 'Riposte');
    $session->saveDatabase('actors');
    $workspace = ProjectWorkspace::fromProject($root);
    $saved = array_find($workspace->actorDatabase->getActors(), static fn($candidate): bool => $candidate->getName() === 'Kaelion');

    expect($saved->getData()['counterAttack'])->toBe(['skill' => 'Riposte'])
        ->and(counterRow(EditorSession::open($root), 'actors', $actor, 'counterAttack')['value'])->toBe('Riposte');

    $session->applyDatabaseRecord('actors', $actor, counterRow($session, 'actors', $actor, 'counterAttack')['key'], '');
    $session->saveDatabase('actors');
    $cleared = array_find(ProjectWorkspace::fromProject($root)->actorDatabase->getActors(), static fn($candidate): bool => $candidate->getName() === 'Kaelion');

    expect($cleared->getData())->not->toHaveKey('counterAttack');
});

it('reports a grant the Engine would refuse, with the Engine\'s reason', function () {
    $root = counterAttackProject();
    $file = $root . '/assets/Data/Enemies/regular-bat.php';
    file_put_contents($file, str_replace("'level' => 2,", "'level' => 2,\n    'counterAttack' => ['skill' => 'Flare'],", (string) file_get_contents($file)));

    $issues = new CounterAttackValidator()->validate(ProjectWorkspace::fromProject($root));

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->where)->toContain('Regular Bat')
        ->and($issues[0]->message)->toContain('battle-usable basic or special skill');
});

it('never offers or accepts a skill the project links to a summon', function () {
    $root = counterAttackProject();
    // A special skill that would otherwise qualify, linked to an authored summon.
    writeSkillRecords($root,
        new BasicSkill('Riposte', '', '', 0, 0),
        new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Ember Call', '', '', 0, 0),
    );
    $summon = $root . '/assets/Cutscenes/Summons/ember';
    mkdir($summon, 0777, true);
    file_put_contents($summon . '/ember.data.php', "<?php\n\nreturn " . var_export([
        'id' => 'ember', 'name' => 'Ember', 'linkedActionId' => 'Ember Call',
        'availability' => ['conditions' => []],
        'wielders' => ['mode' => 'characters', 'characters' => ['Kaelion'], 'tenancy' => 'exclusive'],
    ], true) . ";\n");
    file_put_contents($summon . '/ember.timeline.php', "<?php\n\nreturn ['fps' => 12, 'lengthFrames' => 1, 'tracks' => [], 'cues' => []];\n");
    $file = $root . '/assets/Data/Enemies/regular-bat.php';
    file_put_contents($file, str_replace("'level' => 2,", "'level' => 2,\n    'counterAttack' => ['skill' => 'Ember Call'],", (string) file_get_contents($file)));

    $session = EditorSession::open($root);
    [$map] = $session->describeMaps();
    $issues = new CounterAttackValidator()->validate(ProjectWorkspace::fromProject($root));

    expect(array_column($session->listReferences($map['id'], 'counter_skills'), 'value'))->toBe(['Riposte'])
        ->and($issues)->toHaveCount(1)
        ->and($issues[0]->where)->toContain('Regular Bat')
        ->and($issues[0]->message)->toContain('without summon');
});
