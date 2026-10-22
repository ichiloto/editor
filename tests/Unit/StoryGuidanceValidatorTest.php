<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Editor\Validation\Severity;

/**
 * Synthetic story: a keeper NPC starts the errand quest on one page and opens
 * a gate on another, and a door (event D) waits for the gate. Each case
 * varies only the quests that could lead the player there, or what opens the
 * gate: extra map events and data, and troops whose battles can open it.
 */
function writeGuidanceProject(array $quests, array $keeperPages = [], array $scripts = [], array $troops = [],
    array $events = [], array $mapData = []): string
{
    $root = makeTemporaryProject();
    $keeperPages = $keeperPages !== [] ? $keeperPages : [
        ['conditions' => [['type' => 'quest', 'name' => 'errand', 'status' => 'active']],
            'lines' => [['name' => 'Keeper', 'text' => 'Open it, then.']], 'sets' => [['type' => 'event', 'name' => 'gate_opened']]],
        ['lines' => [['name' => 'Keeper', 'text' => 'An errand for you.']], 'sets' => [['type' => 'quest', 'name' => 'errand']]],
    ];
    $map = [
        'name' => 'Test Map',
        'region' => '',
        'triggers' => [],
        'npcs' => [['id' => 'keeper', 'name' => 'Keeper', 'sprite' => '@', 'x' => 1, 'y' => 1, 'dialogue' => $keeperPages]],
        'events' => [
            'D' => [
                'class' => 'Ichiloto\\Engine\\Events\\Triggers\\TransferPlayerTrigger',
                'conditions' => [['type' => 'event', 'name' => 'gate_opened']],
                'whenBlocked' => 'The gate is shut.',
                'data' => ['destinationMap' => 'test-map', 'spawnPoint' => ['x' => 0, 'y' => 0]],
            ],
            ...$events,
        ],
        ...$mapData,
    ];
    file_put_contents($root . '/assets/Maps/test-map/test-map.data.php', '<?php return ' . var_export($map, true) . ';');
    file_put_contents($root . '/assets/Data/quests.php', '<?php return ' . var_export($quests, true) . ';');
    if ($troops !== []) {
        file_put_contents($root . '/assets/Data/troops.php', '<?php return ' . var_export($troops, true) . ';');
    }
    foreach ($scripts as $id => $commands) {
        file_put_contents($root . '/assets/Events/' . $id . '.php', '<?php return ' . var_export($commands, true) . ';');
    }

    return $root;
}

/** @return list<Issue> The guidance gaps validation reports. */
function guidanceGaps(string $root): array
{
    return array_values(array_filter(new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)),
        static fn(Issue $issue): bool => str_contains($issue->message, 'wayfinder')));
}

function errandQuest(array $objectives, array $extra = []): array
{
    return ['id' => 'errand', 'name' => 'Errand', 'description' => '', 'objectives' => [
        ['type' => 'reach_map', 'target' => 'test-map', 'waymarks' => [['map' => 'test-map']]],
        ...$objectives,
    ], ...$extra];
}

function gateObjective(array $waymark = ['map' => 'test-map', 'npc' => 'keeper']): array
{
    return ['type' => 'flag', 'target' => 'gate_opened', 'description' => 'Open the gate', 'waymarks' => [$waymark]];
}

it('warns at a blocked door whose story flag no objective leads to', function () {
    $gaps = guidanceGaps(writeGuidanceProject([errandQuest([])]));

    expect($gaps)->toHaveCount(1)
        ->and($gaps[0]->severity)->toBe(Severity::WARNING)
        ->and($gaps[0]->where)->toBe('test-map event D')
        ->and($gaps[0]->map)->toBe('test-map')
        ->and($gaps[0]->message)->toContain('gate_opened');
});

it('finds no gap once an objective tracks the flag with a waymark', function () {
    expect(guidanceGaps(writeGuidanceProject([errandQuest([gateObjective()])])))->toBe([]);
});

it('counts a waymark on the page that sets the flag, through its event script', function () {
    $pages = [
        ['conditions' => [['type' => 'quest', 'name' => 'errand', 'status' => 'active']], 'scriptId' => 'open-gate'],
        ['lines' => [['name' => 'Keeper', 'text' => 'An errand for you.']], 'sets' => [['type' => 'quest', 'name' => 'errand']]],
    ];
    $talk = ['type' => 'talk_to', 'target' => 'Keeper', 'waymarks' => [['map' => 'test-map', 'npc' => 'keeper']]];
    $root = writeGuidanceProject([errandQuest([$talk])], $pages,
        ['open-gate' => [['type' => 'branch', 'conditions' => [['type' => 'event', 'name' => 'other']],
            'then' => [], 'else' => [['type' => 'record_event', 'name' => 'gate_opened']]]]]);

    expect(guidanceGaps($root))->toBe([]);
});

