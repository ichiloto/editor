<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\ChangeBatch;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Field\NpcPages;

/**
 * An NPC owns what talking to it does: its pages, each with conditions, an
 * action (lines, an event script or a Cinematic), a cue and whether it runs
 * once. Synthetic project: a mentor who delivers a briefing script, and an
 * aide who stands beside the mentor for the scene.
 */
function npcPageProject(array $event = ['class' => 'Ichiloto\\Engine\\Events\\Triggers\\DialogueEventTrigger',
    'data' => ['dialogue' => [['name' => '', 'text' => 'A notice.']]]]): string
{
    $root = mapGraphicsProject();
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $path;
    $data['events'] = ['E' => $event];
    $data['npcs'] = [
        ['id' => 'mentor', 'name' => 'Mentor', 'sprite' => '', 'x' => 2, 'y' => 1, 'dialogue' => [['text' => 'Ready when you are.']]],
        ['id' => 'aide', 'name' => 'Aide', 'sprite' => '', 'x' => 1, 'y' => 1, 'dialogue' => [['text' => 'Hm.']]],
    ];
    file_put_contents($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");
    writeNpcEventScripts($root, ['briefing' => [['type' => 'text', 'name' => 'Mentor', 'text' => 'Your first assignment.']]]);

    return $root;
}

it('authors a story page that runs an event script once with its own cue, and the Engine reads it back as authored', function () {
    $root = npcPageProject();
    $session = EditorSession::open($root);
    $revision = $session->readMap('test-map')['revision'];
    $added = $session->addNpcItem('test-map', $revision, 0, npcSessionRow($session->readNpc('test-map', 0), 'variant0Conditions')['key']);
    $apply = static function (string $field, mixed $value) use ($session): void {
        $session->applyNpc('test-map', $session->readMap('test-map')['revision'], 0, npcSessionRow($session->readNpc('test-map', 0), $field)['key'], $value);
    };
    expect($added['changed'])->toBeTrue();

    // The new page goes after the first; the story page must lead, so the ordinary lines move behind it.
    $apply('variant1Conditions', 'event:arrived');
    $apply('variant1ScriptId', 'briefing');
    $apply('variant1Reusable', 'false');
    $apply('variant1Id', 'briefing');
    $apply('variant1Speakers', 'aide');
    $apply('variant1Cuesymbol', '!');
    $session->saveMap('test-map');
    $npc = (require $root . '/assets/Maps/test-map/test-map.data.php')['npcs'][0];
    $pages = NpcPages::fromEntry($npc);

    expect($npc)->not->toHaveKey('cue')
        ->and($pages->pages)->toHaveCount(2)
        ->and($pages->pages[1]->id)->toBe('briefing')->and($pages->pages[1]->scriptId)->toBe('briefing')
        ->and($pages->pages[1]->reusable)->toBeFalse()->and($pages->pages[1]->speakers)->toBe(['aide'])
        ->and($pages->pages[1]->cue?->symbol)->toBe('!')
        // Page 1 is unconditional, so the Engine's rule says page 2 never runs until the author reorders them.
        ->and(array_column(Ichiloto\Engine\Field\Validation\MapNpcInteractionRule::inspectPages('test-map', ['npcs' => (require $root . '/assets/Maps/test-map/test-map.data.php')['npcs']]), 'rule'))
            ->toBe(['npc.page']);
});

it('saves an NPC page list through a change set and refuses pages the Engine would refuse', function () {
    $root = npcPageProject();
    $page = static fn(string $field): array => ['target' => 'npc', 'field' => $field, 'frame' => []];
    $write = static fn(array $changes): array => ChangeBatch::apply($root, $changes);
    $apply = static fn(string $field, string $value): array => ['method' => 'npc.apply', 'params' => ['map' => 'test-map', 'index' => 0,
        'key' => $page($field), 'value' => $value]];
    // The new page lands after the ordinary lines; the story page moves ahead of them, as talking runs the first.
    $once = [
        ['method' => 'npc.add', 'params' => ['map' => 'test-map', 'index' => 0, 'key' => $page('variant0Conditions')]],
        $apply('variant1Conditions', 'event:arrived'),
        $apply('variant1ScriptId', 'briefing'),
        $apply('variant1Reusable', 'false'),
        $apply('variant1Cuesymbol', '!'),
        ['method' => 'npc.remove', 'params' => ['map' => 'test-map', 'index' => 0, 'key' => $page('variant1Line0Text')]],
        ['method' => 'npc.reorder', 'params' => ['map' => 'test-map', 'index' => 0, 'key' => $page('variant1Conditions'), 'step' => -1]],
    ];

    $refused = $write($once);
    expect($refused['status'])->toBe('refused')
        ->and(implode("\n", array_column($refused['issues'], 'message')))->toContain('needs a stable id')
        ->and((string) file_get_contents($root . '/assets/Maps/test-map/test-map.data.php'))->not->toContain("'reusable'");

    $saved = $write([...$once, $apply('variant0Id', 'briefing')]);
    $briefing = (require $root . '/assets/Maps/test-map/test-map.data.php')['npcs'][0]['dialogue'][0];
    $source = (string) file_get_contents($root . '/assets/Maps/test-map/test-map.data.php');
    $npcs = (require $root . '/assets/Maps/test-map/test-map.data.php')['npcs'];
    $pages = NpcPages::fromEntry($npcs[0]);

    expect($saved['status'])->toBe('saved')
        ->and($pages->pages[0]->id)->toBe('briefing')->and($pages->pages[0]->cue?->symbol)->toBe('!')
        ->and($pages->pages[1]->lines)->toBe([['text' => 'Ready when you are.']])
        // The aide was not touched: still authored as plain lines.
        ->and($npcs[1]['dialogue'])->toBe([['text' => 'Hm.']])
        ->and($source)->toContain("'scriptId' => 'briefing'")
        ->and($briefing)->not->toHaveKey('lines');
});

it('refuses a player-started event an NPC delivers from a separate cell, pointing at the NPC pages', function () {
    $root = npcPageProject(['class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
        'data' => ['mode' => 'action', 'scriptId' => 'briefing'], 'speakers' => ['mentor']]);

    $result = ChangeBatch::apply($root, [['method' => 'npc.apply', 'params' => ['map' => 'test-map', 'index' => 1,
        'key' => ['target' => 'npc', 'field' => 'name', 'frame' => []], 'value' => 'Aide Kemi']]]);
    $issues = array_filter($result['issues'] ?? [], static fn(array $issue): bool => ($issue['code'] ?? '') === 'event.npc-interaction');

    expect($issues)->not->toBe([])
        ->and(implode("\n", array_column($issues, 'message')))->toContain("Mentor's to deliver");
});

it('keeps an NPC named as a staged speaker from being renamed away', function () {
    $root = npcSessionProject([
        ['id' => 'mentor', 'name' => 'Mentor', 'sprite' => 'M', 'x' => 2, 'y' => 1, 'dialogue' => [
            ['speakers' => ['aide'], 'lines' => [['text' => 'Both of you, listen.']]],
        ]],
        ['id' => 'aide', 'name' => 'Aide', 'sprite' => 'A', 'x' => 3, 'y' => 1, 'dialogue' => [['text' => 'Hm.']]],
    ]);
    $session = EditorSession::open($root);
    $revision = $session->readMap('test-map')['revision'];

    expect(fn() => $session->applyNpc('test-map', $revision, 1, npcSessionRow($session->readNpc('test-map', 1), 'id')['key'], 'clerk'))
        ->toThrow(SessionRefusal::class, 'NPC Mentor page 1 (speaker)');
});

it('marks the rows of a page, not of its lines, as ordered for the graphical editor', function () {
    $session = EditorSession::open(npcPageProject());
    $rows = $session->readNpc('test-map', 0)['rows'];
    $ordered = static fn(string $field): bool => (array_find($rows, static fn(array $row): bool => ($row['key']['field'] ?? null) === $field)['ordered'] ?? false) === true;

    expect($ordered('variant0Conditions'))->toBeTrue()->and($ordered('variant0Cuesymbol'))->toBeTrue()
        ->and($ordered('variant0Line0Text'))->toBeFalse()->and($ordered('name'))->toBeFalse();
});

it('moves a one-shot story event onto its NPC as the first page, carrying its saved completion, in one change set', function () {
    $root = npcPageProject(['class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger', 'conditions' => [['type' => 'event', 'name' => 'arrived']],
        'cue' => ['symbol' => '!', 'color' => 'bright-yellow'], 'whenBlocked' => 'Not yet.',
        'data' => ['mode' => 'action', 'reusable' => false, 'scriptId' => 'briefing'], 'speakers' => ['mentor', 'aide']]);
    $events = (string) file_get_contents($root . '/assets/Maps/test-map/test-map.event.php');

    $result = ChangeBatch::apply($root, [['method' => 'npc.adoptEvent', 'params' => ['map' => 'test-map', 'marker' => 'E']]]);
    $data = require $root . '/assets/Maps/test-map/test-map.data.php';
    $manifest = require $root . '/assets/Data/save-compatibility.php';
    $pages = NpcPages::fromEntry($data['npcs'][0]);

    expect($result['status'])->toBe('saved')
        ->and($data['events'] ?? [])->not->toHaveKey('E')
        ->and((string) file_get_contents($root . '/assets/Maps/test-map/test-map.event.php'))->not->toBe($events)
        ->and(getSessionMap(EditorSession::open($root))->getEventArea('E'))->toBeNull()
        ->and($pages->pages[0]->id)->toBe('briefing')->and($pages->pages[0]->reusable)->toBeFalse()
        ->and($pages->pages[0]->conditions)->toBe([['type' => 'event', 'name' => 'arrived']])
        ->and($pages->pages[0]->cue?->symbol)->toBe('!')->and($pages->pages[0]->speakers)->toBe(['aide'])
        ->and($pages->pages[1]->lines)->toBe([['text' => 'Ready when you are.']])
        ->and($manifest['aliases']['one_shot_events'])->toBe([['from' => 'test-map:E', 'to' => 'test-map:mentor/briefing']])
        ->and(Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest::fromProjectRoot($root)->resolveOneShotEvent('test-map:E', 'old.save'))
            ->toBe('test-map:mentor/briefing');
});

it('reports what a moved event leaves behind and undoes the whole move as one step', function () {
    $root = npcPageProject(['class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger', 'whenBlocked' => 'Not yet.',
        'data' => ['mode' => 'action', 'reusable' => false, 'scriptId' => 'briefing'], 'speakers' => ['mentor']]);
    $manifest = (string) file_get_contents($root . '/assets/Data/save-compatibility.php');
    $session = EditorSession::open($root);
    $moved = $session->adoptEventOnNpc('test-map', $session->readMap('test-map')['revision'], 'E');

    expect($moved['npc'])->toBe('mentor')->and($moved['dropped'])->toBe(['its blocked message'])
        ->and($moved['alias'])->toBe(['from' => 'test-map:E', 'to' => 'test-map:mentor/briefing'])
        ->and(getSessionMap($session)->getEventDefinition('E'))->toBeNull();
    $session->undo();
    expect(getSessionMap($session)->getEventDefinition('E'))->not->toBeNull()
        ->and(getSessionMap($session)->getNpcs()->get(0)->getDialogue())->toBe([['text' => 'Ready when you are.']])
        ->and((string) file_get_contents($root . '/assets/Data/save-compatibility.php'))->toBe($manifest)
        ->and(fn() => $session->adoptEventOnNpc('test-map', $session->readMap('test-map')['revision'], 'E', 'nobody'))
            ->toThrow(SessionRefusal::class, 'has no NPC nobody');
});

it('carries an older save alias through a repeating event that moves onto its NPC', function () {
    $root = npcPageProject(['class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
        'data' => ['mode' => 'action', 'scriptId' => 'briefing'], 'speakers' => ['mentor']]);
    file_put_contents($root . '/assets/Data/save-compatibility.php', "<?php\n\nreturn [\n  'contentVersion' => 0,\n  'migrations' => [],\n"
        . "  'aliases' => [\n    'one_shot_events' => [\n      ['from' => 'old-map:B', 'to' => 'test-map:E'],\n    ],\n  ],\n  'tombstones' => [],\n];\n");

    // The repeating page always answers, so the plain lines it hides go with it, as validation requires.
    $result = ChangeBatch::apply($root, [['method' => 'npc.adoptEvent', 'params' => ['map' => 'test-map', 'marker' => 'E']],
        ['method' => 'npc.remove', 'params' => ['map' => 'test-map', 'index' => 0, 'key' => ['target' => 'npc', 'field' => 'variant1Conditions', 'frame' => []]]]]);
    $manifest = Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest::fromProjectRoot($root);

    expect($result['status'])->toBe('saved')
        ->and($manifest->resolveOneShotEvent('old-map:B', 'old.save'))->toBe('test-map:mentor/briefing')
        ->and(NpcPages::fromEntry((require $root . '/assets/Maps/test-map/test-map.data.php')['npcs'][0])->pages[0]->reusable)->toBeTrue();
});

it('names the NPC who delivers a story event for an interface to offer the move, and nobody for an object or arrival', function () {
    $session = EditorSession::open(npcPageProject(['class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
        'data' => ['mode' => 'action', 'scriptId' => 'briefing'], 'speakers' => ['mentor']]));
    $arrival = EditorSession::open(npcPageProject(['class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
        'data' => ['mode' => 'auto', 'scriptId' => 'briefing'], 'speakers' => ['mentor']]));

    expect($session->readInspector('test-map', 'E')['deliveredBy'])->toBe(['npc' => 'mentor', 'name' => 'Mentor'])
        ->and($arrival->readInspector('test-map', 'E')['deliveredBy'])->toBeNull()
        ->and($session->readInspector('test-map')['deliveredBy'])->toBeNull();
});
