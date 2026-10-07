<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * An NPC's graphical character sheet, set from the graphical editor's NPC
 * page: a picture picked from the project's images, checked as the game will
 * read it, one undo step, and never one of the terminal's rows.
 */

/** A session on the graphics test map with one NPC and a single-character sheet (3 x 4 frames). */
function npcFieldSheetSession(): array
{
    $root = mapGraphicsProject();
    writeTilesetTestPng($root . '/assets/Graphics/Characters/$Guard.png', 144, 192);
    writeTilesetTestPng($root . '/assets/Graphics/Characters/Odd.png', 50, 50);
    $session = EditorSession::open($root);
    $session->createNpc('test-map', $session->readMap('test-map')['revision'], 2, 1, 'Gate Guard');

    return [$session, 0];
}

function findFieldSheetRow(EditorSession $session, int $index): ?array
{
    return array_find($session->readNpc('test-map', $index)['rows'], static fn(array $row): bool => $row['label'] === 'Field Sheet');
}

it('shows the field sheet as a picture to pick, after the terminal appearance', function () {
    [$session, $index] = npcFieldSheetSession();
    $row = findFieldSheetRow($session, $index);
    $labels = array_column($session->readNpc('test-map', $index)['rows'], 'label');

    expect($row)->toMatchArray(['kind' => 'reference', 'reference' => 'png_assets', 'value' => '',
            'media' => ['kind' => 'image', 'root' => 'assets'], 'noneLabel' => 'None: the glyph shows'])
        ->and(array_search('Field Sheet', $labels, true))->toBeGreaterThan(array_search('Sprite', $labels, true));
});

it('sets and clears the sheet as one undo step each, keeping the terminal sprite', function () {
    [$session, $index] = npcFieldSheetSession();
    $sprite = $session->readNpc('test-map', $index)['npc']['sprite'];
    $key = findFieldSheetRow($session, $index)['key'];

    $session->applyNpc('test-map', $session->readMap('test-map')['revision'], $index, $key, 'Graphics/Characters/$Guard.png');
    expect(findFieldSheetRow($session, $index)['value'])->toBe('Graphics/Characters/$Guard.png')
        ->and($session->readNpc('test-map', $index)['npc']['sprite'])->toBe($sprite);
    $session->applyNpc('test-map', $session->readMap('test-map')['revision'], $index, $key, '');
    expect(findFieldSheetRow($session, $index)['value'])->toBe('');
    $session->undo();
    expect(findFieldSheetRow($session, $index)['value'])->toBe('Graphics/Characters/$Guard.png');
});

it('refuses a picture the game could not read as a character sheet, changing nothing', function () {
    [$session, $index] = npcFieldSheetSession();
    $key = findFieldSheetRow($session, $index)['key'];

    expect(fn() => $session->applyNpc('test-map', $session->readMap('test-map')['revision'], $index, $key, 'Graphics/Characters/Odd.png'))
        ->toThrow(SessionRefusal::class)
        ->and(fn() => $session->applyNpc('test-map', $session->readMap('test-map')['revision'], $index, $key, 'Graphics/Characters/Missing.png'))
        ->toThrow(SessionRefusal::class)
        ->and(findFieldSheetRow($session, $index)['value'])->toBe('');
});
