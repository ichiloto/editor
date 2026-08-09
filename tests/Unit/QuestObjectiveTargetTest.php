<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;

/**
 * Returns the quest settings fields with the given objective type set.
 *
 * @param string $type The objective type to set on the first objective.
 * @return array<string, mixed> The target field descriptor.
 */
function questTargetFieldFor(string $type): array
{
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/quests.php';

    file_put_contents($path, <<<PHP
    <?php

    return [
      [
        'id' => 'errand',
        'name' => 'An Errand',
        'objectives' => [
          ['type' => '{$type}', 'target' => ''],
        ],
      ],
    ];
    PHP);

    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    callEditorMethod($editor, 'openDatabaseAtCategory', 'quests');

    foreach (callEditorMethod($editor, 'getDatabaseSettingsFields') as $field) {
        if (($field['field'] ?? null) === 'objective0Target') {
            return $field;
        }
    }

    return [];
}

it('picks an item for something to collect', function () {
    $field = questTargetFieldFor('collect');

    expect($field['reference'] ?? null)->toBe('items')
        // A reference has no text control, so it cannot be typed into.
        ->and($field)->not->toHaveKey('control');
});

it('picks an enemy for something to defeat', function () {
    expect(questTargetFieldFor('defeat')['reference'] ?? null)->toBe('enemies');
});

it('picks a map for somewhere to reach', function () {
    expect(questTargetFieldFor('reach_map')['reference'] ?? null)->toBe('maps');
});

it('picks an actor for someone to talk to', function () {
    expect(questTargetFieldFor('talk_to')['reference'] ?? null)->toBe('actors');
});

it('leaves a flag as free text', function () {
    $field = questTargetFieldFor('flag');

    // A flag names a switch or story event the world sets, which is authored
    // rather than chosen from a list of records.
    expect($field['reference'] ?? null)->toBeNull()
        ->and($field)->toHaveKey('control');
});
