<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\InventoryCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Editor\Validation\SaveCompatibilityValidator;
use Ichiloto\Editor\Validation\Severity;

/**
 * Writes an items.php whose entries are the given PHP constructor snippets.
 *
 * @param string $root The project root.
 * @param string[] $entries The constructor expressions.
 * @return void
 */
function writeInventorySource(string $root, array $entries): void
{
    file_put_contents(
        $root . '/assets/Data/items.php',
        "<?php\n\nreturn [\n" . implode(",\n", array_map(static fn(string $e): string => '  ' . $e, $entries)) . ",\n];\n",
    );
}

/**
 * An Item constructor expression with named arguments.
 *
 * @param array<string, string> $arguments The named arguments, already as PHP.
 * @return string The expression.
 */
function itemExpression(array $arguments): string
{
    $parts = [];

    foreach ($arguments as $name => $value) {
        $parts[] = sprintf('%s: %s', $name, $value);
    }

    return 'new \Ichiloto\Engine\Entities\Inventory\Items\Item(' . implode(', ', $parts) . ')';
}

/**
 * Returns the real game project root, with its own class autoloader
 * registered, or null when this checkout has no game beside it.
 *
 * The project is read, never written: its map files construct project-owned
 * classes, so the namespace has to resolve before the workspace can load.
 */
function readOnlyGameProject(): ?string
{
    $root = gameSourceRoot();

    if ($root === null) {
        return null;
    }

    static $registered = false;

    if (! $registered) {
        $registered = true;
        spl_autoload_register(static function (string $class) use ($root): void {
            $prefix = 'Ichiloto\\FinalQuest\\';

            if (! str_starts_with($class, $prefix)) {
                return;
            }

            $path = $root . '/assets/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($path)) {
                require $path;
            }
        });
    }

    return $root;
}

/**
 * Loads a project's inventory catalogue.
 */
function inventoryCatalog(string $root): InventoryCatalog
{
    return InventoryCatalog::fromWorkspace(ProjectWorkspace::fromProject($root));
}

/**
 * Returns validation issue lines of one severity, or all of them.
 *
 * @return string[]
 */
function issueLines(string $root, ?Severity $severity = null): array
{
    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));

    return array_values(array_map(
        static fn($issue): string => $issue->where . ': ' . $issue->message,
        array_filter($issues, static fn($issue): bool => $severity === null || $issue->severity === $severity),
    ));
}

/**
 * Writes a map's data file.
 *
 * @param string $path The data file path.
 * @param array<string, mixed> $data The map data.
 * @return void
 */
