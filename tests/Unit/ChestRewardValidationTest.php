<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\InventoryCatalog;
use Ichiloto\Editor\Events\ChestLoot;
use Ichiloto\Editor\Inspector\MapInspector;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\ChestRewardValidator;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Editor\Validation\Severity;
use Ichiloto\Engine\Entities\Actions\ChestOpeningAction;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Events\Enumerations\LootType;

/**
 * A synthetic project whose inventory holds one tonic, known by its id, its
 * name and an alias, and two charms that both answer to one alias.
 */
function chestRewardProject(): string
{
    $root = makeTemporaryProject('ichiloto-chest-reward-');
    writeItemRecords($root,
        new Item(id: 'item.tonic', name: 'Tonic', description: '', icon: 't', price: 5, aliases: ['Old Tonic']),
        new Item(id: 'item.charm-a', name: 'Charm A', description: '', icon: 'a', price: 1, aliases: ['Charm']),
        new Item(id: 'item.charm-b', name: 'Charm B', description: '', icon: 'b', price: 1, aliases: ['charm']),
    );

    return $root;
}

/**
 * Checks one chest's data against the synthetic project.
 *
 * @param array<string, mixed> $data The chest's data.
 * @return Issue[]
 */
function checkChestReward(array $data): array
{
    static $catalog = null;
    $catalog ??= InventoryCatalog::fromWorkspace(ProjectWorkspace::fromProject(chestRewardProject()));

    return new ChestRewardValidator($catalog)->validate($data, 'test-map event C');
}

/**
 * Writes a synthetic map whose events are the given definitions.
 *
 * @param array<string, array<string, mixed>> $events Event definitions by marker.
 */
function writeChestRewardMap(string $root, array $events): void
{
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $map = require $path;
    $map['events'] = $events;
    file_put_contents($path, "<?php\n\nreturn " . var_export($map, true) . ";\n");
}

/**
 * Validates a project and keeps its chest reward findings.
 *
 * @return Issue[]
 */
function listChestRewardIssues(string $root): array
{
    return array_values(array_filter(
        new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)),
        static fn(Issue $issue): bool => $issue->code === ChestRewardValidator::CODE,
    ));
}

it('reports an item chest with no item chosen as an error', function (mixed $loot) {
    $issues = checkChestReward(['lootType' => 'item', 'loot' => $loot, 'quantity' => 1]);

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Severity::ERROR)
        ->and($issues[0]->code)->toBe(ChestRewardValidator::CODE)
        ->and($issues[0]->message)->toBe('It has no item chosen, so it fails when opened.');
})->with([
    'blank' => [''],
    'null' => [null],
    'whitespace' => ['   '],
]);

it('reports a chest with no loot at all, which the Engine reads as an empty item', function () {
    expect(checkChestReward([]))->toHaveCount(1)
        ->and(checkChestReward([])[0]->message)->toBe('It has no item chosen, so it fails when opened.');
});

it('resolves item loot by the Engine\'s id, name and alias rules', function (string $loot) {
    expect(checkChestReward(['lootType' => 'item', 'loot' => $loot]))->toBe([]);
})->with([
    'stable id' => ['item.tonic'],
    'display name' => ['Tonic'],
    'alias' => ['Old Tonic'],
    'any case, padded' => ['  old tonic '],
]);

it('reports item loot the item store cannot resolve', function () {
    $missing = checkChestReward(['lootType' => 'item', 'loot' => 'item.absent']);
    $ambiguous = checkChestReward(['lootType' => 'item', 'loot' => 'Charm']);

    expect($missing)->toHaveCount(1)
        ->and($missing[0]->severity)->toBe(Severity::ERROR)
        ->and($missing[0]->message)->toBe('It gives the item "item.absent", which does not exist, so it fails when opened.')
        ->and($ambiguous)->toHaveCount(1)
        ->and($ambiguous[0]->message)->toContain('which more than one definition answers to');
});

it('reads a chest with no loot type as holding an item, as the Engine does', function () {
    expect(checkChestReward(['loot' => 'Tonic']))->toBe([])
        ->and(checkChestReward(['loot' => 'item.absent']))->toHaveCount(1);
});

