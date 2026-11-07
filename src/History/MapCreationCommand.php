<?php

declare(strict_types=1);

namespace Ichiloto\Editor\History;

use Closure;
use Ichiloto\Editor\ProjectWorkspace;
use RuntimeException;

/**
 * A map an open change set made, as a step of its history: making it wrote
 * the map's files at once and put a workspace holding it in place. Undoing
 * removes those files and puts back the workspace that was in place before,
 * so the steps the change set took before it (a line insertion written at
 * once, for one) find the workspace they left and undo in turn. Redoing
 * makes the map again.
 */
final class MapCreationCommand implements Command
{
    /**
     * @param string $label The status-line action label.
     * @param string $mapId The map made.
     * @param Closure(): ProjectWorkspace $make Writes the map's files again and returns the workspace holding it.
     * @param Closure(): ?ProjectWorkspace $getWorkspace
     * @param Closure(ProjectWorkspace): void $replaceWorkspace
     */
    public function __construct(
        public readonly string $label,
        private readonly string $mapId,
        private readonly ProjectWorkspace $before,
        private ProjectWorkspace $after,
        private readonly Closure $make,
        private readonly Closure $getWorkspace,
        private readonly Closure $replaceWorkspace,
    ) {
    }

    public function execute(): void
    {
        $this->assertWorkspace($this->before);
        $this->after = ($this->make)();
        ($this->replaceWorkspace)($this->after);
    }

    public function undo(): void
    {
        $this->assertWorkspace($this->after);
        $index = array_search($this->mapId, $this->after->mapIds, true);
        if ($index === false) {
            throw new RuntimeException("{$this->mapId} is no longer in the workspace. Reload before trying again.");
        }
        $this->after->deleteMap((int) $index);
        ($this->replaceWorkspace)($this->before);
    }

    private function assertWorkspace(ProjectWorkspace $expected): void
    {
        if (($this->getWorkspace)() !== $expected) {
            throw new RuntimeException("The workspace changed since {$this->mapId} was made. Reload before trying again.");
        }
    }
}