it('does not count guidance that can only appear once the flag is set', function (array $quest) {
    expect(array_map(static fn(Issue $issue): string => $issue->where, guidanceGaps(writeGuidanceProject([$quest]))))->toContain('test-map event D');
})->with([
    'a waymark waiting for the flag' => [errandQuest([gateObjective(['map' => 'test-map', 'npc' => 'keeper',
        'conditions' => [['type' => 'event', 'name' => 'gate_opened']]])])],
    'a quest needing the flag to start' => [errandQuest([gateObjective()], ['prerequisites' => [['type' => 'event', 'name' => 'gate_opened']]])],
    'an optional quest' => [errandQuest([gateObjective()], ['optional' => true])],
]);

it('does not count a quest that starts only where the flag is set', function () {
    $pages = [['lines' => [['name' => 'Keeper', 'text' => 'Go.']],
        'sets' => [['type' => 'quest', 'name' => 'errand'], ['type' => 'event', 'name' => 'gate_opened']]]];

    expect(guidanceGaps(writeGuidanceProject([errandQuest([gateObjective()])], $pages)))->toHaveCount(1);
});

it('warns at a main quest whose prerequisite no objective leads to', function () {
    $next = ['id' => 'next', 'name' => 'Next Step', 'description' => '',
        'prerequisites' => [['type' => 'event', 'name' => 'gate_opened']],
        'objectives' => [['type' => 'reach_map', 'target' => 'test-map']]];
    $gaps = guidanceGaps(writeGuidanceProject([errandQuest([]), $next]));

    expect(array_map(static fn(Issue $issue): string => $issue->where, $gaps))->toContain('quest next', 'test-map event D')
        ->and(guidanceGaps(writeGuidanceProject([errandQuest([gateObjective()]), $next])))->toBe([]);
});

/* Battles: a troop's event page opens the gate in its battle. */

function gateTroop(): array
{
    return ['name' => 'Gate Warden', 'enemies' => [['enemy' => 'Regular Bat', 'position' => [15, 7]]], 'events' => [
        ['name' => 'Yields', 'span' => 'battle', 'conditions' => ['enemy' => 0, 'enemyHpPercent' => 25],
            'commands' => [['type' => 'common_event', 'id' => 'warden-yields']]],
    ]];
}

/** A keeper who starts the errand but opens nothing: only the battle opens the gate. */
function errandOnlyPages(): array
{
    return [['lines' => [['name' => 'Keeper', 'text' => 'An errand for you.']], 'sets' => [['type' => 'quest', 'name' => 'errand']]]];
}

it('counts a waymark on the event that starts the battle whose troop page sets the flag', function () {
    $duel = ['B' => ['class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
        'data' => ['mode' => 'action', 'reusable' => true, 'scriptId' => 'duel']]];
    $scripts = ['duel' => [['type' => 'start_battle', 'troop' => 'Gate Warden', 'resultVariable' => 'duel']],
        'warden-yields' => [['type' => 'record_event', 'name' => 'gate_opened']]];
    $toDuel = ['type' => 'flag', 'target' => 'duel_fought', 'waymarks' => [['map' => 'test-map', 'event' => 'B']]];
    $project = static fn(array $objectives): string => writeGuidanceProject([errandQuest($objectives)], errandOnlyPages(),
        $scripts, [gateTroop()], $duel);

    expect(guidanceGaps($project([$toDuel])))->toBe([])
        ->and(array_map(static fn(Issue $issue): string => $issue->message, guidanceGaps($project([]))))
        ->toHaveCount(1)->each->toContain('no objective of a main quest leads');
});

it('counts a waymark leading to the map whose encounters bring that battle', function () {
    $scripts = ['warden-yields' => [['type' => 'set_switch', 'name' => 'gate_opened', 'value' => true]]];
    $encounters = ['encounters' => ['troops' => ['Gate Warden' => 4], 'rate' => 12]];
    // The errand's first objective leads to the map; without encounters nothing there opens the gate.
    expect(guidanceGaps(writeGuidanceProject([errandQuest([])], errandOnlyPages(), $scripts, [gateTroop()], mapData: $encounters)))->toBe([])
        ->and(guidanceGaps(writeGuidanceProject([errandQuest([])], errandOnlyPages(), $scripts, [gateTroop()])))->toHaveCount(1);
});

it('warns at a gate nothing in the project sets, battles included', function () {
    $gaps = guidanceGaps(writeGuidanceProject([errandQuest([])], errandOnlyPages(), troops: [gateTroop()]));

    expect($gaps)->toHaveCount(1)
        ->and($gaps[0]->where)->toBe('test-map event D')
        ->and($gaps[0]->message)->toContain('nothing in the project sets it');
});
