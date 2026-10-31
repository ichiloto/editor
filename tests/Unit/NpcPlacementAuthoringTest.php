<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Field\NpcPlacementFields;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandRegistry;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;

/**
 * An NPC's named placements (`npcs[].placements`) and the
 * `commit_npc_placement` command that saves one, as the Engine's
 * `CinematicCommandSchema::export()['npcPlacements']` defines them: authored
 * on the NPC with the Engine's reader and physical floor preflight judging
 * every edit before it is kept, picked by the NPC the command selects,
 * edited in their own source with undo and redo byte for byte, and checked
 * against the map the script runs on. Every project here is a synthetic
 * fixture.
 */

afterEach(function (): void {
    ScriptCommandRegistry::reset();
});

/**
 * The harbour fixture with walls solid and floor clear, its keeper given the
 * NPC fields and an event `S` running the given script.
 *
 * @param array<string, mixed> $keeper
 * @param list<array<string, mixed>> $script
 * @return array{string, string} The project root and the map's data path.
 */
function placementProject(array $keeper = [], array $script = []): array
{
    $root = cutsceneProject();
    $mapPath = $root . '/assets/Maps/harbour/harbour.data.php';
    $map = require $mapPath;
    $map['npcs'] = [['id' => 'keeper', 'name' => 'Keeper', 'sprite' => 'K', 'x' => 4, 'y' => 1, ...$keeper]];
    if ($script !== []) {
        $map['events']['S'] = ['class' => ScriptEventTrigger::class, 'data' => ['script' => $script]];
    }
    file_put_contents($mapPath, "<?php\n\n// Harbour, authored by hand.\nreturn " . var_export($map, true) . ";\n");
    file_put_contents($root . '/assets/Maps/collisions.php', "<?php\n\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\n\n"
        . "return ['#' => CollisionType::SOLID, ' ' => CollisionType::NONE, '=' => CollisionType::NONE];\n");

    return [$root, $mapPath];
}

/** One of the keeper's root rows, by its field id. */
function placementRow(EditorSession $session, string $field): array
{
    return array_find($session->readNpc('harbour', 0)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === $field)
        ?? throw new RuntimeException('Missing NPC row ' . $field);
}

function applyPlacementRow(EditorSession $session, string $field, string $value): void
{
    $session->applyNpc('harbour', $session->readNpc('harbour', 0)['revision'], 0, placementRow($session, $field)['key'], $value);
}

/** One row of the harbour event script, by its label. */
function placementCommandRow(EditorSession $session, string $name, int $command = 0): array
{
    return array_find($session->readInspector('harbour', 'S', ['script'])['rows'],
        static fn(array $row): bool => ($row['name'] ?? '') === $name && ($row['entry'] ?? null) === [$command])
        ?? throw new RuntimeException('Missing command row ' . $name);
}

/** @return list<string> The validation messages naming a needle. */
function placementIssueMessages(string $root, string $needle): array
{
    return array_values(array_filter(array_map(static fn($issue): string => $issue->message, validateProject($root)),
        static fn(string $message): bool => str_contains($message, $needle)));
}

