<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Returns the inspector field for a path within an event's data.
 *
 * @param array<string, mixed> $data The event's data.
 * @param array<int, string> $path The path to look for.
 * @return array<string, mixed> The field descriptor, or an empty array.
 */
function eventDataFieldFor(array $data, array $path): array
{
    $editor = createEditorForTesting(makeTemporaryProject());

    foreach (callEditorMethod($editor, 'buildEventDataFields', 'A', ['data' => $data]) as $field) {
        if (($field['path'] ?? null) === $path) {
            return $field;
        }
    }

    return [];
}

it('picks a track for an event that plays music', function () {
    $field = eventDataFieldFor(['bgm' => 'overworld-music'], ['data', 'bgm']);

    expect($field['reference'] ?? null)->toBe('bgm')
        // No control is what stops it being typed into.
        ->and($field)->not->toHaveKey('control');
});

it('picks a sound for an event that plays one', function () {
    expect(eventDataFieldFor(['sfx' => 'sfx_door'], ['data', 'sfx'])['reference'] ?? null)->toBe('sfx');
});

it('picks from the inventory for a shop\'s stock', function () {
    $data = ['items' => [['item' => 'S-Potion', 'price' => 10]]];
    $field = eventDataFieldFor($data, ['data', 'items', '0', 'item']);

    expect($field['reference'] ?? null)->toBe('inventory')
        ->and($field)->not->toHaveKey('control');
});

it('leaves a shop price as a number to type', function () {
    $data = ['items' => [['item' => 'S-Potion', 'price' => 10]]];
    $field = eventDataFieldFor($data, ['data', 'items', '0', 'price']);

    // Only the reference is constrained; what it costs is the author's.
    expect($field['reference'] ?? null)->toBeNull()
        ->and($field)->toHaveKey('control');
});

it('does not mistake another event\'s item field for shop stock', function () {
    // The leaf name alone would match this. A shop's stock is data.items.N.item.
    $field = eventDataFieldFor(['item' => 'S-Potion'], ['data', 'item']);

    expect($field['reference'] ?? null)->toBeNull()
        ->and($field)->toHaveKey('control');
});

it('offers a shop every kind of thing an item store holds', function () {
    $catalog = new ReferenceCatalog(ProjectWorkspace::fromProject(makeTemporaryProject()));
    $stock = $catalog->valuesFor('inventory');

    // The engine's ItemStore loads all of items.php, so a shop may sell a
    // sword as readily as a potion. Stock is stored by stable id and read
    // by display name.
    expect($stock)->toContain('legacy.s-potion')
        ->and($stock)->toContain('legacy.wooden-sword')
        ->and($catalog->labelsFor('inventory')['legacy.wooden-sword'] ?? null)->toBe('Wooden Sword (legacy.wooden-sword)');
});

it('offers the tracks the project actually has', function () {
    $root = makeTemporaryProject();
    mkdir($root . '/assets/Audio/BGM', 0o777, true);
    touch($root . '/assets/Audio/BGM/town-theme.wav');
    touch($root . '/assets/Audio/BGM/town-theme.mp3');
    touch($root . '/assets/Audio/BGM/battle-theme.wav');

    $tracks = new ReferenceCatalog(ProjectWorkspace::fromProject($root))->valuesFor('bgm');

    // The engine resolves the extension itself, so a track is named once
    // however many encodings of it are on disk.
    expect($tracks)->toBe(['battle-theme', 'town-theme']);
});

it('offers nothing for audio a project has none of', function () {
    $catalog = new ReferenceCatalog(ProjectWorkspace::fromProject(makeTemporaryProject()));

    expect($catalog->valuesFor('sfx'))->toBe([])
        ->and(ReferenceCatalog::knows('sfx'))->toBeTrue();
});

it('picks rather than spells what an event script command names', function () {
    $variants = [];

    foreach (RecordSchemaCatalog::all()['common_events']->subList?->variants ?? [] as $type => $fields) {
        foreach ($fields as $field) {
            $variants[$type][$field->key] = $field->reference;
        }
    }

    // Each of these is handed straight to a store or loader by the
    // interpreter, so a name that matches nothing does nothing.
    expect($variants['give_item']['item'])->toBe('inventory')
        ->and($variants['play_music']['music'])->toBe('bgm')
        ->and($variants['play_sound']['sound'])->toBe('sfx')
        ->and($variants['accept_quest']['id'])->toBe('quests')
        ->and($variants['transfer']['map'])->toBe('maps')
        ->and($variants['start_battle']['troop'])->toBe('troops');
});

it('leaves a switch, a variable and a story event as names to invent', function () {
    $variants = [];

    foreach (RecordSchemaCatalog::all()['common_events']->subList?->variants ?? [] as $type => $fields) {
        foreach ($fields as $field) {
            $variants[$type][$field->key] = $field->reference;
        }
    }

    // Nothing in the project declares these up front; the author names them
    // where they are set and reads them back where they matter.
    expect($variants['set_switch']['name'])->toBeNull()
        ->and($variants['set_variable']['name'])->toBeNull()
        ->and($variants['record_event']['name'])->toBeNull()
        // A speaker may be a passer-by who exists in no database.
        ->and($variants['text']['name'])->toBeNull();
});
