<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;

/**
 * Project validation over cutscenes: paired folders, the Engine's own
 * verdict on each asset, the references a command tree makes, and the map
 * triggers that launch cinematics.
 */
function cutsceneIssues(string $root): array
{
    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));

    return array_values(array_filter(
        $issues,
        static fn(Issue $issue): bool => str_starts_with($issue->where, 'cinematic ') || str_starts_with($issue->where, 'summon ') || str_contains($issue->message, 'Cinematic'),
    ));
}

function issueMessages(array $issues): array
{
    return array_map(static fn(Issue $issue): string => $issue->where . ': ' . $issue->message, $issues);
}

/** @param array<int, mixed> $commands */
function writeValidationCommonEvent(string $root, string $id, array $commands): void
{
    file_put_contents(
        $root . '/assets/Events/' . $id . '.php',
        "<?php\n\nreturn " . var_export($commands, true) . ";\n",
    );
}

/** Makes the fixture cinematic eligible to invoke Common Events. */
function forbidHarbourCinematicSkip(string $root): void
{
    $path = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.data.php';
    $source = (string) file_get_contents($path);
    file_put_contents($path, str_replace("'policy' => 'authored'", "'policy' => 'forbidden'", $source));
}

/** Appends commands immediately before the fixture's declared checkpoint. */
function insertHarbourCommands(string $root, array $commands): void
{
    $path = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    $source = (string) file_get_contents($path);
    $insert = implode("\n", array_map(
        static fn(array $command): string => '  ' . var_export($command, true) . ',',
        $commands,
    ));
    file_put_contents(
        $path,
        str_replace(
            "  ['type' => 'checkpoint', 'name' => 'boats-crossed'],",
            $insert . "\n  ['type' => 'checkpoint', 'name' => 'boats-crossed'],",
            $source,
        ),
    );
}

it('passes the clean fixture project without cutscene findings', function () {
    $root = cutsceneProject();

    expect(issueMessages(cutsceneIssues($root)))->toBe([]);
});

it('reports what the Engine refuses, by asset, with the Engine\'s own message', function () {
    $root = cutsceneProject();
    $folder = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns';
    $script = str_replace("['type' => 'checkpoint', 'name' => 'boats-crossed'],", "['type' => 'give_item', 'item' => 'S-Potion'],", harbourCinematicScript());
    file_put_contents($folder . '/harbour-lanterns.script.php', $script);

    $messages = issueMessages(cutsceneIssues($root));
    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toStartWith('cinematic harbour-lanterns:')
        ->and($messages[0])->toContain('give_item');
});

it('resolves the references the Engine only sees at play time', function () {
    $root = cutsceneProject();
    $folder = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns';
    $script = harbourCinematicScript();
    $script = str_replace("'actorId' => 'boat-west'", "'actorId' => 'boat-north'", $script);
    $script = str_replace("['type' => 'checkpoint', 'name' => 'boats-crossed'],", "['type' => 'checkpoint', 'name' => 'boats-crossed'],\n  ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'ghost', 'steps' => [['direction' => 'up', 'count' => 1]]],\n  ['type' => 'checkpoint', 'name' => 'undeclared'],\n  ['type' => 'cinematic_music', 'track' => 'no-such-track', 'loop' => false, 'completionBehavior' => 'stop'],\n  ['type' => 'transfer', 'map' => 'nowhere', 'x' => 1, 'y' => 1],", $script);
    file_put_contents($folder . '/harbour-lanterns.script.php', $script);

    $messages = implode("\n", issueMessages(cutsceneIssues($root)));
    expect($messages)->toContain('staged actor "boat-north"')
        ->and($messages)->toContain('NPC "ghost", which is not on harbour')
        ->and($messages)->toContain('checkpoint "undeclared"')
        ->and($messages)->toContain('"no-such-track"')
        ->and($messages)->toContain('"nowhere"');
});

it('validates nested Common Events in their owning cinematic context', function () {
    $root = cutsceneProject();
    forbidHarbourCinematicSkip($root);
    insertHarbourCommands($root, [['type' => 'common_event', 'id' => 'cinematic-route']]);
    writeValidationCommonEvent($root, 'cinematic-route', [
        [
            'type' => 'stage_actor',
            'id' => 'route-guide',
            'sprite' => 'G',
            'subject' => ['kind' => 'npc', 'id' => 'keeper'],
        ],
        ['type' => 'common_event', 'id' => 'cinematic-route-nested'],
    ]);
    writeValidationCommonEvent($root, 'cinematic-route-nested', [
        [
            'type' => 'move_route',
            'subject' => 'npc',
            'npcId' => 'keeper',
            'remember' => 'keeper-outbound',
            'waypoints' => [['x' => 5], ['x' => 5, 'y' => 2]],
        ],
        ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'keeper', 'retrace' => 'keeper-outbound'],
        [
            'type' => 'move_route',
            'subject' => 'staged_actor',
            'actorId' => 'route-guide',
            'steps' => [['direction' => 'right', 'count' => 1]],
        ],
    ]);

    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));
    $relevant = array_values(array_filter(
        issueMessages($issues),
        static fn(string $message): bool => str_contains($message, 'cinematic-route')
            || str_contains($message, 'route-guide')
            || str_contains($message, 'keeper-outbound'),
    ));

    expect($relevant)->toBe([]);
});

