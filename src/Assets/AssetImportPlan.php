<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

use Ichiloto\Editor\Storage\SourceSetPlan;

/**
 * Bringing one file into a project's assets, worked out and not yet
 * written ({@see AssetImport::plan()}): its kind, the asset-relative path a
 * row then names it by, the facts read from it, and the write to make, or
 * null when the same file is there already.
 */
final readonly class AssetImportPlan
{
    /** @param array<string, mixed> $facts Read from the file, and for a sound the role and name a field stores. */
    public function __construct(
        public AssetKind $kind,
        public string $path,
        public array $facts,
        public ?SourceSetPlan $plan,
    ) {
    }

    /**
     * What bringing it in did, as an interface is told: its kind, path and
     * facts, and whether anything was written.
     *
     * @return array<string, mixed>
     */
    public function describeReceipt(): array
    {
        return ['kind' => $this->kind->value, 'path' => $this->path] + $this->facts + ['changed' => $this->plan !== null];
    }
}