it('authors named placements on an NPC under the Engine\'s floor preflight, with undo and redo byte for byte', function (): void {
    [$root, $mapPath] = placementProject();
    $original = (string) file_get_contents($mapPath);
    $session = EditorSession::open($root);
    $heading = placementRow($session, NpcPlacementFields::PREFIX . 'List');

    // A new placement starts on the NPC's own cell, which is floor.
    $session->addNpcItem('harbour', $session->readNpc('harbour', 0)['revision'], 0, $heading['key']);
    expect(placementRow($session, 'placement0Id')['value'])->toBe('placement')
        ->and(placementRow($session, 'placement0X')['value'])->toBe('4')
        ->and(placementRow($session, 'placement0Facing')['options'] ?? null)->toBe(['north', 'south', 'east', 'west'])
        // A wall is no destination, and default is the NPC's own cell.
        ->and(fn() => applyPlacementRow($session, 'placement0X', '0'))->toThrow(SessionRefusal::class, 'clear physical floor')
        ->and(fn() => applyPlacementRow($session, 'placement0Id', 'default'))->toThrow(SessionRefusal::class)
        ->and(placementRow($session, 'placement0X')['value'])->toBe('4');

    applyPlacementRow($session, 'placement0Id', 'after-arrival');
    applyPlacementRow($session, 'placement0X', '8');
    applyPlacementRow($session, 'placement0Y', '5');
    applyPlacementRow($session, 'placement0Facing', 'west');
    // The NPC's own cell is its default placement: a wall is refused there too.
    expect(fn() => $session->moveNpc('harbour', $session->readNpc('harbour', 0)['revision'], 0, 0, 3))
        ->toThrow(SessionRefusal::class, 'clear physical floor');
    $session->saveMap('harbour');
    $edited = (string) file_get_contents($mapPath);

    expect($edited)->toContain('// Harbour, authored by hand.')
        ->and((require $mapPath)['npcs'][0])->toBe(['id' => 'keeper', 'name' => 'Keeper', 'sprite' => 'K', 'x' => 4, 'y' => 1,
            'placements' => ['after-arrival' => ['x' => 8, 'y' => 5, 'facing' => 'west']]])
        ->and(placementIssueMessages($root, 'placement'))->toBe([]);

    foreach (range(1, 5) as $step) { $session->undo(); }
    $session->saveMap('harbour');
    expect(file_get_contents($mapPath))->toBe($original);

    foreach (range(1, 5) as $step) { $session->redo(); }
    $session->saveMap('harbour');
    expect(file_get_contents($mapPath))->toBe($edited);
});

it('picks a commit_npc_placement placement among the selected NPC\'s and refuses another before writing', function (): void {
    [$root, $mapPath] = placementProject(['placements' => ['after-arrival' => ['x' => 8, 'y' => 5], 'quay' => ['x' => 6, 'y' => 3]]],
        [['type' => 'commit_npc_placement', 'npc' => 'keeper', 'placement' => 'default', 'note' => ['keep' => true]]]);
    $original = (string) file_get_contents($mapPath);
    $session = EditorSession::open($root);

    expect(placementCommandRow($session, 'NPC'))->toMatchArray(['value' => 'keeper', 'reference' => 'map_npcs'])
        ->and(placementCommandRow($session, 'Placement')['options'])->toBe(['', 'default', 'after-arrival', 'quay'])
        // Outside a command there is no NPC to list placements for.
        ->and($session->listReferences('harbour', ReferenceCatalog::NPC_PLACEMENTS))->toBe([]);

    $revision = $session->readInspector('harbour', 'S', ['script'])['revision'];
    expect(fn() => $session->applyInspector('harbour', $revision, placementCommandRow($session, 'Placement')['key'], 'lighthouse'))
        ->toThrow(SessionRefusal::class, 'not one this entry offers')
        ->and($session->undo()['label'])->toBeNull();

    $session->applyInspector('harbour', $revision, placementCommandRow($session, 'Placement')['key'], 'quay');
    $session->saveMap('harbour');
    $edited = (string) file_get_contents($mapPath);
    expect($edited)->toContain('// Harbour, authored by hand.')
        ->and((require $mapPath)['events']['S']['data']['script'][0])
        ->toBe(['type' => 'commit_npc_placement', 'npc' => 'keeper', 'placement' => 'quay', 'note' => ['keep' => true]]);

    $session->undo();
    $session->saveMap('harbour');
    expect(file_get_contents($mapPath))->toBe($original);
    $session->redo();
    $session->saveMap('harbour');
    expect(file_get_contents($mapPath))->toBe($edited);
});

