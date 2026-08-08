<?php

declare(strict_types=1);

namespace Ichiloto\Editor\UI;

/**
 * Tracks which modal surfaces are open.
 *
 * Modals push in interaction order; the active modal — the one that receives
 * input and renders on top — is resolved by Modal declaration priority, which
 * makes the stack provably equivalent to the boolean dispatch cascade it
 * replaced even if flags are toggled out of nesting order.
 */
final class ModalStack
{
    /**
     * @var Modal[] The open modals in push order.
     */
    private array $stack = [];

    /**
     * Opens a modal (no-op when it is already open).
     *
     * @param Modal $modal The modal to open.
     * @return void
     */
    public function push(Modal $modal): void
    {
        if (! $this->has($modal)) {
            $this->stack[] = $modal;
        }
    }

    /**
     * Closes a modal wherever it sits in the stack (no-op when closed).
     *
     * @param Modal $modal The modal to close.
     * @return void
     */
    public function remove(Modal $modal): void
    {
        $this->stack = array_values(array_filter(
            $this->stack,
            static fn(Modal $open): bool => $open !== $modal,
        ));
    }

    /**
     * Returns whether the given modal is open.
     *
     * @param Modal $modal The modal to check.
     * @return bool
     */
    public function has(Modal $modal): bool
    {
        return in_array($modal, $this->stack, true);
    }

    /**
     * Returns the modal that should receive input and render on top.
     *
     * @return Modal|null
     */
    public function active(): ?Modal
    {
        $active = null;

        foreach ($this->stack as $modal) {
            if ($active === null || $modal->priority() < $active->priority()) {
                $active = $modal;
            }
        }

        return $active;
    }

    /**
     * Returns the most recently opened modal.
     *
     * @return Modal|null
     */
    public function top(): ?Modal
    {
        return $this->stack === [] ? null : $this->stack[count($this->stack) - 1];
    }

    /**
     * Returns whether no modal is open.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->stack === [];
    }

    /**
     * Closes every modal.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->stack = [];
    }
}
