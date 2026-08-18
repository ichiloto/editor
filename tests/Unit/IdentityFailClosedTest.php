<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\InventoryCatalog;
use Ichiloto\Editor\ProjectActorDatabase;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Severity;

/**
 * Identity, when a project has got it wrong.
 *
 * The runtime's item store refuses a whole catalogue that lets two
 * definitions answer to one reference. An editor cannot refuse to open the
 * project -- it is the thing an author fixes it with -- but it must not
 * resolve anything the runtime would not, or an author edits the wrong item
 * and never finds out.
 *
 * An actor's durable identity is the same story from the other end: a save
 * reconstructs an actor by its definition id, so that is what an alias may
 * point at.
 */

/**
 * Writes an inventory where two definitions claim one id.
 *
 * @return string The project root.
 */
function contestedInventoryProject(): string
{
    $root = makeTemporaryProject('ichiloto-identity-');

    file_put_contents($root . '/assets/Data/items.php', <<<'PHP'
    <?php

    use Ichiloto\Engine\Entities\Enumerations\WeaponType;
    use Ichiloto\Engine\Entities\Inventory\Items\Item;
    use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;

    return [
      new Item(id: 'thing.contested', name: 'First Claimant', description: 'x', icon: 'i', price: 1, aliases: ['Old First']),
      new Item(id: 'item.untouched', name: 'Untouched', description: 'x', icon: 'i', price: 1, aliases: ['Old Untouched']),
      // A weapon claiming the same id: the conflict spans categories.
      new Weapon(id: 'thing.contested', name: 'Second Claimant', description: 'x', icon: '/', price: 1, equipmentType: WeaponType::SWORD, aliases: ['Old Second']),
    ];
    PHP);

    return $root;
}

it('resolves nothing belonging to either claimant of a contested id', function () {
    $root = contestedInventoryProject();
    $catalog = InventoryCatalog::fromWorkspace(ProjectWorkspace::fromProject($root));

    // Not the id, and not what either claimant brought with it. The runtime
    // loads none of this, so neither does the editor.
    foreach (['thing.contested', 'First Claimant', 'Old First', 'Second Claimant', 'Old Second'] as $reference) {
        expect($catalog->definitionIdFor($reference))
            ->toBeNull(sprintf('"%s" still resolves.', $reference))
            ->and($catalog->isAmbiguous($reference))
            ->toBeTrue(sprintf('"%s" is not reported as ambiguous.', $reference));
    }

    // A definition that has nothing to do with the conflict is unaffected.
    expect($catalog->definitionIdFor('item.untouched'))->toBe('item.untouched')
        ->and($catalog->definitionIdFor('Untouched'))->toBe('item.untouched')
        ->and($catalog->definitionIdFor('Old Untouched'))->toBe('item.untouched');
})->group('engine');

it('offers nothing contested to a picker or a compatibility target', function () {
    $root = contestedInventoryProject();
    $workspace = ProjectWorkspace::fromProject($root);
    $catalog = InventoryCatalog::fromWorkspace($workspace);
    $references = new \Ichiloto\Editor\Database\ReferenceCatalog($workspace);

    // Selecting a contested id would author a reference this same catalogue
    // refuses to resolve, so it is not offered.
    expect($catalog->ids())->not->toContain('thing.contested')
        ->and($catalog->idsIn('items'))->not->toContain('thing.contested')
        ->and($catalog->idsIn('weapons'))->not->toContain('thing.contested')
        ->and(array_keys($catalog->definitions()))->not->toContain('thing.contested')
        ->and($references->valuesFor('inventory'))->not->toContain('thing.contested')
        ->and($references->valuesFor('items'))->not->toContain('thing.contested')
        ->and($references->valuesFor('weapons'))->not->toContain('thing.contested')
        ->and(array_keys($references->labelsFor('inventory')))->not->toContain('thing.contested')
        // The one definition with no part in the conflict is still offered.
        ->and($references->valuesFor('inventory'))->toContain('item.untouched')
        // And every claimant is still there for a diagnostic to name.
        ->and(array_keys($catalog->allDefinitions()))->toContain('thing.contested');
})->group('engine');

