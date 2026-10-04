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
        // The party stands where the battle's slots put it, named for the starting party.
        ->and($formation['party'])->toHaveCount(1)
        ->and($formation['party'][0])->toMatchArray(['name' => 'Kaelion', 'ground' => ['x' => 1050.0, 'y' => 420.0]])
        // A placed member stands on its placement's ground point; without art it is drawn by its bounds alone.
        ->and($leader['placement'])->toBe(['x' => 260, 'y' => 300, 'width' => 275, 'height' => 190])
        ->and($leader['battler'])->toMatchArray(['ground' => ['x' => 260.0, 'y' => 300.0], 'image' => null, 'bodySpan' => null])
        ->and($leader['battler']['diagnostics'])->toBe(['Base battler artwork unavailable: Regular Bat'])
        ->and($formation['members'][1])->toBe(['enemy' => 'Regular Bat', 'placement' => null, 'battler' => null])
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
    expect($session->readTroopFormation(0)['members'][0]['placement'])->toBe(['x' => 410, 'y' => 330, 'width' => 275, 'height' => 190])
        ->and(fn() => $session->applyDatabaseRecord('troops', 0, $placement['key'], '410, 330'))->toThrow(SessionRefusal::class, 'four numbers');

    $session->saveDatabase('troops');
    $written = (string) file_get_contents($root . '/assets/Data/troops.php');
    expect($written)->toContain('// The leader, placed for the graphical battle.', "'position' => [15, 7]", "'x' => 410", "'y' => 330")
        ->and($session->undo()['label'] ?? null)->not->toBeNull()
        ->and($session->readTroopFormation(0)['members'][0]['placement']['x'])->toBe(260);
});
