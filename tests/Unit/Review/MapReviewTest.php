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

it('draws a player who has art by their sprite alone, never their Terminal glyph cell beneath it', function () {
    $root = mapReviewProject();
    $still = EditorSession::open($root)->renderMapReview(new MapRenderView('test-map', subject: new MapRenderSubject(5, 3)))->still;
    $cells = [];
    foreach ($still->textLayers as $layer) {
        foreach ($layer->runs as $run) {
            for ($i = 0; $i < mb_strlen($run->text); $i++) {
                $cells[] = [$run->column + $i, $run->row];
            }
        }
    }

    // The game's composer leaves out the Terminal layer of a subject its sprite presents; the still does too.
    expect($still->sprites)->not->toBe([])
        ->and($cells)->not->toContain([5, 3]);
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


it('reviews newly authored and revised field resources before saving, with undo and no source writes', function () {
    $root = mapReviewProject();
    writeTilesetTestPng($root . '/assets/Graphics/Props/ReviewRed.png', 48, 48);
    writeTilesetTestPng($root . '/assets/Graphics/Props/ReviewBlue.png', 48, 48);
    $before = sourceHashTree($root . '/assets');
    $session = EditorSession::open($root);
    $category = \Ichiloto\Editor\Field\FieldResourceFields::CATEGORY;
    $session->createDatabaseRecord($category);
    $apply = static function (string $field, string $value) use ($session, $category): void {
        $row = array_find($session->readDatabaseRecord($category, 0)['rows'],
            static fn(array $row): bool => ($row['key']['field'] ?? null) === $field);
        $session->applyDatabaseRecord($category, 0, $row['key'], $value);
    };
    $apply('id', 'review-tree');
    $apply('sprites2d.asset', 'Graphics/Props/ReviewRed.png');
    $session->createWorldObject('test-map', $session->readMap('test-map')['revision'], 'review-tree-001', 4, 3, 'review-tree');
    $asset = static function () use ($session): string {
        $rendering = $session->renderMapReview(new MapRenderView('test-map'));
        $sprite = array_find($rendering->still->sprites,
            static fn($sprite): bool => $sprite->id === 'world-object:test-map:review-tree-001');
        return $sprite?->asset ?? '';
    };
    expect($asset())->toBe('Graphics/Props/ReviewRed.png');
    $apply('sprites2d.asset', 'Graphics/Props/ReviewBlue.png');
    expect($asset())->toBe('Graphics/Props/ReviewBlue.png');
    $session->undo();
    expect($asset())->toBe('Graphics/Props/ReviewRed.png');
    $session->redo();
    expect($asset())->toBe('Graphics/Props/ReviewBlue.png')
        ->and(sourceHashTree($root . '/assets'))->toBe($before);
});

/** A PNG of one solid colour, clear across its top rows when asked. */
function writeReviewPng(string $path, int $width, int $height, array $rgb, int $clearRows = 0): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, 0, $clearRows, $width - 1, $height - 1,
        (int) imagecolorallocate($image, ...$rgb));
    imagepng($image, $path);
}

/**
 * The review map with a floor tile under the keeper and beside them, and a
 * bed at (6, 2) whose sleeper lies in it: a green base, a red sleeper below
 * its top third and a blue cover across its bottom quarter. The keeper has no graphical art, so the
 * field shows them by their terminal layer.
 */
function mapReviewBedProject(): string
{
    $root = mapReviewProject();
    $map = $root . '/assets/Maps/test-map';
    writeTestTileset($root);
    writeTileLayer($map, '01.floor.tiles.php', "0 0 0 0 0 0 0 0 0 0 0 0\n0 0 2816 2816 0 0 0 0 0 0 0 0\n"
        . implode("\n", array_fill(0, 3, trim(str_repeat('0 ', 12)))));
    $data = require $map . '/test-map.data.php';
    $data['tileset'] = 'home';
    $data['worldObjects'] = [['id' => 'cot', 'resource' => 'guest-bed', 'anchor' => ['x' => 6, 'y' => 2]]];
    $data['npcs'][] = ['id' => 'sleeper', 'name' => 'Sleeper', 'sprite' => 'S', 'x' => 7, 'y' => 2,
        'bed' => ['object' => 'cot', 'attachment' => 'occupant', 'poses2d' => ['lying' => ['asset' => 'Graphics/Characters/Sleeper-Lying.png']]]];
    file_put_contents($map . '/test-map.data.php', "<?php\n\nreturn " . var_export($data, true) . ";\n");
    @mkdir($root . '/assets/Data/Presentation', 0o777, true);
    file_put_contents($root . '/assets/Data/Presentation/field.php', "<?php\nreturn " . var_export(['resources' => ['guest-bed' => [
        'name' => 'Synthetic bed', 'pivot' => ['x' => 0.5, 'y' => 1.0], 'sprites2d' => ['asset' => 'Graphics/Objects/Bed.png'],
        'bedCover2d' => ['asset' => 'Graphics/Objects/Bed-Cover.png'],
        'attachments' => [['id' => 'occupant', 'cell' => ['x' => 0, 'y' => 0], 'point' => ['x' => 0.5, 'y' => 0.5]]],
    ]]], true) . ";\n");
    writeReviewPng($root . '/assets/Graphics/Objects/Bed.png', 48, 48, [0, 200, 0]);
    writeReviewPng($root . '/assets/Graphics/Objects/Bed-Cover.png', 48, 48, [0, 0, 220], clearRows: 36);
    writeReviewPng($root . '/assets/Graphics/Characters/Sleeper-Lying.png', 48, 48, [230, 0, 0], clearRows: 16);

    return $root;
}

