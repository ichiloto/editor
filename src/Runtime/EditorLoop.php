<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Runtime;

/**
 * The deadline-budget frame loop.
 *
 * Each tick is buffered into a single terminal write by the TerminalHost;
 * the loop then sleeps only the remainder of the frame budget, so input
 * latency stays at one frame while idle CPU stays near zero.
 */
final class EditorLoop
{
    /**
     * @param int $frameBudgetMicroseconds The per-frame time budget (~60fps at 16 666 us).
     * @param TerminalHost $terminal The terminal host that buffers each frame.
     */
    public function __construct(
        private readonly int $frameBudgetMicroseconds,
        private readonly TerminalHost $terminal,
    ) {
    }

    /**
     * Runs the frame loop until the continuation callback reports false.
     *
     * @param callable(): bool $shouldContinue Reports whether the loop should keep running.
     * @param callable(): void $tick Advances one frame (input, update, render).
     * @return void
     */
    public function run(callable $shouldContinue, callable $tick): void
    {
        while ($shouldContinue()) {
            $frameStartedAt = microtime(true);

            $this->terminal->beginFrame();

            try {
                $tick();
            } finally {
                $this->terminal->endFrame();
            }

            $elapsedMicroseconds = (int) ((microtime(true) - $frameStartedAt) * 1_000_000);
            usleep(max(0, $this->frameBudgetMicroseconds - $elapsedMicroseconds));
        }
    }
}