it('retains malformed route reference and cycle failures inside cinematic Common Events', function () {
    $root = cutsceneProject();
    forbidHarbourCinematicSkip($root);
    insertHarbourCommands($root, [
        ['type' => 'common_event', 'id' => 'cinematic-malformed-route'],
        ['type' => 'common_event', 'id' => 'cinematic-missing-subjects'],
        ['type' => 'common_event', 'id' => 'cinematic-missing-nested'],
        ['type' => 'common_event', 'id' => 'cinematic-cycle-a'],
    ]);
    writeValidationCommonEvent($root, 'cinematic-malformed-route', [[
        'type' => 'move_route',
        'subject' => 'player',
        'steps' => [['direction' => 'right']],
        'waypoints' => [['x' => 3]],
    ]]);
    writeValidationCommonEvent($root, 'cinematic-missing-subjects', [
        [
            'type' => 'move_route',
            'subject' => 'npc',
            'npcId' => 'missing-keeper',
            'waypoints' => [['x' => 5]],
        ],
        [
            'type' => 'move_route',
            'subject' => 'staged_actor',
            'actorId' => 'missing-stage',
            'steps' => [['direction' => 'left']],
        ],
    ]);
    writeValidationCommonEvent($root, 'cinematic-missing-nested', [
        ['type' => 'common_event', 'id' => 'does-not-exist'],
    ]);
    writeValidationCommonEvent($root, 'cinematic-cycle-a', [
        ['type' => 'common_event', 'id' => 'cinematic-cycle-b'],
    ]);
    writeValidationCommonEvent($root, 'cinematic-cycle-b', [
        ['type' => 'common_event', 'id' => 'cinematic-cycle-a'],
    ]);

    $messages = implode("\n", issueMessages(new ProjectValidator()->validate(ProjectWorkspace::fromProject($root))));

    expect($messages)->toContain('requires exactly one of steps, waypoints or retrace')
        ->and($messages)->toContain('NPC "missing-keeper", which is not on harbour')
        ->and($messages)->toContain('staged actor "missing-stage"')
        ->and($messages)->toContain('common event "does-not-exist", which does not exist')
        ->and($messages)->toContain('Common Event references contain a cycle')
        ->and($messages)->toContain('cinematic-cycle-a -> cinematic-cycle-b -> cinematic-cycle-a');
});

it('keeps legacy Common Events on the non-cinematic lifecycle contract', function () {
    $root = cutsceneProject();
    $mapPath = $root . '/assets/Maps/harbour/harbour.data.php';
    $map = require $mapPath;
    $map['events']['L'] = [
        'class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
        'data' => ['scriptId' => 'legacy-root', 'mode' => 'action', 'reusable' => false],
    ];
    file_put_contents($mapPath, "<?php\n\nreturn " . var_export($map, true) . ";\n");
    writeValidationCommonEvent($root, 'legacy-root', [
        ['type' => 'common_event', 'id' => 'legacy-waypoint'],
        ['type' => 'common_event', 'id' => 'legacy-history'],
        ['type' => 'common_event', 'id' => 'legacy-stage'],
        ['type' => 'common_event', 'id' => 'legacy-cycle-a'],
    ]);
    writeValidationCommonEvent($root, 'legacy-waypoint', [[
        'type' => 'move_route',
        'subject' => 'player',
        'waypoints' => [['x' => 2], ['x' => 2, 'y' => 3]],
    ]]);
    writeValidationCommonEvent($root, 'legacy-history', [[
        'type' => 'move_route',
        'subject' => 'player',
        'remember' => 'legacy-outbound',
        'waypoints' => [['x' => 2]],
    ]]);
    writeValidationCommonEvent($root, 'legacy-stage', [[
        'type' => 'move_route',
        'subject' => 'staged_actor',
        'actorId' => 'legacy-actor',
        'steps' => [['direction' => 'left']],
    ]]);
    writeValidationCommonEvent($root, 'legacy-cycle-a', [
        ['type' => 'common_event', 'id' => 'legacy-cycle-b'],
    ]);
    writeValidationCommonEvent($root, 'legacy-cycle-b', [
        ['type' => 'common_event', 'id' => 'legacy-cycle-a'],
    ]);

    $messages = issueMessages(new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)));
    $legacyMessages = implode("\n", array_values(array_filter(
        $messages,
        static fn(string $message): bool => str_contains($message, 'legacy-'),
    )));

    expect($legacyMessages)->not->toContain('legacy-waypoint: A movement route has no steps')
        ->and($legacyMessages)->toContain('Recorded movement routes require an active cinematic session')
        ->and($legacyMessages)->toContain('unsupported subject "staged_actor"')
        ->and($legacyMessages)->toContain('Common Event references contain a cycle')
        ->and($legacyMessages)->toContain('legacy-cycle-a -> legacy-cycle-b -> legacy-cycle-a');
});

