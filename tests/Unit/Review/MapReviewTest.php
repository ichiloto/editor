<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Preview\PreviewField;
use Ichiloto\Editor\Review\FieldStillRaster;
use Ichiloto\Editor\Review\MapRenderSubject;
use Ichiloto\Editor\Review\MapRenderView;
use Ichiloto\Editor\Review\MapReviewWriter;
use Ichiloto\Editor\Review\TerminalPicture;
use Ichiloto\Editor\Session\ChangeBatch;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\IO\Console\TerminalText;

/**
 * A synthetic 12 x 5 layered map, its water blue, with an NPC, and a player
 * whose graphical art is a one-character sheet of solid red frames, so a
 * pixel of the player is known.
 */
function mapReviewProject(): string
{
    $root = makeTemporaryProject('ichiloto-map-review-');
    $directory = $root . '/assets/Maps/test-map';
    unlink($directory . '/test-map.map.php');
    mkdir($directory . '/layers');
    file_put_contents($directory . '/layers/01.terrain.map.php', MapGridSource::buildSource(
        "############\n#..<fg=blue>~~~</>.....#\n#..........#\n#..........#\n############", 'AUTHORED'));
    // A door at (9, 2) cues the player in; one at (10, 2) waits on a world state a new game has not reached.
    file_put_contents($directory . '/test-map.event.php', MapGridSource::buildSource(
        "            \n            \n         AB \n            \n            ", 'EVENTS'));
    $door = static fn(array $more): array => ['class' => 'Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger',
        'data' => ['destinationMap' => 'test-map', 'spawnPoint' => ['x' => 1, 'y' => 1], 'spawnSprite' => ['South']],
        'cue' => ['symbol' => '!', 'color' => 'bright-yellow'], ...$more];
    file_put_contents($directory . '/test-map.data.php', "<?php\n\nreturn " . var_export(['name' => 'Test Map', 'region' => '',
        'events' => ['A' => $door([]), 'B' => $door(['conditions' => [['type' => 'event', 'name' => 'never_reached']]])],
        'npcs' => [['id' => 'keeper', 'name' => 'Keeper', 'sprite' => '@', 'x' => 2, 'y' => 1]]], true) . ";\n");
    $sheet = imagecreatetruecolor(144, 192);
    imagefill($sheet, 0, 0, imagecolorallocate($sheet, 255, 0, 0));
    mkdir($root . '/assets/Graphics/Characters', 0o777, true);
    imagepng($sheet, $root . '/assets/Graphics/Characters/$Hero.png');
    $player = $root . '/assets/Data/Entities/player.php';
    file_put_contents($player, str_replace("return [\n", "return [\n  'sprites2d' => ['sheet' => 'Graphics/Characters/\$Hero.png'],\n",
        (string) file_get_contents($player)));

    return $root;
}

/** The 4 x 2 layered map with tiles ({@see mapGraphicsProject()}), its event marker left out: it names no event. */
function mapReviewGraphicsProject(): string
{
    $root = mapGraphicsProject();
    file_put_contents($root . '/assets/Maps/test-map/test-map.event.php', MapGridSource::buildSource("    \n    ", 'EVENTS'));

    return $root;
}

/** The rgb of an image pixel as [r, g, b]. */
function readReviewPixel(GdImage $image, int $x, int $y): array
{
    $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));

    return [$color['red'], $color['green'], $color['blue']];
}

it('draws the Terminal field exactly as the field\'s camera draws it, with its NPCs and the player', function () {
    $root = mapReviewProject();
    $session = EditorSession::open($root);
    $rendering = $session->renderMapReview(new MapRenderView('test-map', subject: new MapRenderSubject(5, 3, MovementHeading::SOUTH)));

    // The same field opened from the map's files, as a cinematic preview opens it, captured by its own camera.
    $field = PreviewField::open($root, 'test-map', new Vector2(5, 3), 20, 8, static fn(): float => 0.0);
    $field->holdCameraAt(0, 0);
    $origin = $field->getCameraOrigin();
    $screen = TerminalPicture::readRows($field->frame());
    $field->dispose();
    $expected = [];
    for ($y = 0; $y < 5; $y++) {
        for ($x = 0; $x < 12; $x++) {
            $expected[$y][$x] = $screen[$y - $origin['y']][$x - $origin['x']];
        }
    }

    expect($rendering->terminal->cells)->toBe($expected)
        ->and(explode("\n", $rendering->terminal->renderPlainText()))->toBe([
            '############', '#.@~~~.....#', '#........!.#', '#....v.....#', '############', '',
        ])
        ->and(TerminalText::stripAnsi($rendering->terminal->cells[1][2]))->toBe('@')
        ->and($rendering->terminal->renderAnsi())->toContain("\e[34m")
        ->and($rendering->terminal->renderHtml())->toContain('color:#61afef');
});

it('crops a region to exactly the cells the whole map shows there, both ways', function () {
    $root = mapReviewGraphicsProject();
    $session = EditorSession::open($root);
    $whole = $session->renderMapReview(new MapRenderView('test-map'));
    $region = $session->renderMapReview(new MapRenderView('test-map', ['x' => 1, 'y' => 1, 'width' => 3, 'height' => 1]));
    $raster = new FieldStillRaster($root . '/assets');
    $wholeImage = $raster->rasterize($whole->still);
    $regionImage = $raster->rasterize($region->still);

    expect($region->terminal->cells)->toBe([array_slice($whole->terminal->cells[1], 1, 3)])
        ->and([imagesx($regionImage), imagesy($regionImage)])->toBe([3 * 48, 48]);
    foreach ([[0, 0], [47, 47], [100, 20], [143, 47]] as [$x, $y]) {
        expect(readReviewPixel($regionImage, $x, $y))->toBe(readReviewPixel($wholeImage, $x + 48, $y + 48));
    }
});

