<?php

declare(strict_types=1);

use Ichiloto\Editor\Canvas\PieceOwnership;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\SavePointAgreementCheck;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapGridSource;

/**
 * A save point is one fact in three places: the terminal mark, the collision
 * that makes it save and the art that shows it. The 4 x 2 synthetic map keeps
 * its collision declared unless a test asks otherwise; synthetic pieces only.
 */

/**
 * @param list<list<CollisionType>>|false|null $occupancy Declared cells, null for open floor, false for none declared.
 * @param string $fixtures The fixtures layer's glyphs.
 * @param string $decor The decor tile layer's entries.
 */
function savePointProject(array|false|null $occupancy = null, string $fixtures = "    \n    ", string $decor = "0 0 0 0\n0 0 0 0"): string
{
    $root = makeTemporaryProject('editor-save-point-');
    file_put_contents($root . '/assets/Maps/collisions.php', "<?php\n\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\n\n"
        . "return ['.' => CollisionType::NONE, '#' => CollisionType::SOLID, '?' => CollisionType::SAVE_POINT];\n");
    $directory = $root . '/assets/Maps/test-map';
    unlink($directory . '/test-map.map.php');
    mkdir($directory . '/layers');
    file_put_contents($directory . '/layers/01.terrain.map.php', MapGridSource::buildSource("....\n....", 'AUTHORED', ''));
    file_put_contents($directory . '/layers/02.fixtures.map.php', MapGridSource::buildSource($fixtures, 'AUTHORED', ''));
    file_put_contents($directory . '/test-map.event.php', MapGridSource::buildSource("    \n    ", 'EVENTS'));
    $none = CollisionType::NONE;
    $data = ['name' => 'Save points', 'region' => '', 'events' => [], 'tileset' => 'home'];
    if ($occupancy !== false) {
        $data['occupancy'] = $occupancy ?? [[$none, $none, $none, $none], [$none, $none, $none, $none]];
    }
    file_put_contents($directory . '/test-map.data.php', "<?php\nreturn " . var_export($data, true) . ";\n");
    writeTileLayer($directory, '01.floor.tiles.php', "2816 2816 2816 2816\n2816 2816 2816 2816");
    writeTileLayer($directory, '02.decor.tiles.php', $decor);
    writeTestTileset($root, pieces: [
        'save-point' => ['name' => 'Save point', 'layer' => 'fixtures', 'glyphs' => ['?'], 'tiles' => ['decor' => ['11']]],
        'crate' => ['name' => 'Crate', 'layer' => 'fixtures', 'glyphs' => ['#'], 'tiles' => ['decor' => ['12']]],
    ]);

    return $root;
}

/** @return array<string, list<array{0: int, 1: int}>> The cells each finding names, by its code. */
function readSavePointFindings(EditorSession $session): array
{
    $found = [];
    foreach (SavePointAgreementCheck::validate(getPhysicalOccupancyMap($session)) as $issue) {
        expect($issue)->toBeInstanceOf(Issue::class);
        $found[$issue->code] = $issue->cells;
    }

    return $found;
}

function readSavePointCollision(EditorSession $session, int $x, int $y): int
{
    return $session->readMap('test-map')['occupancy']['rows'][$y][$x];
}

it('makes a placed save point save on a map with declared collision, as one undo step', function () {
    $root = savePointProject();
    $session = EditorSession::open($root);

    $session->placePiece('test-map', $session->readMap('test-map')['revision'], 'save-point', [2, 1], [2, 1]);
    expect(readSavePointCollision($session, 2, 1))->toBe(CollisionType::SAVE_POINT->value)
        ->and(readSavePointFindings($session))->toBe([]);

    $session->saveAll();
    expect(readSavePointCollision(EditorSession::open($root), 2, 1))
        ->toBe(CollisionType::SAVE_POINT->value);

    $session->undo();
    expect(readSavePointCollision($session, 2, 1))->toBe(CollisionType::NONE->value);
    $session->redo();
    expect(readSavePointCollision($session, 2, 1))->toBe(CollisionType::SAVE_POINT->value);
});

