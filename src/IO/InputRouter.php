<?php

declare(strict_types=1);

namespace Ichiloto\Editor\IO;

use Closure;
use Ichiloto\Editor\UI\Modal;
use Ichiloto\Editor\UI\ModalStack;

/**
 * Routes decoded input tokens to the active context.
 *
 * The routing order is fixed and mirrors the editor's historic dispatch:
 *
 *   1. safety modals (status detail, unsaved guard, rename confirm) consume
 *      everything;
 *   2. the global status-detail shortcut (Ctrl+E);
 *   3. the Database screen consumes everything while open;
 *   4. the global database shortcut (Ctrl+D / F2);
 *   5. dialog modals (spawn round trip, destination, loot, option, type);
 *   6. the shared mouse interceptor;
 *   7. remaining modals (character map, delete confirm);
 *   8. the inline text-edit gate (inspector field editing);
 *   9. the base binding table (global shortcuts);
 *  10. the focused-pane fallback.
 *
 * Modal handlers register per Modal case; base-mode shortcuts register as an
 * ordered KeyBinding table. The active modal comes from the ModalStack, so
 * dispatch and overlay rendering share one source of truth.
 */
final class InputRouter
{
    /**
     * The Ctrl+E byte that opens the status-detail overlay from any
     * non-safety context.
     */
    private const string KEY_STATUS_DETAIL = "\x05";
    /**
     * The Ctrl+D byte that opens the Database screen from any non-safety
     * context. Chosen over the historic `!` (now free for painting) because
     * a control byte can never collide with an authorable glyph, and it
     * matches the editor's Ctrl+letter global-shortcut family.
     */
    private const string KEY_DATABASE = "\x04";
    /**
     * F2 aliases for the Database shortcut: the SS3 encoding (`\033OQ`,
     * xterm-style application mode) and the CSI encoding (`\033[12~`,
     * VT/linux-style). Both tokenize as single events in InputDecoder.
     */
    private const array KEY_DATABASE_F2_SEQUENCES = ["\033OQ", "\033[12~"];
    /**
     * The human-readable label for the Database shortcut.
     */
    public const string KEY_DATABASE_LABEL = 'Ctrl+D / F2';

    /**
     * @var array<string, Closure(string, string): void> Handlers keyed by Modal value.
     */
    private array $modalHandlers = [];
    /**
     * @var KeyBinding[] The ordered base-mode binding table.
     */
    private array $baseBindings = [];
    /**
     * Opens the status-detail overlay (Ctrl+E).
     */
    private ?Closure $statusDetailShortcut = null;
    /**
     * Opens the Database screen (Ctrl+D / F2).
     */
    private ?Closure $databaseShortcut = null;
    /**
     * Attempts mouse-event handling; returns whether the input was consumed.
     */
    private ?Closure $mouseInterceptor = null;
    /**
     * Reports whether an inline text edit is capturing keystrokes.
     */
    private ?Closure $textEditGate = null;
    /**
     * Handles keystrokes while an inline text edit is active.
     */
    private ?Closure $textEditHandler = null;
    /**
     * Receives anything the binding table did not consume.
     */
    private ?Closure $fallback = null;

    /**
     * @param ModalStack $modals The shared modal stack that resolves the active context.
     */
    public function __construct(private readonly ModalStack $modals)
    {
    }

    /**
     * Registers the input handler for one modal.
     *
     * @param Modal $modal The modal the handler serves.
     * @param callable(string, string): void $handler Receives the raw and normalized input.
     * @return void
     */
    public function bindModal(Modal $modal, callable $handler): void
    {
        $this->modalHandlers[$modal->value] = $handler(...);
    }

    /**
     * Registers the Ctrl+E status-detail shortcut action.
     *
     * @param callable(): void $action The action opening the overlay.
     * @return void
     */
    public function onStatusDetailShortcut(callable $action): void
    {
        $this->statusDetailShortcut = $action(...);
    }

    /**
     * Registers the Ctrl+D / F2 database shortcut action.
     *
     * @param callable(): void $action The action opening the Database screen.
     * @return void
     */
    public function onDatabaseShortcut(callable $action): void
    {
        $this->databaseShortcut = $action(...);
    }

    /**
     * Registers the shared mouse interceptor.
     *
     * @param callable(string): bool $interceptor Returns whether the input was consumed.
     * @return void
     */
    public function setMouseInterceptor(callable $interceptor): void
    {
        $this->mouseInterceptor = $interceptor(...);
    }

    /**
     * Registers the inline text-edit gate and handler.
     *
     * @param callable(): bool $isActive Reports whether an inline edit is active.
     * @param callable(string): void $handler Receives the raw input while active.
     * @return void
     */
    public function bindTextEditing(callable $isActive, callable $handler): void
    {
        $this->textEditGate = $isActive(...);
        $this->textEditHandler = $handler(...);
    }

