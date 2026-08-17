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
    $root = dirname(__DIR__, 3) . '/examples/last-legend';

    if (! is_file($root . '/assets/Data/items.php')) {
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
