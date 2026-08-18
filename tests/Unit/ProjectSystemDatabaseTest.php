<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectSystemDatabase;

function scratchSystemProject(): string
{
    $root = rememberTemporaryProject(sys_get_temp_dir() . '/ichiloto-system-' . uniqid());
    mkdir($root . '/assets/Data', 0777, true);

    return $root;
}

it('round-trips every system field including extended and unknown ATB settings', function (): void {
    $root = scratchSystemProject();
    $path = $root . '/assets/Data/system.php';
    $original = [
        'title' => 'Production Fixture',
        'currency' => ['amount' => 400, 'symbol' => 'G'],
        'startingParty' => ['Kaelion', 'Liora', 'Drazek', 'Seraphis'],
        'startingInventory' => [['item' => 'S-Potion', 'quantity' => 10]],
        'startingPositions' => [
            'player' => [
                'destinationMap' => 'happyville/home',
                'spawnPoint' => ['x' => 8, 'y' => 4],
                'spawnSprite' => ['South'],
            ],
        ],
        'battle' => [
            'engine' => 'active_time',
            'unknownBattleSetting' => ['future' => true],
            'activeTime' => [
                'mode' => 'wait',
                'baseFillRate' => 35,
                'speedFactorPercent' => 100,
                'openingVariance' => 24,
                'openingSpeedFactorPercent' => 250,
                'surpriseAttackChancePercent' => 8,
                'backAttackChancePercent' => 6,
                'futureTuningKey' => 17,
            ],
        ],
        'unknownSystemSetting' => ['preserve' => 'yes'],
    ];
    file_put_contents($path, "<?php\n\nreturn " . var_export($original, true) . ";\n");

    $database = ProjectSystemDatabase::fromProject($root);
    $database->save();

    $saved = require $path;
    $reloaded = ProjectSystemDatabase::fromProject($root);
    $reloaded->save();
    $reloadedAgain = require $path;

    expect($saved)->toBe($original)
        ->and($saved['battle']['activeTime'])->toBe($original['battle']['activeTime'])
        ->and($saved['battle']['unknownBattleSetting'])->toBe(['future' => true])
        ->and($saved['unknownSystemSetting'])->toBe(['preserve' => 'yes'])
        ->and($reloadedAgain)->toBe($original);
});

it('merges an edited system field without removing unedited settings', function (): void {
    $root = scratchSystemProject();
    $path = $root . '/assets/Data/system.php';
    $original = [
        'title' => 'Production Fixture',
        'battle' => [
            'engine' => 'active_time',
            'activeTime' => [
                'mode' => 'wait',
                'baseFillRate' => 35,
                'speedFactorPercent' => 100,
                'openingVariance' => 24,
                'futureTuningKey' => 17,
            ],
        ],
    ];
    file_put_contents($path, "<?php\n\nreturn " . var_export($original, true) . ";\n");

    $database = ProjectSystemDatabase::fromProject($root);
    $database->setField('atbBaseFillRate', 42);
    $database->save();
    $saved = require $path;

    expect($saved['battle']['activeTime']['baseFillRate'])->toBe(42)
        ->and($saved['battle']['activeTime']['openingVariance'])->toBe(24)
        ->and($saved['battle']['activeTime']['futureTuningKey'])->toBe(17);
});
