<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;

/**
 * The Engine sends a view's frame over as many exchanges as its upload
 * needs, and one exchange may carry none of it, so every exchange's answer
 * says whether a delivery is pending. A window, or a test, that sees only
 * the answers can tell a frame still on its way from a view with nothing to
 * send. Synthetic projects only.
 */

/**
 * A session with one of its graphical views open, the view's exchange, and its window's READY.
 *
 * @return array{0: EditorSession, 1: Closure(list<string>, ?string): array<string, mixed>, 2: string}
 */
function openDeliveryReportView(string $view): array
{
    if ($view === 'map canvas') {
        $session = EditorSession::open(mapGraphicsProject());

        return [$session, static fn(array $events, ?string $sessionId): array
            => $session->exchangeAuthoringCanvas('test-map', $events, sessionId: $sessionId), canvasWindowReady()];
    }
    if ($view === 'effect field') {
        $root = effectProject();
        writeHarbourNewGame($root, 'harbour', 2, 3);
        $session = EditorSession::open($root);
        $session->showEffectOnField(findEffectIndex($session, 'ember-spark'), 0, 40, 12);

        return [$session, $session->exchangeEffectFieldScene(...), previewWindowReady()];
    }
    $session = EditorSession::open(cutsceneProject());
    $session->startCinematicPreview(0, 40, 12);

    return [$session, $session->exchangeCinematicScene(...), previewWindowReady()];
}

it('says a delivery is pending from the window\'s READY until the frame is whole, and neither before nor after', function (string $view) {
    [$session, $exchange, $ready] = openDeliveryReportView($view);

    try {
        // Nothing is sent before the window says what it can draw, so nothing is pending: asking again would bring nothing.
        $opened = $exchange([], null);
        expect($opened['messages'])->toBe([])->and($opened['deliveryPending'])->toBeFalse();
        $waiting = $exchange([], $opened['sessionId']);
        expect($waiting['messages'])->toBe([])->and($waiting['deliveryPending'])->toBeFalse();

        // The report alone carries a window to the whole frame: it exchanges while one is pending, and no longer.
        $answer = $exchange([$ready], $opened['sessionId']);
        $messages = $answer['messages'];
        for ($exchanges = 1; $answer['deliveryPending']; $exchanges++) {
            expect($exchanges)->toBeLessThan(64);
            $answer = $exchange([], $opened['sessionId']);
            array_push($messages, ...$answer['messages']);
        }
        expect(previewFrameGenerations($messages))->not->toBe([])
            ->and(isPreviewFrameUnfinished($messages))->toBeFalse();

        // The whole frame acknowledged, the view has nothing more to send.
        $settled = $exchange(acknowledgeCanvasFrames(['messages' => $messages]), $opened['sessionId']);
        expect($settled['messages'])->toBe([])->and($settled['deliveryPending'])->toBeFalse();
    } finally {
        $session->close();
    }
})->with(['cinematic', 'effect field', 'map canvas']);
