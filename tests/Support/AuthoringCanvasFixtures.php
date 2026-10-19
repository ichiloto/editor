<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/** The editor window's READY, with what a map's canvas draws, signs included. */
function canvasWindowReady(): string
{
    return json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => [...json_decode(previewWindowReady(), true)['capabilities'],
        RendererSessionConfig::TEXT_LAYOUT, RendererSessionConfig::CANVAS_LABELS, RendererSessionConfig::WORLD_CANVAS,
        RendererSessionConfig::SPRITE_PIVOT, RendererSessionConfig::TILE_BATCHES]], JSON_THROW_ON_ERROR);
}

/** @return list<array<string, mixed>> */
function findCanvasMessages(array $answer, string $type): array
{
    return array_values(array_filter($answer['messages'], static fn(array $message): bool => $message['type'] === $type));
}

/** The window's acknowledgement of every frame an exchange sent it. */
function acknowledgeCanvasFrames(array $answer): array
{
    return array_map(static fn(array $frame): string => json_encode(['protocol' => 2, 'type' => 'frame_ack',
        'generation' => $frame['payload']['generation'], 'frame' => $frame['payload']['frame'], 'presented' => true], JSON_THROW_ON_ERROR),
        findCanvasMessages($answer, 'frame'));
}

/**
 * A map's whole picture, as a newly opened editing canvas first draws it: the exchange's answer, with every
 * operation of the frames it sent.
 *
 * @param array<string, string> $variants
 * @return array<string, mixed>
 */
function drawAuthoringCanvas(EditorSession $session, string $mapId, array $variants = [], float $seconds = 0): array
{
    $session->closeAuthoringCanvas($mapId);
    $opened = $session->exchangeAuthoringCanvas($mapId, [], $variants, $seconds);
    $drawn = $session->exchangeAuthoringCanvas($mapId, [canvasWindowReady()], $variants, $seconds, $opened['sessionId']);
    $session->closeAuthoringCanvas($mapId);

    return [...$drawn, 'operations' => array_merge(...array_map(static fn(array $frame): array => $frame['payload']['operations'],
        findCanvasMessages($drawn, 'frame')))];
}