function writeMapData(string $path, array $data): void
{
    file_put_contents($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");
}

/**
 * Writes a save-compatibility manifest.
 *
 * @param array<string, mixed> $manifest The manifest.
 * @return void
 */
function writeCompatibilityManifest(string $root, array $manifest): void
{
    file_put_contents(
        $root . '/assets/Data/save-compatibility.php',
        "<?php\n\nreturn " . var_export($manifest, true) . ";\n",
    );
}

// -- The one resolver ------------------------------------------------------

it('resolves a stable id, a display name, and a declared alias to one definition', function () {
    $root = makeTemporaryProject('ichiloto-inventory-');
    writeInventorySource($root, [
        itemExpression([
            'id' => "'item.s-potion'",
            'name' => "'S-Potion'",
            'description' => "'Restores 50 HP.'",
            'icon' => "'🧪'",
            'price' => '50',
            'aliases' => "['Old Potion Name']",
        ]),
    ]);

    $catalog = inventoryCatalog($root);

    // All three references are the same definition, case-insensitively, and
    // the id is what gets stored.
    expect($catalog->definitionIdFor('item.s-potion'))->toBe('item.s-potion')
        ->and($catalog->definitionIdFor('S-Potion'))->toBe('item.s-potion')
        ->and($catalog->definitionIdFor('s-potion'))->toBe('item.s-potion')
        ->and($catalog->definitionIdFor('item.absent'))->toBeNull()
        ->and($catalog->definitionIdFor('  ITEM.S-POTION  '))->toBe('item.s-potion')
        ->and($catalog->definitionIdFor('old potion NAME'))->toBe('item.s-potion')
        ->and($catalog->displayNameFor('item.s-potion'))->toBe('S-Potion')
        ->and($catalog->displayNameFor('Old Potion Name'))->toBe('S-Potion')
        ->and($catalog->ids())->toBe(['item.s-potion'])
        ->and($catalog->describe('Old Potion Name'))->toBe('S-Potion (item.s-potion)');
});

it('agrees with the engine store about every reference in the real catalogue', function () {
    $game = readOnlyGameProject();

    if ($game === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    $catalog = inventoryCatalog($game);
    // The store reads its catalogue relative to the working directory, the
    // same way the running game does.
    $store = \Ichiloto\Editor\ProjectDirectoryContext::run(
        $game,
        static fn(): \Ichiloto\Engine\Util\Stores\ItemStore => new \Ichiloto\Engine\Util\Stores\ItemStore(),
    );

    expect($catalog->ids())->not->toBe([]);

    foreach ($catalog->definitions() as $id => $definition) {
        // The tooling resolver and the runtime store answer identically for
        // the id, the display name, and every declared alias.
        foreach ([$id, $definition['name'], ...$definition['aliases']] as $reference) {
            expect($catalog->definitionIdFor((string) $reference))->toBe($store->definitionIdFor((string) $reference))
                ->and($catalog->displayNameFor((string) $reference))->toBe($store->displayNameFor((string) $reference));
        }
    }
})->group('engine');

it('fails closed on an ambiguous reference instead of guessing a definition', function () {
    $root = makeTemporaryProject('ichiloto-inventory-');
    writeInventorySource($root, [
        itemExpression(['id' => "'item.one'", 'name' => "'First'", 'description' => "''", 'icon' => "'a'", 'price' => '1', 'aliases' => "['Shared Name']"]),
        itemExpression(['id' => "'item.two'", 'name' => "'Second'", 'description' => "''", 'icon' => "'b'", 'price' => '2', 'aliases' => "['shared name']"]),
    ]);

    $catalog = inventoryCatalog($root);

    expect($catalog->definitionIdFor('Shared Name'))->toBeNull()
        ->and($catalog->isAmbiguous('SHARED NAME'))->toBeTrue()
        ->and($catalog->conflicts())->toHaveKey('shared name')
        ->and($catalog->conflicts()['shared name'])->toBe(['item.one', 'item.two'])
        // Each definition still resolves by its own id.
        ->and($catalog->definitionIdFor('item.one'))->toBe('item.one')
        ->and($catalog->definitionIdFor('item.two'))->toBe('item.two');

    expect(fn() => $catalog->requireDefinitionId('Shared Name', 'stocking a shop'))
        ->toThrow(RuntimeException::class, 'Ambiguous inventory reference "Shared Name" while stocking a shop');
    expect(fn() => $catalog->requireDefinitionId('item.absent', 'stocking a shop'))
        ->toThrow(RuntimeException::class, 'Inventory reference "item.absent" while stocking a shop');
});

it('derives the engine legacy id for a definition authored without one', function () {
    $root = makeTemporaryProject('ichiloto-inventory-');
    writeInventorySource($root, [
        itemExpression(['name' => "'Old Charm'", 'description' => "''", 'icon' => "'c'", 'price' => '3']),
    ]);

    $catalog = inventoryCatalog($root);

    expect($catalog->ids())->toBe(['legacy.old-charm'])
        ->and($catalog->definitionIdFor('Old Charm'))->toBe('legacy.old-charm')
        ->and(InventoryCatalog::definitionId(null, 'Old Charm'))->toBe('legacy.old-charm')
        ->and(InventoryCatalog::definitionId('Item.Mixed-CASE', ''))->toBe('item.mixed-case')
        ->and(InventoryCatalog::definitionId('not a valid id', ''))->toBeNull()
        ->and(InventoryCatalog::isWellFormedId('item.s-potion'))->toBeTrue()
        ->and(InventoryCatalog::isWellFormedId('-leading-dash'))->toBeFalse();
});

// -- The alias targets that were 30 false positives ------------------------

it('accepts an alias target that is a stable definition id', function () {
    $root = makeTemporaryProject('ichiloto-inventory-');
    writeInventorySource($root, [
        itemExpression(['id' => "'item.s-potion'", 'name' => "'S-Potion'", 'description' => "''", 'icon' => "'🧪'", 'price' => '50']),
    ]);
    writeCompatibilityManifest($root, [
        'contentVersion' => 0,
        'migrations' => [],
        'aliases' => ['items' => [['from' => 'Potion', 'to' => 'item.s-potion']]],
        'tombstones' => [],
    ]);

    $issues = new SaveCompatibilityValidator()->validate(ProjectWorkspace::fromProject($root));

    expect(array_map(static fn($issue): string => $issue->message, $issues))->toBe([]);
});

it('still fails an alias target that names nothing in the catalogue', function () {
    $root = makeTemporaryProject('ichiloto-inventory-');
    writeInventorySource($root, [
        itemExpression(['id' => "'item.s-potion'", 'name' => "'S-Potion'", 'description' => "''", 'icon' => "'🧪'", 'price' => '50']),
    ]);
    writeCompatibilityManifest($root, [
        'contentVersion' => 0,
        'migrations' => [],
        'aliases' => [
            'items' => [
                ['from' => 'Potion', 'to' => 'item.absent'],
                // A display name is not identity: aliasing to one is not a
                // stable target either.
                ['from' => 'Old Potion', 'to' => 'S-Potion'],
            ],
        ],
        'tombstones' => [],
    ]);

    $messages = implode("\n", array_map(
        static fn($issue): string => $issue->message,
        new SaveCompatibilityValidator()->validate(ProjectWorkspace::fromProject($root)),
    ));

    expect($messages)->toContain('Alias target "item.absent" is not defined in the current items catalog')
        ->and($messages)->toContain('Alias target "S-Potion" is not defined in the current items catalog');
});

it('validates the real project clean, and its equipment aliases resolve to definitions', function () {
    $game = readOnlyGameProject();

    if ($game === null) {
        $this->markTestSkipped('The game project is not reachable from this checkout.');
    }

    $workspace = ProjectWorkspace::fromProject($game);
    $issues = new SaveCompatibilityValidator()->validate($workspace);
    $catalog = InventoryCatalog::fromWorkspace($workspace);
    $manifest = require $game . '/assets/Data/save-compatibility.php';
    $targets = [];

    foreach (['items', 'equipment'] as $category) {
        foreach ((array) ($manifest['aliases'][$category] ?? []) as $alias) {
            $targets[] = (string) ($alias['to'] ?? '');
        }
    }

    // The 30 declared stable-id targets all resolve, which is why the
    // project validates clean rather than because a rule was dropped.
    expect($targets)->toHaveCount(30)
        ->and(array_map(static fn($issue): string => $issue->where . ': ' . $issue->message, $issues))->toBe([]);

    foreach ($targets as $target) {
        expect($catalog->definitionIdFor($target))->toBe($target);
    }
})->group('engine');

// -- Every consumer, one contract -----------------------------------------

it('offers inventory references as stable ids labelled with their names', function () {
    $root = makeTemporaryProject('ichiloto-inventory-');
    writeInventorySource($root, [
        itemExpression(['id' => "'item.s-potion'", 'name' => "'S-Potion'", 'description' => "''", 'icon' => "'🧪'", 'price' => '50']),
    ]);

    $catalog = new \Ichiloto\Editor\Database\ReferenceCatalog(ProjectWorkspace::fromProject($root));

    expect($catalog->valuesFor('inventory'))->toBe(['item.s-potion'])
        ->and($catalog->valuesFor('items'))->toBe(['item.s-potion'])
        ->and($catalog->labelsFor('inventory'))->toBe(['item.s-potion' => 'S-Potion (item.s-potion)'])
        // Only inventory kinds carry labels; a map id is its own label.
        ->and($catalog->labelsFor('maps'))->toBe([]);
});

it('puts the picker cursor on the definition a field already holds, however it is spelled', function () {
    $picker = new \Ichiloto\Editor\Database\ReferencePicker();
    $values = ['item.s-potion', 'item.antidote'];
    $labels = ['item.s-potion' => 'S-Potion (item.s-potion)', 'item.antidote' => 'Antidote (item.antidote)'];

    // The stored id.
    $picker->open('f', 'Item', 'inventory', $values, 'item.antidote', $labels);
    expect($picker->selected())->toBe('item.antidote');

    // A different case, and the label an author reads.
    $picker->open('f', 'Item', 'inventory', $values, 'ITEM.ANTIDOTE', $labels);
    expect($picker->selected())->toBe('item.antidote');
    $picker->open('f', 'Item', 'inventory', $values, 'Antidote (item.antidote)', $labels);
    expect($picker->selected())->toBe('item.antidote');

    // Rows read as the names; typing narrows on either name or id.
    $picker->open('f', 'Item', 'inventory', $values, '', $labels);
    expect($picker->rows())->toBe(['S-Potion (item.s-potion)', 'Antidote (item.antidote)']);
    $picker->type('anti');
    expect($picker->matches())->toBe(['item.antidote']);
    $picker->backspace();
    $picker->backspace();
    $picker->backspace();
    $picker->backspace();
    $picker->type('i');
    $picker->type('t');
    $picker->type('e');
    $picker->type('m');
    $picker->type('.');
    $picker->type('s');
    expect($picker->matches())->toBe(['item.s-potion']);
});

it('accepts an id, a name or an alias wherever content names an item, and refuses the rest', function () {
    $root = makeTemporaryProject('ichiloto-inventory-');
    writeInventorySource($root, [
        itemExpression(['id' => "'item.s-potion'", 'name' => "'S-Potion'", 'description' => "''", 'icon' => "'🧪'", 'price' => '50', 'aliases' => "['Potion']"]),
        itemExpression(['id' => "'item.twin'", 'name' => "'First Twin'", 'description' => "''", 'icon' => "'a'", 'price' => '1', 'aliases' => "['Shared']"]),
        itemExpression(['id' => "'item.twin-two'", 'name' => "'Second Twin'", 'description' => "''", 'icon' => "'b'", 'price' => '1', 'aliases' => "['shared']"]),
    ]);

    $mapPath = $root . '/assets/Maps/test-map/test-map.data.php';
    $map = require $mapPath;
    // A shop stocking one of each spelling, a chest, and a give_item.
    $map['events']['S'] = [
        'class' => 'Ichiloto\Engine\Events\Triggers\ShopEventTrigger',
        'data' => ['items' => [
            ['item' => 'item.s-potion'],
            ['item' => 'S-Potion'],
            ['item' => 'Potion'],
            ['item' => 'Shared'],
            ['item' => 'item.absent'],
        ]],
    ];
    $map['events']['E']['data'] = ['lootType' => 'item', 'loot' => 'Potion'];
    writeMapData($mapPath, $map);
    file_put_contents($root . '/assets/Events/grant.php', "<?php\n\nreturn " . var_export([
        ['type' => 'give_item', 'item' => 'item.s-potion', 'quantity' => 1],
        ['type' => 'give_item', 'item' => 'Shared', 'quantity' => 1],
    ], true) . ";\n");

    $lines = implode("\n", issueLines($root));

    // Every legal spelling passes, including the chest's alias.
    expect($lines)->not->toContain('"item.s-potion"')
        ->and($lines)->not->toContain('"S-Potion"')
        ->and($lines)->not->toContain('"Potion"')
        // What names nothing, and what names two things, both fail -- and the
        // ambiguous one says so rather than reporting it as missing.
        ->and($lines)->toContain('It names the item "item.absent", which does not exist.')
        ->and($lines)->toContain('It names the item "Shared", which more than one definition answers to.');
});

it('validates what a chest gives out', function () {
    $root = makeTemporaryProject('ichiloto-inventory-');
    writeInventorySource($root, [
        itemExpression(['id' => "'item.s-potion'", 'name' => "'S-Potion'", 'description' => "''", 'icon' => "'🧪'", 'price' => '50']),
    ]);
    $mapPath = $root . '/assets/Maps/test-map/test-map.data.php';
    $map = require $mapPath;
    $map['events']['E']['data'] = ['lootType' => 'item', 'loot' => 'item.absent'];
    $map['events']['G'] = ['class' => 'Ichiloto\Engine\Events\Triggers\ChestEventTrigger', 'data' => ['lootType' => 'gold', 'loot' => 'lots']];
    $map['events']['H'] = ['class' => 'Ichiloto\Engine\Events\Triggers\ChestEventTrigger', 'data' => ['lootType' => 'treasure', 'loot' => 'x']];
    $map['events']['I'] = ['class' => 'Ichiloto\Engine\Events\Triggers\ChestEventTrigger', 'data' => ['lootType' => 'item', 'loot' => null]];
    writeMapData($mapPath, $map);

    $lines = implode("\n", issueLines($root));

    expect($lines)->toContain('It names the item "item.absent", which does not exist.')
        ->and($lines)->toContain('It gives gold "lots", which is not an amount.')
        ->and($lines)->toContain('Its loot type "treasure" is not one the game knows.')
        // An unconfigured chest is unfinished authoring, not a broken
        // reference.
        ->and($lines)->not->toContain('event I');
});

it('keeps a key item\'s quantity through the condition line, both ways', function () {
    $condition = ['type' => 'key_item', 'name' => 'Rusty Key', 'quantity' => 3];
    $line = \Ichiloto\Editor\Database\ConditionCodec::encodeAll([$condition]);

    expect($line)->toBe('key_item:Rusty Key:3')
        ->and(\Ichiloto\Editor\Database\ConditionCodec::decodeAll($line))->toBe([$condition]);
});
