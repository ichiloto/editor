<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * Describes one editor-facing Database category.
 */
final readonly class DatabaseCategoryDefinition
{
    /**
     * @param string $key Stable category identifier.
     * @param string $label User-facing category label.
     * @param string $description Short category purpose summary.
     * @param bool $isImplemented Whether the editor is currently implemented.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public bool $isImplemented = false,
    ) {
    }
}
