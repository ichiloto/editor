<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;

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

/** The troop formation project with one enemy record and two terminal sprites. */
function enemyPreviewProject(): string
{
    $root = troopFormationProject();
    @mkdir($root . '/assets/Graphics/Enemies', 0o777, true);
    file_put_contents($root . '/assets/Graphics/Enemies/wisp.txt', "~*~\n");
    file_put_contents($root . '/assets/secret.txt', "not a sprite\n");
    writeRegularBatEnemy($root);

    return $root;
}

/** The Regular Bat the troop formation project's troop fights, as its one enemy record, with its terminal sprite. */
function writeRegularBatEnemy(string $root): void
{
    @mkdir($root . '/assets/Data/Enemies', 0o777, true);
    @mkdir($root . '/assets/Graphics/Enemies', 0o777, true);
    file_put_contents($root . '/assets/Graphics/Enemies/bat.txt', "/\\o/\\\n ' '\n");
    file_put_contents($root . '/assets/Data/Enemies/regular-bat.php', "<?php\n\nuse Ichiloto\\Engine\\Entities\\Enemies\\Enemy;\n\nreturn ['class' => Enemy::class, 'data' => "
        . var_export(['name' => 'Regular Bat', 'level' => 2, 'imagePath' => 'bat', 'rewards' => ['experience' => 1, 'gold' => 1], 'stats' => [
            'maxHp' => 10, 'maxMp' => 0, 'attack' => 3, 'defence' => 2, 'magicAttack' => 1, 'magicDefence' => 1, 'speed' => 2, 'grace' => 1, 'evasion' => 1,
        ]], true) . "];\n");
}

/** The enemy preview project with battle art for its bat on disk. */
function battlerArtProject(): string
{
    $root = enemyPreviewProject();
    writeTilesetTestPng($root . '/assets/Graphics/Enemies/Bat.png', 40, 80);
    writeTilesetTestPng($root . '/assets/Graphics/Enemies/BatAttack.png', 160, 80);

    return $root;
}

/** A battle art record's row, by its field. */
function readBattlerRow(EditorSession $session, int $index, string $field): array
{
    return array_find($session->readDatabaseRecord('battler_enemies', $index)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === $field)
        ?? throw new RuntimeException("No row {$field}.");
}

function setBattlerRow(EditorSession $session, int $index, string $field, string $value): void
{
    $session->applyDatabaseRecord('battler_enemies', $index, readBattlerRow($session, $index, $field)['key'], $value);
}

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

/** The battle test project holding the lantern summon, with sheet art that rests on its second cell. */
function summonBattlePreviewProject(array $wielders = []): string
{
    $root = battleTestProject();
    $summon = $root . '/assets/Cutscenes/Summons/lantern-wisp';
    mkdir($summon, 0o777, true);
    $data = lanternSummonData();
    if ($wielders !== []) {
        $data = str_replace("  'linkedActionId' => 'Fireball',", "  'linkedActionId' => 'Fireball',\n  'wielders' => " . var_export($wielders, true) . ',', $data);
    }
    file_put_contents($summon . '/lantern-wisp.data.php', $data);
    file_put_contents($summon . '/lantern-wisp.timeline.php', str_replace(["  'lengthFrames' => 24,", "    ['type' => 'text', 'id' => 'name',"], [
        "  'lengthFrames' => 24,\n  'restFrame' => 12,",
        "    ['type' => 'image', 'id' => 'wisp-art', 'presentation' => 'graphical', 'asset' => 'Graphics/Summons/Wisp.png',\n"
        . "      'sheet' => ['columns' => 2, 'rows' => 1], 'anchor' => 'target', 'attachment' => 'ground', 'pivot' => ['x' => 0.5, 'y' => 1],\n"
        . "      'keyframes' => [['frame' => 0, 'duration' => 12, 'sourceFrame' => 0], ['frame' => 12, 'duration' => 12, 'sourceFrame' => 1]]],\n"
        . "    ['type' => 'text', 'id' => 'name',"], lanternSummonTimeline()));
    mkdir($root . '/assets/Graphics/Summons', 0o777, true);
    writeTilesetTestPng($root . '/assets/Graphics/Summons/Wisp.png', 16, 8);
    writeFireballSkill($root);
    // The battle reads enemies the way the game loads them, one file each through the project's barrel.
    writeRegularBatEnemy($root);
    // A graphical battle places every enemy it fights.
    file_put_contents($root . '/assets/Data/troops.php', str_replace("['enemy' => 'Regular Bat', 'position' => [15, 20]]",
        "['enemy' => 'Regular Bat', 'position' => [15, 20], 'graphicalPlacement' => ['x' => 420, 'y' => 380, 'width' => 275, 'height' => 190]]",
        (string) file_get_contents($root . '/assets/Data/troops.php')));
    file_put_contents($root . '/assets/Data/enemies.php', "<?php\n\nreturn Ichiloto\\Engine\\Entities\\Enemies\\EnemyCatalog::loadProjectEnemies(dirname(__DIR__));\n");

    return $root;
}