it('keeps mixed-context Common Events legacy-safe through every runtime command container', function () {
    $root = cutsceneProject();
    forbidHarbourCinematicSkip($root);
    $sharedEvents = [
        'shared-sequence' => 'sequence-actor',
        'shared-parallel-list' => 'parallel-list-actor',
        'shared-parallel-keyed' => 'parallel-keyed-actor',
        'shared-cancel' => 'cancel-actor',
    ];
    insertHarbourCommands($root, array_map(
        static fn(string $eventId): array => ['type' => 'common_event', 'id' => $eventId],
        array_keys($sharedEvents),
    ));

    foreach ($sharedEvents as $eventId => $actorId) {
        writeValidationCommonEvent($root, $eventId, [
            [
                'type' => 'stage_actor',
                'id' => $actorId,
                'sprite' => 'G',
                'subject' => ['kind' => 'npc', 'id' => 'keeper'],
            ],
            [
                'type' => 'move_route',
                'subject' => 'staged_actor',
                'actorId' => $actorId,
                'steps' => [['direction' => 'right', 'count' => 1]],
            ],
        ]);
    }

    writeValidationCommonEvent($root, 'legacy-container-root', [
        [
            'type' => 'sequence',
            'commands' => [['type' => 'common_event', 'id' => 'shared-sequence']],
        ],
        [
            'type' => 'parallel',
            'lanes' => [
                [['type' => 'common_event', 'id' => 'shared-parallel-list']],
                [
                    'id' => 'keyed',
                    'commands' => [['type' => 'common_event', 'id' => 'shared-parallel-keyed']],
                ],
            ],
        ],
        [
            'type' => 'choice',
            'prompt' => 'Continue?',
            'options' => [['text' => 'Continue', 'then' => []]],
            'cancel' => [['type' => 'common_event', 'id' => 'shared-cancel']],
        ],
    ]);

    $mapPath = $root . '/assets/Maps/harbour/harbour.data.php';
    $map = require $mapPath;
    $map['events']['L'] = [
        'class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
        'data' => ['scriptId' => 'legacy-container-root', 'mode' => 'action', 'reusable' => false],
    ];
    file_put_contents($mapPath, "<?php\n\nreturn " . var_export($map, true) . ";\n");

    $messages = issueMessages(new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)));
    $legacyFailures = array_values(array_filter(
        $messages,
        static fn(string $message): bool => str_starts_with($message, 'harbour event L')
            && str_contains($message, 'unsupported subject "staged_actor"'),
    ));

    expect($legacyFailures)->toHaveCount(4);

    foreach (array_keys($sharedEvents) as $eventId) {
        expect(implode("\n", $legacyFailures))->toContain('common event ' . $eventId);
    }
});

