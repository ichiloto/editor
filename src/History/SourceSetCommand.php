<?php

declare(strict_types=1);

namespace Ichiloto\Editor\History;

use Closure;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Storage\SourceSetPlan;
use RuntimeException;
use Throwable;

/**
 * Keeps a written source set and the editor models read from it together
 * through history: executing writes the planned files and reloads the
 * workspace from them; undoing restores every file and the workspace as it
 * was.
 */
final class SourceSetCommand implements Command
{
    private ?ProjectWorkspace $after = null;

    /**
     * @param string $label The status-line action label.
     * @param string $subject What the plan does, for refusals (this actor migration).
     * @param Closure(): ?ProjectWorkspace $getWorkspace
     * @param Closure(ProjectWorkspace): void $replaceWorkspace
     */
    public function __construct(
        public readonly string $label,
        private readonly string $subject,
        private readonly SourceSetPlan $plan,
        private readonly ProjectWorkspace $before,
        private readonly Closure $getWorkspace,
        private readonly Closure $replaceWorkspace,
    ) {
    }

    public function execute(): void
    {
        $this->assertWorkspace($this->before);
        $this->plan->apply();
        try {
            $this->after ??= ProjectWorkspace::fromProject($this->before->projectRoot);
        } catch (Throwable $failure) {
            $this->plan->revert();
            throw $failure;
        }
        ($this->replaceWorkspace)($this->after);
    }

    public function undo(): void
    {
        $this->assertWorkspace($this->after);
        $this->plan->revert();
        ($this->replaceWorkspace)($this->before);
    }

    private function assertWorkspace(?ProjectWorkspace $expected): void
    {
        $current = ($this->getWorkspace)();
        if ($current === null || $current !== $expected) {
            throw new RuntimeException("The workspace changed since {$this->subject}. Reload before trying again.");
        }
        if ($current->hasUnsavedChanges()) {
            throw new RuntimeException("Save or undo pending edits before changing {$this->subject}. No files were changed.");
        }
    }
}
