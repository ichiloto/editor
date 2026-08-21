<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\EquipmentOptimizationPolicy;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Severity;

/**
 * Writes an Optimize policy into a project.
 *
 * @param string $root The project root.
 * @param string $body The policy body, as PHP source.
 * @return string The path written.
 */
function writeOptimizePolicy(string $root, string $body): string
{
    $path = $root . '/' . EquipmentOptimizationPolicy::RELATIVE_PATH;
    file_put_contents($path, "<?php\n\nreturn [\n" . $body . "\n];\n");

    return $path;
}

/**
 * Replaces a project's inventory with equipment a policy can be shown on.
 *
 * @param string $root The project root.
 */
function writeOptimizeInventory(string $root): void
{
    file_put_contents($root . '/assets/Data/items.php', <<<'PHP'
    <?php

    use Ichiloto\Engine\Entities\Enumerations\WeaponType;
    use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
    use Ichiloto\Engine\Entities\ParameterChanges;

    return [
      new Weapon(
        id: 'weapon.heavy-axe',
        name: 'Heavy Axe',
        description: 'Slow, and it does not matter.',
        icon: 'a',
        price: 100,
        equipmentType: WeaponType::AXE,
        parameterChanges: new ParameterChanges(attack: 12, speed: -4),
      ),
      new Weapon(
        id: 'weapon.quick-dagger',
        name: 'Quick Dagger',
        description: 'Barely a weapon, barely a moment.',
        icon: 'd',
        price: 100,
        equipmentType: WeaponType::DAGGER,
        parameterChanges: new ParameterChanges(attack: 4, speed: 6),
        criticalModifier: 15,
      ),
      new Weapon(
        id: 'weapon.heirloom',
        name: 'Heirloom Blade',
        description: 'Not for the taking.',
        icon: 'h',
        price: 100,
        equipmentType: WeaponType::SWORD,
        parameterChanges: new ParameterChanges(attack: 30),
        availability: 'unique',
        specialProperty: ['type' => 'lifesteal', 'amount' => 10],
      ),
    ];
    PHP);
}

/**
 * Opens one Optimize category of a project.
 */
function optimizeDatabase(string $root, string $category): ProjectRecordDatabase
{
    return ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey($category));
}

/**
 * Returns a project's issues as "where: message" lines.
 *
 * @return string[] The lines.
 */
function optimizeIssues(string $root, ?Severity $severity = null): array
{
    $issues = new \Ichiloto\Editor\Validation\ProjectValidator()
        ->validate(ProjectWorkspace::fromProject($root));

    return array_values(array_map(
        static fn($issue): string => $issue->where . ': ' . $issue->message,
        array_filter($issues, static fn($issue): bool => $severity === null || $issue->severity === $severity),
    ));
}

it('reads all four weight scopes as vectors and writes them back nested', function () {
    $root = makeTemporaryProject('ichiloto-optimize-');
    $path = writeOptimizePolicy($root, <<<'PHP'
      'statWeights' => ['attack' => 3, 'speed' => 1],
      'roleStatWeights' => ['vanguard' => ['attack' => 5]],
      'slotStatWeights' => ['weapon' => ['attack' => 4]],
      'roleSlotStatWeights' => ['oracle' => ['weapon' => ['magicAttack' => 6]]],
      'elementOutcomeWeights' => ['defence:*:absorb' => 40],
    PHP);

    $weights = optimizeDatabase($root, 'optimize_weights');

    expect($weights->getEntryLabels())->toBe([
        'Every role and slot',
        'vanguard',
        'weapon',
        'oracle · weapon',
    ])
        ->and($weights->getRecordByIndex(0)?->get('weights.attack'))->toBe(3)
        ->and($weights->getRecordByIndex(3)?->get('weights.magicAttack'))->toBe(6);

    $weights->setField(1, 'weights.attack', '9');
    $weights->save();

    $after = require $path;

    // The edited scope changed and the rest of the policy did not.
    expect($after['roleStatWeights'])->toBe(['vanguard' => ['attack' => 9]])
        ->and($after['statWeights'])->toBe(['attack' => 3, 'speed' => 1])
        ->and($after['slotStatWeights'])->toBe(['weapon' => ['attack' => 4]])
        ->and($after['roleSlotStatWeights'])->toBe(['oracle' => ['weapon' => ['magicAttack' => 6]]])
        ->and($after['elementOutcomeWeights'])->toBe(['defence:*:absorb' => 40]);
});

