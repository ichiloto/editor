<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Field\Validation\MapPassageAgreementRule;

/**
 * The Editor shares the Engine's passage agreement rule: it lists the cells
 * a collision view marks, words the findings as warnings that never refuse a
 * save, and authors what tiles show at ground level through the tileset
 * record, one undo step at a time. Synthetic project only: a 4 x 2 map of
 * ground tiles (2816) with an unclassified upper tile (5) at (1, 0) and
 * (3, 1), where the building glyphs at (1, 0), (1, 1) and (2, 1) are solid.
 */

function passageSession(): EditorSession
{
    $root = mapGraphicsProject();
    file_put_contents($root . '/assets/Maps/collisions.php', "<?php\n\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\n\n"
        . "return ['.' => CollisionType::NONE, ' ' => CollisionType::NONE, '/' => CollisionType::SOLID, 'x' => CollisionType::SOLID];\n");

    return EditorSession::open($root);
}

/** @return array<string, string> Appearance rows' values by label. */
function readAppearanceRows(EditorSession $session): array
{
    $rows = array_filter($session->readDatabaseRecord('tilesets', 0)['rows'],
        static fn(array $row): bool => str_starts_with((string) $row['label'], 'Appearance'));

    return array_column($rows, 'value', 'label');
}

it('marks solid cells that show only ground, and leaves a cell whose tile it cannot classify alone', function () {
    $agreement = passageSession()->readPassageAgreement('test-map');

    expect($agreement['available'])->toBeTrue()
        ->and($agreement['cells'])->toBe([[1, 1, MapPassageAgreementRule::INVISIBLE_WALL], [2, 1, MapPassageAgreementRule::INVISIBLE_WALL]]);
});

it('authors tile and sheet appearance through the tileset record, one undo step each, and the findings follow', function () {
    $session = passageSession();
    $session->setTileAppearance(0, 'home', [5], 'ground');

    expect(readAppearanceRows($session))->toMatchArray(['Appearance: Ground' => '5'])
        ->and((array) $session->readTilesetPreview(0)['appearance'])->toMatchArray([5 => ['class' => 'ground', 'sheet' => 'B', 'source' => 'tile']])
        ->and(array_column($session->readPassageAgreement('test-map')['cells'], 0))->toBe([1, 1, 2]);

    // Setting what already holds changes nothing and records no step.
    expect($session->setTileAppearance(0, 'home', [5], 'ground'))->toMatchArray(['changed' => false]);

    // Moving the tile to another class takes it out of the first, in one step.
    $session->setTileAppearance(0, 'home', [5], 'barrier');
    expect(readAppearanceRows($session))->toMatchArray(['Appearance: Ground' => '', 'Appearance: Barrier' => '5'])
        ->and($session->readPassageAgreement('test-map')['cells'])->toBe([[1, 1, MapPassageAgreementRule::INVISIBLE_WALL],
            [2, 1, MapPassageAgreementRule::INVISIBLE_WALL], [3, 1, MapPassageAgreementRule::WALK_THROUGH]]);
    $session->undo();
    expect(readAppearanceRows($session))->toMatchArray(['Appearance: Ground' => '5', 'Appearance: Barrier' => '']);

    // A whole sheet, then the tile's own class taken away so it reads by its sheet again.
    $session->setTileAppearance(0, 'home', ['B'], 'water');
    $session->setTileAppearance(0, 'home', [5], null);
    expect(readAppearanceRows($session))->toMatchArray(['Appearance: Ground' => '', 'Appearance: Water' => 'B'])
        ->and((array) $session->readTilesetPreview(0)['appearance'])->toMatchArray([5 => ['class' => 'water', 'sheet' => 'B', 'source' => 'sheet']])
        ->and($session->readTilesetPreview(0)['appearanceSheets'])->toEqual((object) ['B' => 'water']);
});

it('refuses an unknown class, selection or record before changing anything', function (array $selectors, ?string $class, string $tileset, string $message) {
    $session = passageSession();
    expect(fn() => $session->setTileAppearance(0, $tileset, $selectors, $class))->toThrow(SessionRefusal::class, $message)
        ->and(readAppearanceRows($session))->each->toBe('');
})->with([
    'class' => [[5], 'lava', 'home', 'not a tile appearance'],
    'selector' => [['F'], 'barrier', 'home', 'neither a tile nor an RPG Maker sheet'],
    'empty' => [[], 'barrier', 'home', 'Choose tiles or a sheet'],
    'record' => [[5], 'barrier', 'other', 'record changed'],
]);

it('reports findings as warnings in project validation, which never refuse a save', function () {
    $session = passageSession();
    $issues = array_values(array_filter($session->validateProject()['issues'],
        static fn(array $issue): bool => str_starts_with((string) $issue['code'], 'passage.')));

    expect($issues)->toHaveCount(1)
        ->and($issues[0])->toMatchArray(['severity' => 'warning', 'code' => MapPassageAgreementRule::INVISIBLE_WALL, 'map' => 'test-map'])
        ->and($issues[0]['message'])->toContain('2 cells: (1, 1), (2, 1)');
});
