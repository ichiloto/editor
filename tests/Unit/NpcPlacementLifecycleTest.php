<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Core\EngineStoryEvent;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;

/**
 * Two NPCs on one tile, judged where their scenes can leave them at rest
 * ({@see \Ichiloto\Editor\Field\NpcPlacementLifecycle}): a guide's default
 * and an arrival's default may be one tile when the scene that brings the
 * arrival commits the guide elsewhere first and walks the arrival on to its
 * own placement. A tile both can show on is still refused, in validation,
 * in a change set and in authoring. Every project here is a synthetic
 * fixture.
 */

/**
 * The harbour fixture with walls solid and floor clear, the given NPCs, map
 * events running the given scripts, and reusable event scripts.
 *
 * @param list<array<string, mixed>> $npcs
 * @param array<string, list<array<string, mixed>>> $events Inline scripts by event marker.
 * @param array<string, list<array<string, mixed>>> $scripts Reusable event scripts by id.
 */
function lifecycleHarbour(array $npcs, array $events = [], array $scripts = []): string
{
    $root = cutsceneProject();
    $folder = $root . '/assets/Maps/harbour';
    $map = require $folder . '/harbour.data.php';
    $map['npcs'] = $npcs;
    $layer = array_fill(0, 8, str_repeat(' ', 20));
    $column = 10;
    foreach ($events as $marker => $script) {
        $map['events'][$marker] = ['class' => ScriptEventTrigger::class, 'data' => ['script' => $script, 'mode' => 'action']];
        $layer[5][$column] = $marker;
        $column += 2;
    }
    file_put_contents($folder . '/harbour.data.php', "<?php\n\nreturn " . var_export($map, true) . ";\n");
    file_put_contents($folder . '/harbour.event.php', "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n" . implode("\n", $layer) . "\nICHILOTO_EVENT_MAP;\n");
    file_put_contents($root . '/assets/Maps/collisions.php', "<?php\n\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\n\n"
        . "return ['#' => CollisionType::SOLID, ' ' => CollisionType::NONE, '=' => CollisionType::NONE];\n");
    writeNpcEventScripts($root, $scripts);

    return $root;
}


/**
 * A guide and an arrival who both start on the guide's tile (4,1), both
 * talkable; the guide can step aside to (6,3) and the arrival, when given
 * placements, can walk on to them.
 *
 * @param array<string, array{x: int, y: int}>|null $arrivalPlacements
 * @return list<array<string, mixed>>
 */
function lifecycleCast(?array $arrivalPlacements = null): array
{
    return [
        ['id' => 'guide', 'name' => 'Guide', 'sprite' => 'G', 'x' => 4, 'y' => 1, 'dialogue' => [['text' => 'Wait by the stairs.']],
            'placements' => ['waiting' => ['x' => 6, 'y' => 3]]],
        ['id' => 'arrival', 'name' => 'Arrival', 'sprite' => 'A', 'x' => 4, 'y' => 1, 'dialogue' => [['text' => 'We made it.']],
            'conditions' => [['type' => 'event', 'name' => 'arrived']],
            ...($arrivalPlacements === null ? [] : ['placements' => $arrivalPlacements])],
    ];
}

/** The guide steps aside before the arrival appears, as one scene. */
function lifecycleStepAside(): array
{
    return [
        ['type' => 'commit_npc_placement', 'npc' => 'guide', 'placement' => 'waiting'],
        ['type' => 'record_event', 'name' => 'arrived'],
    ];
}

/** A porter with nowhere to be walked, at (10,1), with extra fields. */
function lifecyclePorter(array $fields = []): array
{
    return ['id' => 'porter', 'name' => 'Porter', 'sprite' => 'P', 'x' => 10, 'y' => 1, 'dialogue' => [['text' => 'Ships in.']], ...$fields];
}

/** @return list<string> The tile messages validation reports for the harbour's NPCs. */
function lifecycleTileIssues(string $root): array
{
    return array_values(array_map(static fn($issue): string => $issue->severity->name . ': ' . $issue->message, array_filter(validateProject($root),
        static fn($issue): bool => str_contains($issue->message, 'shares tile') || str_contains($issue->message, 'same tile'))));
}

