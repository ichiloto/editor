<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\IO\Console\TerminalText;

/** @param list<array{type: string, payload: array<string, mixed>}> $messages */
function getNarrationPreviewFrame(array $messages): array
{
    $frames = array_values(array_filter($messages, static fn(array $message): bool => $message['type'] === 'frame'));
    $last = $frames[array_key_last($frames)]['payload'] ?? throw new RuntimeException('No preview frame was sent.');
    if (! $last['present']) {
        throw new RuntimeException('The preview frame upload has not finished.');
    }
    $operations = [];
    $reset = false;
    $viewport = [];
    // A retained frame may span several packets, with reset and images before the final present packet.
    foreach ($frames as $message) {
        $packet = $message['payload'];
        if ($packet['frame'] !== $last['frame']) {
            continue;
        }
        array_push($operations, ...$packet['operations']);
        $reset = $reset || $packet['reset'];
        if (array_key_exists('viewport', $packet)) {
            $viewport = ['viewport' => $packet['viewport']];
        }
    }

    return [...$last, 'operations' => $operations, 'reset' => $reset, ...$viewport];
}

/** Returns the objects written by this frame, not objects retained from earlier frames. */
function getNarrationPreviewObjects(array $frame, string $kind): array
{
    return array_values(array_map(static fn(array $operation): array => $operation['value'],
        array_filter($frame['operations'], static fn(array $operation): bool => $operation['op'] === 'put'
            && $operation['kind'] === $kind)));
}

function acknowledgeNarrationPreviewFrame(array $frame): string
{
    return json_encode(['protocol' => 2, 'type' => 'frame_ack', 'generation' => $frame['generation'],
        'frame' => $frame['frame'], 'presented' => true], JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $this->root = cutsceneProject();
    writeOpeningMaps($this->root);
    $directory = $this->root . '/assets/Data/Presentation';
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    $artDirectory = $this->root . '/assets/Graphics/UI';
    if (! is_dir($artDirectory)) {
        mkdir($artDirectory, 0777, true);
    }
    writeTilesetTestPng($artDirectory . '/narration-frame.png', 12, 12);
    $frame = ['asset' => 'Graphics/UI/narration-frame.png', 'cuts' => [2, 2, 2, 2]];
    file_put_contents($directory . '/dialogue.php', '<?php return ' . var_export([
        'schema' => 'ichiloto.dialogue/1',
        'theme' => ['schema' => 'ichiloto.menu/1', 'colors' => ['text' => [31, 79, 113]],
            'frames' => ['dialogue' => $frame, 'nameplate' => $frame]],
    ], true) . ';');
    $this->text = "Synthetic narration stays readable while the camera travels across the field.\n"
        . 'A separate line belongs to the same timed cue.';
    $this->definition = CinematicDefinition::fromArrays([
        'id' => 'preview-narration', 'name' => 'Preview Narration', 'startMap' => 'skyfield-night',
    ], [
        ['type' => 'parallel', 'lanes' => [
            ['id' => 'words', 'commands' => [['type' => 'narration', 'title' => 'Preview narrator',
                'text' => $this->text, 'seconds' => 20.0]]],
            // Begin the pan after READY has selected the graphical field's viewport.
            ['id' => 'camera', 'commands' => [
                ['type' => 'wait', 'seconds' => 0.2],
                ['type' => 'camera', 'operation' => 'pan',
                    'target' => ['kind' => 'position', 'x' => 45, 'y' => 8], 'seconds' => 2.0],
            ]],
        ]],
    ]);
});

