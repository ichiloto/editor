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

// -- Round 3: the full scalar contract, strictly ---------------------------

/**
 * Compares two parameter maps strictly, treating NAN as equal to NAN and
 * telling -0.0 from 0.0 -- which `===` cannot.
 */
function sameParameters(array $expected, array $actual): bool
{
    if (array_keys($expected) !== array_keys($actual)) {
        return false;
    }

    foreach ($expected as $key => $value) {
        $other = $actual[$key];

        if (is_float($value) && is_nan($value)) {
            if (! is_float($other) || ! is_nan($other)) {
                return false;
            }

            continue;
        }

        if (is_float($value) && $value === 0.0) {
            // Same sign of zero, not just the same magnitude.
            if (! is_float($other) || $other !== 0.0 || (fdiv(1, $value) !== fdiv(1, $other))) {
                return false;
            }

            continue;
        }

        if ($value !== $other) {
            return false;
        }
    }

    return true;
}

it('round-trips every scalar type exactly, including the ones that used to change type', function (string $label, mixed $value) {
    // The float 1.0 came back the integer 1; 1.0E+20 came back the string
    // "1.0E+20"; -0.0 came back 0; a quoted key lost its spaces.
    $original = ['parameter' => $value];
    $line = ParameterMapCodec::encode($original);
    $decoded = ParameterMapCodec::decode($line);

    expect(sameParameters($original, $decoded))->toBeTrue(sprintf(
        '%s did not survive "%s": got %s.',
        $label,
        $line,
        var_export($decoded['parameter'] ?? null, true),
    ));

    // And it is the same type, not merely the same spelling.
    expect(gettype($decoded['parameter']))->toBe(gettype($value), $label);
})->with([
    ['integral float', 1.0],
    ['negative zero', -0.0],
    ['positive zero float', 0.0],
    ['decimal float', 0.5],
    ['negative decimal float', -0.25],
    ['large exponent float', 1.0E+20],
    ['small exponent float', 5.0E-7],
    ['huge float', 1.7976931348623157E+308],
    ['tiny float', 5.0E-324],
    ['float with every digit', 0.1 + 0.2],
    ['fifteen zeros as a float', 1.0E+15],
    ['positive infinity', INF],
    ['negative infinity', -INF],
    ['not a number', NAN],
    ['string that spells an exponent', '1e5'],
    ['string that spells a float', '1.0'],
    ['string that spells a big float', '1.0E+20'],
    ['string that spells negative zero', '-0.0'],
    ['string that spells infinity', 'INF'],
    ['string that spells NAN', 'NAN'],
    ['string that spells minus infinity', '-INF'],
    ['string true', 'true'],
    ['string false', 'false'],
    ['boolean true', true],
    ['boolean false', false],
    ['integer', 42],
    ['negative integer', -7],
    ['zero', 0],
    ['leading-zero identifier', '007'],
    ['negative-zero-looking identifier', '-0'],
    ['empty string', ''],
    ['single space', ' '],
    ['padded string', '  spaced  '],
    ['comma in value', 'Blood, Oath'],
    ['equals in value', 'a=b'],
    ['quotes in value', 'say "hi"'],
    ['backslash in value', 'back\\slash'],
    ['backslash before quote', 'x\\"y'],
    ['trailing backslash', 'ends\\'],
    ['only escapes', '\\"\\'],
    ['unicode', '日本語 ☠'],
    ['pictographs', '👨‍👩‍👧‍👦 🗡️'],
    ['combining marks', 'e\u{0301}a\u{0308}'],
]);

it('round-trips keys with the same care as values', function (string $key) {
    $original = [$key => 'v'];
    $line = ParameterMapCodec::encode($original);

    expect(ParameterMapCodec::decode($line))->toBe($original, sprintf('Key %s did not survive "%s".', var_export($key, true), $line));
})->with([
    ' spaced key ',
    'lead ',
    ' trail',
    'with, comma',
    'with=equals',
    'with "quotes"',
    'with\\backslash',
    '日本語',
    'true',
    '1.0',
    'plain',
]);

it('round-trips a whole map of mixed keys and values in one line', function () {
    $original = [
        'label' => 'Blood, Oath',
        ' padded ' => 1.0,
        'rate' => -0.0,
        'big' => 1.0E+20,
        'flag' => 'true',
        'on' => true,
        'id' => '007',
        'note' => 'say "hi" \\ bye',
        'never' => -INF,
    ];
    $line = ParameterMapCodec::encode($original);

    expect(sameParameters($original, ParameterMapCodec::decode($line)))->toBeTrue($line);
});

it('refuses an escape it does not define rather than dropping the backslash', function (string $line, string $complaint) {
    expect(static fn() => ParameterMapCodec::decode($line))
        ->toThrow(\Ichiloto\Editor\Database\ParameterMapSyntaxError::class, $complaint);
})->with([
    ['label="\\q"', 'Unknown escape \\q'],
    ['label="\\n"', 'Unknown escape \\n'],
    ['label="a\\ b"', 'Unknown escape \\ '],
    ['label="\\', 'stray backslash'],
    ['label=a\\b', 'Unexpected backslash'],
    ['label=a"b', 'Unexpected quote'],
    ['label=a=b', 'Unexpected equals sign'],
    ['la"bel=1', 'Unexpected quote'],
    ['la\\bel=1', 'Unexpected backslash'],
]);

it('reads the two escapes it defines, and only those', function () {
    expect(ParameterMapCodec::decode('a="q\\"q", b="s\\\\s", c="both\\\\\\""'))
        ->toBe(['a' => 'q"q', 'b' => 's\\s', 'c' => 'both\\"']);
});

