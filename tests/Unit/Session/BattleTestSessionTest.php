<?php

declare(strict_types=1);

use Ichiloto\Editor\Playtest\PlaytestOverlay;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\Validation\BattleTestValidator;
use Ichiloto\Engine\Scenes\Arena\ProjectBattleTest;

/** Choosing the battle test's troop and party in the editor, kept in system data as RPG Maker keeps its Battle Test. Synthetic fixtures only. */

/** The troop formation project with a Kaelion the Engine can build: full stats, as a battle needs. */
function battleTestProject(): string
{
    $root = troopFormationProject();
    file_put_contents($root . '/assets/Data/Actors/Kaelion.php', "<?php\n\nuse Ichiloto\\Engine\\Entities\\Character;\n\nreturn " . var_export(['class' => 'Ichiloto\\Engine\\Entities\\Character', 'data' => [
        'id' => 'Kaelion', 'name' => 'Kaelion', 'level' => 1, 'currentExp' => 0, 'stats' => [
            'currentHp' => 40, 'currentMp' => 10, 'currentAp' => 3, 'totalHp' => 100, 'totalMp' => 20, 'totalAp' => 3,
            'attack' => 8, 'defence' => 7, 'magicAttack' => 6, 'magicDefence' => 5, 'speed' => 4, 'grace' => 3, 'evasion' => 2,
        ],
    ]], true) . ";\n");

    return $root;
}

it('stands the starting party in for a battle test that sets none, saying so, with what each member may wear', function () {
    $root = battleTestProject();
    $test = EditorSession::open($root)->describeBattleTest();

    expect($test['source'])->toBe('starting party')
        ->and($test['troops'])->toBe(['Pair'])
        ->and(array_column($test['members'], 'actor'))->toBe(['Kaelion'])
        ->and($test['members'][0]['maxLevel'])->toBeInt()
        ->and(array_column($test['members'][0]['equipment'], 'slot'))->toContain('Weapon')
        ->and($test['members'][0]['equipment'][0]['choices'][0])->toBe(['id' => null, 'name' => '(None)'])
        ->and($test['members'][0]['choices']['commands'][0]['id'])->toBeNull()
        ->and($test['problems'])->toBe([])
        ->and($test['issue'])->toBeNull();
});

it('sets the battle test in system data as one undo step, and the previews stand its party', function () {
    $root = battleTestProject();
    $file = $root . '/assets/Data/system.php';
    $before = (string) file_get_contents($file);
    $session = EditorSession::open($root);

    $test = $session->applyBattleTest(['troop' => 'Pair', 'members' => [['actor' => 'Kaelion', 'level' => 3]]]);
    expect($test['source'])->toBe('battle test')
        ->and($test['troop'])->toBe('Pair')
        ->and($test['members'][0]['level'])->toBe(3)
        ->and($session->readTroopFormation(0)['partySource'])->toBe('battle test');

    $session->saveDatabase('system');
    expect((require $file)[0][ProjectBattleTest::SYSTEM_KEY] ?? (require $file)[ProjectBattleTest::SYSTEM_KEY] ?? null)
        ->toBe(['troop' => 'Pair', 'members' => [['actor' => 'Kaelion', 'level' => 3]]]);

    $session->undo();
    $session->saveDatabase('system');
    expect(file_get_contents($file))->toBe($before)
        ->and($session->readTroopFormation(0)['partySource'])->toBe('starting party');
});

it('refuses what the Engine would not read, changing nothing', function () {
    $root = battleTestProject();
    $session = EditorSession::open($root);

    expect(fn() => $session->applyBattleTest(['members' => [['actor' => 'Kaelion']]]))->toThrow(SessionRefusal::class, 'whole-number level')
        ->and(fn() => $session->applyBattleTest(['party' => []]))->toThrow(SessionRefusal::class, 'has no party')
        ->and($session->listUnsavedChanges())->toBe([]);
});

it('names the problems a battle test party has, and the validator warns of them and of a missing troop', function () {
    $root = battleTestProject();
    $session = EditorSession::open($root);
    $test = $session->applyBattleTest(['troop' => 'Nobody', 'members' => [['actor' => 'Kaelion', 'level' => 999]]]);
    $session->saveDatabase('system');
    $issues = array_map(static fn($issue): string => $issue->message, new BattleTestValidator()->validate(ProjectWorkspace::fromProject($root)));

    expect(implode(' ', $test['problems']))->toContain('level 999 is beyond its highest')
        ->and(implode(' ', $issues))->toContain('The battle test troop Nobody is not among the troops.')
        ->and(implode(' ', $issues))->toContain('level 999 is beyond its highest')
        ->and(fn() => $session->startBattleTest())->toThrow(SessionRefusal::class);
});

it('refuses to start without a troop, or while a database a battle reads is unsaved', function () {
    $root = battleTestProject();
    $session = EditorSession::open($root);

    expect(fn() => $session->startBattleTest())->toThrow(SessionRefusal::class, 'Choose a troop');

    $placement = array_find($session->readDatabaseRecord('troops', 0)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === 'member0GraphicalPlacement');
    $session->applyDatabaseRecord('troops', 0, $placement['key'], '300, 300, 275, 190');
    expect(fn() => $session->startBattleTest(0))->toThrow(SessionRefusal::class, 'first; the battle reads them on disk');
});

it('plays a battle test from an overlay carrying the battle test as it stands, never writing the project', function () {
    $root = battleTestProject();
    $system = $root . '/assets/Data/system.php';
    $before = (string) file_get_contents($system);
    $entry = ['troop' => 'Pair', 'members' => [['actor' => 'Kaelion', 'level' => 3]]];

    $overlay = PlaytestOverlay::createForBattle($root, $entry);
    $copied = require $overlay->root . '/assets/Data/system.php';
    $emptied = PlaytestOverlay::createForBattle($root, []);
    $none = require $emptied->root . '/assets/Data/system.php';
    $overlay->destroy();
    $emptied->destroy();

    expect($copied[ProjectBattleTest::SYSTEM_KEY])->toBe($entry)
        ->and($copied['startingParty'])->toBe(['Kaelion'])
        ->and($none)->not->toHaveKey(ProjectBattleTest::SYSTEM_KEY)
        ->and(file_get_contents($system))->toBe($before);
});

it('describes a draft the dialog is editing without writing it', function () {
    $root = battleTestProject();
    $session = EditorSession::open($root);
    $draft = $session->describeBattleTest(['members' => [['actor' => 'Kaelion', 'level' => 5]]]);

    expect($draft['source'])->toBe('battle test')
        ->and($draft['members'][0]['level'])->toBe(5)
        ->and($session->describeBattleTest()['source'])->toBe('starting party')
        ->and($session->listUnsavedChanges())->toBe([]);
});