it('draws a bed\'s world canvas with its sleeper between base and cover, as the game composes it', function () {
    $root = mapReviewBedProject();
    $rendering = EditorSession::open($root)->renderMapReview(new MapRenderView('test-map'));
    $raster = new FieldStillRaster($root . '/assets');
    $image = $raster->rasterize($rendering->still);
    $cell = static fn(int $x, int $y, int $left, int $top): array => readReviewPixel($image, $x * 48 + $left, $y * 48 + $top);

    expect(array_column($rendering->still->worldCanvases, 'id'))->toHaveCount(1)
        ->and([$rendering->still->worldCanvases[0]->x, $rendering->still->worldCanvases[0]->y])->toBe([6, 2])
        // The base shows above the sleeper, the sleeper over the base, the cover over the sleeper.
        ->and($cell(6, 2, 4, 4))->toBe([0, 200, 0])
        ->and($cell(6, 2, 24, 24))->toBe([230, 0, 0])
        ->and($cell(6, 2, 24, 42))->toBe([0, 0, 220])
        ->and($raster->diagnostics)->toBe([]);
});

it('draws the field\'s text layers where a subject has no art, over the tiles beneath it, on map cells', function () {
    $root = mapReviewBedProject();
    $session = EditorSession::open($root);
    $whole = $session->renderMapReview(new MapRenderView('test-map'));
    $region = $session->renderMapReview(new MapRenderView('test-map', ['x' => 2, 'y' => 1, 'width' => 2, 'height' => 1]));
    $raster = new FieldStillRaster($root . '/assets');
    $keeper = static fn($still): array => array_merge(...array_map(
        static fn($layer): array => array_map(static fn($run): array => [$run->column, $run->row, $run->text], $layer->runs),
        array_values(array_filter($still->textLayers, static fn($layer): bool => str_contains($layer->id, 'keeper')))));
    $image = $raster->rasterize($whole->still);

    expect($keeper($whole->still))->toBe([[2, 1, '@']])
        ->and($keeper($region->still))->toBe([[2, 1, '@']])
        // The keeper's cell paints its terminal cell over the floor tile, as the graphical renderer paints it; the tile beside shows.
        ->and(readReviewPixel($image, 2 * 48 + 2, 48 + 2))->toBe([0x11, 0x18, 0x20])
        ->and(readReviewPixel($image, 3 * 48 + 2, 48 + 2))->toBe([0x70, 0x90, 0xB0])
        ->and(readReviewPixel($raster->rasterize($region->still), 2, 2))->toBe([0x11, 0x18, 0x20])
        // The Terminal picture is the field's own camera picture, unchanged.
        ->and(TerminalText::stripAnsi($whole->terminal->cells[1][2]))->toBe('@');
});

/** Finds the renderer the tests name, as the installed-renderer resolver finds the installed one. */
function reviewRenderers(string $executable): Ichiloto\Engine\Rendering\Launch\RendererExecutableResolverInterface
{
    return new class($executable) implements Ichiloto\Engine\Rendering\Launch\RendererExecutableResolverInterface {
        public function __construct(private readonly string $executable)
        {
        }

        public function resolve(string $rendererId): string
        {
            return $this->executable;
        }
    };
}

/** An executable PHP script in a throwaway directory of its own, removed after the test. */
function writeReviewExecutable(string $name, string $php): string
{
    $directory = rememberTemporaryProject(sys_get_temp_dir() . '/' . uniqid('ichiloto-review-renderer-', true));
    mkdir($directory);
    $path = $directory . '/' . $name;
    file_put_contents($path, '#!' . PHP_BINARY . "\n<?php " . $php . "\n");
    chmod($path, 0o755);

    return $path;
}

/** An executable that runs the synthetic label renderer ({@see tests/Support/fake-label-renderer.php}). */
function fakeLabelRenderer(): string
{
    return writeReviewExecutable('gpui-renderer', 'require ' . var_export(dirname(__DIR__, 2) . '/Support/fake-label-renderer.php', true) . ';');
}

