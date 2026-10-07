<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Preview\PreviewRendererRelay;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/**
 * The editor window is a preview's renderer: the Engine's own client and
 * presentation send frames through the relay, and the window's answers come
 * back as renderer event lines.
 */

/** A client started over a relay, with the window's READY already answered. */
function startRelayClient(PreviewRendererRelay $relay, array $capabilities): RendererClient
{
    $client = new RendererClient($relay);
    $client->start(new RendererSessionConfig('Preview', sys_get_temp_dir(), new RendererGridConfig(10, 4, 16, 24), RendererProtocolVersion::V2));
    $relay->receive([json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => $capabilities])]);
    $client->pump();

    return $client;
}

it('negotiates what the window supports from its READY, as with a renderer process', function () {
    $client = startRelayClient(new PreviewRendererRelay(), [RendererSessionConfig::GRAPHICAL_CANVAS]);

    expect($client->supports(RendererSessionConfig::GRAPHICAL_CANVAS))->toBeTrue()
        ->and($client->supports(RendererSessionConfig::CANVAS_OVERLAY))->toBeFalse();
});

it('queues each frame for the window in order, payload without envelope, and returns its acknowledgements', function () {
    $relay = new PreviewRendererRelay();
    $client = startRelayClient($relay, [RendererSessionConfig::GRAPHICAL_CANVAS]);
    $presentation = new RendererPresentation($client, new RendererGridConfig(10, 4, 16, 24));

    $presentation->presentFrame($presentation->prepareCanvas(new PresentationCanvas(160, 96)));
    $sent = $relay->collect();
    expect($sent)->not->toBeEmpty()
        ->and(array_column($sent, 'type'))->toContain('frame')
        ->and(array_key_exists('protocol', $sent[0]['payload']))->toBeFalse()
        ->and($relay->collect())->toBe([])
        ->and($relay->getPendingWriteBytes())->toBe(0);

    $generation = $sent[array_key_last($sent)]['payload']['generation'] ?? 1;
    $relay->receive([json_encode(['protocol' => 2, 'type' => 'frame_ack', 'generation' => $generation, 'frame' => 1, 'presented' => true])]);
    $client->pump();
    expect(array_map(static fn($event) => $event->generation, $client->drainFrameAcknowledgements()))->toBe([$generation]);
});

it('refuses an answer that is not a renderer event, and anything sent once the window is detached', function () {
    $relay = new PreviewRendererRelay();
    startRelayClient($relay, []);

    expect(fn() => $relay->receive(['not json']))->toThrow(RendererProtocolException::class);
    $relay->shutdown();
    expect(fn() => $relay->trySend(new \Ichiloto\Engine\Rendering\Transport\RendererMessage(
        \Ichiloto\Engine\Rendering\Transport\Enumerations\RendererMessageType::FRAME)))->toThrow(RendererTransportException::class);
});
