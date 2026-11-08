<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;

/**
 * `transfer_through_door`: a script leaves through a door a map already has,
 * and the door's TransferPlayerTrigger owns where the player arrives and
 * faces. The command names the door's map and marker, chosen among that map's
 * doors, never coordinates. The harbour fixture is synthetic.
 */

/**
 * The harbour with two doors (D, E), a script event T that is not a door, and
 * an event S running the given script.
 *
 * @param list<array<string, mixed>> $script
 * @return array{string, string} The project root and the map's data path.
 */
function doorTransferProject(array $script): array
{
    $root = cutsceneProject();
    $mapPath = $root . '/assets/Maps/harbour/harbour.data.php';
    $map = require $mapPath;
    $door = static fn(string $to, int $x): array => ['class' => TransferPlayerTrigger::class,
        'data' => ['destinationMap' => $to, 'spawnPoint' => ['x' => $x, 'y' => 1], 'spawnSprite' => ['South']]];
    // D leads out to the test map; E back onto the harbour.
    $map['events']['D'] = $door('test-map', 1);
    $map['events']['E'] = $door('harbour', 5);
    $map['events']['T'] = ['class' => ScriptEventTrigger::class, 'data' => ['script' => [['type' => 'wait', 'seconds' => 1]]]];
    $map['events']['S'] = ['class' => ScriptEventTrigger::class, 'data' => ['script' => $script]];
    file_put_contents($mapPath, "<?php\n\n// Harbour, authored by hand.\nreturn " . var_export($map, true) . ";\n");
    // Each event stands on its painted cell: D and E on the quay's south edge, T and S beside them.
    $rows = array_fill(0, 8, str_repeat(' ', 20));
    $rows[6] = '  D E T S' . str_repeat(' ', 11);
    file_put_contents($root . '/assets/Maps/harbour/harbour.event.php',
        "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n" . implode("\n", $rows) . "\nICHILOTO_EVENT_MAP;\n");

    return [$root, $mapPath];
}

function doorCommandRow(EditorSession $session, string $name): array
{
    return array_find($session->readInspector('harbour', 'S', ['script'])['rows'],
        static fn(array $row): bool => ($row['name'] ?? '') === $name && ($row['entry'] ?? null) === [0])
        ?? throw new RuntimeException('Missing command row ' . $name);
}

/** @return list<string> The validation messages naming a door. */
function doorIssueMessages(string $root): array
{
    return array_values(array_filter(array_map(static fn($issue): string => $issue->message, validateProject($root)),
        static fn(string $message): bool => str_contains($message, 'door')));
}

it('picks the door among the named map\'s doors only, and edits it in the script\'s own source', function (): void {
    [$root, $mapPath] = doorTransferProject([['type' => 'transfer_through_door', 'map' => 'harbour', 'event' => 'D']]);
    $original = (string) file_get_contents($mapPath);
    $session = EditorSession::open($root);

    expect(doorCommandRow($session, 'Door On Map'))->toMatchArray(['value' => 'harbour', 'reference' => 'maps'])
        // The script event T is not a door, so it is not offered.
        ->and(doorCommandRow($session, 'Door')['options'])->toBe(['', 'D', 'E'])
        ->and(new ReferenceCatalog(getEditorProperty(deletionEditor($root), 'workspace'))->valuesFor(ReferenceCatalog::MAP_DOORS))
            ->toContain('harbour:D', 'harbour:E')->not->toContain('harbour:T', 'harbour:S');

    $revision = $session->readInspector('harbour', 'S', ['script'])['revision'];
    expect(fn() => $session->applyInspector('harbour', $revision, doorCommandRow($session, 'Door')['key'], 'T'))
        ->toThrow(SessionRefusal::class, 'not one this entry offers');

    $session->applyInspector('harbour', $revision, doorCommandRow($session, 'Door')['key'], 'E');
    $session->saveMap('harbour');
    expect((string) file_get_contents($mapPath))->toContain('// Harbour, authored by hand.')
        ->and((require $mapPath)['events']['S']['data']['script'][0])
        ->toBe(['type' => 'transfer_through_door', 'map' => 'harbour', 'event' => 'E']);

    $session->undo();
    $session->saveMap('harbour');
    expect(file_get_contents($mapPath))->toBe($original);
});

it('reports a door transfer naming no door on its map, or an event that is not a door', function (string $event, bool $valid, string $reason): void {
    [$root] = doorTransferProject([['type' => 'transfer_through_door', 'map' => 'harbour', 'event' => $event]]);
    $messages = doorIssueMessages($root);

    expect($messages)->toBe($valid ? [] : ['It leaves through a door the game cannot use: ' . $reason]);
})->with([
    'a door' => ['D', true, ''],
    'a missing marker' => ['Q', false, "Door 'Q' was not found on map 'harbour'."],
    'a script event' => ['T', false, "Event 'T' on map 'harbour' is not a transfer door."],
]);

it('follows a script through the door onto the map the door leads to', function (string $event, array $expected): void {
    [$root] = doorTransferProject([
        ['type' => 'transfer_through_door', 'map' => 'harbour', 'event' => $event],
        ['type' => 'commit_npc_placement', 'npc' => 'keeper', 'placement' => 'default'],
    ]);
    $messages = array_values(array_filter(array_map(static fn($issue): string => $issue->message, validateProject($root)),
        static fn(string $message): bool => str_contains($message, 'which is not on')));

    expect($messages)->toBe($expected);
})->with([
    'out to a map without the keeper' => ['D', ['Its commit_npc_placement command names NPC "keeper", which is not on test-map.']],
    'back onto the harbour' => ['E', []],
]);

it('offers the door command in cinematics and their finalizers with the field script\'s rows', function (): void {
    $keys = static fn(array $fields): array => array_map(static fn($field): string => $field->key, $fields);
    $door = Ichiloto\Engine\Field\MapDoorReference::COMMAND;

    expect($keys(Ichiloto\Editor\Database\CutsceneSchemas::cinematicCommandVariants()[$door] ?? []))->toBe(['map', 'event'])
        ->and($keys(Ichiloto\Editor\Database\CutsceneSchemas::finalizerCommandList()->variants[$door] ?? []))->toBe(['map', 'event']);
});