it('leaves the record, its dirt and its source untouched when a line is refused, on both surfaces', function () {
    // Special-property parameters, on a weapon.
    [$root, $path] = projectOwnedProject();
    $sourceBefore = (string) file_get_contents($path);
    $weapons = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('weapons'));
    $before = $weapons->getRecordByIndex(0)?->get('specialProperty');

    foreach (['percent="\\q"', 'percent=1, percent=2', 'label="open', 'percent=a=b', '=1'] as $malformed) {
        expect(static fn() => $weapons->setField(0, 'specialProperty.parameters', $malformed))
            ->toThrow(\Ichiloto\Editor\Database\ParameterMapSyntaxError::class);
    }

    expect($weapons->getRecordByIndex(0)?->get('specialProperty'))->toBe($before)
        ->and($weapons->isDirty())->toBeFalse()
        ->and((string) file_get_contents($path))->toBe($sourceBefore);

    // Permanent-growth metadata, on a grant.
    $growthRoot = makeTemporaryProject('ichiloto-owned-');
    $growthPath = $growthRoot . '/assets/Data/permanent-growth.php';
    file_put_contents($growthPath, <<<'PHP_SOURCE'
    <?php

    return [
      [
        'id' => 'growth.spring',
        'stat' => 'maxHp',
        'amount' => 25,
        'sourceType' => 'landmark',
        'sourceId' => 'spring',
        'metadata' => ['label' => 'The Spring', 'weight' => 1.0, 'tags' => ['a'], 'nested' => ['deep' => ['x' => 1]]],
      ],
    ];
    PHP_SOURCE);
    $growthSource = (string) file_get_contents($growthPath);
    $growth = ProjectRecordDatabase::fromProject($growthRoot, RecordSchemaCatalog::forKey('permanent_growth'));
    $metadataBefore = $growth->getRecordByIndex(0)?->get('metadata');

    expect(static fn() => $growth->setField(0, 'metadata', 'label="\\z"'))
        ->toThrow(\Ichiloto\Editor\Database\ParameterMapSyntaxError::class);

    expect($growth->getRecordByIndex(0)?->get('metadata'))->toBe($metadataBefore)
        ->and($growth->isDirty())->toBeFalse()
        ->and((string) file_get_contents($growthPath))->toBe($growthSource);
})->group('engine');

it('keeps floats floats and hidden shapes intact through both surfaces', function () {
    // Special-property parameters: a weight of 1.0 stays a float on disk,
    // and the nested list nobody could put on the line stays exactly.
    [$root, $path] = projectOwnedProject();
    $weapons = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('weapons'));
    $weapons->setField(0, 'specialProperty.parameters', 'percent=10, capPerHit=40, appliesTo=physical, weight=1.0, big=1.0E+20, tag="1e5", " odd key "=-0.0');
    $weapons->save();

    $parameters = (static fn(): mixed => require $path)()[0]->specialProperty['parameters'];

    expect($parameters['weight'])->toBe(1.0)
        ->and($parameters['big'])->toBe(1.0E+20)
        ->and($parameters['tag'])->toBe('1e5')
        ->and($parameters[' odd key '])->toBe(-0.0)
        ->and(fdiv(1, $parameters[' odd key ']))->toBe(-INF)
        ->and($parameters['tiers'])->toBe(['minor', 'major']);

    // Reopened, the line shows exactly what was written, so a second save
    // is a no-op.
    $reopened = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('weapons'));
    $shown = null;

    foreach ($reopened->getSettingsFields(0) as $field) {
        if (($field['field'] ?? null) === 'specialProperty.parameters') {
            $shown = $field['value'];
        }
    }

    expect($shown)->toBe('percent=10, capPerHit=40, appliesTo=physical, weight=1.0, big=1.0E+20, tag="1e5", " odd key "=-0.0');

    $bytes = (string) file_get_contents($path);
    $reopened->setField(0, 'specialProperty.parameters', (string) $shown);
    expect($reopened->isDirty())->toBeFalse();
    $reopened->save();
    expect((string) file_get_contents($path))->toBe($bytes);

    // Permanent-growth metadata: same grammar, nested maps and lists kept.
    $growthRoot = makeTemporaryProject('ichiloto-owned-');
    $growthPath = $growthRoot . '/assets/Data/permanent-growth.php';
    file_put_contents($growthPath, <<<'PHP_SOURCE'
    <?php

    return [
      [
        'id' => 'growth.spring',
        'stat' => 'maxHp',
        'amount' => 25,
        'sourceType' => 'landmark',
        'sourceId' => 'spring',
        'metadata' => ['label' => 'The Spring', 'tags' => ['a', 'b'], 'nested' => ['deep' => ['x' => 1]]],
      ],
    ];
    PHP_SOURCE);
    $growth = ProjectRecordDatabase::fromProject($growthRoot, RecordSchemaCatalog::forKey('permanent_growth'));
    $growth->setField(0, 'metadata', 'label="Spring, of Vigour", ratio=0.5, whole=2.0, flag="false", on=false');
    $growth->save();

    $metadata = (static fn(): mixed => require $growthPath)()[0]['metadata'];

    expect($metadata['label'])->toBe('Spring, of Vigour')
        ->and($metadata['ratio'])->toBe(0.5)
        ->and($metadata['whole'])->toBe(2.0)
        ->and($metadata['flag'])->toBe('false')
        ->and($metadata['on'])->toBeFalse()
        ->and($metadata['tags'])->toBe(['a', 'b'])
        ->and($metadata['nested'])->toBe(['deep' => ['x' => 1]]);
})->group('engine');