it('accepts gold whose amount comes from its quantity when the loot is empty', function (array $data) {
    expect(checkChestReward($data))->toBe([]);
})->with([
    'null loot' => [['lootType' => 'gold', 'loot' => null, 'quantity' => 30]],
    'blank loot' => [['lootType' => 'gold', 'loot' => '', 'quantity' => 30]],
    'no loot' => [['lootType' => 'gold', 'quantity' => 30]],
    'amount as loot' => [['lootType' => 'gold', 'loot' => 50]],
    'amount as text' => [['lootType' => 'gold', 'loot' => '50']],
]);

it('reports gold that gives nothing or takes money', function (array $data, string $message) {
    $issues = checkChestReward($data);

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Severity::ERROR)
        ->and($issues[0]->message)->toBe($message);
})->with([
    'no amount' => [['lootType' => 'gold', 'loot' => null, 'quantity' => 0], 'It gives 0 gold, so opening it gives nothing.'],
    'negative' => [['lootType' => 'gold', 'loot' => -5], 'It gives -5 gold, which opening it takes from the party.'],
    'not a number' => [['lootType' => 'gold', 'loot' => 'lots'], 'It gives gold "lots", which is not an amount.'],
]);

it('treats a loot type case and its string value as the same declaration', function (LootType $type, array $data) {
    $asCase = checkChestReward(['lootType' => $type, ...$data]);
    $asString = checkChestReward(['lootType' => $type->value, ...$data]);

    expect(array_map(static fn(Issue $issue): string => $issue->message, $asCase))
        ->toBe(array_map(static fn(Issue $issue): string => $issue->message, $asString));
})->with([
    'gold' => [LootType::GOLD, ['loot' => null, 'quantity' => 10]],
    'item' => [LootType::ITEM, ['loot' => 'Tonic']],
    'blank item' => [LootType::ITEM, ['loot' => '']],
    'skill' => [LootType::SKILL, ['loot' => 'Twin Cut']],
]);

it('reports a loot type the Engine\'s chest cannot give', function (string $type, string $kind) {
    $issues = checkChestReward(['lootType' => $type, 'loot' => 'Twin Cut', 'quantity' => 1]);

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Severity::ERROR)
        ->and($issues[0]->message)->toBe(sprintf('It gives %s, which the Engine\'s chests cannot give, so it fails when opened.', $kind));
})->with([
    'skill' => ['skill', 'a skill'],
    'spell' => ['spell', 'a spell'],
    'experience' => ['experience', 'experience'],
]);

it('reports a loot type the Engine does not know, exactly as the Engine spells it', function (string $type) {
    $issues = checkChestReward(['lootType' => $type, 'loot' => 'Tonic']);

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->message)->toBe(sprintf('Its loot type "%s" is not one the Engine knows, so the game reads it as an item.', $type));
})->with([
    'unknown' => ['treasure'],
    // LootType::tryFrom() is case-sensitive: the Engine reads this as an item.
    'miscased' => ['Gold'],
]);

it('reports a quantity that gives nothing or cannot be read', function () {
    expect(checkChestReward(['lootType' => 'item', 'loot' => 'Tonic', 'quantity' => 0])[0]->message ?? null)
        ->toBe('Its quantity is 0, so opening it gives nothing.')
        ->and(checkChestReward(['lootType' => 'item', 'loot' => 'Tonic', 'quantity' => 'many'])[0]->message ?? null)
        ->toBe('Its quantity "many" is not a whole number, so the game cannot read the chest.')
        ->and(checkChestReward(['lootType' => 'item', 'loot' => 'Tonic', 'quantity' => '3']))->toBe([]);
});

it('offers only the loot types the Engine\'s chest can give', function () {
    $offered = array_column(MapInspector::listLootTypeChoices(), 'value');

    expect($offered)->toBe(['item', 'gold', 'weapon', 'armor', 'accessory']);

    foreach (LootType::cases() as $type) {
        // The Engine answers which kinds its chest gives; the Editor asks it rather than keeping its own list.
        expect(in_array($type->value, $offered, true))->toBe(ChestOpeningAction::supportsLootType($type))
            ->and(ChestLoot::isGrantable($type))->toBe(ChestOpeningAction::supportsLootType($type));
    }
});

