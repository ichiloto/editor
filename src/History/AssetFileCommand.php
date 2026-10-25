<?php

declare(strict_types=1);

namespace Ichiloto\Editor\History;

use Ichiloto\Editor\Storage\SourceSetPlan;

/**
 * An artwork file written at once, rather than on Save, kept in history: an
 * imported image, or a sheet given cells copied from another image. Undo puts
 * back exactly the bytes there were, or removes a file the edit created, and
 * redo writes it again. Either refuses, changing nothing, when the file was
 * changed outside the Editor since. Artwork is read from disk by every view,
 * so nothing in the workspace is reloaded.
 */
final class AssetFileCommand implements Command
{
    public function __construct(
        public readonly string $label,
        private readonly SourceSetPlan $plan,
    ) {
    }

    public function execute(): void
    {
        $this->plan->apply();
    }

    public function undo(): void
    {
        $this->plan->revert();
    }
}
