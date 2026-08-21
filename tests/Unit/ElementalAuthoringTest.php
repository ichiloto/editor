<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\AffinityEditor;
use Ichiloto\Editor\Database\ElementAffinityCodec;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Entities\Inventory\Armor;

/**
 * Writes an element enum into a project the way a real one declares it.
 *
 * @param string $root The project root.
 * @return void
 */
function writeElementTypes(string $root): void
{
    if (! is_dir($root . '/assets/Data/Types')) {
        mkdir($root . '/assets/Data/Types', 0o777, true);
    }

    // Overwrites any fixture enum so the expected cases are exactly these.
    file_put_contents($root . '/assets/Data/Types/ElementType.php', <<<'PHP'
    <?php

    enum ElementType: string
    {
      case FIRE = 'Fire';
      case ICE = 'Ice';
      case WATER = 'Water';
    }
    PHP);
}

it('round-trips an affinity map through the one-line form', function () {
    $map = ['Fire' => 0.5, 'Water' => -1.0, 'Ice' => 2.0];
    $line = ElementAffinityCodec::encodeAll($map);

    expect($line)->toBe('Fire: 0.5; Water: -1; Ice: 2')
        ->and(ElementAffinityCodec::decodeAll($line))->toBe($map);
});

it('describes the named effects in words and keeps a custom value', function () {
    expect(ElementAffinityCodec::describe(2.0))->toBe('Weak ×2')
        ->and(ElementAffinityCodec::describe(0.5))->toBe('Resist ×0.5')
        ->and(ElementAffinityCodec::describe(0.0))->toBe('Null ×0')
        ->and(ElementAffinityCodec::describe(-1.0))->toBe('Absorb ×-1')
        // A hand-authored 1.5 is nobody's to round away.
        ->and(ElementAffinityCodec::describe(1.5))->toBe('×1.5')
        ->and(ElementAffinityCodec::decodeAll('Fire: 1.5'))->toBe(['Fire' => 1.5]);
});

it('reads the elements a project declares', function () {
    $root = makeTemporaryProject();
    writeElementTypes($root);

    $catalog = new ReferenceCatalog(ProjectWorkspace::fromProject($root));

    expect($catalog->valuesFor('elements'))->toBe(['Fire', 'Ice', 'Water']);
});

it('builds a ward list by picking and cycling, never typing', function () {
    $editor = new AffinityEditor();
    $editor->open('elementAffinities', 'Elemental Wards', [], ['Fire', 'Ice', 'Water']);

    $editor->add();

    // A new row starts on the first unnamed element at Resist, which is the
    // most common thing a ward is.
    expect($editor->describeRows())->toBe(['Fire — Resist ×0.5']);

    $editor->cycleEffect(1);
    $editor->cycleEffect(1);

    expect($editor->describeRows())->toBe(['Fire — Absorb ×-1']);

    $editor->add();

    // The second row starts on Ice rather than shadowing Fire.
    expect($editor->affinities())->toBe(['Fire' => -1.0, 'Ice' => 0.5]);

    $editor->setElement('Water');
    $editor->remove();

    expect($editor->encoded())->toBe('Fire: -1');
});

it('authors an armor ward end to end, into the engine object', function () {
    $root = makeTemporaryProject();
    writeElementTypes($root);

    $database = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('armors'));
    $index = $database->addRecord();
    $database->setField($index, 'name', 'Flame Ward');
    $database->setField($index, 'elementAffinities', 'Fire: 0.5; Ice: 2');
    $database->save();

    $reloaded = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('armors'));
    $labels = $reloaded->getEntryLabels();
    $armor = $reloaded->getRecords()[array_search('Flame Ward', $labels, true)]->toArray();

    expect($armor)->toBeInstanceOf(Armor::class)
        ->and($armor->elementAffinities)->toBe(['Fire' => 0.5, 'Ice' => 2.0])
        // The engine's own lookup, on the object the editor wrote.
        ->and($armor->getElementMultiplier('Fire'))->toBe(0.5);
});

it('gives a weapon an attack element and takes it away again', function () {
    $root = makeTemporaryProject();
    writeElementTypes($root);

    $database = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('weapons'));
    $index = $database->addRecord();
    $database->setField($index, 'element', 'Fire');

    expect($database->getRecords()[$index]->toArray()->element)->toBe('Fire');

    // '(None)' is what the picker's clearing entry commits: an optional
    // reference must have a way back to nothing.
    $database->setField($index, 'element', '(None)');

    expect($database->getRecords()[$index]->toArray()->element)->toBeNull();
});

it('declares the element fields as picked, cycled, or row-built', function () {
    $fields = [];

    foreach (['weapons', 'armors', 'enemies'] as $category) {
        foreach (RecordSchemaCatalog::forKey($category)->fields as $field) {
            $fields[$category][$field->key] = $field;
        }
    }

    expect($fields['weapons']['element']->reference)->toBe('elements')
        ->and($fields['weapons']['element']->allowsNone)->toBeTrue()
        ->and($fields['weapons']['elementAffinities']->codec->value)->toBe('affinities')
        ->and($fields['armors']['elementAffinities']->codec->value)->toBe('affinities')
        // Enemy affinities were browse-only; they are the same map, so they
        // get the same editor.
        ->and($fields['enemies']['elementAffinities']->isReadOnly)->toBeFalse()
        ->and($fields['enemies']['elementAffinities']->codec->value)->toBe('affinities');
});