it('draws the graphical still at native size with its tiles, and the player on their cell', function () {
    $root = mapReviewProject();
    $map = $root . '/assets/Maps/test-map';
    writeTestTileset($root);
    file_put_contents($map . '/test-map.data.php', str_replace("'region' => '',", "'region' => '', 'tileset' => 'home',",
        (string) file_get_contents($map . '/test-map.data.php')));
    // A floor tile on the wall cell at (0, 0) only; every other cell shows its glyph.
    writeTileLayer($map, '01.floor.tiles.php', "2816" . str_repeat(' 0', 11) . "\n" . implode("\n", array_fill(0, 4, trim(str_repeat('0 ', 12)))));
    $session = EditorSession::open($root);
    $still = $session->renderMapReview(new MapRenderView('test-map', subject: new MapRenderSubject(5, 3)))->still;
    $raster = new FieldStillRaster($root . '/assets');
    $image = $raster->rasterize($still);
    $overview = $raster->rasterize($still, 0.25);

    expect([imagesx($image), imagesy($image)])->toBe([12 * 48, 5 * 48])
        ->and([imagesx($overview), imagesy($overview)])->toBe([3 * 48, 5 * 12])
        // The tile's colour fills its cell and hides the wall glyph.
        ->and(readReviewPixel($image, 24, 24))->toBe([0x70, 0x90, 0xB0])
        // The player's frame stands on its cell's ground.
        ->and(readReviewPixel($image, 5 * 48 + 24, 3 * 48 + 24))->toBe([255, 0, 0])
        // An open floor cell no tile covers shows the field's background.
        ->and(readReviewPixel($image, 8 * 48 + 2, 2 * 48 + 2))->toBe([0x11, 0x18, 0x20])
        ->and($raster->diagnostics)->toBe([]);
});

it('renders a batch\'s changes before they are written, and writes nothing', function () {
    $root = mapReviewProject();
    $before = sourceHashTree($root . '/assets');
    $layer = EditorSession::open($root)->readMap('test-map')['baseLayer'];
    $outcome = ChangeBatch::preview($root, [['method' => 'map.paint', 'params' => ['map' => 'test-map', 'layer' => $layer,
        'cells' => [[8, 2]], 'symbol' => 'T']]], static fn(EditorSession $session) => $session->renderMapReview(new MapRenderView('test-map')));

    expect($outcome['status'])->toBe('previewed')
        ->and(TerminalText::stripAnsi($outcome['result']->terminal->cells[2][8]))->toBe('T')
        ->and(sourceHashTree($root . '/assets'))->toBe($before)
        ->and(TerminalText::stripAnsi(EditorSession::open($root)->renderMapReview(new MapRenderView('test-map'))->terminal->cells[2][8]))
        ->toBe('.');
});

it('writes each view\'s pictures and a review page that sets every view\'s Terminal picture beside its still', function () {
    $root = mapReviewGraphicsProject();
    $session = EditorSession::open($root);
    $out = sys_get_temp_dir() . '/' . uniqid('ichiloto-map-review-', true);
    rememberTemporaryProject($out);
    $writer = new MapReviewWriter($out, new FieldStillRaster($root . '/assets'), 0.5);
    $views = [new MapRenderView('test-map', label: 'The whole map'),
        new MapRenderView('test-map', ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 2], label: 'Its west half')];
    foreach ($views as $view) {
        $written = $writer->writeRendering($session->renderMapReview($view));
        expect(array_keys($written['files']))->toBe(['terminal-ans', 'terminal-html', 'png', 'scaled-png']);
        foreach ($written['files'] as $file) {
            expect(is_file($file))->toBeTrue();
        }
    }
    $page = (string) file_get_contents($writer->writeReviewPage('Review'));

    expect($page)->toContain('The whole map')->toContain('Its west half')
        // The page carries its stills, so it shows them when opened or sent on its own.
        ->toContain('src="data:image/png;base64,' . base64_encode((string) file_get_contents($out . '/test-map@0.5x.png')) . '"')
        ->toContain('src="data:image/png;base64,' . base64_encode((string) file_get_contents($out . '/test-map@0,0,2,2@0.5x.png')) . '"')
        ->toContain('href="test-map.png"')
        ->and(substr_count($page, '<pre class="terminal">'))->toBe(2)
        ->and(getimagesize($out . '/test-map@0,0,2,2.png'))->toMatchArray([0 => 96, 1 => 96])
        ->and((string) file_get_contents($out . '/test-map.terminal.html'))->toStartWith('<!doctype html>')->toContain('<pre class="terminal">');
});

it('refuses a region or subject off the map, and an unknown actor', function () {
    $session = EditorSession::open(mapReviewProject());

    expect(fn() => $session->renderMapReview(new MapRenderView('test-map', ['x' => 10, 'y' => 0, 'width' => 4, 'height' => 1])))
        ->toThrow(Ichiloto\Editor\Session\SessionRefusal::class, 'runs off map test-map')
        ->and(fn() => $session->renderMapReview(new MapRenderView('test-map', subject: new MapRenderSubject(12, 0))))
        ->toThrow(Ichiloto\Editor\Session\SessionRefusal::class, 'is off map test-map')
        ->and(fn() => $session->renderMapReview(new MapRenderView('test-map', subject: new MapRenderSubject(1, 1, actorId: 'nobody'))))
        ->toThrow(Ichiloto\Editor\Session\SessionRefusal::class, 'There is no actor nobody')
        ->and(fn() => $session->renderMapReview(new MapRenderView('nowhere')))
        ->toThrow(Ichiloto\Editor\Session\SessionRefusal::class, 'There is no map nowhere');
});
