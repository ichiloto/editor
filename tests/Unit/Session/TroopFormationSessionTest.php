<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/** A project whose battle has two arenas, two party slots and a troop of two. */
function troopFormationProject(): string
{
    $root = makeTemporaryProject();
    @mkdir($root . '/assets/Data/Presentation', 0o777, true);
    file_put_contents($root . '/assets/Data/Presentation/battle.php', <<<'PHP'
<?php
use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlerSlot;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

$arena = static fn(string $name, string $file): BattleArenaDefinition => new BattleArenaDefinition($name,
    new CanvasImage('arena', 'Graphics/Battlebacks/' . $file, new CanvasRectangle(0, 0, 1350, 720)));
return new BattlePresentationCatalog(
    arenas: ['arena.road' => $arena('Road', 'Road.png'), 'arena.cave' => $arena('Cave', 'Cave.png')],
    actors: [],
    enemies: [],
    ui: new BattleCanvasLayout(1350, 720, partySlots: [new BattlerSlot(1050, 420, 180, 260), new BattlerSlot(1180, 560, 180, 260)]),
    defaultArena: 'arena.cave',
);
PHP);
    file_put_contents($root . '/assets/Data/troops.php', <<<'PHP'
<?php

return [
    [
        'name' => 'Pair',
        'enemies' => [
            // The leader, placed for the graphical battle.
            ['enemy' => 'Regular Bat', 'position' => [15, 7], 'graphicalPlacement' => ['x' => 260, 'y' => 300, 'width' => 275, 'height' => 190]],
            ['enemy' => 'Regular Bat', 'position' => [15, 20]],
        ],
    ],
];
PHP);

    return $root;
}

it('reads a troop formation over a previewed arena, the party in its slots and each member placed or not yet placed', function () {
    $session = EditorSession::open(troopFormationProject());
    $formation = $session->readTroopFormation(0);

    expect($formation['canvas'])->toBe(['width' => 1350, 'height' => 720])
        ->and($formation['arenas'])->toBe([['id' => 'arena.road', 'name' => 'Road'], ['id' => 'arena.cave', 'name' => 'Cave']])
        ->and($formation['arena'])->toBe('arena.cave')
        ->and($formation['background'])->toMatchArray(['asset' => 'Graphics/Battlebacks/Cave.png', 'width' => 1350.0, 'height' => 720.0])
        ->and(array_map(static fn(array $slot): array => [$slot['x'], $slot['y']], $formation['party']))->toBe([[1050.0, 420.0], [1180.0, 560.0]])
        ->and($formation['members'])->toBe([
            ['enemy' => 'Regular Bat', 'placement' => ['x' => 260, 'y' => 300, 'width' => 275, 'height' => 190]],
            ['enemy' => 'Regular Bat', 'placement' => null],
        ])
        // The arena is only previewed: choosing another writes nothing.
        ->and($session->readTroopFormation(0, 'arena.road')['background']['asset'])->toBe('Graphics/Battlebacks/Road.png')
        ->and($session->listUnsavedChanges())->toBe([])
        ->and(fn() => $session->readTroopFormation(0, 'arena.moon'))->toThrow(SessionRefusal::class, 'no battle arena arena.moon');
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
