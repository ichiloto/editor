<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Field\MapEncounters;
use Ichiloto\Editor\Inspector\MapInspector;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Editor\Validation\Severity;

/**
 * The arena a random encounter's graphical battle takes place in: the map's
 * own, or one entry's, which outranks it. Arenas are graphical presentation
 * only, so a graphical editor offers them and the terminal editor keeps them
 * exactly as authored without offering them. An entry written as a map stays
 * one, with every key it carries. Synthetic fixtures only.
 */

/** A project with two arenas whose test map offers one structured encounter. */
function encounterArenaProject(): string
{
    $root = troopFormationProject();
    editTestMapData($root, static fn(string $source): string => str_replace("  'triggers' => [],", <<<'PHP'
  'triggers' => [],
  'encounters' => [
    'troops' => [
      // The lake's own fight.
      'Pair' => ['weight' => 2, 'battleArena' => 'arena.road', 'note' => 'kept'],
    ],
    'rate' => 20,
  ],
PHP, $source));

    return $root;
}

/** The inspector row whose key names a field (and, for a troop row, its index). */
function encounterRow(array $read, string $field): array
{
    return array_find($read['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === $field)
        ?? throw new RuntimeException("No inspector row {$field}.");
}

it('reads structured entries and keeps what they carry through every edit', function () {
    $encounters = MapEncounters::of([
        'troops' => [
            'Rats' => 5,
            'Loch Ness' => ['weight' => 1, 'battleArena' => 'arena.lake', 'note' => 'kept'],
        ],
        'battleArena' => 'arena.ruins',
    ]);

    expect($encounters->isSupported())->toBeTrue()
        ->and($encounters->mapArena())->toBe('arena.ruins')
        ->and(array_column($encounters->rows(), 'arena'))->toBe([null, 'arena.lake'])
        ->and(array_column($encounters->rows(), 'weight'))->toBe([5, 1])
        // A rename and a weight keep the entry a map, with its other keys, in place.
        ->and($encounters->withTroopAt(1, 'Nessie')['troops'])
        ->toBe(['Rats' => 5, 'Nessie' => ['weight' => 1, 'battleArena' => 'arena.lake', 'note' => 'kept']])
        ->and($encounters->withWeightAt(1, 3)['troops']['Loch Ness'])->toBe(['weight' => 3, 'battleArena' => 'arena.lake', 'note' => 'kept'])
        // A weight alone gains a map only with an arena; an arena cleared keeps the rest.
        ->and($encounters->withArenaAt(0, 'arena.cave')['troops']['Rats'])->toBe(['weight' => 5, 'battleArena' => 'arena.cave'])
        ->and($encounters->withArenaAt(1, null)['troops']['Loch Ness'])->toBe(['weight' => 1, 'note' => 'kept'])
        ->and($encounters->withMapArena(null))->not->toHaveKey('battleArena')
        ->and($encounters->withMapArena('arena.cave')['battleArena'])->toBe('arena.cave')
        // The last row takes the map's arena with it, as it takes the rate.
        ->and(MapEncounters::of(['troops' => ['Rats' => 5], 'battleArena' => 'arena.ruins'])->withTroopRemovedAt(0))->toBeNull();
});

it('refuses an arena it cannot hold, saying where', function (array $block, string $reason) {
    $encounters = MapEncounters::of($block);

    expect($encounters->isSupported())->toBeFalse()
        ->and($encounters->unsupportedReason())->toContain($reason);
})->with([
    'an entry\'s arena' => [['troops' => ['Rats' => ['weight' => 5, 'battleArena' => 7]]], 'the troop "Rats" has a battleArena that is int'],
    'the map\'s arena' => [['troops' => ['Rats' => 5], 'battleArena' => ['x']], 'the map\'s battleArena is array'],
    'an entry\'s weight' => [['troops' => ['Rats' => ['weight' => [5]]]], 'the troop "Rats" has a weight that is array'],
    'an entry\'s null arena' => [['troops' => ['Rats' => ['weight' => 5, 'battleArena' => null]]], 'the troop "Rats" has a battleArena that is null'],
    'the map\'s null arena' => [['troops' => ['Rats' => 5], 'battleArena' => null], 'the map\'s battleArena is null'],
]);

it('offers the arenas in a graphical editor, writing only what changed', function () {
    $root = encounterArenaProject();
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $session = EditorSession::open($root);
    $read = $session->readInspector('test-map');

    expect(encounterRow($read, 'arena'))->toMatchArray(['kind' => 'reference', 'reference' => 'battle_arenas', 'noneLabel' => '(map arena)', 'value' => 'Road (arena.road)'])
        ->and(encounterRow($read, 'mapArena'))->toMatchArray(['kind' => 'reference', 'noneLabel' => '(default arena)', 'value' => '(default arena)'])
        ->and($session->listReferences('test-map', 'battle_arenas'))->toBe([['value' => 'arena.road', 'label' => 'Road'], ['value' => 'arena.cave', 'label' => 'Cave']]);

    // A weight edit changes the weight and nothing else of the entry.
    $before = (string) file_get_contents($path);
    $session->applyInspector('test-map', $read['revision'], encounterRow($read, 'weight')['key'], '4');
    $session->saveMap('test-map');

    expect((string) file_get_contents($path))->toBe(str_replace("['weight' => 2,", "['weight' => 4,", $before));

    // The map's arena, then the entry's cleared to the map's.
    $read = $session->readInspector('test-map');
    $session->applyInspector('test-map', $read['revision'], encounterRow($read, 'mapArena')['key'], 'arena.cave');
    $read = $session->readInspector('test-map');
    $session->applyInspector('test-map', $read['revision'], encounterRow($read, 'arena')['key'], '');
    $session->saveMap('test-map');
    $saved = (require $path)['encounters'];

    expect($saved['battleArena'])->toBe('arena.cave')
        ->and($saved['troops'])->toBe(['Pair' => ['weight' => 4, 'note' => 'kept']])
        ->and((string) file_get_contents($path))->toContain('// The lake\'s own fight.');

    // An arena the battle presentation does not declare is refused.
    $read = $session->readInspector('test-map');
    expect(fn() => $session->applyInspector('test-map', $read['revision'], encounterRow($read, 'arena')['key'], 'arena.moon'))
        ->toThrow(SessionRefusal::class, 'arena.moon');
});

it('keeps arenas in the terminal editor without offering them', function () {
    $root = encounterArenaProject();
    $workspace = ProjectWorkspace::fromProject($root);
    $map = $workspace->maps[0];
    $terminal = new MapInspector(new ReferenceCatalog($workspace, $map));

    $fields = $terminal->getMapFields($map);

    expect(array_filter($fields, static fn(array $field): bool => in_array($field['field'] ?? null, ['arena', 'mapArena'], true)))->toBe([]);

    $weight = array_find($fields, static fn(array $field): bool => ($field['field'] ?? null) === 'weight');
    $terminal->apply($map, $weight, '6');

    expect($map->getMapDataField(['encounters', 'troops', 'Pair']))->toBe(['weight' => 6, 'battleArena' => 'arena.road', 'note' => 'kept']);
});

it('reports an arena a battle names that the battle presentation does not declare', function () {
    $root = encounterArenaProject();
    editTestMapData($root, static fn(string $source): string => str_replace("'rate' => 20,", "'rate' => 20,\n    'battleArena' => 'arena.moon',", $source));
    $errors = static fn(string $root): string => implode("\n", array_map(
        static fn($issue): string => $issue->where . ': ' . $issue->message,
        array_filter(new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)), static fn($issue): bool => $issue->severity === Severity::ERROR),
    ));

    expect($errors($root))->toContain('test-map: The encounters names the arena "arena.moon", which the battle presentation does not declare.')
        ->not->toContain('"arena.road"');

    // Without a battle presentation, no arena can be drawn.
    unlink($root . '/assets/Data/Presentation/battle.php');

    expect($errors($root))->toContain('The encounter with "Pair" names the arena "arena.road", but the project declares no battle presentation to draw it in.');
});
