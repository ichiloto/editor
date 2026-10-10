<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * A map places named field resources by reference: the resource owns the art and the pivot, the placement only its
 * stable id, anchor and coverage. Synthetic project and resources only.
 */

/** @return array{string, string, EditorSession} A project with two named resources and one placement of the first. */
function createResourcePlacementProject(): array
{
    $root = mapGraphicsProject();
    writeTilesetTestPng($root . '/assets/Graphics/Props/Broadleaf.png', 12, 16);
    writeTilesetTestPng($root . '/assets/Graphics/Props/Conifer.png', 12, 16);
    is_dir($root . '/assets/Data/Presentation') || mkdir($root . '/assets/Data/Presentation', 0o777, true);
    file_put_contents($root . '/assets/Data/Presentation/field.php', "<?php\n\nreturn ['resources' => [\n"
        . "  'broadleaf' => ['name' => 'Broadleaf', 'pivot' => ['x' => 0.5, 'y' => 0.9], 'sprites2d' => ['asset' => 'Graphics/Props/Broadleaf.png']],\n"
        . "  'conifer' => ['name' => 'Conifer', 'pivot' => ['x' => 0.5, 'y' => 1.0], 'sprites2d' => ['asset' => 'Graphics/Props/Conifer.png']],\n"
        . "]];\n");
    $file = $root . '/assets/Maps/test-map/test-map.data.php';
    $source = str_replace("'events' => [],", "'events' => [], 'worldObjects' => [
        ['id' => 'grove-001', 'resource' => 'broadleaf', 'anchor' => ['x' => 1, 'y' => 1]],
    ], 'preserved' => abs(7),", (string) file_get_contents($file));
    file_put_contents($file, $source);

    return [$root, $file, EditorSession::open($root)];
}

/** @return array<string, mixed>|null The placement's row for a field. */
function findPlacementRow(EditorSession $session, string $field): ?array
{
    return array_find($session->readWorldObject('test-map', 'grove-001')['rows'], static fn($row): bool => ($row['key']['field'] ?? null) === $field);
}

it('previews a placed resource with the resource\'s own art, and watches that art', function () {
    [, , $session] = createResourcePlacementProject();
    $preview = $session->readWorldObjectPreview('test-map');
    $sprite = array_find($preview['update']['operations'], static fn($op): bool => ($op['kind'] ?? '') === 'sprite');

    expect($preview['diagnostics'])->toBe([])
        ->and($sprite['value']['id'])->toBe('world-object:test-map:grove-001')
        ->and($sprite['value']['pivot'])->toBe(['x' => .5, 'y' => .9])
        ->and($preview['assetPaths'])->toContain('Graphics/Props/Broadleaf.png');
});

it('offers a placement the resource to choose, never the art and pivot the resource owns', function () {
    [$root, , $session] = createResourcePlacementProject();
    $catalog = new ReferenceCatalog(ProjectWorkspace::fromProject($root));

    expect(findPlacementRow($session, 'resource'))->toMatchArray(['reference' => 'field_resources'])
        ->and(findPlacementRow($session, 'pivot.x'))->toBeNull()
        ->and(findPlacementRow($session, 'sprites2d.asset'))->toBeNull()
        ->and($catalog->valuesFor('field_resources'))->toBe(['broadleaf', 'conifer'])
        ->and($catalog->labelsFor('field_resources'))->toBe(['broadleaf' => 'Broadleaf (broadleaf)', 'conifer' => 'Conifer (conifer)']);
});

it('changes a placement\'s resource as one undoable step that keeps the rest of the source as authored', function () {
    [$root, $file, $session] = createResourcePlacementProject();
    $row = findPlacementRow($session, 'resource');
    $session->applyWorldObject('test-map', $session->readWorldObject('test-map', 'grove-001')['revision'], 'grove-001', $row['key'], 'conifer');

    expect($session->readMap('test-map')['worldObjects'][0])->toBe(['id' => 'grove-001', 'resource' => 'conifer', 'anchor' => ['x' => 1, 'y' => 1]]);
    $session->saveMap('test-map');
    expect((string) file_get_contents($file))->toContain("'resource' => 'conifer'")->toContain("'preserved' => abs(7)");
    $session->undo();
    expect($session->readMap('test-map')['worldObjects'][0]['resource'])->toBe('broadleaf')
        ->and(EditorSession::open($root)->readMap('test-map')['worldObjects'][0]['resource'])->toBe('conifer');
});

it('refuses a resource the catalogue does not name, before writing anything', function () {
    [, $file, $session] = createResourcePlacementProject();
    $before = (string) file_get_contents($file);
    $map = $session->readMap('test-map');
    $row = findPlacementRow($session, 'resource');

    expect(fn() => $session->applyWorldObject('test-map', $session->readWorldObject('test-map', 'grove-001')['revision'], 'grove-001', $row['key'], 'birch'))
        ->toThrow(SessionRefusal::class)
        ->and($session->readMap('test-map'))->toBe($map)
        ->and((string) file_get_contents($file))->toBe($before);
});
