<?php

declare(strict_types=1);

use Ichiloto\Editor\Inspector\MapSpeakerFields;
use Ichiloto\Editor\Session\ChangeBatch;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * An event names the NPCs who speak it, chosen from its map's NPCs, and the
 * Engine's rule says when a speaker stands away from it. Synthetic project:
 * the graphics test map's inspection E at 3,0, a clerk beside it, a guard
 * across the room and a passer-by with no id.
 */
function eventSpeakerProject(): string
{
    $root = mapGraphicsProject();
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($path, str_replace("'events' => [],", "'events' => [
        'E' => ['class' => 'Ichiloto\\\\Engine\\\\Events\\\\Triggers\\\\DialogueEventTrigger', 'data' => ['dialogue' => [['name' => 'Clerk', 'text' => 'Next.']]]],
    ], 'npcs' => [
        ['id' => 'guard', 'name' => 'Guard', 'sprite' => '', 'x' => 0, 'y' => 1],
        ['id' => 'clerk', 'name' => 'Clerk', 'sprite' => '', 'x' => 2, 'y' => 1],
        ['name' => 'Passer-by', 'sprite' => '', 'x' => 1, 'y' => 0],
    ],", (string) file_get_contents($path)));

    return $root;
}

/** @return list<array<string, mixed>> The event's speaker rows, heading first. */
function readSpeakerRows(EditorSession $session): array
{
    return array_values(array_filter($session->readInspector('test-map', 'E')['rows'],
        static fn(array $row): bool => ($row['key']['target'] ?? null) === MapSpeakerFields::TARGET));
}

/** @return list<string> */
function readSpeakerCodes(EditorSession $session): array
{
    return array_values(array_map(static fn(array $issue): string => (string) $issue['code'], array_filter(
        $session->validateProject(['test-map'])['issues'], static fn(array $issue): bool => $issue['code'] === 'event.speaker')));
}

it('adds the nearest NPC as a speaker, lets the author choose another from the map, and removes one', function () {
    $session = EditorSession::open(eventSpeakerProject());
    [$heading] = readSpeakerRows($session);
    expect($heading['label'])->toBe('Speakers · 0')->and($heading['value'])->toBe('No one speaks it')->and($heading['list'])->toBe(['index' => 0]);

    $session->addInspectorListEntry('test-map', $session->readMap('test-map')['revision'], $heading['key']);
    $rows = readSpeakerRows($session);
    expect(getSessionMap($session)->getEventDefinition('E')['speakers'])->toBe(['clerk'])
        ->and($rows[1]['value'])->toBe('clerk')
        // NPCs without an id cannot be named, so they are not offered.
        ->and($rows[1]['options'])->toBe(['guard', 'clerk'])
        ->and($rows[1]['optionLabels'])->toBe(['Guard (guard)', 'Clerk (clerk)']);

    $session->applyInspector('test-map', $session->readMap('test-map')['revision'], $rows[1]['key'], 'guard');
    expect(getSessionMap($session)->getEventDefinition('E')['speakers'])->toBe(['guard'])
        ->and(fn() => $session->applyInspector('test-map', $session->readMap('test-map')['revision'], $rows[1]['key'], 'nobody'))
            ->toThrow(SessionRefusal::class, 'choose one of: guard, clerk');

    $session->removeInspectorListEntry('test-map', $session->readMap('test-map')['revision'], readSpeakerRows($session)[1]['key']);
    expect(getSessionMap($session)->getEventDefinition('E'))->not->toHaveKey('speakers');
    $session->undo();
    expect(getSessionMap($session)->getEventDefinition('E')['speakers'])->toBe(['guard']);
});

it('reports a speaker standing away from the event through the Engine rule, and clears once they stand beside it', function () {
    $session = EditorSession::open(eventSpeakerProject());
    $session->addInspectorListEntry('test-map', $session->readMap('test-map')['revision'], readSpeakerRows($session)[0]['key']);
    expect(readSpeakerCodes($session))->toBe([]);
    $session->applyInspector('test-map', $session->readMap('test-map')['revision'], readSpeakerRows($session)[1]['key'], 'guard');
    expect(readSpeakerCodes($session))->toBe(['event.speaker']);
    $session->moveNpc('test-map', $session->readMap('test-map')['revision'], 0, 3, 1);
    expect(readSpeakerCodes($session))->toBe([]);
});

it('protects a speaker id from rename and deletion while an event names it', function () {
    $session = EditorSession::open(eventSpeakerProject());
    $session->addInspectorListEntry('test-map', $session->readMap('test-map')['revision'], readSpeakerRows($session)[0]['key']);
    $revision = $session->readMap('test-map')['revision'];

    expect(fn() => $session->deleteNpc('test-map', $revision, 1))->toThrow(SessionRefusal::class, 'test-map event E (speaker)')
        ->and(fn() => $session->applyNpc('test-map', $revision, 1, npcSessionRow($session->readNpc('test-map', 1), 'id')['key'], 'registrar'))
            ->toThrow(SessionRefusal::class, 'test-map event E (speaker)');
});

it('names speakers through a batch, which writes them as authored and refuses a speaker left away from the event', function () {
    $root = eventSpeakerProject();
    $key = ['target' => MapSpeakerFields::TARGET, 'marker' => 'E'];
    $away = ChangeBatch::apply($root, [['method' => 'inspector.add', 'params' => ['map' => 'test-map', 'key' => $key]],
        ['method' => 'inspector.apply', 'params' => ['map' => 'test-map', 'key' => [...$key, 'field' => MapSpeakerFields::SPEAKER_FIELD, 'index' => 0], 'value' => 'guard']]]);
    expect($away['status'])->toBe('refused')->and(array_column($away['issues'], 'code'))->toContain('event.speaker')
        ->and((string) file_get_contents($root . '/assets/Maps/test-map/test-map.data.php'))->not->toContain("'speakers'");

    $beside = ChangeBatch::apply($root, [['method' => 'inspector.add', 'params' => ['map' => 'test-map', 'key' => $key]]]);
    expect($beside['status'])->toBe('saved');
    $source = (string) file_get_contents($root . '/assets/Maps/test-map/test-map.data.php');
    expect($source)->toContain("'speakers'")
        ->and(getSessionMap(EditorSession::open($root))->getEventDefinition('E')['speakers'])->toBe(['clerk']);
});
