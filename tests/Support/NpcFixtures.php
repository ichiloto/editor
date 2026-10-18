<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;

/**
 * A throwaway project whose 12x5 fixture map carries the given NPCs.
 *
 * @param array<int, mixed> $npcs The npcs block.
 */
function npcSessionProject(array $npcs): string
{
    $root = makeTemporaryProject('ichiloto-npc-session-');
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $path;
    $data['npcs'] = $npcs;
    file_put_contents($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");

    return $root;
}

/** The ids of the map's NPCs as the session reads them. */
function npcSessionIds(EditorSession $session): array
{
    return array_column($session->readMap('test-map')['npcs'], 'id');
}

/** The row with a field id in an NPC read. */
function npcSessionRow(array $read, string $fieldId): array
{
    return array_find($read['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === $fieldId)
        ?? throw new RuntimeException("No NPC row {$fieldId}.");
}

/**
 * Writes reusable event scripts into a project, each as the bare command list
 * `assets/Events/<id>.php` returns.
 *
 * @param array<string, list<array<string, mixed>>> $scripts Commands by script id.
 */
function writeNpcEventScripts(string $root, array $scripts): void
{
    foreach ($scripts as $id => $commands) {
        file_put_contents($root . '/assets/Events/' . $id . '.php', "<?php\n\nreturn " . var_export($commands, true) . ";\n");
    }
}

/**
 * Ways a map reaches event script `b`, whose move_route alone names NPC
 * `mara`, through `common_event` chains: Bob's script, or a map event, starts
 * `a`, and `a` starts `b`; in the cycle `b` starts `a` again.
 *
 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, list<array<string, mixed>>>}>
 *   Bob's extra fields, the map's events and the event scripts, per case.
 */
function npcEventScriptChains(): array
{
    $startA = [['type' => 'common_event', 'id' => 'a']];
    $route = ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'mara', 'steps' => [['direction' => 'north']]];
    $event = static fn(array $data): array => ['T' => ['class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger', 'data' => [...$data, 'mode' => 'action']]];
    $chain = ['a' => [['type' => 'common_event', 'id' => 'b']], 'b' => [$route]];

    return [
        'an NPC script through a to b' => [['script' => $startA], [], $chain],
        'an event inline script through a to b' => [[], $event(['script' => $startA]), $chain],
        'an event script id through a to b' => [[], $event(['scriptId' => 'a']), $chain],
        'a cycle between a and b' => [['script' => $startA], [], [...$chain, 'b' => [['type' => 'common_event', 'id' => 'a'], $route]]],
    ];
}
