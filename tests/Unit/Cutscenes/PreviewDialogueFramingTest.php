<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Preview\CinematicCapture;
use Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession;
use Ichiloto\Editor\Cutscenes\Preview\PreviewField;
use Ichiloto\Editor\Review\CinematicCaptureWriter;
use Ichiloto\Editor\Review\SceneFrameRaster;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationProviderInterface;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueScenePresentation;
use Ichiloto\Engine\Rendering\FieldViewport;

/**
 * A previewed or captured cinematic frames its cast clear of the dialogue
 * where the game's field frames it. The Engine owns the framing; the preview
 * resolves it as the game does, after every tick of the scene and before
 * every picture, in its Terminal picture, the editor window's view and a
 * capture, and keeps what the Engine reports about a cast it cannot frame.
 *
 * Everything here is synthetic: an open yard taller than any screen, the
 * player, one staged friend and two spoken lines.
 */

/** The world row of the yard's one landmark tile, which the Terminal picture is read by. */
const FRAMING_LANDMARK_ROW = 18;

/** A project with the open yard and a themed dialogue box, which the graphical field frames against. */
function createFramingProject(): string
{
    $root = cutsceneProject();
    $folder = $root . '/assets/Maps/yard';
    mkdir($folder, 0o777, true);
    $tiles = [str_repeat('#', 60)];
    for ($row = 1; $row < 39; $row++) {
        $tiles[] = '#' . str_repeat(' ', 58) . '#';
    }
    $tiles[] = str_repeat('#', 60);
    $tiles[FRAMING_LANDMARK_ROW] = substr_replace($tiles[FRAMING_LANDMARK_ROW], '=', 5, 1);
    $events = array_fill(0, count($tiles), str_repeat(' ', 60));
    file_put_contents($folder . '/yard.map.php', "<?php\n\nreturn <<<'ICHILOTO_MAP'\n" . implode("\n", $tiles) . "\nICHILOTO_MAP;\n");
    file_put_contents($folder . '/yard.event.php', "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n" . implode("\n", $events) . "\nICHILOTO_EVENT_MAP;\n");
    file_put_contents($folder . '/yard.data.php', "<?php\n\nreturn " . var_export(['name' => 'Yard', 'region' => 'Test',
        'description' => 'Open ground.', 'triggers' => [], 'events' => [], 'npcs' => []], true) . ";\n");
    mkdir($root . '/assets/Data/Presentation', 0o777, true);
    mkdir($root . '/assets/Graphics/UI', 0o777, true);
    writeTilesetTestPng($root . '/assets/Graphics/UI/framing-frame.png', 12, 12);
    $frame = ['asset' => 'Graphics/UI/framing-frame.png', 'cuts' => [2, 2, 2, 2]];
    file_put_contents($root . '/assets/Data/Presentation/dialogue.php', '<?php return ' . var_export([
        'schema' => 'ichiloto.dialogue/1',
        'theme' => ['schema' => 'ichiloto.menu/1', 'colors' => ['text' => [31, 79, 113]],
            'frames' => ['dialogue' => $frame, 'nameplate' => $frame]],
    ], true) . ';');

    return $root;
}

/**
 * A scene beside a friend staged on a row of the yard: two spoken lines with
 * a gap between them, unless it is given other commands.
 *
 * @param list<array<string, mixed>>|null $commands
 */
function createFramingDefinition(int $friendRow, ?array $commands = null): CinematicDefinition
{
    return CinematicDefinition::fromArrays([
        'id' => 'framing-talk', 'name' => 'Framing talk', 'startMap' => 'yard', 'presentation' => ['initial' => 'field'],
        'cast' => [['kind' => 'staged_actor', 'id' => 'friend', 'sprite' => ['F'], 'x' => 32, 'y' => $friendRow]],
        'skip' => ['policy' => 'forbidden'], 'checkpoints' => [], 'finalizer' => [],
    ], $commands ?? [
        ['type' => 'wait', 'seconds' => 0.3],
        ['type' => 'text', 'name' => 'Friend', 'text' => 'A line held on screen.'],
        ['type' => 'wait', 'seconds' => 0.5],
        ['type' => 'text', 'name' => 'Friend', 'text' => 'A second line.'],
        ['type' => 'wait', 'seconds' => 0.3],
    ]);
}

