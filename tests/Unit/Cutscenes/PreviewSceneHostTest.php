<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
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
    $grid = new RendererGridConfig(40, 12, 10, 20);

    try {
        expect($preview->exchangeScene($grid, []))->toBe([]);
        $sent = $preview->exchangeScene($grid, [previewWindowReady()]);
        expect(array_column($sent, 'type'))->toContain('frame')
            ->and(previewFrameGenerations($sent))->not->toBe([]);
    } finally {
        $preview->dispose();
    }
});

it('sends nothing while nothing visible changes, and starts again from a reset when the view is attached again', function () {
    $root = cutsceneProject();
    $preview = CinematicPreviewSession::start($root, harbourDefinition($root), ['x' => 2, 'y' => 3, 'width' => 40, 'height' => 12]);
    $grid = new RendererGridConfig(40, 12, 10, 20);

    try {
        $first = $preview->exchangeScene($grid, [previewWindowReady()]);
        $generation = max(previewFrameGenerations($first));
        expect($preview->exchangeScene($grid, [json_encode(['protocol' => 2, 'type' => 'frame_ack',
            'generation' => $generation, 'frame' => 1, 'presented' => true])]))->toBe([]);

        $preview->detachScene();
        // The Terminal picture is unchanged by the graphical view coming and going.
        expect(count($preview->frame()))->toBe(12);
        $again = array_values(array_filter($preview->exchangeScene($grid, [previewWindowReady()]),
            static fn(array $message): bool => $message['type'] === 'frame'));
        expect($again)->not->toBe([])->and($again[0]['payload']['reset'] ?? false)->toBeTrue();
    } finally {
        $preview->dispose();
    }
});
