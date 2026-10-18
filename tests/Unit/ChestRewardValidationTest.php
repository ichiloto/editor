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

    return new ChestRewardValidator($catalog)->validate('test-map', 'C', $data);
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

// The rules are the Engine's (MapCompletenessValidator::validateChestReward); these tests pin what the
// Editor adds: its inventory identity, where each finding belongs, and that every finding is an error.

it('reports every chest the Engine cannot grant as an error on that chest', function (array $data) {
    $issues = checkChestReward($data);

    expect($issues)->not->toBeEmpty();
    foreach ($issues as $issue) {
        expect($issue->severity)->toBe(Severity::ERROR)
            ->and($issue->code)->toBe(ChestRewardValidator::CODE)
            ->and($issue->where)->toBe('test-map event C')
            ->and($issue->message)->not->toBe('');
    }
})->with([
    'blank item' => [['lootType' => 'item', 'loot' => '', 'quantity' => 1]],
    'null item' => [['lootType' => 'item', 'loot' => null, 'quantity' => 1]],
    'whitespace item' => [['lootType' => 'item', 'loot' => '   ', 'quantity' => 1]],
    // The Engine reads a chest with no data as an empty item.
    'no data' => [[]],
    'unknown item' => [['lootType' => 'item', 'loot' => 'item.absent']],
    'ambiguous item' => [['lootType' => 'item', 'loot' => 'Charm']],
    'skill' => [['lootType' => 'skill', 'loot' => 'Twin Cut']],
    'spell' => [['lootType' => 'spell', 'loot' => 'Twin Cut']],
    'experience' => [['lootType' => 'experience', 'loot' => 10]],
    'unknown type' => [['lootType' => 'treasure', 'loot' => 'Tonic']],
    // LootType::tryFrom() is case-sensitive, so the Engine does not know this spelling.
    'miscased type' => [['lootType' => 'Gold', 'loot' => 10]],
    'no gold' => [['lootType' => 'gold', 'loot' => null, 'quantity' => 0]],
    'negative gold' => [['lootType' => 'gold', 'loot' => -5]],
    'gold not a number' => [['lootType' => 'gold', 'loot' => 'lots']],
    'no items' => [['lootType' => 'item', 'loot' => 'Tonic', 'quantity' => 0]],
    'quantity not a number' => [['lootType' => 'item', 'loot' => 'Tonic', 'quantity' => 'many']],
]);

it('resolves item loot by the Editor\'s catalogue of the Engine\'s id, name and alias rules', function (string $loot) {
    expect(checkChestReward(['lootType' => 'item', 'loot' => $loot]))->toBe([]);
})->with([
    'stable id' => ['item.tonic'],
    'display name' => ['Tonic'],
    'alias' => ['Old Tonic'],
    'any case, padded' => ['  old tonic '],
]);

it('accepts what the Engine grants, including gold whose amount is its quantity', function (array $data) {
    expect(checkChestReward($data))->toBe([]);
})->with([
    'no loot type' => [['loot' => 'Tonic']],
    'quantity as text' => [['lootType' => 'item', 'loot' => 'Tonic', 'quantity' => '3']],
    'gold, null loot' => [['lootType' => 'gold', 'loot' => null, 'quantity' => 30]],
    'gold, blank loot' => [['lootType' => 'gold', 'loot' => '', 'quantity' => 30]],
    'gold, no loot' => [['lootType' => 'gold', 'quantity' => 30]],
    'gold amount' => [['lootType' => 'gold', 'loot' => 50]],
    'gold amount as text' => [['lootType' => 'gold', 'loot' => '50']],
]);

it('treats a loot type case and its string value as the same declaration', function (LootType $type, array $data) {
    $describe = static fn(array $issues): array => array_map(static fn(Issue $issue): string => $issue->code . ' ' . $issue->message, $issues);

    expect($describe(checkChestReward(['lootType' => $type, ...$data])))
        ->toBe($describe(checkChestReward(['lootType' => $type->value, ...$data])));
})->with([
    'gold' => [LootType::GOLD, ['loot' => null, 'quantity' => 10]],
    'item' => [LootType::ITEM, ['loot' => 'Tonic']],
    'blank item' => [LootType::ITEM, ['loot' => '']],
    'skill' => [LootType::SKILL, ['loot' => 'Twin Cut']],
]);

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
    $malformed = array_values(array_filter(validateProject($root), static fn(Issue $issue): bool => $issue->code === 'event.definition'));

    expect($where)->toBe(['test-map event A', 'test-map event B'])
        // A chest with no data at all is a malformed event, by the Engine's event rule.
        ->and(array_map(static fn(Issue $issue): string => $issue->where, $malformed))->toBe(['test-map event E'])
        ->and($malformed[0]->severity)->toBe(Severity::ERROR);
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
    ])
        ->and($error['value'] ?? '')->not->toBe('')
        // Under the rows it is about.
        ->and(array_search('Reward Error', $labels, true))->toBeGreaterThan(array_search('Loot', $labels, true))
        // Only kinds the Engine's chest can give are offered.
        ->and(array_find($rows, static fn(array $row): bool => trim($row['label']) === 'Loot Type')['options'])
            ->toBe(['item', 'gold', 'weapon', 'armor', 'accessory']);

    $saved = $session->saveMap('test-map');

    expect($saved['saved'])->toBe('test-map')
        ->and(array_filter($saved['errors'], static fn(array $error): bool => $error['where'] === "test-map event {$marker}"
            && $error['code'] === ChestRewardValidator::CODE))->toHaveCount(1)
        ->and((require $root . '/assets/Maps/test-map/test-map.data.php')['events'][$marker]['data']['loot'])->toBe('');

    $loot = array_find($session->readInspector('test-map', $marker)['rows'], static fn(array $row): bool => trim($row['label']) === 'Loot');
    $session->applyInspector('test-map', $session->readMap('test-map')['revision'], $loot['key'], 'item.tonic');

    expect(array_column($session->readInspector('test-map', $marker)['rows'], 'label'))->not->toContain('Reward Error')
        ->and(array_filter($session->saveMap('test-map')['errors'], static fn(array $error): bool => $error['where'] === "test-map event {$marker}"))->toBe([]);
});
