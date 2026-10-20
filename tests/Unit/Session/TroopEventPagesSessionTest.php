<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Battle\Events\BattleEventPage;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandCatalog;

/** The row a record frame shows for a field id. */
function troopEventRow(array $record, string $field): array
{
    return array_find($record['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === $field)
        ?? throw new RuntimeException("No row {$field}: " . implode(', ', array_filter(array_map(
            static fn(array $row): ?string => $row['key']['field'] ?? null, $record['rows']))));
}

it('authors a troop event page with conditions and battle commands, saved as the Engine reads it', function () {
    $root = troopPageProject();
    $session = EditorSession::open($root);
    $record = $session->readDatabaseRecord('troops', 0);

    $session->addDatabaseItem('troops', 0, troopEventRow($record, 'pageList')['key']);
    $record = $session->readDatabaseRecord('troops', 0);
    $enemy = troopEventRow($record, 'page0Conditionsenemy');
    expect($enemy['options'] ?? null)->toBe(['None', '1 · Regular Bat', '2 · Regular Bat']);

    $session->applyDatabaseRecord('troops', 0, troopEventRow($record, 'page0Name')['key'], 'Concession');
    $session->applyDatabaseRecord('troops', 0, $enemy['key'], '2 · Regular Bat');
    $record = $session->readDatabaseRecord('troops', 0);
    $session->applyDatabaseRecord('troops', 0, troopEventRow($record, 'page0ConditionsenemyHpPercent')['key'], '25');
    $session->applyDatabaseRecord('troops', 0, troopEventRow($record, 'page0Conditionsturn')['key'], '0');

    $commands = troopEventRow($session->readDatabaseRecord('troops', 0), 'page0Commands');
    expect($commands['key']['frame'] ?? $commands['frame'] ?? null)->not->toBeNull();
    $frame = $commands['frame'] ?? $commands['key']['frame'];
    expect($frame)->toBe(['events', 0, 'commands']);

    $session->addDatabaseItem('troops', 0, ['frame' => $frame]);
    $inside = $session->readDatabaseRecord('troops', 0, $frame);
    $type = troopEventRow($inside, 'command0Type');
    expect($type['options'])->toContain('concede', 'end_battle', 'text')->not->toContain('transfer', 'shop', 'start_battle');

    $session->applyDatabaseRecord('troops', 0, $type['key'], 'concede');
    $inside = $session->readDatabaseRecord('troops', 0, $frame);
    $session->applyDatabaseRecord('troops', 0, troopEventRow($inside, 'command0Enemy')['key'], '2 · Regular Bat');
    $session->saveDatabase('troops');
    clearstatcache();

    $data = require $root . '/assets/Data/troops.php';
    expect($data[0]['events'])->toBe([[
        'span' => 'battle',
        'conditions' => ['enemy' => 1, 'enemyHpPercent' => 25],
        'commands' => [['type' => 'concede', 'enemy' => 1]],
        'name' => 'Concession',
    ]]);
    expect(BattleEventPage::readPages($data[0]['events'], 'saved', 2, ScriptCommandCatalog::createEngineCatalog()))->toHaveCount(1);
});

it('refuses to save a page the Engine would refuse, saying what to fix', function () {
    $root = troopPageProject();
    $session = EditorSession::open($root);
    $session->addDatabaseItem('troops', 0, troopEventRow($session->readDatabaseRecord('troops', 0), 'pageList')['key']);
    $record = $session->readDatabaseRecord('troops', 0);
    // An enemy chosen without its HP share is half a condition.
    $session->applyDatabaseRecord('troops', 0, troopEventRow($record, 'page0Conditionsenemy')['key'], '1 · Regular Bat');
    $before = (string) file_get_contents($root . '/assets/Data/troops.php');

    expect(fn() => $session->saveDatabase('troops'))->toThrow('needs both enemy and enemyHpPercent');
    expect((string) file_get_contents($root . '/assets/Data/troops.php'))->toBe($before);
});

it('sets a member\'s knockout floor on its own row', function () {
    $root = troopPageProject();
    $session = EditorSession::open($root);
    $session->applyDatabaseRecord('troops', 0, troopEventRow($session->readDatabaseRecord('troops', 0), 'member0KnockoutFloorPercent')['key'], '25');
    $session->saveDatabase('troops');

    expect((require $root . '/assets/Data/troops.php')[0]['enemies'][0]['knockoutFloorPercent'])->toBe(25);
});

it('keeps field-only commands out of field scripts and battle-only ones out of them too', function () {
    $types = Ichiloto\Editor\Database\RecordSchemaCatalog::getEventCommandTypes();
    $battle = Ichiloto\Editor\Database\RecordSchemaCatalog::getEventCommandTypes(Ichiloto\Engine\Events\Interpreter\EventExecutionScope::BATTLE);

    expect($types)->toContain('transfer', 'shop')->not->toContain('concede', 'end_battle')
        ->and($battle)->toContain('concede', 'end_battle', 'branch')->not->toContain('transfer', 'shop', 'move_route');
});

it('keeps event pages naming the same members when a member is added or removed, and refuses removing a named one', function () {
    $root = troopPageProject();
    $troops = $root . '/assets/Data/troops.php';
    file_put_contents($troops, str_replace("'name' => 'Pair',", "'name' => 'Pair',\n        'events' => [['conditions' => ['enemy' => 1, 'enemyHpPercent' => 25], 'commands' => [['type' => 'branch', 'conditions' => [], 'then' => [['type' => 'concede', 'enemy' => 1]]]]]],", (string) file_get_contents($troops)));
    $session = EditorSession::open($root);
    expect(fn() => $session->removeTroopMember(0, 1))->toThrow('names member 2')
        ->and(fn() => $session->clearTroopMembers(0))->toThrow('names member 2');

    $session->removeTroopMember(0, 0);
    $session->saveDatabase('troops');
    clearstatcache();
    $page = (require $troops)[0]['events'][0];
    expect($page['conditions']['enemy'])->toBe(0)->and($page['commands'][0]['then'][0]['enemy'])->toBe(0);

    $session->undo();
    $session->saveDatabase('troops');
    clearstatcache();
    $page = (require $troops)[0]['events'][0];
    expect($page['conditions']['enemy'])->toBe(1)->and($page['commands'][0]['then'][0]['enemy'])->toBe(1);
});
