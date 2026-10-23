<?php

declare(strict_types=1);

use Ichiloto\Editor\Field\MapEncounters;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * Encounter troops limited to the encounter terrains of a map's terrain
 * kinds: authored per row, typed as the terrains the map's kinds meet, and
 * validated against them. Synthetic fixtures only ({@see terrainKindProject()}).
 */

/** The kind project painted in kinds, with one troop of the grove and one met anywhere. */
function createEncounterTerrainProject(array $troops = ['Sprites' => ['weight' => 2, 'terrains' => ['grove']], 'Wolves' => 1]): array
{
    $root = terrainKindProject();
    $file = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($file, str_replace("'tileset' => 'home',", "'tileset' => 'home', 'terrainKinds' => true, 'encounters' => "
        . var_export(['troops' => $troops, 'rate' => 12], true) . ',', (string) file_get_contents($file)));

    return [$file, EditorSession::open($root)];
}

it('reads and writes the terrains a troop is met on, keeping the rest of its entry', function () {
    $encounters = MapEncounters::of(['troops' => ['Sprites' => ['weight' => 2, 'terrains' => ['grove'], 'note' => 'kept'], 'Wolves' => 3]]);

    expect(array_column($encounters->rows(), 'terrains'))->toBe([['grove'], []])
        ->and($encounters->withTerrainsAt(1, ['meadow', 'meadow'])['troops']['Wolves'])->toBe(['weight' => 3, 'terrains' => ['meadow']])
        ->and($encounters->withTerrainsAt(0, [])['troops']['Sprites'])->toBe(['weight' => 2, 'note' => 'kept'])
        ->and(MapEncounters::of(['troops' => ['Sprites' => ['weight' => 2, 'terrains' => 'grove']]])->unsupportedReason())
        ->toContain('the troop "Sprites" has terrains');
});

it('edits a troop\'s terrains through the inspector, accepting only those the map\'s kinds meet', function () {
    [$file, $session] = createEncounterTerrainProject();
    $terrains = static fn(int $index): array => findLandmarkRow($session, ['target' => 'map-encounters', 'field' => 'terrains', 'index' => $index]);

    expect($terrains(0)['value'])->toBe('grove')->and($terrains(1)['value'])->toBe('(any terrain)');

    applyLandmarkRow($session, ['target' => 'map-encounters', 'field' => 'terrains', 'index' => 1], 'meadow');
    expect(fn() => applyLandmarkRow($session, ['target' => 'map-encounters', 'field' => 'terrains', 'index' => 0], 'grove, swamp'))
        ->toThrow(SessionRefusal::class, "swamp names no encounter terrain of this map's kinds; they are meadow, grove.");
    $session->saveMap('test-map');

    expect((require $file)['encounters']['troops'])->toBe(['Sprites' => ['weight' => 2, 'terrains' => ['grove']],
        'Wolves' => ['weight' => 1, 'terrains' => ['meadow']]]);
});

it('reports a troop limited to a terrain no kind of the map meets, which is never met', function () {
    [, $session] = createEncounterTerrainProject(['Ghosts' => ['weight' => 1, 'terrains' => ['swamp']]]);

    $messages = array_column(array_filter($session->validateProject(['test-map'])['issues'],
        static fn(array $issue): bool => $issue['severity'] === 'error'), 'message');

    expect(implode("\n", $messages))->toContain('The troop "Ghosts" is limited to the encounter terrain "swamp", which no terrain kind of this map meets');
});
