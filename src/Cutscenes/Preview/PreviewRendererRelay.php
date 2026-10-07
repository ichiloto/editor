<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererMessageType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererTransportState;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\Interfaces\RendererTransportInterface;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/**
 * The renderer transport of a preview whose renderer is the editor window.
 *
 * The Engine's RendererClient and RendererPresentation drive it exactly as
 * they drive a renderer process: what they send is queued in order for the
 * window to collect, and what the window answers (its READY with the
 * capabilities its scene view supports, frame acknowledgements and
 * rejections) arrives as renderer event lines, decoded by the Engine's own
 * RendererEvent::fromJson. Nothing here reads or writes a frame's contents;
 * the window and the Engine own the protocol at both ends.
 */
final class PreviewRendererRelay implements RendererTransportInterface
{
    /** How many bytes of unread frames the window may fall behind by before sending waits. */
    public const int MAX_PENDING_BYTES = 8_388_608;

    private RendererTransportState $state = RendererTransportState::NEW;
    /** @var list<RendererMessage> Sent and not yet collected by the window, in order. */
    private array $outgoing = [];
    private int $outgoingBytes = 0;
    /** @var list<RendererEvent> Answered by the window and not yet polled by the client. */
    private array $incoming = [];
    private ?RendererSessionConfig $session = null;

    public function start(RendererSessionConfig $session): void
    {
        $this->session = $session;
        $this->outgoing = $this->incoming = [];
        $this->outgoingBytes = 0;
        $this->state = RendererTransportState::RUNNING;
    }

    public function isRunning(): bool
    {
        return $this->state === RendererTransportState::RUNNING;
    }

    public function send(RendererMessage $message): void
    {
        if (! $this->isRunning()) {
            throw new RendererTransportException('The preview window is not attached.');
        }
        $this->outgoing[] = $message;
        $this->outgoingBytes += strlen($message->encode());
    }

    public function trySend(RendererMessage $message): bool
    {
        if (! $this->isRunning()) {
            throw new RendererTransportException('The preview window is not attached.');
        }
        if ($this->outgoingBytes >= self::MAX_PENDING_BYTES) {
            return false;
        }
        $this->send($message);

        return true;
    }

    public function getPendingWriteBytes(): int
    {
        return $this->outgoingBytes;
    }

    public function pollEvents(float $waitSeconds = 0.0): array
    {
        $events = $this->incoming;
        $this->incoming = [];

        return $events;
    }

    public function shutdown(): ?int
    {
        $this->state = RendererTransportState::STOPPED;
        $this->outgoing = [];
        $this->outgoingBytes = 0;

        return 0;
    }

    public function getState(): RendererTransportState
    {
        return $this->state;
    }

    public function getExitCode(): ?int
    {
        return $this->state === RendererTransportState::STOPPED ? 0 : null;
    }

    public function getDiagnostics(): string
    {
        return sprintf('Preview window relay: %d message(s), %d byte(s) waiting; %d event(s) unread.',
            count($this->outgoing), $this->outgoingBytes, count($this->incoming));
    }

    /**
     * The window's answers, as renderer event lines, for the client to poll.
     *
     * @param list<string> $lines
     * @throws \Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException When a line is not a renderer event.
     */
    public function receive(array $lines): void
    {
        foreach ($lines as $line) {
            $this->incoming[] = RendererEvent::fromJson($line);
        }
    }

    /**
     * What has been sent since the window last collected, in order: each
     * message's type and payload, never its envelope.
     *
     * @return list<array{type: string, payload: array<string, mixed>}>
     */
    public function collect(): array
    {
        $messages = array_map(static fn(RendererMessage $message): array => ['type' => $message->type->value, 'payload' => $message->payload],
            $this->outgoing);
        $this->outgoing = [];
        $this->outgoingBytes = 0;

        return $messages;
    }

    /** The session the client started, with the capabilities it requires and asks for. */
    public function getSession(): ?RendererSessionConfig
    {
        return $this->session;
    }

    /** Whether a frame is waiting for the window. */
    public function hasFrame(): bool
    {
        return array_any($this->outgoing, static fn(RendererMessage $message): bool => $message->type === RendererMessageType::FRAME);
    }
}