/** The bed project ({@see mapReviewBedProject()}) with a wooden sign reading Inn at (4, 3). */
function mapReviewSignProject(): string
{
    $root = mapReviewBedProject();
    $map = $root . '/assets/Maps/test-map';
    $data = require $map . '/test-map.data.php';
    $data['worldObjects'][] = ['id' => 'inn-sign', 'resource' => 'wood-sign', 'anchor' => ['x' => 4, 'y' => 3],
        'label' => ['message' => 'signs.inn-sign', 'default' => 'Inn']];
    file_put_contents($map . '/test-map.data.php', "<?php\n\nreturn " . var_export($data, true) . ";\n");
    $field = require $root . '/assets/Data/Presentation/field.php';
    $field['resources']['wood-sign'] = ['name' => 'Synthetic sign', 'pivot' => ['x' => 0.5, 'y' => 1.0],
        'sign' => ['asset' => 'Graphics/Objects/Board.png', 'fontRole' => 'sign', 'left' => 4, 'right' => 4, 'density' => 1,
            'padding' => [2, 2, 2, 2], 'minimumWidth' => 20, 'maximumWidth' => 100, 'repeatWidth' => 4]];
    file_put_contents($root . '/assets/Data/Presentation/field.php', "<?php\nreturn " . var_export($field, true) . ";\n");
    file_put_contents($root . '/assets/Data/Presentation/text.php', '<?php return ' . var_export(['schema' => 'ichiloto.text/1',
        'roles' => ['sign' => ['fonts' => [['asset' => 'Fonts/replaceable.ttf']], 'fontSize' => 12,
            'foreground' => ['kind' => 'rgb', 'r' => 20, 'g' => 30, 'b' => 40]]]], true) . ';');
    writeReviewPng($root . '/assets/Graphics/Objects/Board.png', 32, 20, [90, 60, 30]);

    return $root;
}

/** The pixel of a still at the centre of a world canvas's first label, in still pixels. */
function readReviewLabelCentre(GdImage $image, Ichiloto\Engine\Rendering\Presentation\PresentationWorldCanvas $sign): array
{
    $paint = $sign->canvas->labels[0]->paintBounds;
    $left = ($sign->x + 0.5) * 48 - $sign->pivot->x * $sign->canvas->width;
    $top = ($sign->y + 1) * 48 - $sign->pivot->y * $sign->canvas->height;

    return readReviewPixel($image, (int) round($left + $paint->x + $paint->width / 2), (int) round($top + $paint->y + $paint->height / 2));
}

it('draws a bed with its sleeper, a sign with its label, and field text in one still, as the game composes them', function () {
    $root = mapReviewSignProject();
    $rendering = new Ichiloto\Editor\Review\MapRenderer(Ichiloto\Editor\ProjectWorkspace::fromProject($root),
        reviewRenderers(fakeLabelRenderer()))->render(new MapRenderView('test-map'));
    $raster = new FieldStillRaster($root . '/assets');
    $image = $raster->rasterize($rendering->still);
    $canvases = array_column(array_map(static fn($canvas): array => [$canvas->id, $canvas], $rendering->still->worldCanvases), 1, 0);
    $sign = $canvases['world-object:test-map:inn-sign'] ?? null;

    expect(array_keys($canvases))->toEqualCanonicalizing(['world-object:test-map:cot', 'world-object:test-map:inn-sign'])
        ->and($sign->canvas->labels)->toHaveCount(1)
        ->and($rendering->still->labelRasters)->toHaveKey($sign->canvas->labels[0]->measurement->layoutId)
        // The label shows the renderer's raster of it, over the board.
        ->and(readReviewLabelCentre($image, $sign))->toBe([200, 40, 120])
        ->and(readReviewPixel($image, 6 * 48 + 24, 2 * 48 + 24))->toBe([230, 0, 0])
        ->and(readReviewPixel($image, 2 * 48 + 2, 48 + 2))->toBe([0x11, 0x18, 0x20])
        ->and([...$rendering->diagnostics, ...$raster->diagnostics])->toBe([]);
});

it('names each sign it leaves out when the installed renderer cannot prepare labels, and still draws the rest', function () {
    $root = mapReviewSignProject();
    $workspace = Ichiloto\Editor\ProjectWorkspace::fromProject($root);
    $missing = new Ichiloto\Editor\Review\MapRenderer($workspace, reviewRenderers('/nonexistent/gpui-renderer'))->render(new MapRenderView('test-map'));
    // A renderer without the label mode answers the check with something else and is never sent a hello.
    $older = writeReviewExecutable('gpui-renderer', "echo 'not a label mode', PHP_EOL;");
    $outdated = new Ichiloto\Editor\Review\MapRenderer($workspace, reviewRenderers($older))->render(new MapRenderView('test-map'));
    $outside = new Ichiloto\Editor\Review\MapRenderer($workspace, reviewRenderers($older))
        ->render(new MapRenderView('test-map', ['x' => 6, 'y' => 0, 'width' => 6, 'height' => 5]));

    expect($missing->diagnostics)->toBe(['Sign inn-sign at x 4, y 3 is left out of the graphical still: The installed GPUI renderer cannot be launched.'])
        ->and($outdated->diagnostics)->toBe(['Sign inn-sign at x 4, y 3 is left out of the graphical still: The installed GPUI renderer '
            . 'has no windowless label mode; update it with `ichiloto renderer:update`.'])
        ->and(array_column($outdated->still->worldCanvases, 'id'))->toBe(['world-object:test-map:cot'])
        // A sign outside the view is not in it to leave out.
        ->and($outside->diagnostics)->toBe([]);
});
