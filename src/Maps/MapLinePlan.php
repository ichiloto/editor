<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Storage\SourceSetPlan;

/**
 * A planned row or column insertion or removal: the files it writes, as one
 * reversible transaction, and what it could not rewrite.
 *
 * @package Ichiloto\Editor\Maps
 */
final class MapLinePlan
{
    /**
     * @param MapLineEdit $edit What changes where.
     * @param SourceSetPlan $sources The files written, and how to restore them.
     * @param list<array{file: string, where: string, reason: string}> $handEdits Coordinates to move by hand, by project-relative file.
     * @param list<string> $notes What the author should know, such as whether saves follow.
     */
    public function __construct(
        public readonly MapLineEdit $edit,
        private readonly SourceSetPlan $sources,
        public readonly array $handEdits,
        public readonly array $notes,
        public readonly int $width,
        public readonly int $height,
    ) {
    }

    public function getSourceSet(): SourceSetPlan { return $this->sources; }

    /** @return list<string> Every file the edit writes. */
    public function getChangedPaths(): array { return $this->sources->getChangedPaths(); }

    /** @return list<string> Every changed path. */
    public function apply(): array { return $this->sources->apply(); }

    public function revert(): void { $this->sources->revert(); }
}