it('asks a vector only for the parts its scope narrows by', function () {
    $root = makeTemporaryProject('ichiloto-optimize-');
    writeOptimizePolicy($root, <<<'PHP'
      'statWeights' => ['attack' => 3],
      'roleStatWeights' => ['vanguard' => ['attack' => 5]],
      'roleSlotStatWeights' => ['oracle' => ['weapon' => ['magicAttack' => 6]]],
    PHP);

    $weights = optimizeDatabase($root, 'optimize_weights');
    $fieldsAt = static fn(int $index): array => array_column($weights->getSettingsFields($index), 'field');

    // The base vector applies everywhere, so it asks neither question.
    expect($fieldsAt(0))->not->toContain('role')
        ->not->toContain('slot')
        ->and($fieldsAt(1))->toContain('role')
        ->and($fieldsAt(1))->not->toContain('slot')
        ->and($fieldsAt(2))->toContain('role')
        ->and($fieldsAt(2))->toContain('slot');
});

it('composes an outcome name from parts that are picked, and keeps one it cannot', function () {
    $root = makeTemporaryProject('ichiloto-optimize-');
    writeOptimizeInventory($root);
    $path = writeOptimizePolicy($root, <<<'PHP'
      'elementOutcomeWeights' => [
        'offence:fire' => 10,
        'defence:ice:resist' => 20,
        'defence:*:absorb' => 40,
        'something-else' => 5,
      ],
      'specialPropertyWeights' => ['lifesteal' => 25],
    PHP);

    $outcomes = optimizeDatabase($root, 'optimize_outcomes');

    expect($outcomes->getEntryLabels())->toBe([
        'Dealing fire',
        'Resist to ice',
        'Absorb to any element',
        'something-else',
        'lifesteal',
    ]);

    $fieldsAt = static fn(int $index): array => array_column($outcomes->getSettingsFields($index), 'field');

    // Offence asks which element; defence asks which element and what
    // happened to it; a special property asks which property.
    expect($fieldsAt(0))->toBe(['kind', 'element', 'weight'])
        ->and($fieldsAt(1))->toBe(['kind', 'element', 'outcome', 'weight'])
        ->and($fieldsAt(4))->toBe(['kind', 'property', 'weight']);

    $index = $outcomes->addRecord();
    $outcomes->setField($index, 'kind', 'defence');
    $outcomes->setField($index, 'element', '*');
    $outcomes->setField($index, 'outcome', 'weak');
    $outcomes->setField($index, 'weight', '-30');
    $outcomes->save();

    $after = require $path;

    expect($after['elementOutcomeWeights'])->toBe([
        'offence:fire' => 10,
        'defence:ice:resist' => 20,
        'defence:*:absorb' => 40,
        // A name neither shape composes belongs to the project, so it is
        // written back exactly as authored rather than dropped.
        'something-else' => 5,
        'defence:*:weak' => -30,
    ])
        ->and($after['specialPropertyWeights'])->toBe(['lifesteal' => 25]);
});

it('picks each kind of exclusion from its own vocabulary', function () {
    $root = makeTemporaryProject('ichiloto-optimize-');
    writeOptimizeInventory($root);
    $path = writeOptimizePolicy($root, <<<'PHP'
      'excludedDefinitionIds' => ['weapon.heirloom'],
      'excludedAvailabilities' => ['unique'],
    PHP);

    $exclusions = optimizeDatabase($root, 'optimize_exclusions');

    expect($exclusions->getEntryLabels())->toBe([
        'weapon.heirloom · definition',
        'unique · availability',
    ]);

    $references = static function (ProjectRecordDatabase $database, int $index): array {
        foreach ($database->getSettingsFields($index) as $field) {
            if (($field['field'] ?? null) === 'value') {
                return [$field['label'], $field['reference'] ?? null];
            }
        }

        return [];
    };

    // What an exclusion is picked from depends on what kind of thing it
    // excludes; neither is typed.
    expect($references($exclusions, 0))->toBe(['Item', 'inventory'])
        ->and($references($exclusions, 1))->toBe(['Availability', 'equipment_availabilities']);

    $index = $exclusions->addRecord();
    $exclusions->setField($index, 'kind', 'acquisition');
    $exclusions->setField($index, 'value', 'quest-only');
    $exclusions->save();

    $after = require $path;

    expect($after['excludedDefinitionIds'])->toBe(['weapon.heirloom'])
        ->and($after['excludedAvailabilities'])->toBe(['unique'])
        ->and($after['excludedAcquisitionPolicies'])->toBe(['quest-only']);
});