it('makes every copy of a save point save when several are placed at once', function () {
    $session = EditorSession::open(savePointProject());

    $session->placePieceCopies('test-map', $session->readMap('test-map')['revision'], 'save-point', [[0, 0], [3, 1]]);
    expect(readSavePointCollision($session, 0, 0))->toBe(CollisionType::SAVE_POINT->value)
        ->and(readSavePointCollision($session, 3, 1))->toBe(CollisionType::SAVE_POINT->value)
        ->and(readSavePointCollision($session, 1, 0))->toBe(CollisionType::NONE->value);
});

it('gives another piece no collision and declares none on a map whose glyphs give it', function () {
    $session = EditorSession::open(savePointProject());
    $session->placePiece('test-map', $session->readMap('test-map')['revision'], 'crate', [1, 0], [1, 0]);
    expect(readSavePointCollision($session, 1, 0))->toBe(CollisionType::NONE->value);

    $legacy = EditorSession::open(savePointProject(false));
    $legacy->placePiece('test-map', $legacy->readMap('test-map')['revision'], 'save-point', [1, 0], [1, 0]);
    expect(getPhysicalOccupancyMap($legacy)->hasMapDataField(['occupancy']))->toBeFalse()
        ->and(getPhysicalOccupancyMap($legacy)->getResolvedCollisionMap()[0][1])->toBe(CollisionType::SAVE_POINT->value)
        ->and(readSavePointFindings($legacy))->toBe([]);
});

it('takes the collision away with a removed save point and carries it with a moved one', function () {
    $session = EditorSession::open(savePointProject());
    $session->placePiece('test-map', $session->readMap('test-map')['revision'], 'save-point', [1, 0], [1, 0]);

    $session->movePlacedPiece('test-map', $session->readMap('test-map')['revision'], 'save-point', [1, 0], [3, 1], false, null);
    expect(readSavePointCollision($session, 1, 0))->toBe(CollisionType::NONE->value)
        ->and(readSavePointCollision($session, 3, 1))->toBe(CollisionType::SAVE_POINT->value)
        ->and(readSavePointFindings($session))->toBe([]);

    $session->removePlacedPiece('test-map', $session->readMap('test-map')['revision'], 'save-point', 3, 1, false);
    expect(readSavePointCollision($session, 3, 1))->toBe(CollisionType::NONE->value)
        ->and(readSavePointFindings($session))->toBe([]);

    $session->undo();
    expect(readSavePointCollision($session, 3, 1))->toBe(CollisionType::SAVE_POINT->value);
});

it('refuses save point art laid alone on a cell that does not save, and reports art left there', function () {
    $session = EditorSession::open(savePointProject());

    expect(fn() => $session->placePiece('test-map', $session->readMap('test-map')['revision'], 'save-point', [1, 1], [1, 1], ownership: PieceOwnership::GRAPHICAL))
        ->toThrow(SessionRefusal::class);
    expect(readSavePointCollision($session, 1, 1))->toBe(CollisionType::NONE->value);

    $painted = EditorSession::open(savePointProject(decor: "0 0 0 0\n0 11 0 0"));
    expect(readSavePointFindings($painted))->toBe([SavePointAgreementCheck::INERT => [[1, 1]]]);
});

it('reports a save point mark whose cell does not save', function () {
    $session = EditorSession::open(savePointProject(fixtures: "    \n  ? ", decor: "0 0 0 0\n0 0 11 0"));

    expect(readSavePointFindings($session))->toBe([SavePointAgreementCheck::INERT => [[2, 1]]]);
});

it('reports a cell that saves with no terminal mark', function () {
    $none = CollisionType::NONE;
    $save = CollisionType::SAVE_POINT;
    $session = EditorSession::open(savePointProject([[$none, $none, $none, $save], [$none, $none, $none, $none]], decor: "0 0 0 11\n0 0 0 0"));

    expect(readSavePointFindings($session))->toBe([SavePointAgreementCheck::UNMARKED => [[3, 0]]]);
});

it('reports a cell that saves with no art on a graphical map', function () {
    $none = CollisionType::NONE;
    $save = CollisionType::SAVE_POINT;
    $session = EditorSession::open(savePointProject([[$save, $none, $none, $none], [$none, $none, $none, $none]], fixtures: "?   \n    "));

    expect(readSavePointFindings($session))->toBe([SavePointAgreementCheck::NO_ART => [[0, 0]]]);
});
