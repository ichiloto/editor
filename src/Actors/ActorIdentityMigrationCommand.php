<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Actors;

use Closure;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\ProjectWorkspace;
use RuntimeException;
use Throwable;

/** Keeps source migration and the corresponding editor models together through history. */
final class ActorIdentityMigrationCommand implements Command
{
    public readonly string $label;
    private ?ProjectWorkspace $after = null;

    /**
     * @param Closure(): ?ProjectWorkspace $getWorkspace
     * @param Closure(ProjectWorkspace): void $replaceWorkspace
     */
    public function __construct(
        private readonly ActorIdentityMigrationPlan $plan,
        private readonly ProjectWorkspace $before,
        private readonly Closure $getWorkspace,
        private readonly Closure $replaceWorkspace,
    ) {
        $this->label = 'Migrate actor identities and references';
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
            throw new RuntimeException('The workspace changed since this actor migration. Reload before migrating again.');
        }
        if ($current->hasUnsavedChanges()) {
            throw new RuntimeException('Save or undo pending edits before changing the actor migration. No files were changed.');
        }
    }
}
