<?php

declare(strict_types=1);

use Ichiloto\Editor\Actors\ActorAuthoring;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Entities\Enemies\EnemyCatalog;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;

/** Choosing what an enemy attacks with from the Database, the choice an actor makes. Synthetic fixtures only. */

/** The enemy preview project with an attack an enemy may use and two it may not. */
function enemyAttackSkillProject(): string
{
    $root = enemyPreviewProject();
    writeSkillRecords($root,
        new BasicSkill('Tail Lash', '', '', 0, 0),
        new BasicSkill('Field Bandage', '', '', 0, 0, occasion: Occasion::MENU_SCREEN),
        new SpecialSkill('Pounce', '', '', 0, 0),
    );

    return $root;
}

/** The enemy's Attack Skill row. */
function enemyAttackSkillRow(EditorSession $session): ?array
{
    return array_find($session->readDatabaseRecord('enemies', 0)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'attackSkill');
}

it('offers an enemy the battle-usable basic skills an actor may attack with, and the built-in attack', function () {
    $session = EditorSession::open(enemyAttackSkillProject());
    [$map] = $session->describeMaps();

    expect(array_column($session->listReferences($map['id'], 'attack_skills'), 'value'))->toBe(['Tail Lash'])
        ->and(enemyAttackSkillRow($session))->toMatchArray([
            'kind' => 'reference',
            'reference' => 'attack_skills',
            'value' => ActorAuthoring::BUILT_IN_ATTACK,
        ]);
});

it('gives an enemy an attack skill the Engine reads, and takes it back without leaving a key behind', function () {
    $root = enemyAttackSkillProject();
    $session = EditorSession::open($root);
    $file = $root . '/assets/Data/Enemies/regular-bat.php';
    $before = (string) file_get_contents($file);

    $session->applyDatabaseRecord('enemies', 0, enemyAttackSkillRow($session)['key'], 'Tail Lash');
    $session->saveDatabase('enemies');
    // The Engine reads enemy sprites from the project's working directory.
    $previous = getcwd();
    chdir($root);
    try {
        $catalog = EnemyCatalog::load($root . '/assets', SkillCatalog::load($root . '/assets'));
    } finally {
        chdir((string) $previous);
    }
    $enemy = $catalog->findEnemy('Regular Bat');

    expect((require $file)['data']['attackSkill'])->toBe('Tail Lash')
        ->and($enemy?->attackSkill?->name)->toBe('Tail Lash');

    $session->applyDatabaseRecord('enemies', 0, enemyAttackSkillRow($session)['key'], '');
    $session->saveDatabase('enemies');

    expect((require $file)['data'])->not->toHaveKey('attackSkill')
        ->and(file_get_contents($file))->toBe($before);
});
