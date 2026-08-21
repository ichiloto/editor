<?php

declare(strict_types=1);

namespace Ichiloto\Editor\UI;

use Closure;

/**
 * One runnable entry in the command palette.
 */
final readonly class PaletteItem
{
    /**
     * @param string $label The searchable, user-facing label.
     * @param string $hint A short key/context hint shown after the label.
     * @param Closure(): void $action The action executed on Enter.
     */
    public function __construct(
        public string $label,
        public string $hint,
        public Closure $action,
    ) {
    }
}