/** Starts a scene with the player on a row of the yard, presented as one of the preview's three pictures. */
function startFramingPreview(string $root, string $picture, int $playerRow, CinematicDefinition $definition, int $height = 30): CinematicPreviewSession
{
    $options = ['x' => 30, 'y' => $playerRow, 'width' => 100, 'height' => $height];

    return $picture === 'capture'
        ? CinematicPreviewSession::startCapture($root, $definition, SceneFrameRaster::supports(...), $options)
        : CinematicPreviewSession::start($root, $definition, $options);
}

function readFramingField(CinematicPreviewSession $preview): PreviewField
{
    return new ReflectionProperty($preview, 'field')->getValue($preview);
}

/**
 * Draws the scene as the preview shows it now and answers the world row at
 * the top of that picture: read from the landmark in the Terminal picture,
 * from the viewport of a captured composition, or from the viewport the
 * editor window was last sent.
 *
 * @param array{answer?: string, row?: int} $window What the editor window last answered and was last shown.
 */
function drawFramingPicture(CinematicPreviewSession $preview, string $picture, array &$window): int
{
    if ($picture === 'terminal') {
        foreach ($preview->frame() as $row => $text) {
            if (str_contains(TerminalText::stripAnsi($text), '=')) {
                return FRAMING_LANDMARK_ROW - $row;
            }
        }

        throw new RuntimeException('The landmark is not in the Terminal picture.');
    }
    if ($picture === 'capture') {
        return $preview->composeFrame()->viewport?->worldOriginY ?? throw new RuntimeException('The captured frame has no field viewport.');
    }
    foreach ($preview->exchangeScene([$window['answer'] ?? previewWindowReady()], $preview->getSceneSessionId()) as $message) {
        if ($message['type'] !== 'frame') {
            continue;
        }
        $window['row'] = $message['payload']['viewport']['worldOrigin']['row'] ?? $window['row'] ?? null;
        $window['answer'] = json_encode(['protocol' => 2, 'type' => 'frame_ack', 'generation' => $message['payload']['generation'],
            'frame' => $message['payload']['frame'], 'presented' => true], JSON_THROW_ON_ERROR);
    }

    return $window['row'] ?? throw new RuntimeException('The editor window was sent no field viewport.');
}

/**
 * How far the bottom of a world row's cell sits below the top of the
 * dialogue box on screen, with the picture's top at a world row: positive
 * while the box covers the cell. Terminal cells against the box's own
 * footprint; graphical pixels against the area the Engine's dialogue page
 * protects.
 */
function measureFramingOverlap(CinematicPreviewSession $preview, string $picture, int $worldRow, int $pictureRow): float
{
    $field = readFramingField($preview);
    $line = array_find($field->presentation->getActivePresentations(),
        static fn(object $owner): bool => $owner instanceof DialoguePresentationProviderInterface)
        ?? throw new RuntimeException('No dialogue line is on screen.');
    if ($picture === 'terminal') {
        return ($worldRow + 1 - $pictureRow) - $line->getPresentationBounds()->getY();
    }
    $grid = $preview->getSceneGrid();
    $height = $grid->rows * $grid->cellHeight;
    $protected = new DialogueScenePresentation($field->scene->getPresentationContext()->assetRoot)
        ->getProtectedBounds($line->getDialogueSnapshot(), $grid->columns * $grid->cellWidth, $height)
        ?? throw new RuntimeException('The dialogue page protects no area.');
    $view = new FieldViewport($grid);
    $top = ($height - $view->rows * FieldViewport::TILE_SIZE) / 2;

    return $top + ($worldRow + 1 - $pictureRow) * FieldViewport::TILE_SIZE - min(array_map(static fn($area): float => $area->y, $protected));
}

function stepToFramingLine(CinematicPreviewSession $preview): void
{
    for ($tick = 0; $tick < 30 && $preview->getWaitKind() === null && ! $preview->isFinished(); $tick++) {
        $preview->step();
    }
    expect($preview->getWaitKind())->toBe('dialogue');
}

