<?php

declare(strict_types=1);

namespace Ichiloto\Editor\UI;

use Closure;

/**
 * The update/render contract for one editor pane.
 *
 * A panel owns its dirty flag: input handlers mutate state and mark the
 * affected panels dirty; the loop renders dirty panels once per tick, so an
 * idle frame writes zero bytes. Focus gates input — an unfocused panel's
 * handleInput() early-returns.
 *
 * Panels deliberately do not own editor state yet: their render and input
 * bodies live on the Editor coordinator and are handed in as closures, which
 * keeps this extraction byte-identical while establishing the seam that
 * later phases (binding tables, per-panel state) build on.
 */
abstract class Panel
{
    /**
     * Whether this panel needs repainting on the next render pass.
     */
    private bool $isDirty = false;

    /**
     * @param string $id The pane identifier (matches the editor focus constants).
     * @param Closure(): void $renderer Paints the panel from current editor state.
     * @param Closure(string, string): void $inputHandler Receives raw and normalized input while focused.
     * @param Closure(): bool $focusResolver Reports whether this panel has focus.
     */
    public function __construct(
        public readonly string $id,
        private readonly Closure $renderer,
        private readonly Closure $inputHandler,
        private readonly Closure $focusResolver,
    ) {
    }

    /**
     * Flags the panel for repainting on the next render pass.
     *
     * @return void
     */
    final public function markDirty(): void
    {
        $this->isDirty = true;
    }

    /**
     * Clears the repaint flag (used when a full-screen redraw supersedes it).
     *
     * @return void
     */
    final public function clearDirty(): void
    {
        $this->isDirty = false;
    }

    /**
     * Returns whether the panel needs repainting.
     *
     * @return bool
     */
    final public function isDirty(): bool
    {
        return $this->isDirty;
    }

    /**
     * Returns whether the panel currently has focus.
     *
     * @return bool
     */
    final public function hasFocus(): bool
    {
        return ($this->focusResolver)();
    }

    /**
     * Advances per-panel state once per tick. Panels are currently pure
     * views over editor state, so the default is a no-op.
     *
     * @return void
     */
    public function update(): void
    {
    }

    /**
     * Handles one input token; unfocused panels ignore input.
     *
     * @param string $input The raw input token.
     * @param string $normalizedInput The lowercased input token.
     * @return void
     */
    final public function handleInput(string $input, string $normalizedInput): void
    {
        if (! $this->hasFocus()) {
            return;
        }

        ($this->inputHandler)($input, $normalizedInput);
    }

    /**
     * Paints the panel and clears its dirty flag.
     *
     * @return void
     */
    final public function render(): void
    {
        ($this->renderer)();
        $this->isDirty = false;
    }
}
