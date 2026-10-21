<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

it('reads a troop formation as the Engine composes it over a previewed arena, the party in its slots', function () {
    $session = EditorSession::open(troopFormationProject());
    $formation = $session->readTroopFormation(0);
    $leader = $formation['members'][0];

    expect($formation['canvas'])->toBe(['width' => 1350, 'height' => 720])
        ->and($formation['arenas'])->toBe([['id' => 'arena.road', 'name' => 'Road'], ['id' => 'arena.cave', 'name' => 'Cave']])
        ->and($formation['arena'])->toBe('arena.cave')
        ->and($formation['backgrounds'])->toHaveCount(1)
        ->and($formation['backgrounds'][0])->toMatchArray(['asset' => 'Graphics/Battlebacks/Cave.png'])
        // Feet may stand from the canvas top to the battle UI's top: nothing declares a ground.
        ->and($formation['ground'])->toBe(['top' => 0.0, 'bottom' => 600.0, 'perspective' => 1.0, 'partyCompression' => 1.0, 'enemyCompression' => 1.0])
        // A party of one stands in the formation's front slot, named for the starting party.
        ->and($formation['party'])->toHaveCount(1)
        ->and($formation['party'][0])->toMatchArray(['name' => 'Kaelion', 'ground' => ['x' => 1180.0, 'y' => 560.0]])
        // A placed member stands at its place's depth on the ground; without art it is drawn by its bounds alone.
        ->and($leader['placement'])->toBe(['x' => 260, 'depth' => 300, 'width' => 275, 'height' => 190])
        ->and($leader['place'])->toBe(['x' => 260.0, 'depth' => 300.0])
        ->and($leader['battler'])->toMatchArray(['ground' => ['x' => 260.0, 'y' => 300.0], 'image' => null, 'bodySpan' => null])
        ->and($leader['battler']['diagnostics'])->toBe(['Base battler artwork unavailable: Regular Bat'])
        // A member nobody placed is arranged by the battle beside the placed one, on the ground.
        ->and($formation['members'][1])->toMatchArray(['enemy' => 'Regular Bat', 'placement' => null])
        ->and($formation['members'][1]['battler']['ground']['y'])->toBeGreaterThan(0.0)->toBeLessThanOrEqual(600.0)
        // The arena is only previewed: choosing another writes nothing; a background with no file is left out.
        ->and($session->readTroopFormation(0, 'arena.road')['backgrounds'])->toBe([])
        ->and($session->readTroopFormation(0, 'arena.road')['arena'])->toBe('arena.road')
        ->and($session->listUnsavedChanges())->toBe([])
        ->and(fn() => $session->readTroopFormation(0, 'arena.moon'))->toThrow(SessionRefusal::class, 'arena.moon');
});