it('moves a cast that starts under the dialogue box clear of it for the line, holds it between lines and releases it at the end', function (string $picture) {
    $root = createFramingProject();
    $preview = startFramingPreview($root, $picture, 32, createFramingDefinition(35));
    $camera = readFramingField($preview)->scene->camera;
    $window = [];

    try {
        $before = drawFramingPicture($preview, $picture, $window);
        expect($camera->isFramed)->toBeFalse();

        stepToFramingLine($preview);
        $framed = drawFramingPicture($preview, $picture, $window);
        // The friend stood under the box where the camera was; the picture now shows the whole cast above it.
        expect(measureFramingOverlap($preview, $picture, 35, $before))->toBeGreaterThan(0)
            ->and(measureFramingOverlap($preview, $picture, 35, $framed))->toBeLessThanOrEqual(0)
            ->and($framed)->toBeGreaterThan($before)->toBeLessThanOrEqual(32)
            ->and($camera->isFramed)->toBeTrue()
            ->and($camera->getWorldOrigin()['y'])->toBe($framed)
            ->and(readFramingField($preview)->scene->getDialogueFramingDiagnostic())->toBeNull();

        // The line held, then closed: the cast-safe view stays through the gap and the next line.
        $preview->step();
        expect(drawFramingPicture($preview, $picture, $window))->toBe($framed);
        $preview->confirm();
        expect(drawFramingPicture($preview, $picture, $window))->toBe($framed);
        $preview->step();
        expect($preview->getWaitKind())->toBeNull()
            ->and(drawFramingPicture($preview, $picture, $window))->toBe($framed);
        stepToFramingLine($preview);
        expect(drawFramingPicture($preview, $picture, $window))->toBe($framed);

        $preview->confirm();
        expect($preview->runToCompletion(5.0))->toBeTrue()
            ->and(drawFramingPicture($preview, $picture, $window))->toBe($before)
            ->and($camera->isFramed)->toBeFalse()
            ->and($preview->getDiagnostics())->toBe([]);
    } finally {
        $preview->dispose();
    }
})->with(['terminal', 'window', 'capture']);

it('keeps a cast that walks while its line is held clear of the dialogue, with no page shown or closed in between', function (string $picture) {
    $root = createFramingProject();
    $preview = startFramingPreview($root, $picture, 32, createFramingDefinition(35, [
        ['type' => 'wait', 'seconds' => 0.3],
        ['type' => 'parallel', 'lanes' => [
            ['id' => 'words', 'commands' => [['type' => 'text', 'name' => 'Friend', 'text' => 'A line held while I walk.']]],
            ['id' => 'walk', 'commands' => [
                ['type' => 'wait', 'seconds' => 0.3],
                ['type' => 'move_route', 'subject' => 'staged_actor', 'actorId' => 'friend', 'secondsPerStep' => 0.1,
                    'steps' => [['direction' => 'down', 'count' => 1]]],
            ]],
        ]],
    ]));
    $window = [];

    try {
        drawFramingPicture($preview, $picture, $window);
        stepToFramingLine($preview);
        $framed = drawFramingPicture($preview, $picture, $window);
        expect(measureFramingOverlap($preview, $picture, 35, $framed))->toBeLessThanOrEqual(0);

        // The friend steps one row down under the same page; the view follows on the scene's own ticks.
        for ($tick = 0; $tick < 8; $tick++) {
            $preview->step();
        }
        $followed = drawFramingPicture($preview, $picture, $window);
        expect($preview->getWaitKind())->toBe('dialogue')
            ->and($preview->snapshot()->values['staged actors']['friend']['y'])->toBe(36)
            ->and($followed)->toBeGreaterThan($framed)
            ->and(measureFramingOverlap($preview, $picture, 36, $followed))->toBeLessThanOrEqual(0)
            ->and($preview->getDiagnostics())->toBe([]);
    } finally {
        $preview->dispose();
    }
})->with(['terminal', 'window', 'capture']);

it('frames a resized preview as one opened at that size, against the dialogue box where the new screen puts it', function () {
    $root = createFramingProject();
    $resized = startFramingPreview($root, 'terminal', 32, createFramingDefinition(35));
    $window = [];

    try {
        // A preview draws its field when it opens, before anything is said.
        drawFramingPicture($resized, 'terminal', $window);
        $resized->resize(100, 40);
        stepToFramingLine($resized);
        $framed = drawFramingPicture($resized, 'terminal', $window);
        $overlap = measureFramingOverlap($resized, 'terminal', 35, $framed);
    } finally {
        $resized->dispose();
    }
    $opened = startFramingPreview($root, 'terminal', 32, createFramingDefinition(35), 40);

    try {
        drawFramingPicture($opened, 'terminal', $window);
        stepToFramingLine($opened);
        expect(drawFramingPicture($opened, 'terminal', $window))->toBe($framed)
            ->and(measureFramingOverlap($opened, 'terminal', 35, $framed))->toBe($overlap)->toBeLessThanOrEqual(0);
    } finally {
        $opened->dispose();
    }
});