it('validates a reused Common Event independently in every calling map context', function () {
    $root = cutsceneProject();
    writeValidationCommonEvent($root, 'map-scoped-npc-route', [[
        'type' => 'move_route',
        'subject' => 'npc',
        'npcId' => 'keeper',
        'steps' => [['direction' => 'left', 'count' => 1]],
    ]]);

    $harbourPath = $root . '/assets/Maps/harbour/harbour.data.php';
    $harbour = require $harbourPath;
    $harbour['events']['H'] = [
        'class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
        'data' => ['scriptId' => 'map-scoped-npc-route', 'mode' => 'action', 'reusable' => false],
    ];
    file_put_contents($harbourPath, "<?php\n\nreturn " . var_export($harbour, true) . ";\n");

    $emptyMapFolder = $root . '/assets/Maps/empty-quay';
    mkdir($emptyMapFolder, 0o777, true);
    copy($root . '/assets/Maps/harbour/harbour.map.php', $emptyMapFolder . '/empty-quay.map.php');
    copy($root . '/assets/Maps/harbour/harbour.event.php', $emptyMapFolder . '/empty-quay.event.php');
    $emptyQuay = $harbour;
    $emptyQuay['name'] = 'Empty Quay';
    $emptyQuay['npcs'] = [];
    $emptyQuay['events'] = [
        'E' => [
            'class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
            'data' => ['scriptId' => 'map-scoped-npc-route', 'mode' => 'action', 'reusable' => false],
        ],
    ];
    file_put_contents(
        $emptyMapFolder . '/empty-quay.data.php',
        "<?php\n\nreturn " . var_export($emptyQuay, true) . ";\n",
    );

    $messages = issueMessages(new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)));
    $npcFailures = array_values(array_filter(
        $messages,
        static fn(string $message): bool => str_contains($message, 'route targets NPC id "keeper"'),
    ));

    expect($npcFailures)->toHaveCount(1)
        ->and($npcFailures[0])->toStartWith('empty-quay event E:')
        ->and($npcFailures[0])->toContain('not on map "empty-quay"');
});

it('checks the map triggers that launch cinematics', function () {
    $root = cutsceneProject();
    $mapData = <<<'PHP_SOURCE'
<?php

return [
  'name' => 'Harbour',
  'region' => 'Coast',
  'description' => 'A quay at dusk.',
  'triggers' => [],
  'events' => [
    'C' => [
      'class' => 'Ichiloto\Engine\Events\Triggers\CinematicEventTrigger',
      'data' => ['cinematicId' => 'harbour-lanterns', 'mode' => 'auto', 'reusable' => false],
    ],
    'B' => [
      'class' => 'Ichiloto\Engine\Events\Triggers\CinematicEventTrigger',
      'extra' => true,
      'data' => ['cinematicId' => 'missing-one', 'mode' => 'sometimes', 'reusable' => 'yes', 'bogus' => 1],
    ],
    'E' => [
      'class' => 'Ichiloto\Engine\Events\Triggers\CinematicEventTrigger',
      'data' => ['cinematicId' => ''],
    ],
  ],
  'npcs' => [],
];
PHP_SOURCE;
    file_put_contents($root . '/assets/Maps/harbour/harbour.data.php', $mapData);
    $events = [
        '                    ',
        ' C                  ',
        '  B                 ',
        '   E                ',
        '                    ',
        '                    ',
        '                    ',
        '                    ',
    ];
    file_put_contents($root . '/assets/Maps/harbour/harbour.event.php', "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n" . implode("\n", $events) . "\nICHILOTO_EVENT_MAP;\n");

    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));
    $messages = implode("\n", array_map(static fn(Issue $issue): string => $issue->where . ': ' . $issue->message, $issues));

    expect($messages)->not->toContain('harbour event C')
        ->and($messages)->toContain('harbour event B: CinematicEventTrigger uses unsupported root field "extra"')
        ->and($messages)->toContain('harbour event B: CinematicEventTrigger uses unsupported data field "bogus"')
        ->and($messages)->toContain('harbour event B: It launches cinematic "missing-one", which does not exist')
        ->and($messages)->toContain('harbour event B: CinematicEventTrigger uses unsupported mode "sometimes"')
        ->and($messages)->toContain('harbour event B: CinematicEventTrigger reusable must be boolean')
        ->and($messages)->toContain('harbour event E: CinematicEventTrigger names no cinematicId');
});

it('reports folder findings and id/folder disagreement', function () {
    $root = cutsceneProject();
    mkdir($root . '/assets/Cutscenes/Cinematics/lonely', 0o777, true);
    file_put_contents($root . '/assets/Cutscenes/Cinematics/lonely/lonely.data.php', "<?php\n\nreturn ['id' => 'lonely', 'name' => 'Lonely'];\n");
    mkdir($root . '/assets/Cutscenes/Summons/other-name', 0o777, true);
    file_put_contents($root . '/assets/Cutscenes/Summons/other-name/other-name.data.php', lanternSummonData());
    file_put_contents($root . '/assets/Cutscenes/Summons/other-name/other-name.timeline.php', lanternSummonTimeline());

    $messages = implode("\n", issueMessages(cutsceneIssues($root)));
    expect($messages)->toContain('cinematic lonely')
        ->and($messages)->toContain('summon other-name: other-name is read-only: the folder is "other-name" but the data file declares id "lantern-wisp"')
        ->and(count(array_filter(explode("\n", $messages), static fn(string $line): bool => str_starts_with($line, 'summon other-name:'))))->toBe(1);
});