it('offers what the project itself says, rather than a vocabulary of its own', function () {
    $root = makeTemporaryProject('ichiloto-optimize-');
    writeOptimizeInventory($root);

    $references = new \Ichiloto\Editor\Database\ReferenceCatalog(ProjectWorkspace::fromProject($root));

    expect($references->valuesFor('equipment_availabilities'))->toBe(['ordinary', 'unique'])
        ->and($references->valuesFor('equipment_special_properties'))->toBe(['lifesteal'])
        ->and($references->valuesFor('elements_or_any'))->toContain('*')
        ->and($references->labelsFor('elements_or_any')['*'])->toContain('whichever element');
});

it('scores through the engine and shows the components it returns', function () {
    if (! EquipmentOptimizationPolicy::isAvailable()) {
        $this->markTestSkipped('The engine optimization policy is not reachable from this checkout.');
    }

    $root = makeTemporaryProject('ichiloto-optimize-');
    writeOptimizeInventory($root);
    writeOptimizePolicy($root, <<<'PHP'
      'statWeights' => ['attack' => 1, 'speed' => 5, 'critical' => 2],
      'excludedAvailabilities' => ['unique'],
    PHP);

    $workspace = ProjectWorkspace::fromProject($root);
    $ranked = EquipmentOptimizationPolicy::rank($workspace, $workspace->actorDatabase->getActors()[0], 'weapon');
    $byId = array_column($ranked, null, 'id');

    // Speed weighed five times over turns the quick dagger into the better
    // pick, which is the whole point of declaring weights.
    expect(array_column($ranked, 'id'))->toBe(['weapon.quick-dagger', 'weapon.heavy-axe', 'weapon.heirloom'])
        ->and($byId['weapon.quick-dagger']['value'])->toBe(4 + 30 + 30)
        ->and($byId['weapon.quick-dagger']['components'])->toBe(['attack' => 4, 'speed' => 30, 'critical' => 30])
        ->and($byId['weapon.heavy-axe']['components'])->toBe(['attack' => 12, 'speed' => -20])
        // A candidate the project excludes is not a candidate scoring zero.
        ->and($byId['weapon.heirloom']['excluded'])->toBeTrue()
        ->and(EquipmentOptimizationPolicy::describeRow($byId['weapon.heirloom']))
        ->toBe('excluded from automatic selection by this project')
        ->and(EquipmentOptimizationPolicy::describeRow($byId['weapon.heavy-axe']))
        ->toBe('attack +12, speed -20');
});

it('names the legacy fallback as a fallback rather than as the project\'s policy', function () {
    if (! EquipmentOptimizationPolicy::isAvailable()) {
        $this->markTestSkipped('The engine optimization policy is not reachable from this checkout.');
    }

    $root = makeTemporaryProject('ichiloto-optimize-');
    writeOptimizeInventory($root);

    expect(EquipmentOptimizationPolicy::isDeclaredIn($root))->toBeFalse()
        ->and(EquipmentOptimizationPolicy::describeSource($root))
        ->toContain('legacy equal weight')
        ->toContain('compatibility fallback')
        ->and(EquipmentOptimizationPolicy::enginePolicyFor($root))
        ->toBeInstanceOf(\Ichiloto\Engine\Entities\EquipmentOptimization\LegacyEqualWeightEquipmentOptimizationPolicy::class);

    $workspace = ProjectWorkspace::fromProject($root);
    $ranked = EquipmentOptimizationPolicy::rank($workspace, $workspace->actorDatabase->getActors()[0], 'weapon');

    // Without a declared policy every score is the engine's compatibility
    // sum, and it says so in the component the engine itself names.
    expect(array_keys($ranked[0]['components']))->toBe(['legacyEqualWeight']);

    writeOptimizePolicy($root, "  'statWeights' => ['attack' => 1],");

    expect(EquipmentOptimizationPolicy::describeSource($root))->toContain('declared by this project')
        ->and(EquipmentOptimizationPolicy::enginePolicyFor($root))
        ->toBeInstanceOf(\Ichiloto\Engine\Entities\EquipmentOptimization\DeclaredEquipmentOptimizationPolicy::class);
});

