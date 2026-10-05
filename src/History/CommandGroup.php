<?php

declare(strict_types=1);

namespace Ichiloto\Editor\History;

/**
 * Several commands an author made as one action, undone and redone
 * together: a click that sets the ground point of two images showing the
 * same stance. Undo reverts them last first; redo re-applies them in order.
 */
final class CommandGroup implements Command
{
    /**
     * @param string $label The status-line action label.
     * @param non-empty-list<Command> $commands The commands, in the order they were made.
     */
    public function __construct(
        public readonly string $label,
        private readonly array $commands,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(): void
    {
        foreach ($this->commands as $command) {
            $command->execute();
        }
    }

    /**
     * @inheritDoc
     */
    public function undo(): void
    {
        foreach (array_reverse($this->commands) as $command) {
            $command->undo();
        }
    }
}