it('still refuses a tile two NPCs can both show on', function (array $cast, array $events, string $message): void {
    expect(lifecycleTileIssues(lifecycleHarbour($cast, $events)))->toBe(['ERROR: ' . $message]);
})->with([
    'no scene moves either' => [lifecycleCast(), [], 'It shares tile (4, 1) with NPC Arrival.'],
    'the arrival walks on to rest on the guide\'s tile' => [lifecycleCast(['rest' => ['x' => 4, 'y' => 1]]), ['S' => [
        ['type' => 'record_event', 'name' => 'arrived'],
        ['type' => 'commit_npc_placement', 'npc' => 'arrival', 'placement' => 'rest'],
    ]], 'It shares tile (4, 1) with NPC Arrival at its rest placement.'],
    'the guide steps aside on one arm only' => [lifecycleCast(), ['S' => [
        ['type' => 'branch', 'conditions' => [['type' => 'switch', 'name' => 'hurry']],
            'then' => [['type' => 'commit_npc_placement', 'npc' => 'guide', 'placement' => 'waiting']]],
        ['type' => 'record_event', 'name' => 'arrived'],
    ]], 'It shares tile (4, 1) with NPC Arrival.'],
    'the guide steps aside on another map' => [lifecycleCast(), ['S' => [
        ['type' => 'transfer', 'map' => 'test-map', 'x' => 2, 'y' => 2],
        ['type' => 'commit_npc_placement', 'npc' => 'guide', 'placement' => 'waiting'],
        ['type' => 'record_event', 'name' => 'arrived'],
    ]], 'It shares tile (4, 1) with NPC Arrival.'],
]);

it('accepts a shared default tile the scene order keeps apart', function (array $cast, array $events, array $scripts): void {
    expect(lifecycleTileIssues(lifecycleHarbour($cast, $events, $scripts)))->toBe([]);
})->with([
    'the guide steps aside before the arrival appears' => [lifecycleCast(), ['S' => lifecycleStepAside()], []],
    'the arrival walks on to its own placement, through a reusable script' => [lifecycleCast(['rest' => ['x' => 8, 'y' => 3]]),
        ['S' => [['type' => 'common_event', 'id' => 'arrivals']]],
        ['arrivals' => [['type' => 'record_event', 'name' => 'arrived'], ['type' => 'commit_npc_placement', 'npc' => 'arrival', 'placement' => 'rest']]]],
]);

it('refuses the shared default again when anything else can record the arrival or move the guide', function (array $cast, array $events): void {
    expect(lifecycleTileIssues(lifecycleHarbour($cast, ['S' => lifecycleStepAside(), ...$events])))
        ->toBe(['ERROR: It shares tile (4, 1) with NPC Arrival.']);
})->with([
    'another event records it without moving anyone' => [lifecycleCast(), ['T' => [['type' => 'record_event', 'name' => 'arrived']]]],
    'an NPC writes it through its sets' => [[...lifecycleCast(), lifecyclePorter(['sets' => [['type' => 'event', 'name' => 'arrived']]])], []],
    'a script writes the guide\'s placement as a variable' => [lifecycleCast(),
        ['T' => [['type' => 'set_variable', 'name' => 'npc-placement:harbour:guide', 'value' => 'default']]]],
]);

it('preserves overlap errors when the Engine can record the arrival independently', function (EngineStoryEvent $eventKind): void {
    $event = $eventKind->getName('synthetic-arrival');
    $cast = lifecycleCast();
    $cast[1]['conditions'][0]['name'] = $event;
    $commands = lifecycleStepAside();
    $commands[1]['name'] = $event;
    expect(lifecycleTileIssues(lifecycleHarbour($cast, ['S' => $commands])))
        ->toBe(['ERROR: It shares tile (4, 1) with NPC Arrival.']);
})->with(EngineStoryEvent::cases());

it('lets a change set touching the map pass, and keeps authoring to the same rule', function (): void {
    $session = EditorSession::open(lifecycleHarbour([...lifecycleCast(), lifecyclePorter()], ['S' => lifecycleStepAside()]));
    $set = static function (int $index, string $field, string $value) use ($session): void {
        $read = $session->readNpc('harbour', $index);
        $session->applyNpc('harbour', $read['revision'], $index, npcSessionRow($read, $field)['key'], $value);
    };

    // The arrival moves off the guide's tile and back: the guide has always stepped aside by then.
    $set(1, 'x', '5');
    $set(1, 'x', '4');
    $session->saveMap('harbour');
    $session->beginChanges();
    $set(0, 'sprite', 'H');
    expect($session->commitChanges(checkOnly: true))->toMatchArray(['status' => 'checked', 'blocking' => 0]);

    // No scene steps the guide aside for the porter.
    expect(fn() => $set(2, 'x', '4'))->toThrow(SessionRefusal::class, 'Guide already stands at 4,1 while both can be visible.');
    // The guide's waiting tile is taken whenever the guide rests there.
    $set(2, 'y', '3');
    expect(fn() => $set(2, 'x', '6'))->toThrow(SessionRefusal::class, 'Guide already stands at 6,3 at its waiting placement')
        ->and($session->readMap('harbour')['npcs'][2])->toMatchArray(['x' => 10, 'y' => 3]);
});
