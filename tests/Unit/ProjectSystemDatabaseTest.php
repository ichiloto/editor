<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Session\EditorSession;

function scratchSystemProject(): string
{
    $root = rememberTemporaryProject(sys_get_temp_dir() . '/ichiloto-system-' . uniqid());
    mkdir($root . '/assets/Data', 0777, true);

    return $root;
}

function systemRecords(string $root): ProjectRecordDatabase
{
    return ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('system'));
}

/** @return array<string, mixed> */
function productionSystem(): array
{
    return [
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
                'futureTuningKey' => 17,
            ],
        ],
        'unknownSystemSetting' => ['preserve' => 'yes'],
    ];
}

it('round-trips every system field, extended and unknown ones included, byte for byte', function (): void {
    $root = scratchSystemProject();
    $path = $root . '/assets/Data/system.php';
    file_put_contents($path, "<?php\n\n// Keep this header.\nreturn " . var_export(productionSystem(), true) . ";\n");
    $source = (string) file_get_contents($path);

    $database = systemRecords($root);
    $database->setField(0, 'title', 'Changed');
    $database->setField(0, 'title', 'Production Fixture');
    $database->save();

    expect((string) file_get_contents($path))->toBe($source);
});

it('merges an edited system field without removing unedited settings', function (): void {
    $root = scratchSystemProject();
    $path = $root . '/assets/Data/system.php';
    file_put_contents($path, "<?php\n\nreturn " . var_export(productionSystem(), true) . ";\n");

    $database = systemRecords($root);
    $database->setField(0, 'battle.activeTime.baseFillRate', '42');
    $database->setField(0, 'startingPositions.player.spawnSprite.0', 'West');
    $database->save();
    $saved = require $path;
    $expected = productionSystem();
    $expected['battle']['activeTime']['baseFillRate'] = 42;
    $expected['startingPositions']['player']['spawnSprite'] = ['West'];

    expect($saved)->toBe($expected);
});

it('edits the starting party as actor ids and the starting inventory as items with quantities', function (): void {
    $root = scratchSystemProject();
    $path = $root . '/assets/Data/system.php';
    file_put_contents($path, "<?php\n\nreturn " . var_export(productionSystem(), true) . ";\n");

    $database = systemRecords($root);
    $database->removeSubItem(0, 1, 'startingParty');
    $stock = $database->addSubItem(0, listKey: 'startingInventory');
    $database->setField(0, "stock{$stock}Item", 'Ether');
    $database->setField(0, "stock{$stock}Quantity", '3');
    $database->save();
    $saved = require $path;

    // A party member stays a bare id, as the file authors it.
    expect($saved['startingParty'])->toBe(['Kaelion', 'Drazek', 'Seraphis'])
        ->and($saved['startingInventory'])->toBe([['item' => 'S-Potion', 'quantity' => 10], ['item' => 'Ether', 'quantity' => 3]]);
});

it('keeps the file as the category\'s one record, only ever edited', function (): void {
    $root = scratchSystemProject();
    file_put_contents($root . '/assets/Data/system.php', "<?php\n\nreturn " . var_export(productionSystem(), true) . ";\n");
    $database = systemRecords($root);

    expect($database->getEntryLabels())->toBe(['Production Fixture'])
        ->and($database->isEditable())->toBeTrue()
        ->and($database->supportsRecordCreation())->toBeFalse()
        ->and($database->supportsRecordDeletion())->toBeFalse()
        ->and($database->duplicateRecordSupported())->toBeFalse()
        ->and($database->addRecord())->toBeNull();
});

it('opens System in the GUI session with its lists under their headings', function (): void {
    $session = EditorSession::open(makeTemporaryProject());
    $records = $session->listDatabaseRecords('system');
    $rows = $session->readDatabaseRecord('system', 0)['rows'];
    $labels = array_column($rows, 'label');

    expect($records['editable'])->toBeTrue()
        ->and($records['canCreate'])->toBeFalse()
        ->and($labels)->toContain('Title', 'Starting Gold', 'Start Map', 'Battle Engine', 'Starting Party', 'Starting Inventory');
});

it('keeps a value written as an enum case\'s value written that way, as the new case', function (): void {
    $root = scratchSystemProject();
    $path = $root . '/assets/Data/system.php';
    file_put_contents($path, "<?php\n\nuse Ichiloto\\Engine\\Core\\Enumerations\\MovementHeading;\n\nreturn [\n  'title' => 'Fixture',\n  'startingPositions' => ['player' => ['spawnSprite' => [MovementHeading::SOUTH->value]]],\n];\n");

    $database = systemRecords($root);
    new \Ichiloto\Editor\Database\RecordAuthoring()->applyField($database, 0, [], 'startingPositions.player.spawnSprite.0', 'West', 'Start Facing');
    $database->save();

    expect((string) file_get_contents($path))->toContain('[MovementHeading::WEST->value]')
        ->and((require $path)['startingPositions']['player']['spawnSprite'])->toBe(['West']);
});

it('refuses an edit to a value the file writes as an expression when it is made, not at save', function (): void {
    $root = scratchSystemProject();
    $path = $root . '/assets/Data/system.php';
    file_put_contents($path, "<?php\n\nreturn ['title' => strtoupper('fixture'), 'currency' => ['amount' => 5]];\n");
    $database = systemRecords($root);

    expect(fn() => new \Ichiloto\Editor\Database\RecordAuthoring()->applyField($database, 0, [], 'title', 'Renamed', 'Title'))
        ->toThrow(\Ichiloto\Editor\Database\RecordRefusal::class, 'expression')
        ->and($database->getRecordByIndex(0)->get('title'))->toBe('FIXTURE')
        ->and($database->isDirty())->toBeFalse();

    // Values written as plain data stay editable beside it.
    new \Ichiloto\Editor\Database\RecordAuthoring()->applyField($database, 0, [], 'currency.amount', '9', 'Starting Gold');
    $database->save();

    expect((string) file_get_contents($path))->toContain("strtoupper('fixture')", "'amount' => 9");
});