it('reports every chest in the project report and leaves other events alone', function () {
    $root = chestRewardProject();
    $chest = 'Ichiloto\Engine\Events\Triggers\ChestEventTrigger';
    writeChestRewardMap($root, [
        'A' => ['class' => $chest, 'data' => ['lootType' => 'item', 'loot' => '', 'quantity' => 1]],
        'B' => ['class' => $chest, 'data' => ['lootType' => 'skill', 'loot' => 'Twin Cut', 'quantity' => 1]],
        'C' => ['class' => $chest, 'data' => ['lootType' => 'gold', 'loot' => null, 'quantity' => 25]],
        'D' => ['class' => $chest, 'data' => ['lootType' => 'item', 'loot' => 'Old Tonic']],
        'E' => ['class' => $chest],
        // Not a chest: what it calls loot means nothing to the Engine.
        'F' => ['class' => 'Ichiloto\Engine\Events\Triggers\DialogueEventTrigger', 'data' => ['loot' => '', 'dialogue' => []]],
    ]);

    $where = array_map(static fn(Issue $issue): string => $issue->where, listChestRewardIssues($root));

    expect($where)->toBe(['test-map event A', 'test-map event B', 'test-map event E']);
});

it('diagnoses a new chest on its inspector row and as a save error, and still saves it as authored', function () {
    $root = chestRewardProject();
    $session = Ichiloto\Editor\Session\EditorSession::open($root);
    $map = $session->readMap('test-map');
    $layer = array_find($map['layers'], static fn(array $layer): bool => $layer['event']);
    $cell = null;
    foreach ($layer['rows'] as $y => $row) {
        $x = array_find_key($row, static fn(string $symbol): bool => trim($symbol) === '');
        if ($x !== null) {
            $cell = [$x, $y];
            break;
        }
    }
    $created = $session->createEvent('test-map', $map['revision'], [$cell], 'Chest');
    $marker = $created['marker'];
    $rows = $session->readInspector('test-map', $marker)['rows'];
    $labels = array_map(static fn(array $row): string => trim($row['label']), $rows);
    $error = array_find($rows, static fn(array $row): bool => trim($row['label']) === 'Reward Error');

    // A new chest starts visibly incomplete: no reward is invented for it.
    expect($error)->toMatchArray([
        'kind' => 'info',
        'tone' => 'error',
        'value' => 'It has no item chosen, so it fails when opened. Choose the item from the picker. The chest saves as it is until then.',
    ])
        // Under the rows it is about.
        ->and(array_search('Reward Error', $labels, true))->toBeGreaterThan(array_search('Loot', $labels, true))
        // Only kinds the Engine's chest can give are offered.
        ->and(array_find($rows, static fn(array $row): bool => trim($row['label']) === 'Loot Type')['options'])
            ->toBe(['item', 'gold', 'weapon', 'armor', 'accessory']);

    $saved = $session->saveMap('test-map');

    expect($saved['saved'])->toBe('test-map')
        ->and(array_column($saved['errors'], 'message'))->toContain(sprintf('Chest %s: It has no item chosen, so it fails when opened.', $marker))
        ->and((require $root . '/assets/Maps/test-map/test-map.data.php')['events'][$marker]['data']['loot'])->toBe('');

    $loot = array_find($session->readInspector('test-map', $marker)['rows'], static fn(array $row): bool => trim($row['label']) === 'Loot');
    $session->applyInspector('test-map', $session->readMap('test-map')['revision'], $loot['key'], 'item.tonic');

    expect(array_column($session->readInspector('test-map', $marker)['rows'], 'label'))->not->toContain('Reward Error')
        ->and(array_filter(array_column($session->saveMap('test-map')['errors'], 'message'),
            static fn(string $error): bool => str_starts_with($error, sprintf('Chest %s:', $marker))))->toBe([]);
});
