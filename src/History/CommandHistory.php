<?php

declare(strict_types=1);

namespace Ichiloto\Editor\History;

/**
 * A bounded undo/redo stack over recorded editor commands.
 *
 * Commands are recorded after their mutation has been applied, so record()
 * never calls execute(). Recording a new command clears the redo stack, and
 * the oldest entries are evicted once the cap is reached.
 */
final class CommandHistory
{
    /**
     * @var Command[]
     */
    private array $undoStack = [];

    /**
     * @var Command[]
     */
    private array $redoStack = [];

    /**
     * @param int $capacity The maximum number of retained undo entries.
     */
    public function __construct(private readonly int $capacity = 500)
    {
    }

    /**
     * Records an already-applied command.
     *
     * @param Command $command The applied command.
     * @return void
     */
    public function record(Command $command): void
    {
        $this->undoStack[] = $command;
        $this->redoStack = [];

        if (count($this->undoStack) > max(1, $this->capacity)) {
            array_shift($this->undoStack);
        }
    }

    /**
     * Undoes the most recent command.
     *
     * @return Command|null The undone command, or null when the stack is empty.
     */
    public function undo(): ?Command
    {
        $command = array_pop($this->undoStack);

        if (! $command instanceof Command) {
            return null;
        }

        $command->undo();
        $this->redoStack[] = $command;

        return $command;
    }

    /**
     * Re-applies the most recently undone command.
     *
     * @return Command|null The redone command, or null when nothing was undone.
     */
    public function redo(): ?Command
    {
        $command = array_pop($this->redoStack);

        if (! $command instanceof Command) {
            return null;
        }

        $command->execute();
        $this->undoStack[] = $command;

        return $command;
    }

    /**
     * Returns whether an undo entry is available.
     *
     * @return bool
     */
    public function canUndo(): bool
    {
        return $this->undoStack !== [];
    }

    /**
     * Returns whether a redo entry is available.
     *
     * @return bool
     */
    public function canRedo(): bool
    {
        return $this->redoStack !== [];
    }

    /**
     * Returns the number of retained undo entries.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->undoStack);
    }

    /**
     * Empties both stacks (workspace reloads, map identity changes).
     *
     * @return void
     */
    public function clear(): void
    {
        $this->undoStack = [];
        $this->redoStack = [];
    }
}
