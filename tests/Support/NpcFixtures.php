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