it('shows the preview in the Inspector without writing anything', function () {
    if (! EquipmentOptimizationPolicy::isAvailable()) {
        $this->markTestSkipped('The engine optimization policy is not reachable from this checkout.');
    }

    $root = makeTemporaryProject('ichiloto-optimize-');
    writeOptimizeInventory($root);
    $before = sourceHashTree($root);

    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 140, 'height' => 40]);
    callEditorMethod($editor, 'openDatabaseAtCategory', 'actors');

    $rows = static fn(Editor $editor): array => callEditorMethod($editor, 'getDatabaseActorSettingsFields');
    $valueOf = static function (Editor $editor, string $label) use ($rows): string {
        foreach ($rows($editor) as $field) {
            if (($field['label'] ?? null) === $label) {
                return strval($field['value'] ?? '');
            }
        }

        return '';
    };

    expect(array_column($rows($editor), 'label'))->toContain('Optimize Preview')
        ->and($valueOf($editor, '  Policy'))->toContain('compatibility fallback')
        ->and($valueOf($editor, '  Slot'))->toBe('weapon')
        ->and($valueOf($editor, '  1. Heirloom Blade'))->toContain('legacyEqualWeight');

    // Nothing this project has is worn on the head, and the preview says so
    // rather than showing an empty list.
    callEditorMethod($editor, 'applyDatabaseFieldValue', '__actor_optimize_slot', 'head');

    expect($valueOf($editor, '  (nothing)'))->toContain('no equipment this project has fits that slot')
        ->and(sourceHashTree($root))->toBe($before);

    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    expect($workspace->actorDatabase->isDirty())->toBeFalse();
});

it('reports a policy that names what the project does not have', function () {
    $root = makeTemporaryProject('ichiloto-optimize-');
    writeOptimizeInventory($root);
    writeOptimizePolicy($root, <<<'PHP'
      'statWeights' => ['attack' => 2],
      'roleStatWeights' => ['necromancer' => ['attack' => 1]],
      'slotStatWeights' => ['backpack' => ['attack' => 1]],
      'roleSlotStatWeights' => ['vanguard' => ['pocket' => ['attack' => 1]]],
      'elementOutcomeWeights' => ['defence:fire:melted' => 1, 'nonsense' => 2],
      'specialPropertyWeights' => ['telepathy' => 5],
      'excludedDefinitionIds' => ['weapon.nothing', ''],
      'excludedAvailabilities' => ['unique'],
      'unknownKey' => true,
    PHP);

    $warnings = implode("\n", optimizeIssues($root, Severity::WARNING));

    expect($warnings)->toContain('roleStatWeights narrows to "necromancer", which this project does not have')
        ->toContain('slotStatWeights narrows to "backpack", which this project does not have')
        ->toContain('roleSlotStatWeights narrows to the slot "pocket"')
        ->toContain('weighs the outcome "melted", which is not one an affinity comes to')
        ->toContain('"nonsense" is not a name the runtime composes')
        ->toContain('No equipment carries the special property "telepathy"')
        ->toContain('"weapon.nothing" is excluded, but this project has no such an item')
        ->toContain('holds an exclusion that names nothing')
        ->toContain('"unknownKey" is not part of the policy the runtime reads')
        // The one exclusion that does name something real is not reported.
        ->not->toContain('"unique" is excluded');
});

it('reports a policy the runtime itself refuses', function () {
    if (! EquipmentOptimizationPolicy::isAvailable()) {
        $this->markTestSkipped('The engine optimization policy is not reachable from this checkout.');
    }

    $root = makeTemporaryProject('ichiloto-optimize-');
    writeOptimizePolicy($root, "  'statWeights' => ['charisma' => 3],");

    expect(implode("\n", optimizeIssues($root, Severity::ERROR)))
        ->toContain('The runtime refuses this policy: Unknown base equipment optimization key: charisma');
});

it('adds nothing to a project that declares no policy', function () {
    $root = makeTemporaryProject('ichiloto-optimize-');

    expect(implode("\n", optimizeIssues($root)))->not->toContain('equipment-optimization.php');
});