it('moves a member by its battle placement as one undo step, keeping the troop file\'s comments and the terminal position', function () {
    $root = troopFormationProject();
    $session = EditorSession::open($root);
    $read = $session->readDatabaseRecord('troops', 0);
    $placement = array_find($read['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === 'member0GraphicalPlacement');

    expect($placement)->toMatchArray(['kind' => 'text', 'value' => '260, 300, 275, 190']);
    $session->applyDatabaseRecord('troops', 0, $placement['key'], '410, 330, 275, 190');
    expect($session->readTroopFormation(0)['members'][0]['placement'])->toBe(['x' => 410, 'depth' => 330, 'width' => 275, 'height' => 190])
        ->and(fn() => $session->applyDatabaseRecord('troops', 0, $placement['key'], '410, 330'))->toThrow(SessionRefusal::class, 'four numbers');

    $session->saveDatabase('troops');
    $written = (string) file_get_contents($root . '/assets/Data/troops.php');
    expect($written)->toContain('// The leader, placed for the graphical battle.', "'position' => [15, 7]", "'x' => 410", "'depth' => 330")
        ->and($session->undo()['label'] ?? null)->not->toBeNull()
        ->and($session->readTroopFormation(0)['members'][0]['placement']['x'])->toBe(260);
});

it('keeps a member placement\'s display scale through an edit, and refuses one the battle would not accept', function () {
    $root = troopFormationProject();
    $troops = $root . '/assets/Data/troops.php';
    file_put_contents($troops, str_replace("'height' => 190]]", "'height' => 190, 'displayScale' => 1.1]]", (string) file_get_contents($troops)));
    $session = EditorSession::open($root);
    $placement = array_find($session->readDatabaseRecord('troops', 0)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === 'member0GraphicalPlacement');

    expect($placement['value'])->toBe('260, 300, 275, 190, 1.1');
    $session->applyDatabaseRecord('troops', 0, $placement['key'], '410, 330, 275, 190, 1.1');
    expect($session->readTroopFormation(0)['members'][0]['placement'])->toBe(['x' => 410, 'depth' => 330, 'width' => 275, 'height' => 190, 'displayScale' => 1.1])
        ->and(fn() => $session->applyDatabaseRecord('troops', 0, $placement['key'], '410, 330, 275, 190, 0'))->toThrow(SessionRefusal::class);

    $session->saveDatabase('troops');
    expect((require $troops)[0]['enemies'][0]['graphicalPlacement'])->toBe(['x' => 410, 'depth' => 330, 'width' => 275, 'height' => 190, 'displayScale' => 1.1]);
});

it('aligns, arranges automatically and moves one member as one undo step each, through the Engine\'s arrangement', function () {
    $root = troopFormationProject();
    $session = EditorSession::open($root);
    $arranged = $session->readTroopFormation(0)['members'];

    // Moving one member places the other where it stands now, so it does not move.
    $session->placeTroopMember(0, 0, 300, 120);
    $members = $session->readTroopFormation(0)['members'];
    expect($members[0]['placement'])->toMatchArray(['x' => 300, 'depth' => 120])
        // Placements are written to two decimals.
        ->and(array_values($members[1]['place']))->toEqualWithDelta(array_values($arranged[1]['place']), .01)
        ->and([$members[1]['placement']['x'], $members[1]['placement']['depth']])
        ->toEqualWithDelta([$arranged[1]['place']['x'], $arranged[1]['place']['depth']], .01);

    $session->alignTroop(0);
    $aligned = $session->readTroopFormation(0)['members'];
    expect(array_column($aligned, 'placement'))->each->not->toBeNull()
        // The troop faces the party: its middle stands at the middle of the theme's party formation (depths 180 and 40).
        ->and((min($depths = array_map(static fn(array $member): float => $member['place']['depth'], $aligned)) + max($depths)) / 2)
        ->toEqualWithDelta(110.0, .01);

    $session->arrangeTroopAutomatically(0);
    $automatic = $session->readTroopFormation(0)['members'];
    expect(array_column($automatic, 'placement'))->toBe([null, null])
        // Align writes the arrangement to two decimals.
        ->and(array_merge(...array_map(array_values(...), array_column($automatic, 'place'))))
        ->toEqualWithDelta(array_merge(...array_map(array_values(...), array_column($aligned, 'place'))), .01);

    $session->undo();
    expect(array_column($session->readTroopFormation(0)['members'], 'placement'))->each->not->toBeNull();
    $session->saveDatabase('troops');
    expect((string) file_get_contents($root . '/assets/Data/troops.php'))->toContain('// The leader, placed for the graphical battle.')
        // Whole canvas units are written as integers, the rest to two decimals.
        ->toMatch("/'width' => 1, 'height' => 1\]/")
        ->not->toMatch("/'(x|depth)' => \\d+\\.(\\d{3,}|0+)[,\\]]/");
});

it('refuses a placement still at a fixed canvas height and names how to place it again', function () {
    $root = troopFormationProject();
    $troops = $root . '/assets/Data/troops.php';
    file_put_contents($troops, str_replace("'depth' => 300", "'y' => 300", (string) file_get_contents($troops)));
    $session = EditorSession::open($root);
    expect($session->readTroopFormation(0)['members'][0]['issue'])->toContain('y is a fixed canvas height');
    $session->arrangeTroopAutomatically(0);
    expect($session->readTroopFormation(0)['members'][0])->not->toHaveKey('issue');
});
