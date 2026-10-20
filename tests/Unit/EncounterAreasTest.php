<?php

declare(strict_types=1);

use Ichiloto\Editor\Field\MapEncounters;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Editor\Validation\Severity;

/**
 * Encounter troops limited to a map's named areas: authored per row, typed
 * as the map's area ids, carried through an area's rename and protecting the
 * area from deletion. Synthetic fixtures only ({@see createLandmarkProject()}).
 */

/** The landmark project with a yard area and an encounter table: one troop in the yard, one met map-wide. */
function createEncounterAreaProject(): array
{
    return createLandmarkProject(<<<'PHP'
'areas' => [['id' => 'yard', 'area' => ['x' => 0, 'y' => 1, 'width' => 2, 'height' => 1]]],
    'encounters' => [
        'troops' => [
            'Yard Rats' => ['weight' => 2, 'areas' => ['yard']],
            'Wanderer' => 1,
        ],
        'rate' => 12,
        'tiles' => 'any',
    ],
PHP);
}

it('reads, writes and renames the areas a troop is met in', function () {
    $encounters = MapEncounters::of(['troops' => ['Rats' => ['weight' => 2, 'areas' => ['yard'], 'note' => 'kept'], 'Bats' => 3]]);

    expect($encounters->isSupported())->toBeTrue()
        ->and(array_column($encounters->rows(), 'areas'))->toBe([['yard'], []])
        ->and($encounters->troopsInArea('yard'))->toBe(['Rats'])
        // A weight alone becomes a map only when it gains areas; clearing them keeps the rest.
        ->and($encounters->withAreasAt(1, ['roost', 'roost'])['troops']['Bats'])->toBe(['weight' => 3, 'areas' => ['roost']])
        ->and($encounters->withAreasAt(0, [])['troops']['Rats'])->toBe(['weight' => 2, 'note' => 'kept'])
        ->and($encounters->withAreaRenamed('yard', 'court')['troops']['Rats'])->toBe(['weight' => 2, 'areas' => ['court'], 'note' => 'kept']);
});

it('refuses areas it cannot hold, saying which troop', function (mixed $areas) {
    $encounters = MapEncounters::of(['troops' => ['Rats' => ['weight' => 2, 'areas' => $areas]]]);

    expect($encounters->isSupported())->toBeFalse()
        ->and($encounters->unsupportedReason())->toContain('the troop "Rats" has areas');
})->with(['text' => ['yard'], 'keyed' => [['a' => 'yard']], 'numbers' => [[1]]]);

it('edits a troop\'s areas through the inspector, accepting only the map\'s areas', function () {
    [, $file, $session] = createEncounterAreaProject();
    $areas = static fn(int $index): array => findLandmarkRow($session, ['target' => 'map-encounters', 'field' => 'areas', 'index' => $index]);

    expect($areas(0)['value'])->toBe('yard')->and($areas(1)['value'])->toBe('(whole map)');

    applyLandmarkRow($session, ['target' => 'map-encounters', 'field' => 'areas', 'index' => 1], 'yard');
    expect(fn() => applyLandmarkRow($session, ['target' => 'map-encounters', 'field' => 'areas', 'index' => 0], 'yard, cellar'))
        ->toThrow(SessionRefusal::class, 'cellar names no area of this map; its areas are yard.');
    applyLandmarkRow($session, ['target' => 'map-encounters', 'field' => 'areas', 'index' => 0], '');
    $session->saveMap('test-map');

    expect((require $file)['encounters']['troops'])->toBe(['Yard Rats' => ['weight' => 2], 'Wanderer' => ['weight' => 1, 'areas' => ['yard']]]);
});

it('renames an area in the troops met there and refuses to delete it while they are', function () {
    [, $file, $session] = createEncounterAreaProject();
    $revision = static fn(): int => $session->readMap('test-map')['revision'];

    expect($session->readAreas('test-map')['areas'][0]['references'])->toBe(['test-map encounter Yard Rats']);

    $session->renameArea('test-map', $revision(), 'yard', 'court');
    expect(fn() => $session->deleteArea('test-map', $revision(), 'court'))->toThrow(SessionRefusal::class, 'test-map encounter Yard Rats');
    $session->saveMap('test-map');

    expect((require $file)['encounters']['troops']['Yard Rats'])->toBe(['weight' => 2, 'areas' => ['court']]);

    // One undo step restores both the area and the troop's scope.
    $session->undo();
    expect(getSessionMap($session)->getMapDataField(['encounters', 'troops', 'Yard Rats', 'areas']))->toBe(['yard']);
});

it('reports a troop limited to an area the map does not declare, since the engine never meets it', function () {
    [$root] = createEncounterAreaProject();
    editTestMapData($root, static fn(string $source): string => str_replace("'areas' => ['yard']]", "'areas' => ['yard', 'cellar']]", $source));

    $errors = array_map(static fn($issue): string => $issue->message, array_filter(
        new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)),
        static fn($issue): bool => $issue->severity === Severity::ERROR && $issue->where === 'test-map'));

    expect($errors)->toContain('The troop "Yard Rats" is limited to the area "cellar", which this map does not declare, so it is never met.')
        ->and(implode("\n", $errors))->not->toContain('"yard"');
});
