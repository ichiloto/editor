<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

/**
 * A file its kind's admission accepted ({@see AssetAdmission::admit()}):
 * the facts read from it, as the import's receipt reports them, and the
 * value its written copy must read back as.
 */
final readonly class AdmittedAsset
{
    /** @param array<string, mixed> $facts Read from the file itself: an image's size, a sound's format and length. */
    public function __construct(
        public array $facts,
        public string $value,
    ) {
    }
}
