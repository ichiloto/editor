<?php

declare(strict_types=1);

/**
 * The shared driver the tests exchange frames through, for a preview itself
 * and for a session's answers alike: it gives a frame whose upload yielded
 * its further turns, as an editor window does across its ticks, and stops at
 * a bound. A synthetic window stands in for the preview, and scripted
 * answers for a session.
 */

/**
 * A preview that sends each of its scripted exchanges in turn, records the
 * events it was given, and has a delivery pending for its first exchanges.
 *
 * @param list<list<array<string, mixed>>> $exchanges
 * @param int $pendingExchanges How many exchanges leave a delivery pending.
 */
function createScriptedPreview(array $exchanges, int $pendingExchanges = 0): object
{
    return new class($exchanges, $pendingExchanges) {
        /** @var list<list<string>> */
        public array $received = [];

        public function __construct(private array $exchanges, private readonly int $pendingExchanges)
        {
        }

        public function exchangeScene(array $events, ?string $sessionId = null): array
        {
            $this->received[] = $events;

            return array_shift($this->exchanges) ?? [];
        }

        public function hasPendingSceneDelivery(): bool
        {
            return count($this->received) <= $this->pendingExchanges;
        }
    };
}

function createFramePacket(int $frame, bool $present): array
{
    return ['type' => 'frame', 'payload' => ['frame' => $frame, 'generation' => $frame, 'present' => $present, 'operations' => [], 'reset' => false]];
}

it('gives an unfinished frame further exchanges with no events until it is presented', function () {
    $preview = createScriptedPreview([[createFramePacket(1, false)], [createFramePacket(1, false)], [createFramePacket(1, true)], [createFramePacket(2, true)]]);

    $messages = exchangePreviewSceneUntilPresented($preview, ['ready'], 'session');

    expect($messages)->toHaveCount(3)
        ->and($messages[2]['payload']['present'])->toBeTrue()
        ->and($preview->received)->toBe([['ready'], [], []]);
});

it('keeps exchanging while a delivery is pending and no packet of its frame has been sent yet', function () {
    $preview = createScriptedPreview([[], [], [createFramePacket(1, false)], [createFramePacket(1, true)]], pendingExchanges: 2);

    expect(exchangePreviewSceneUntilPresented($preview, ['ready'], 'session'))
        ->toBe([createFramePacket(1, false), createFramePacket(1, true)])
        ->and($preview->received)->toBe([['ready'], [], [], []]);
});

it('returns after one exchange when the frame is whole or there is nothing to send', function (array $first) {
    $preview = createScriptedPreview([$first, [createFramePacket(9, true)]]);

    expect(exchangePreviewSceneUntilPresented($preview, ['ready'], 'session'))->toBe($first)
        ->and($preview->received)->toBe([['ready']]);
})->with([
    'a presented frame' => [[createFramePacket(1, true)]],
    'nothing to send' => [[]],
]);

it('drives a session\'s answers by their own report, giving the last answer with every message sent', function () {
    $answers = [
        ['sessionId' => 'epoch', 'diagnostics' => [], 'messages' => [], 'deliveryPending' => true],
        ['sessionId' => 'epoch', 'diagnostics' => [], 'messages' => [createFramePacket(1, false)], 'deliveryPending' => true],
        ['sessionId' => 'epoch', 'diagnostics' => ['late'], 'messages' => [createFramePacket(1, true)], 'deliveryPending' => false],
        ['sessionId' => 'epoch', 'diagnostics' => [], 'messages' => [createFramePacket(2, true)], 'deliveryPending' => false],
    ];
    $received = [];
    $exchange = static function (array $events) use (&$answers, &$received): array {
        $received[] = $events;

        return array_shift($answers);
    };

    expect(exchangeSceneUntilPresented($exchange, ['ready']))->toBe(['sessionId' => 'epoch', 'diagnostics' => ['late'],
        'messages' => [createFramePacket(1, false), createFramePacket(1, true)], 'deliveryPending' => false])
        ->and($received)->toBe([['ready'], [], []]);
});

it('stops a session\'s answers at its bound while they stay pending', function () {
    $exchanges = 0;
    $exchange = static function (array $events) use (&$exchanges): array {
        $exchanges++;

        return ['messages' => [], 'deliveryPending' => true];
    };

    expect(fn() => exchangeSceneUntilPresented($exchange, [], 4))->toThrow(RuntimeException::class, 'not whole within 4 exchanges')
        ->and($exchanges)->toBe(4);
});

it('stops at its bound when the frame is never whole', function (array $each, int $pendingExchanges) {
    $preview = createScriptedPreview(array_fill(0, 8, $each), $pendingExchanges);

    expect(fn() => exchangePreviewSceneUntilPresented($preview, [], 'session', 4))
        ->toThrow(RuntimeException::class, 'not whole within 4 exchanges')
        ->and($preview->received)->toHaveCount(4);
})->with([
    'an unfinished frame every time' => [[createFramePacket(1, false)], 0],
    'a delivery pending and nothing sent' => [[], 99],
]);
