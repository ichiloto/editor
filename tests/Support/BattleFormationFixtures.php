<?php

declare(strict_types=1);

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
    file_put_contents($root . '/assets/Data/system.php', "<?php return ['startingParty' => ['Kaelion']];");
    writeTilesetTestPng($root . '/assets/Graphics/Battlebacks/Cave.png', 8, 8);

    return $root;
}