    /**
     * Appends bindings to the ordered base-mode table.
     *
     * @param KeyBinding ...$bindings The bindings, in dispatch order.
     * @return void
     */
    public function bindBase(KeyBinding ...$bindings): void
    {
        foreach ($bindings as $binding) {
            $this->baseBindings[] = $binding;
        }
    }

    /**
     * Registers the focused-pane fallback handler.
     *
     * @param callable(string, string): void $fallback Receives the raw and normalized input.
     * @return void
     */
    public function setFallback(callable $fallback): void
    {
        $this->fallback = $fallback(...);
    }

    /**
     * Routes one decoded input token to the active context.
     *
     * @param string $input The decoded input token.
     * @return void
     */
    public function route(string $input): void
    {
        if ($input === '') {
            return;
        }

        $normalized = strtolower($input);
        $active = $this->modals->active();

        // 1. Safety modals consume everything, including global shortcuts.
        if ($active !== null && $active->priority() <= Modal::RENAME_CONFIRMATION->priority()) {
            $this->dispatchModal($active, $input, $normalized);
            return;
        }

        // 2. The status-detail overlay opens from any remaining context.
        if ($input === self::KEY_STATUS_DETAIL && $this->statusDetailShortcut !== null) {
            ($this->statusDetailShortcut)();
            return;
        }

        // 3. The Database screen consumes everything while open.
        if ($active === Modal::DATABASE) {
            $this->dispatchModal($active, $input, $normalized);
            return;
        }

        // 4. The Database opens from any remaining context (unless it is
        //    already open beneath a higher modal such as help).
        if ($this->databaseShortcut !== null && ! $this->modals->has(Modal::DATABASE) && $this->isDatabaseKey($input)) {
            ($this->databaseShortcut)();
            return;
        }

        // 5. Dialog modals sit above the mouse interceptor.
        if ($active !== null && $active->priority() <= Modal::EVENT_TYPE_DIALOG->priority()) {
            $this->dispatchModal($active, $input, $normalized);
            return;
        }

        // 6. Mouse events route even under the remaining modals.
        if ($this->mouseInterceptor !== null && ($this->mouseInterceptor)($input)) {
            return;
        }

        // 7. Remaining modals (character map, delete confirmation).
        if ($active !== null) {
            $this->dispatchModal($active, $input, $normalized);
            return;
        }

        // 8. Inline text editing captures the keyboard.
        if ($this->textEditGate !== null && ($this->textEditGate)() && $this->textEditHandler !== null) {
            ($this->textEditHandler)($input);
            return;
        }

        // 9. The base binding table.
        foreach ($this->baseBindings as $binding) {
            if ($binding->dispatch($input, $normalized)) {
                return;
            }
        }

        // 10. The focused pane.
        if ($this->fallback !== null) {
            ($this->fallback)($input, $normalized);
        }
    }

    /**
     * Returns whether the token is the Database shortcut (Ctrl+D or F2).
     *
     * @param string $input The decoded input token.
     * @return bool
     */
    public function isDatabaseKey(string $input): bool
    {
        if ($input === self::KEY_DATABASE) {
            return true;
        }

        foreach (self::KEY_DATABASE_F2_SEQUENCES as $sequence) {
            if (str_contains($input, $sequence)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the help-overlay descriptors for every documented shortcut.
     *
     * The list is generated from the live dispatch configuration — the
     * router-owned interceptors plus every labeled base binding — so the
     * help overlay can never go stale against the binding tables.
     *
     * @return array<int, array{key: string, description: string}>
     */
    public function describeBindings(): array
    {
        $entries = [];

        if ($this->databaseShortcut !== null) {
            $entries[] = ['key' => self::KEY_DATABASE_LABEL, 'description' => 'Open the Database screen'];
        }

        if ($this->statusDetailShortcut !== null) {
            $entries[] = ['key' => 'Ctrl+E', 'description' => 'Open the status detail overlay'];
        }

        foreach ($this->baseBindings as $binding) {
            $descriptor = $binding->describe();

            if ($descriptor !== null) {
                $entries[] = $descriptor;
            }
        }

        return $entries;
    }

    /**
     * Invokes the registered handler for a modal.
     *
     * @param Modal $modal The active modal.
     * @param string $input The raw input token.
     * @param string $normalized The lowercased input token.
     * @return void
     */
    private function dispatchModal(Modal $modal, string $input, string $normalized): void
    {
        $handler = $this->modalHandlers[$modal->value] ?? null;

        if ($handler !== null) {
            $handler($input, $normalized);
        }
    }
}
