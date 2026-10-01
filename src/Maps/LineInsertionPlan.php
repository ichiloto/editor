<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Storage\SourceSetPlan;

/**
 * A planned row or column insertion: the files it writes, as one reversible
 * transaction, and what it could not rewrite.
 *
 * @package Ichiloto\Editor\Maps
 */
final class LineInsertionPlan
{
    /**
     * @param LineInsertion $insertion What is inserted where.
     * @param SourceSetPlan $sources The files written, and how to restore them.
     * @param list<array{file: string, where: string, reason: string}> $handEdits Coordinates to move by hand, by project-relative file.
     * @param list<string> $notes What the author should know, such as whether saves follow.
     */
    public function __construct(
        public readonly LineInsertion $insertion,
        private readonly SourceSetPlan $sources,
        public readonly array $handEdits,
        public readonly array $notes,
    ) {
    }

    public function getSourceSet(): SourceSetPlan { return $this->sources; }

    /** @return list<string> Every file the insertion writes. */
    public function getChangedPaths(): array { return $this->sources->getChangedPaths(); }

    /** @return list<string> Every changed path. */
    public function apply(): array { return $this->sources->apply(); }

    public function revert(): void { $this->sources->revert(); }
}