it('reports a cast that cannot be framed in the Engine\'s words with the scene time of each line, and keeps the report after the scene ends', function (string $picture) {
    $root = createFramingProject();
    $preview = startFramingPreview($root, $picture, 5, createFramingDefinition(36));
    $scene = readFramingField($preview)->scene;
    $window = [];

    try {
        $before = drawFramingPicture($preview, $picture, $window);
        expect($preview->getDiagnostics())->toBe([]);

        stepToFramingLine($preview);
        $engine = $scene->getDialogueFramingDiagnostic();
        $first = sprintf('%s First at %.2f s of the scene', $engine, $preview->elapsed());
        expect($engine)->not->toBeNull()
            ->and($preview->getDiagnostics())->toBe([$first . '.'])
            // The camera stays where the game leaves it when the cast does not fit.
            ->and(drawFramingPicture($preview, $picture, $window))->toBe($before)
            ->and($scene->camera->isFramed)->toBeFalse();

        // One line is reported once, however long it is held and however often it is drawn.
        $preview->step();
        $preview->step();
        drawFramingPicture($preview, $picture, $window);
        expect($preview->getDiagnostics())->toBe([$first . '.']);

        $preview->confirm();
        $preview->step();
        expect($preview->getDiagnostics())->toBe([$first . '.']);
        stepToFramingLine($preview);
        $both = sprintf('%s; again at %.2f s.', $first, $preview->elapsed());
        expect($preview->getDiagnostics())->toBe([$both]);

        $preview->confirm();
        expect($preview->runToCompletion(5.0))->toBeTrue()
            ->and($scene->getDialogueFramingDiagnostic())->toBeNull()
            ->and($preview->getDiagnostics())->toBe([$both]);
    } finally {
        $preview->dispose();
    }
})->with(['terminal', 'window', 'capture']);

it('reports a cast that cannot be framed when the scene runs to its end without a picture being drawn', function () {
    $root = createFramingProject();
    $preview = startFramingPreview($root, 'terminal', 5, createFramingDefinition(36));

    try {
        expect($preview->runToCompletion(5.0))->toBeTrue()
            ->and($preview->getDiagnostics())->toHaveCount(1)
            ->and($preview->getDiagnostics()[0])->toMatch('/ First at \d+\.\d\d s of the scene; again at \d+\.\d\d s\.$/');
    } finally {
        $preview->dispose();
    }
});

it('writes what the Engine reported into the capture manifest, timed by the frame the line first shows in', function () {
    $root = createFramingProject();
    $preview = CinematicPreviewSession::startCapture($root, createFramingDefinition(36), SceneFrameRaster::supports(...),
        ['x' => 30, 'y' => 5, 'width' => 60, 'height' => 20]);
    $folder = makeTemporaryProject('framing-capture-') . '/frames';

    try {
        $written = new CinematicCaptureWriter($folder, new SceneFrameRaster($root . '/assets'))
            ->write(new CinematicCapture($preview, 5.0, 0.4, 10.0), $preview->getSceneGrid());
        $manifest = json_decode((string) file_get_contents($written['manifest']), true, flags: JSON_THROW_ON_ERROR);
        $lines = array_values(array_filter($manifest['frames'], static fn(array $frame): bool => $frame['waiting'] === 'dialogue'));

        expect($written['finished'])->toBeTrue()
            ->and($manifest['status'])->toBe(CinematicPreviewSession::STATUS_COMPLETED)
            ->and($manifest['diagnostics'])->toBe($preview->getDiagnostics())->toBe($written['diagnostics'])->toHaveCount(1)
            ->and($manifest['diagnostics'][0])->toContain(sprintf(' First at %.2f s of the scene; again at ', $lines[0]['sceneSeconds']));
    } finally {
        $preview->dispose();
    }
});
