<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/**
 * The editor window draws a previewed cinematic as the game's graphical field
 * draws it: the Engine composes the frame from the preview's own scene and
 * sends it through the relay one generation at a time, as the window
 * acknowledges each. Synthetic cutscene project only.
 */

const PREVIEW_WINDOW_CAPABILITIES = [
    RendererSessionConfig::SPRITE_SOURCE_RECT, RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_OVERLAY,
    RendererSessionConfig::CANVAS_CLIP_OPACITY, RendererSessionConfig::FRAME_VIEWPORT, RendererSessionConfig::FIELD_MOTION,
    RendererSessionConfig::SPRITE_LIFT, RendererSessionConfig::CANVAS_IMAGE_TONE, RendererSessionConfig::CANVAS_IMAGE_FLIP,
];

function previewWindowReady(): string
{
    return json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => PREVIEW_WINDOW_CAPABILITIES]);
}

/** @param list<array{type: string, payload: array<string, mixed>}> $messages */
function previewFrameGenerations(array $messages): array
{
    return array_values(array_filter(array_map(static fn(array $message): ?int => $message['type'] === 'frame'
        ? ($message['payload']['generation'] ?? null) : null, $messages), static fn(?int $generation): bool => $generation !== null));
}

it('sends nothing until the window says what it can draw, then the scene as the game composes it', function () {
    $root = cutsceneProject();
    $preview = CinematicPreviewSession::start($root, harbourDefinition($root), ['x' => 2, 'y' => 3, 'width' => 40, 'height' => 12]);

    try {
        expect($preview->exchangeScene([]))->toBe([]);
        $sent = $preview->exchangeScene([previewWindowReady()]);
        expect(array_column($sent, 'type'))->toContain('frame')
            ->and(previewFrameGenerations($sent))->not->toBe([]);
    } finally {
        $preview->dispose();
    }
});

it('sends nothing while nothing visible changes, and starts again from a reset when the view is attached again', function () {
    $root = cutsceneProject();
    $preview = CinematicPreviewSession::start($root, harbourDefinition($root), ['x' => 2, 'y' => 3, 'width' => 40, 'height' => 12]);

    try {
        $first = $preview->exchangeScene([previewWindowReady()]);
        $generation = max(previewFrameGenerations($first));
        expect($preview->exchangeScene([json_encode(['protocol' => 2, 'type' => 'frame_ack',
            'generation' => $generation, 'frame' => 1, 'presented' => true])]))->toBe([]);

        $preview->detachScene();
        // Detached, the Terminal picture has the preview's own size again.
        expect(count($preview->frame()))->toBe(12)
            ->and(mb_strlen(Ichiloto\Engine\IO\Console\TerminalText::stripAnsi($preview->frame()[0])))->toBe(40);
        $again = array_values(array_filter($preview->exchangeScene([previewWindowReady()]),
            static fn(array $message): bool => $message['type'] === 'frame'));
        expect($again)->not->toBe([])->and($again[0]['payload']['reset'] ?? false)->toBeTrue();
    } finally {
        $preview->dispose();
    }
});

it('follows the playhead: what the cinematic shows next arrives as the next generation once the last is acknowledged', function () {
    $root = cutsceneProject();
    $preview = CinematicPreviewSession::start($root, harbourDefinition($root), ['x' => 2, 'y' => 3, 'width' => 40, 'height' => 12]);

    try {
        $generation = max(previewFrameGenerations($preview->exchangeScene([previewWindowReady()])));
        $preview->play();
        for ($tick = 0; $tick < 5; $tick++) {
            $preview->tick(CinematicPreviewSession::TICK_SECONDS);
        }
        // The harbour's narration is on screen by now; the graphical view receives it as the next generation.
        $next = previewFrameGenerations($preview->exchangeScene([json_encode(['protocol' => 2, 'type' => 'frame_ack',
            'generation' => $generation, 'frame' => 1, 'presented' => true])]));
        expect($next)->not->toBe([])->and(min($next))->toBeGreaterThan($generation);
    } finally {
        $preview->dispose();
    }
});
