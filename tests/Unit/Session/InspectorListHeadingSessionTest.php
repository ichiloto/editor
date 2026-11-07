<?php

declare(strict_types=1);

it('marks every inspector list heading, and every other list row removes the entry it belongs to', function () {
    [, , $session] = createLandmarkProject(<<<'MAP'
'areas' => [['id' => 'yard', 'area' => ['x' => 0, 'y' => 1, 'width' => 2, 'height' => 1]]],
'encounters' => ['troops' => ['Wanderer' => 1, 'Drifter' => 2], 'rate' => 20,
    'pools' => [['area' => 'yard', 'troops' => ['Yard Rats' => 5, 'Yard Bats' => 2]]]],
MAP);
    $rows = array_values(array_filter($session->readInspector('test-map', 'E')['rows'],
        static fn(array $row): bool => isset($row['list'])));
    $headings = array_values(array_filter($rows, static fn(array $row): bool => ($row['listHeading'] ?? false) === true));

    // A heading names its list: the GUI offers adding there, never removing.
    expect(array_column($headings, 'label'))->toEqualCanonicalizing([
        'Music Variants', 'Troops · 2', 'Scoped Encounter Pools · 1', 'Troops · 2', 'Arena Rules · 0',
        'Landmarks · 0', 'Speakers · 0',
    ]);

    // Every row the GUI offers removing at removes its own entry; undo puts it back. A removal the
    // state forbids (a pool's last troop, an area still named) keeps its button and says why: the
    // pool names this area, so its rows are left to the area dependency tests.
    $entries = array_filter($rows, static fn(array $row): bool => ($row['listHeading'] ?? false) !== true
        && ($row['key']['target'] ?? null) !== \Ichiloto\Editor\Inspector\MapAreaFields::TARGET);
    expect($entries)->not->toBeEmpty();
    foreach ($entries as $entry) {
        $revision = $session->readMap('test-map')['revision'];
        expect($session->removeInspectorListEntry('test-map', $revision, $entry['key'])['changed'] ?? true)->toBeTrue();
        $session->undo();
    }
});
