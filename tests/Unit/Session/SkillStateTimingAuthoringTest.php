<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Entities\Effects\SkillEffects\AddStateSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SkillSecondaryTrigger;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\States\State;

/**
 * A skill's state effect authors when it applies (always, or only on a target the action's damage hit) and how many
 * of the bearer's turns it stays pending, and a state authors the rate at which it scales its bearer's outgoing
 * physical damage, through the shared session rows, saves and undo. Synthetic fixtures only.
 */
function findSkillTimingRow(EditorSession $session, string $field): array
{
    return array_find($session->readDatabaseRecord('skills', 0)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field)
        ?? throw new RuntimeException('Missing skill row: ' . $field);
}

/** A project whose one skill strikes and then applies a state, in a file carrying an authored comment. */
function makeSkillTimingProject(Occasion $occasion = Occasion::BATTLE_SCREEN): array
{
    $root = makeTemporaryProject();
    writeSkillRecords($root, new SpecialSkill('Synthetic snare', 'Strikes.', '', 0, 0, occasion: $occasion,
        effects: [new HPDamageSkillEffect('$user->stats->attack * 2'), new AddStateSkillEffect('poison')]));
    $path = glob($root . '/assets/Data/' . SkillCatalog::DIRECTORY . '/*.php')[0];
    file_put_contents($path, str_replace('<?php', "<?php\n// Keep this authored comment.", (string) file_get_contents($path)));

    return [$root, $path];
}

it('authors a landed-hit trigger and a pending delay from the Engine\'s own values, keeping source and undo', function () {
    [$root, $path] = makeSkillTimingProject();
    $before = file_get_contents($path);
    $session = EditorSession::open($root);
    $trigger = findSkillTimingRow($session, 'effect1Trigger');
    expect($trigger['options'])->toBe(array_map(static fn(SkillSecondaryTrigger $case): string => $case->value,
        SkillSecondaryTrigger::cases()));
    $session->applyDatabaseRecord('skills', 0, $trigger['key'], SkillSecondaryTrigger::LANDED_HIT->value);
    $session->applyDatabaseRecord('skills', 0, findSkillTimingRow($session, 'effect1DelayTurns')['key'], '1');
    $session->saveDatabase('skills');
    $effect = SkillCatalog::load($root . '/assets')->findSkill('Synthetic snare')->effects[1];
    expect($effect)->toBeInstanceOf(AddStateSkillEffect::class)
        ->and($effect->trigger)->toBe(SkillSecondaryTrigger::LANDED_HIT)
        ->and($effect->delayTurns)->toBe(1)
        ->and(file_get_contents($path))->toContain('// Keep this authored comment.');
    $panes = json_encode($session->readDatabaseRecord('skills', 0)['panes']);
    expect($panes)->toContain('applies: landedHit')->toContain('pending 1 turn');
    $session->undo();
    $session->undo();
    $session->saveDatabase('skills');
    expect(file_get_contents($path))->toBe($before);
});

it('leaves the trigger and delay out of the record when cleared, as the Engine reads their absence', function () {
    [$root, $path] = makeSkillTimingProject();
    $session = EditorSession::open($root);
    $session->applyDatabaseRecord('skills', 0, findSkillTimingRow($session, 'effect1Trigger')['key'], SkillSecondaryTrigger::LANDED_HIT->value);
    $session->applyDatabaseRecord('skills', 0, findSkillTimingRow($session, 'effect1DelayTurns')['key'], '2');
    $session->saveDatabase('skills');
    $session->applyDatabaseRecord('skills', 0, findSkillTimingRow($session, 'effect1Trigger')['key'], '');
    $session->applyDatabaseRecord('skills', 0, findSkillTimingRow($session, 'effect1DelayTurns')['key'], '');
    $session->saveDatabase('skills');
    expect(file_get_contents($path))->not->toContain("'trigger'")->not->toContain("'delayTurns'");
    $effect = SkillCatalog::load($root . '/assets')->findSkill('Synthetic snare')->effects[1];
    expect($effect->trigger)->toBe(SkillSecondaryTrigger::ALWAYS)->and($effect->delayTurns)->toBe(0);
});

it('refuses a pending delay on a skill used outside battle before writing, through the Engine\'s rule', function () {
    [$root, $path] = makeSkillTimingProject(Occasion::ALWAYS);
    $before = file_get_contents($path);
    $session = EditorSession::open($root);
    $session->applyDatabaseRecord('skills', 0, findSkillTimingRow($session, 'effect1DelayTurns')['key'], '1');
    expect(fn() => $session->saveDatabase('skills'))->toThrow(SessionRefusal::class, 'battle-only');
    expect(file_get_contents($path))->toBe($before);
});

it('refuses a negative pending delay before writing', function () {
    [$root, $path] = makeSkillTimingProject();
    $before = file_get_contents($path);
    $session = EditorSession::open($root);
    $session->applyDatabaseRecord('skills', 0, findSkillTimingRow($session, 'effect1DelayTurns')['key'], '-1');
    expect(fn() => $session->saveDatabase('skills'))->toThrow(SessionRefusal::class, 'negative');
    expect(file_get_contents($path))->toBe($before);
});

it('authors a state\'s outgoing physical damage rate, leaves it out when cleared and refuses a negative rate', function () {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/states.php';
    file_put_contents($path, "<?php\n// Keep this authored comment.\nreturn [['id' => 'weakened', 'name' => 'Weakened']];\n");
    $session = EditorSession::open($root);
    $row = static fn(): array => array_find($session->readDatabaseRecord('states', 0)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'physicalDamageDealtRate')
        ?? throw new RuntimeException('Missing state row: physicalDamageDealtRate');
    $session->applyDatabaseRecord('states', 0, $row()['key'], '0.8');
    $session->saveDatabase('states');
    expect(State::fromArray((require $path)[0])->physicalDamageDealtRate)->toBe(.8)
        ->and(file_get_contents($path))->toContain('// Keep this authored comment.');
    $before = file_get_contents($path);
    $session->applyDatabaseRecord('states', 0, $row()['key'], '-0.5');
    expect(fn() => $session->saveDatabase('states'))->toThrow(SessionRefusal::class, 'physicalDamageDealtRate');
    expect(file_get_contents($path))->toBe($before);
    $session->applyDatabaseRecord('states', 0, $row()['key'], '');
    $session->saveDatabase('states');
    expect(file_get_contents($path))->not->toContain('physicalDamageDealtRate');
});