it('uses the themed wide dialogue reading area without controls or duplicate ASCII while the field camera advances', function () {
    $preview = CinematicPreviewSession::start($this->root, $this->definition,
        ['x' => 10, 'y' => 8, 'width' => 100, 'height' => 30]);

    try {
        $preview->step();
        expect($preview->failure())->toBeNull();
        $first = getNarrationPreviewFrame($preview->exchangeScene([previewWindowReady()], $preview->getSceneSessionId()));
        $images = getNarrationPreviewObjects($first, 'canvas_image');
        $body = array_values(array_filter($images, static fn(array $image): bool => str_contains($image['id'], 'dialogue-body')));
        expect($body)->not->toBeEmpty();
        foreach ($body as $image) {
            expect($image['asset'])->toBe('Graphics/UI/narration-frame.png');
        }

        $text = getNarrationPreviewObjects($first, 'canvas_text');
        $prose = array_find($text, static fn(array $layer): bool => str_ends_with($layer['id'], 'dialogue-text'));
        expect($prose)->not->toBeNull();
        $grid = $preview->getSceneGrid();
        $width = $grid->columns * $grid->cellWidth;
        $height = $grid->rows * $grid->cellHeight;
        $bounds = $prose['clipRect'];
        expect($bounds['width'])->toBeGreaterThan($width * 0.75)
            ->and($bounds['width'])->toBeGreaterThan($bounds['height'] * 3)
            ->and($bounds['x'])->toBeGreaterThanOrEqual(0)
            ->and($bounds['x'] + $bounds['width'])->toBeLessThanOrEqual($width)
            ->and($bounds['y'])->toBeGreaterThanOrEqual(0)
            ->and($bounds['y'] + $bounds['height'])->toBeLessThanOrEqual($height)
            ->and(implode("\n", array_column($prose['runs'], 'text')))->toBe($this->text)
            ->and($prose['runs'][0]['foreground'])->toBe(['kind' => 'rgb', 'r' => 31, 'g' => 79, 'b' => 113]);
        expect(array_any([...$text, ...$images], static fn(array $object): bool => preg_match(
            '/dialogue-(auto|advance|ready)/', $object['id']) === 1))->toBeFalse();
        $scalarText = getNarrationPreviewObjects($first, 'text');
        expect(array_column($scalarText, 'id'))->not->toContain('cinematic-overlay')
            ->and(implode(' ', array_merge([], ...array_map(static fn(array $layer): array => array_column($layer['runs'], 'text'), $text))))
            ->not->toContain('Auto Off', 'Auto On', 'Continue', 'Reveal')
            ->and(getNarrationPreviewObjects($first, 'world'))->not->toBeEmpty()
            ->and($first['viewport']['worldId'])->not->toBeEmpty()
            ->and($preview->confirm())->toBeFalse()
            ->and($preview->hasActiveSession())->toBeTrue();

        $before = $preview->snapshot()->values;
        $preview->play();
        $preview->tick(0.5);
        $preview->tick(0.5);
        $next = getNarrationPreviewFrame($preview->exchangeScene([acknowledgeNarrationPreviewFrame($first)], $preview->getSceneSessionId()));
        $after = $preview->snapshot()->values;
        expect($after['camera'])->not->toBe($before['camera'])
            ->and($after['player position'])->toBe($before['player position'])
            ->and($after['map'])->toBe($before['map'])
            ->and($next['viewport']['worldOrigin'])->not->toBe($first['viewport']['worldOrigin'])
            ->and($next['viewport']['worldId'])->toBe($first['viewport']['worldId'])
            ->and($preview->hasActiveSession())->toBeTrue();

        $preview->stop();
        $stopped = getNarrationPreviewFrame($preview->exchangeScene([acknowledgeNarrationPreviewFrame($next)], $preview->getSceneSessionId()));
        $removed = array_column(array_filter($stopped['operations'], static fn(array $operation): bool => $operation['op'] === 'remove'), 'id');
        expect($removed)->toContain($prose['id'], ...array_column($body, 'id'))
            ->and($preview->status())->toBe(CinematicPreviewSession::STATUS_STOPPED)
            ->and($preview->hasActiveSession())->toBeFalse()
            ->and(implode("\n", array_map(TerminalText::stripAnsi(...), $preview->frame())))
            ->not->toContain('Synthetic narration', 'Preview narrator');
    } finally {
        $preview->dispose();
    }
});

it('returns to terminal narration on detach and does not retain stopped text on reattachment', function () {
    $preview = CinematicPreviewSession::start($this->root, $this->definition,
        ['x' => 10, 'y' => 8, 'width' => 100, 'height' => 30]);

    try {
        $preview->step();
        $first = getNarrationPreviewFrame($preview->exchangeScene([previewWindowReady()], $preview->getSceneSessionId()));
        expect(getNarrationPreviewObjects($first, 'canvas_text'))->not->toBeEmpty();
        $preview->detachScene();
        $terminal = array_map(TerminalText::stripAnsi(...), $preview->frame());
        expect($terminal)->toHaveCount(30)
            ->and(mb_strlen($terminal[0]))->toBe(100)
            ->and(implode("\n", $terminal))->toContain('Synthetic narration', 'Preview narrator', 'A separate line')
            ->and($preview->hasActiveSession())->toBeTrue();
        $preview->stop();
        $again = getNarrationPreviewFrame($preview->exchangeScene([previewWindowReady()], $preview->getSceneSessionId()));
        expect($again['reset'])->toBeTrue()
            ->and(getNarrationPreviewObjects($again, 'canvas_text'))->toBeEmpty()
            ->and(getNarrationPreviewObjects($again, 'canvas_image'))->toBeEmpty()
            ->and(array_column(getNarrationPreviewObjects($again, 'text'), 'id'))->not->toContain('cinematic-overlay');
    } finally {
        $preview->dispose();
    }
});

it('keeps terminal text without graphical theme objects when the preview window has no canvas capability', function () {
    $preview = CinematicPreviewSession::start($this->root, $this->definition,
        ['x' => 10, 'y' => 8, 'width' => 100, 'height' => 30]);

    try {
        $preview->step();
        $terminal = implode("\n", array_map(TerminalText::stripAnsi(...), $preview->frame()));
        expect($terminal)->toContain('Synthetic narration', 'Preview narrator', 'A separate line');
        $frame = getNarrationPreviewFrame($preview->exchangeScene([
            json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => []], JSON_THROW_ON_ERROR),
        ], $preview->getSceneSessionId()));
        expect(getNarrationPreviewObjects($frame, 'canvas_text'))->toBeEmpty()
            ->and(getNarrationPreviewObjects($frame, 'canvas_image'))->toBeEmpty();
        $overlay = array_find(getNarrationPreviewObjects($frame, 'text'), static fn(array $layer): bool => $layer['id'] === 'cinematic-overlay');
        expect($overlay)->not->toBeNull()
            ->and(implode(' ', array_column($overlay['runs'], 'text')))->toContain('Synthetic narration', 'Preview narrator')
            ->and($preview->hasActiveSession())->toBeTrue();
    } finally {
        $preview->dispose();
    }
});
