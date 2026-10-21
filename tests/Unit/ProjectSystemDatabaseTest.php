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

/** What the System page shows for a field, as both editors read it. */
function systemSettingsValue(string $root, string $field): ?string
{
    $row = array_find(systemRecords($root)->getSettingsFields(0), static fn(array $row): bool => ($row['field'] ?? null) === $field);

    return is_array($row) ? (string) $row['value'] : null;
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
        ->and($labels)->toContain('Title', 'Starting Gold', 'Start Map', 'Battle Engine', 'Level Up HP/MP', 'Starting Party', 'Starting Inventory');
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

it('saves System and Types, which share system.php, each with only its own keys', function (): void {
    $root = scratchSystemProject();
    $path = $root . '/assets/Data/system.php';
    file_put_contents($path, "<?php\n\nreturn " . var_export([...productionSystem(), 'elements' => ['Fire', 'Ice']], true) . ";\n");
    $system = systemRecords($root);
    $types = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('types'));

    $types->setField(0, ProjectRecordDatabase::subFieldId('element', 1, 'name'), 'Frost');
    $system->setField(0, 'currency.amount', '900');
    $system->save();
    $types->save();
    $saved = require $path;

    expect($saved['currency']['amount'])->toBe(900)
        ->and($saved['elements'])->toBe(['Fire', 'Frost'])
        ->and($saved['unknownSystemSetting'])->toBe(['preserve' => 'yes'])
        ->and(array_keys($system->getRecordByIndex(0)?->toArray() ?? []))->not->toContain('elements')
        ->and(fn() => new Ichiloto\Editor\Database\RecordSchema(
            key: 'stray',
            entryNoun: 'stray',
            storage: Ichiloto\Editor\Database\RecordStorage::LIST_FILE,
            relativePath: 'assets/Data/system.php',
            fields: [new Ichiloto\Editor\Database\RecordField('title', 'Title')],
            projection: new Ichiloto\Editor\Database\Projections\WholeFileProjection(['elements']),
        ))->toThrow(LogicException::class, 'The stray category edits "title", a key its file projection does not own.');
});

it('chooses what a level up does to current HP and MP, reading an absent rule as the Engine\'s default, and keeps the rest', function (): void {
    $root = scratchSystemProject();
    $path = $root . '/assets/Data/system.php';
    file_put_contents($path, "<?php\n\nreturn " . var_export(productionSystem(), true) . ";\n");
    $field = array_find(RecordSchemaCatalog::forKey('system')->fields,
        static fn($field): bool => $field->key === 'progression.levelUpRecovery');
    $read = static fn(array $system): Ichiloto\Engine\Progression\LevelUpRecovery
        => Ichiloto\Engine\Progression\ProgressionSettings::fromSystemData($system)->levelUpRecovery;

    expect($field->options)->toBe(['full', 'increase', 'none'])
        ->and($field->displayDefault)->toBe('full')
        ->and($read(productionSystem()))->toBe(Ichiloto\Engine\Progression\LevelUpRecovery::FULL)
        ->and(systemSettingsValue($root, 'progression.levelUpRecovery'))->toBe('full');

    foreach (['none', 'increase', 'full'] as $mode) {
        $database = systemRecords($root);
        $database->setField(0, 'progression.levelUpRecovery', $mode);
        $database->save();
        $saved = require $path;

        expect($saved)->toBe([...productionSystem(), 'progression' => ['levelUpRecovery' => $mode]])
            ->and($read($saved)->value)->toBe($mode)
            ->and(systemSettingsValue($root, 'progression.levelUpRecovery'))->toBe($mode);
    }
});

it('serves the level up rule to the GUI session as a choice that keeps the file\'s own writing', function (): void {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/system.php';
    $source = "<?php\n\nuse Ichiloto\\Engine\\Core\\Enumerations\\MovementHeading;\n\nreturn [\n  'title' => 'Fixture',\n"
        . "  // Where the story starts.\n  'startingPositions' => ['player' => ['spawnSprite' => [MovementHeading::SOUTH->value]]],\n];\n";
    file_put_contents($path, $source);
    $session = EditorSession::open($root);
    $row = static fn(): array => array_find($session->readDatabaseRecord('system', 0)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'progression.levelUpRecovery')
        ?? throw new RuntimeException('Missing Level Up HP/MP row');

    expect($row())->toMatchArray(['label' => 'Level Up HP/MP', 'kind' => 'options', 'options' => ['full', 'increase', 'none'], 'value' => 'full']);

    $session->applyDatabaseRecord('system', 0, $row()['key'], 'increase');
    $session->saveDatabase('system');
    $written = (string) file_get_contents($path);

    expect($row()['value'])->toBe('increase')
        ->and($written)->toContain('// Where the story starts.', '[MovementHeading::SOUTH->value]')
        ->and((require $path)['progression'])->toBe(['levelUpRecovery' => 'increase'])
        ->and(Ichiloto\Engine\Progression\ProgressionSettings::fromSystemData(require $path)->levelUpRecovery)
            ->toBe(Ichiloto\Engine\Progression\LevelUpRecovery::INCREASE);

    $session->undo();
    $session->saveDatabase('system');
    expect((string) file_get_contents($path))->toBe($source);
});

it('reports progression rules the Engine would not read as an error', function (): void {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/system.php';
    $system = is_file($path) ? require $path : [];
    $validate = static fn(): array => array_values(array_filter(
        new Ichiloto\Editor\Validation\ProgressionValidator()->validate(Ichiloto\Editor\ProjectWorkspace::fromProject($root)),
        static fn($issue): bool => str_contains($issue->where, 'progression')));

    expect($validate())->toBe([]);

    file_put_contents($path, "<?php\n\nreturn " . var_export([...$system, 'progression' => ['levelUpRecovery' => 'half']], true) . ";\n");
    $issues = $validate();

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Ichiloto\Editor\Validation\Severity::ERROR)
        ->and($issues[0]->message)->toContain('must be one of: full, increase, none');

    file_put_contents($path, "<?php\n\nreturn " . var_export([...$system, 'progression' => ['restoreVitalsOnLevelUp' => false]], true) . ";\n");
    $issues = $validate();

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->message)->toContain("replaced by levelUpRecovery: write 'full' for true or 'none' for false");
});