it('refuses a save alias that targets a contested id', function () {
    $root = contestedInventoryProject();
    file_put_contents($root . '/assets/Data/save-compatibility.php', sprintf(
        "<?php\n\nreturn [\n  'contentVersion' => 0,\n  'migrations' => [],\n  'tombstones' => [],\n  'aliases' => ['items' => [['from' => 'Old Thing', 'to' => %s]]],\n];\n",
        var_export('thing.contested', true),
    ));

    $messages = implode("\n", array_map(
        static fn(object $issue): string => $issue->message,
        new \Ichiloto\Editor\Validation\ProjectValidator()->validate(ProjectWorkspace::fromProject($root)),
    ));

    expect($messages)->toContain('Alias target "thing.contested" is not defined in the current items catalog.');
})->group('engine');

it('names every claimant of a contested id, across categories', function () {
    $root = contestedInventoryProject();
    $issues = new \Ichiloto\Editor\Validation\ProjectValidator()
        ->validate(ProjectWorkspace::fromProject($root));
    $errors = implode("\n", array_map(
        static fn($issue): string => $issue->message,
        array_filter($issues, static fn($issue): bool => $issue->severity === Severity::ERROR),
    ));

    expect($errors)->toContain('First Claimant')
        ->toContain('Second Claimant');
})->group('engine');

it('keeps resolving a catalogue that is merely large', function () {
    // The fail-closed rule must cost nothing to a project that is correct.
    $root = makeTemporaryProject('ichiloto-identity-');
    file_put_contents($root . '/assets/Data/items.php', <<<'PHP'
    <?php

    use Ichiloto\Engine\Entities\Inventory\Items\Item;

    return [
      new Item(id: 'item.one', name: 'One', description: 'x', icon: 'i', price: 1, aliases: ['Uno']),
      new Item(id: 'item.two', name: 'Two', description: 'x', icon: 'i', price: 1, aliases: ['Dos']),
    ];
    PHP);

    $catalog = InventoryCatalog::fromWorkspace(ProjectWorkspace::fromProject($root));

    expect($catalog->definitionIdFor('item.one'))->toBe('item.one')
        ->and($catalog->definitionIdFor('One'))->toBe('item.one')
        ->and($catalog->definitionIdFor('Uno'))->toBe('item.one')
        ->and($catalog->definitionIdFor('Dos'))->toBe('item.two')
        ->and($catalog->conflicts())->toBe([]);
})->group('engine');

it('validates an actor alias against the identity a save reconstructs by', function () {
    $root = makeTemporaryProject('ichiloto-identity-');

    // A definition id that differs from the display name is the case the
    // durable identity exists for.
    $path = $root . '/assets/Data/Actors/Kaelion.php';
    $actor = (static fn(): mixed => require $path)();
    $actor['data']['id'] = 'actor.kaelion';
    $actor['data']['name'] = 'Kaelion the Elder';
    file_put_contents(
        $path,
        "<?php\n\nreturn " . var_export(['class' => \Ichiloto\Engine\Entities\Character::class, 'data' => $actor['data']], true) . ";\n",
    );

    expect(ProjectActorDatabase::fromProject($root)->getActors()[0]->getDefinitionId())->toBe('actor.kaelion');

    $compatibility = static function (string $target) use ($root): string {
        file_put_contents($root . '/assets/Data/save-compatibility.php', sprintf(
            "<?php\n\nreturn [\n  'contentVersion' => 0,\n  'migrations' => [],\n  'tombstones' => [],\n  'aliases' => ['actors' => [['from' => 'Kaelion', 'to' => %s]]],\n];\n",
            var_export($target, true),
        ));

        return implode("\n", array_map(
            static fn(object $issue): string => $issue->message,
            new \Ichiloto\Editor\Validation\ProjectValidator()->validate(ProjectWorkspace::fromProject($root)),
        ));
    };

    // The definition id is a target; the display name is not, however
    // familiar it looks.
    expect($compatibility('actor.kaelion'))->not->toContain('Alias target')
        ->and($compatibility('Kaelion the Elder'))
        ->toContain('Alias target "Kaelion the Elder" is not defined in the current actors catalog.');
});
