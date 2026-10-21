<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\TroopAuthoring;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Battle\Presentation\TerminalBattleEffects;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Editor\Session\SessionRefusal;

it('reads a troop page with its members, the enemies to add from and its formation', function () {
    $page = EditorSession::open(troopPageProject())->readTroopPage(0);

    expect($page['members'])->toBe([
        ['enemy' => 'Regular Bat', 'position' => [15, 7], 'placement' => ['x' => 260, 'depth' => 300, 'width' => 275, 'height' => 190]],
        ['enemy' => 'Regular Bat', 'position' => [15, 20], 'placement' => null],
    ])
        ->and($page['enemies'])->toEqualCanonicalizing(['Regular Bat', 'Marsh Wisp'])
        ->and($page['formation']['members'])->toHaveCount(2)
        ->and($page['formationIssue'])->toBeNull()
        ->and($page['align'])->toBe(['available' => true, 'reason' => null]);
});

it('reads a troop page without a graphical battle, saying why there is no formation', function () {
    $root = troopPageProject();
    unlink($root . '/assets/Data/Presentation/battle.php');
    $page = EditorSession::open($root)->readTroopPage(0);

    expect($page['members'])->toHaveCount(2)
        ->and($page['formation'])->toBeNull()
        ->and($page['formationIssue'])->toBeString()->not->toBe('');
});

it('adds a member by its enemy as one undo step, keeping the file\'s comments and keys it does not know', function () {
    $root = troopPageProject();
    $troops = $root . '/assets/Data/troops.php';
    file_put_contents($troops, str_replace("'name' => 'Pair',", "'name' => 'Pair',\n        'id' => 'pair',\n        'events' => [['conditions' => ['turn' => 1]]],", (string) file_get_contents($troops)));
    $session = EditorSession::open($root);

    $added = $session->addTroopMember(0, 'Marsh Wisp');
    // It stands on the terminal field where the Engine puts a new member beside the two bats, by their sprites.
    $bat = ['/\\o/\\', " ' '"];
    $place = new TerminalBattleEffects()->findTroopPlace([['position' => new Vector2(15, 7), 'sprite' => $bat],
        ['position' => new Vector2(15, 20), 'sprite' => $bat]], ['~*~']);
    expect($added['member'])->toBe(2)
        ->and($session->readTroopPage(0)['members'][2])->toBe(['enemy' => 'Marsh Wisp', 'position' => [(int) $place->x, (int) $place->y], 'placement' => null])
        ->and([(int) $place->x, (int) $place->y])->not->toBe([15, 7])
        ->and(fn() => $session->addTroopMember(0, 'Dragon'))->toThrow(SessionRefusal::class, 'Dragon')
        ->and(fn() => $session->addTroopMember(0, ''))->toThrow(SessionRefusal::class, 'Choose');

    $session->saveDatabase('troops');
    $written = (string) file_get_contents($troops);
    $data = require $troops;
    expect($written)->toContain('// The leader, placed for the graphical battle.')
        ->and($data[0]['id'])->toBe('pair')
        ->and($data[0]['events'])->toBe([['conditions' => ['turn' => 1]]])
        ->and(array_column($data[0]['enemies'], 'enemy'))->toBe(['Regular Bat', 'Regular Bat', 'Marsh Wisp']);

    $session->undo();
    expect($session->readTroopPage(0)['members'])->toHaveCount(2);
});

it('removes one member and clears them all, each one undo step', function () {
    $session = EditorSession::open(troopPageProject());
    $session->addTroopMember(0, 'Marsh Wisp');

    $session->removeTroopMember(0, 0);
    expect(array_column($session->readTroopPage(0)['members'], 'enemy'))->toBe(['Regular Bat', 'Marsh Wisp'])
        ->and(fn() => $session->removeTroopMember(0, 5))->toThrow(SessionRefusal::class, 'member 6');

    expect($session->clearTroopMembers(0)['changed'])->toBeTrue()
        ->and($session->readTroopPage(0)['members'])->toBe([])
        ->and($session->clearTroopMembers(0)['changed'])->toBeFalse();

    $session->undo();
    expect(array_column($session->readTroopPage(0)['members'], 'enemy'))->toBe(['Regular Bat', 'Marsh Wisp']);
    $session->undo();
    expect($session->readTroopPage(0)['members'][0]['placement'])->toBe(['x' => 260, 'depth' => 300, 'width' => 275, 'height' => 190]);
});

it('names a troop after its members, numbering a name another troop has', function () {
    $session = EditorSession::open(troopPageProject());
    $session->addTroopMember(0, 'Marsh Wisp');

    expect($session->nameTroopAfterMembers(0)['name'])->toBe('Regular Bat x 2, Marsh Wisp');
    $session->undo();
    expect($session->listDatabaseRecords('troops')['records'])->toBe(['Pair']);

    $created = $session->createDatabaseRecord('troops');
    expect($session->readTroopPage($created['index'])['members'])->toBe([])
        ->and(fn() => $session->nameTroopAfterMembers($created['index']))->toThrow(SessionRefusal::class, 'Add a member first');
    $session->addTroopMember($created['index'], 'Regular Bat');
    $session->addTroopMember($created['index'], 'Regular Bat');
    $session->addTroopMember($created['index'], 'Marsh Wisp');
    $session->nameTroopAfterMembers(0);

    expect($session->nameTroopAfterMembers($created['index'])['name'])->toBe('Regular Bat x 2, Marsh Wisp 2');
});

it('describes members by each enemy once, in order, with how many there are', function () {
    expect(TroopAuthoring::describeMembers(['Bat', 'Rat', 'Bat', '']))->toBe('Bat x 2, Rat')
        ->and(TroopAuthoring::describeMembers([]))->toBe('');
});

it('sets a troop\'s battle music from the project\'s tracks, and unsetting it leaves the battle theme to the project', function () {
    $root = troopPageProject();
    @mkdir($root . '/assets/Audio/BGM', 0o777, true);
    file_put_contents($root . '/assets/Audio/BGM/Boss Theme.ogg', 'ogg');
    $session = EditorSession::open($root);
    $music = array_find($session->readDatabaseRecord('troops', 0)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === 'bgm');

    expect($music)->toMatchArray(['kind' => 'reference', 'reference' => 'bgm', 'value' => 'Project battle theme']);
    $session->applyDatabaseRecord('troops', 0, $music['key'], 'Boss Theme');
    $session->saveDatabase('troops');
    expect((require $root . '/assets/Data/troops.php')[0]['bgm'])->toBe('Boss Theme');

    $session->applyDatabaseRecord('troops', 0, $music['key'], '');
    $session->saveDatabase('troops');
    expect((require $root . '/assets/Data/troops.php')[0])->not->toHaveKey('bgm');
});
