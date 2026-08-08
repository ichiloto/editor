<?php

declare(strict_types=1);

namespace Ichiloto\Editor\History;

use Closure;

/**
 * A closure-backed command for mutations that already have setter/getter
 * pairs on the data model (map metadata, event fields, database fields).
 */
final class GenericCommand implements Command
{
    /**
     * @param string $label The status-line action label.
     * @param Closure $apply Re-applies the mutation.
     * @param Closure $revert Reverts the mutation.
     */
    public function __construct(
        public readonly string $label,
        private readonly Closure $apply,
        private readonly Closure $revert,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(): void
    {
        ($this->apply)();
    }

    /**
     * @inheritDoc
     */
    public function undo(): void
    {
        ($this->revert)();
    }
}
