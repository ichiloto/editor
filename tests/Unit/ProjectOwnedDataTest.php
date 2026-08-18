<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ParameterMapCodec;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Severity;

/**
 * The parts of the runtime contract that belong to the project.
 *
 * A special property's parameters and a permanent grant's metadata are both
 * carried by the runtime without being interpreted by it: what goes in them
 * is the project's own vocabulary. An editor that offers only the fields it
 * happens to know makes the rest unauthorable, and one that invents a schema
 * decides what a project is allowed to say. Both are edited as typed pairs,
 * and anything the line cannot carry is preserved rather than lost.
 */

/**
 * Returns a project whose weapon carries a special property.
 *
 * @return array{0: string, 1: string} The project root and items path.
 */
function projectOwnedProject(): array
{
    $root = makeTemporaryProject('ichiloto-owned-');
    $path = $root . '/assets/Data/items.php';

    file_put_contents($path, <<<'PHP'
    <?php

    use Ichiloto\Engine\Entities\Enumerations\WeaponType;
    use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;

    return [
      new Weapon(
        id: 'weapon.leech-blade',
        name: 'Leech Blade',
        description: 'It drinks.',
        icon: '/',
        price: 100,
        equipmentType: WeaponType::SWORD,
        specialProperty: [
          'type' => 'lifesteal',
          'parameters' => [
            'percent' => 10,
            'capPerHit' => 40,
            'appliesTo' => 'physical',
            'tiers' => ['minor', 'major'],
          ],
        ],
      ),
    ];
    PHP);

    return [$root, $path];
}

it('reads typed pairs off a line and writes them back typed', function () {
    expect(ParameterMapCodec::decode('percent=10, rate=0.25, enabled=true, off=false, name=lifesteal, id=007'))
        ->toBe([
            'percent' => 10,
            'rate' => 0.25,
            'enabled' => true,
            'off' => false,
            'name' => 'lifesteal',
            // A leading zero is an identifier, not a quantity.
            'id' => '007',
        ]);

    expect(ParameterMapCodec::encode(['percent' => 10, 'enabled' => true, 'tiers' => ['a']]))
        // What the line cannot carry is not shown on it.
        ->toBe('percent=10, enabled=true');
});

it('round-trips every legal scalar a project might write', function (mixed $value) {
    // The naive grammar destroyed these: a comma split one value into two
    // keys, an equals sign was read as a second assignment, surrounding
    // spaces were trimmed away, and the string "true" came back a boolean.
    $original = ['parameter' => $value];
    $line = ParameterMapCodec::encode($original);

    expect(ParameterMapCodec::decode($line))->toBe(
        $original,
        sprintf('%s did not survive "%s".', var_export($value, true), $line),
    );
})->with([
    'Blood, Oath',
    'a=b',
    '  spaced  ',
    'true',
    'false',
    '',
    '007',
    '日本語 ☠',
    'say "hi"',
    'back\\slash',
    'plain',
    42,
    -7,
    0.5,
    -0.25,
    true,
    false,
]);

it('refuses a line it cannot read, rather than repairing it', function (string $line, string $complaint) {
    expect(static fn() => ParameterMapCodec::decode($line))
        ->toThrow(\Ichiloto\Editor\Database\ParameterMapSyntaxError::class, $complaint);
})->with([
    ['percent', 'has no value'],
    ['=10', 'has no name'],
    ['percent=1, percent=2', 'named twice'],
    ['label="unclosed', 'never closed'],
    ['label="ok" trailing', 'Unexpected'],
]);

it('leaves the record untouched when the line is refused', function () {
    [$root, $path] = projectOwnedProject();
    $weapons = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('weapons'));
    $before = $weapons->getRecordByIndex(0)?->get('specialProperty');

    expect(static fn() => $weapons->setField(0, 'specialProperty.parameters', 'percent=1, percent=2'))
        ->toThrow(\Ichiloto\Editor\Database\ParameterMapSyntaxError::class);

    expect($weapons->getRecordByIndex(0)?->get('specialProperty'))->toBe($before)
        ->and($weapons->isDirty())->toBeFalse();
})->group('engine');

it('keeps what the line cannot carry', function () {
    expect(ParameterMapCodec::merge(
        ['percent' => 10, 'tiers' => ['minor', 'major']],
        ['percent' => 20, 'capPerHit' => 40],
    ))->toBe(['percent' => 20, 'capPerHit' => 40, 'tiers' => ['minor', 'major']]);
});

