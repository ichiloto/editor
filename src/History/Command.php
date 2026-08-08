<?php

declare(strict_types=1);

namespace Ichiloto\Editor\History;

/**
 * Represents one undoable editor mutation.
 *
 * A command is recorded after its mutation has already been applied, so
 * execute() is only invoked again on redo.
 */
interface Command
{
    /**
     * The human-readable action label shown in undo/redo status messages.
     */
    public string $label { get; }

    /**
     * Re-applies the mutation (redo).
     *
     * @return void
     */
    public function execute(): void;

    /**
     * Reverts the mutation (undo).
     *
     * @return void
     */
    public function undo(): void;
}