it('keeps a placement, and the NPC, that a command commits to', function (): void {
    [$root, $mapPath] = placementProject(['placements' => ['quay' => ['x' => 6, 'y' => 3], 'spare' => ['x' => 7, 'y' => 3]]],
        [['type' => 'commit_npc_placement', 'npc' => 'keeper', 'placement' => 'quay']]);
    $original = (string) file_get_contents($mapPath);
    $session = EditorSession::open($root);
    $revision = static fn(): int => $session->readNpc('harbour', 0)['revision'];

    expect(fn() => applyPlacementRow($session, 'placement0Id', 'pier'))->toThrow(SessionRefusal::class, 'keeps its placement "quay"')
        ->and(fn() => $session->removeNpcItem('harbour', $revision(), 0, placementRow($session, 'placement0X')['key']))
        ->toThrow(SessionRefusal::class, 'harbour event S')
        // The command's NPC field names it, so it stays too.
        ->and(fn() => $session->deleteNpc('harbour', $revision(), 0))->toThrow(SessionRefusal::class, 'harbour event S')
        ->and(fn() => $session->applyNpc('harbour', $revision(), 0, placementRow($session, 'id')['key'], 'warden'))
        ->toThrow(SessionRefusal::class, 'keeps the id "keeper"');

    // A placement nothing commits to goes freely.
    $session->removeNpcItem('harbour', $revision(), 0, placementRow($session, 'placement1X')['key']);
    $session->saveMap('harbour');
    expect((require $mapPath)['npcs'][0]['placements'])->toBe(['quay' => ['x' => 6, 'y' => 3]]);
    $session->undo();
    $session->saveMap('harbour');
    expect(file_get_contents($mapPath))->toBe($original);
});

it('validates placement commands against the map the script runs on, and placements against its floor', function (): void {
    [$root, $mapPath] = placementProject(['placements' => ['quay' => ['x' => 6, 'y' => 3]]], [
        ['type' => 'commit_npc_placement', 'npc' => 'keeper', 'placement' => 'quay'],
        ['type' => 'commit_npc_placement', 'npc' => 'keeper', 'placement' => 'default'],
        ['type' => 'commit_npc_placement', 'npc' => 'keeper', 'placement' => 'lighthouse'],
    ]);
    expect(placementIssueMessages($root, 'placement'))
        ->toBe(['Its commit_npc_placement command commits NPC "keeper" to placement "lighthouse", which that NPC does not declare on harbour.']);

    // Hand-written onto a wall: the Engine's preflight reports it, as the game would refuse the map.
    $map = require $mapPath;
    $map['npcs'][0]['placements']['quay'] = ['x' => 0, 'y' => 3];
    $map['npcs'][] = ['id' => 'porter', 'name' => 'Porter', 'sprite' => 'P', 'x' => 10, 'y' => 1];
    $map['events']['S']['data']['script'] = [['type' => 'commit_npc_placement', 'npc' => 'porter', 'placement' => 'default']];
    file_put_contents($mapPath, "<?php\n\nreturn " . var_export($map, true) . ";\n");

    expect(placementIssueMessages($root, 'placement'))->toEqualCanonicalizing([
        'NPC placement quay must be current clear physical floor.',
        'Its commit_npc_placement command commits NPC "porter" to placement "default", but that NPC declares no placements on harbour.',
    ]);
});

it('offers a cinematic\'s placements from every map it runs on', function (): void {
    [$root] = placementProject(['placements' => ['quay' => ['x' => 6, 'y' => 3]]]);
    $script = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    file_put_contents($script, "<?php\n\nreturn " . var_export([['type' => 'commit_npc_placement', 'npc' => 'keeper', 'placement' => 'quay']], true) . ";\n");
    $data = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.data.php';
    file_put_contents($data, str_replace("'skip' => ['policy' => 'authored'],", "'skip' => ['policy' => 'forbidden'],", (string) file_get_contents($data)));
    $session = EditorSession::open($root);
    $row = array_find($session->readDatabaseRecord('cutscenes/cinematic', 0, ['commands'])['rows'],
        static fn(array $row): bool => ($row['name'] ?? '') === 'Placement') ?? throw new RuntimeException('No placement row.');

    expect($row['options'])->toBe(['', 'default', 'quay'])
        ->and(fn() => $session->applyDatabaseRecord('cutscenes/cinematic', 0, $row['key'], 'pier'))->toThrow(SessionRefusal::class, 'not one this entry offers');
});
