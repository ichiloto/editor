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
