<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Actors;

use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\Storage\SourceSetPlan;

/** An explicit, reversible source transaction shared by the CLI and TUI. */
final class ActorIdentityMigrationPlan
{
    private readonly SourceSetPlan $sources;

    public function __construct(
        string $root,
        array $watched,
        array $originals,
        array $proposals,
        array $beforeValues,
        array $afterValues,
    ) {
        $this->sources = new SourceSetPlan($root, $watched, $originals, $proposals, $beforeValues, $afterValues,
            static fn(): array => [...(glob($root . '/assets/Data/Actors/*.php') ?: []), ...ActorReferenceInventory::getSourcePaths($root)],
            static fn(string $path, string $staged): mixed => PhpDataFile::getComparableValue(PhpDataFile::evaluateIsolated($staged, $root)),
            'actor migration',
            'Project actor/reference files',
        );
    }

    public function getSourceSet(): SourceSetPlan { return $this->sources; }
    public function getChangedPaths(): array { return $this->sources->getChangedPaths(); }
    public function getOriginalSources(): array { return $this->sources->getOriginalSources(); }
    public function getProposedSources(): array { return $this->sources->getProposedSources(); }
    public function apply(): array { return $this->sources->apply(); }
    public function revert(): void { $this->sources->revert(); }
}