it('authors special-property parameters without losing the nested ones', function () {
    [$root, $path] = projectOwnedProject();
    $weapons = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('weapons'));
    $shown = null;

    foreach ($weapons->getSettingsFields(0) as $field) {
        if (($field['field'] ?? null) === 'specialProperty.parameters') {
            $shown = $field['value'];
        }
    }

    expect($shown)->toBe('percent=10, capPerHit=40, appliesTo=physical');

    $weapons->setField(0, 'specialProperty.parameters', 'percent=25, capPerHit=40, appliesTo=magical');
    $weapons->save();

    $property = (static fn(): mixed => require $path)()[0]->specialProperty;

    expect($property['type'])->toBe('lifesteal')
        ->and($property['parameters']['percent'])->toBe(25)
        ->and($property['parameters']['appliesTo'])->toBe('magical')
        // The nested value the line could not show is still exactly there.
        ->and($property['parameters']['tiers'])->toBe(['minor', 'major']);
})->group('engine');

it('reports a special property the runtime carries but nothing can read', function () {
    $root = makeTemporaryProject('ichiloto-owned-');
    file_put_contents($root . '/assets/Data/items.php', <<<'PHP'
    <?php

    use Ichiloto\Engine\Entities\Enumerations\WeaponType;
    use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;

    return [
      new Weapon(
        id: 'weapon.nameless',
        name: 'Nameless Blade',
        description: 'x',
        icon: '/',
        price: 1,
        equipmentType: WeaponType::SWORD,
        specialProperty: ['parameters' => ['percent' => 10]],
      ),
      new Weapon(
        id: 'weapon.malformed',
        name: 'Malformed Blade',
        description: 'x',
        icon: '/',
        price: 1,
        equipmentType: WeaponType::SWORD,
        specialProperty: ['type' => 'lifesteal', 'parameters' => 'quite a lot'],
      ),
    ];
    PHP);

    $issues = new \Ichiloto\Editor\Validation\ProjectValidator()
        ->validate(ProjectWorkspace::fromProject($root));
    $errors = implode("\n", array_map(
        static fn($issue): string => $issue->message,
        array_filter($issues, static fn($issue): bool => $issue->severity === Severity::ERROR),
    ));

    expect($errors)->toContain('The special property on Nameless Blade names no type.')
        ->toContain('The special property parameters on Malformed Blade are not a set of keys and values.');
})->group('engine');

it('authors any metadata a permanent grant carries', function () {
    $root = makeTemporaryProject('ichiloto-owned-');
    $path = $root . '/assets/Data/permanent-growth.php';
    file_put_contents($path, <<<'PHP'
    <?php

    return [
      [
        'id' => 'growth.spring',
        'stat' => 'maxHp',
        'amount' => 25,
        'sourceType' => 'landmark',
        'sourceId' => 'happyville/spring',
        'metadata' => [
          'label' => 'The Spring',
          'chapter' => 4,
          'tags' => ['optional', 'missable'],
        ],
      ],
    ];
    PHP);

    $growth = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('permanent_growth'));

    $shown = null;

    foreach ($growth->getSettingsFields(0) as $field) {
        if (($field['field'] ?? null) === 'metadata') {
            $shown = $field['value'];
        }
    }

    expect($shown)->toBe('label=The Spring, chapter=4');

    // Author a key the editor has no field of its own for.
    $growth->setField(0, 'metadata', 'label=The Spring, chapter=5, requiresPetition=true');
    $growth->save();

    $metadata = (static fn(): mixed => require $path)()[0]['metadata'];

    expect($metadata['chapter'])->toBe(5)
        ->and($metadata['requiresPetition'])->toBeTrue()
        ->and($metadata['label'])->toBe('The Spring')
        // The list nobody could put on the line is still the author's.
        ->and($metadata['tags'])->toBe(['optional', 'missable']);

    // And the engine still builds the definition from it.
    if (class_exists(\Ichiloto\Engine\Entities\Stats\PermanentStatModifier::class)) {
        $modifier = \Ichiloto\Engine\Entities\Stats\PermanentStatModifier::fromArray(
            (static fn(): mixed => require $path)()[0],
        );

        expect($modifier->metadata['requiresPetition'])->toBeTrue()
            ->and($modifier->metadata['tags'])->toBe(['optional', 'missable']);
    }
});

it('reports metadata under a key that is not a name', function () {
    $root = makeTemporaryProject('ichiloto-owned-');
    file_put_contents($root . '/assets/Data/permanent-growth.php', <<<'PHP'
    <?php

    return [
      [
        'id' => 'growth.spring',
        'stat' => 'maxHp',
        'amount' => 25,
        'sourceType' => 'landmark',
        'sourceId' => 'spring',
        'metadata' => ['ok' => 1, 7 => 'nameless'],
      ],
    ];
    PHP);

    $issues = new \Ichiloto\Editor\Validation\ProjectValidator()
        ->validate(ProjectWorkspace::fromProject($root));

    expect(implode("\n", array_map(static fn($issue): string => $issue->message, $issues)))
        ->toContain('has metadata under a key that is not a name');
});
