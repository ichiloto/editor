<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Navigation;

use Closure;

/**
 * One remembered place in the editor: where the author was, and how to put
 * them back there.
 *
 * The restore closure is the generalization of the destination round trip's
 * context snapshot — it captures whatever the surface needs (map index,
 * cursor, pane focus, database category and entry) at push time.
 */
final readonly class NavigationEntry
{
    /**
     * @param string $label The human-readable origin, shown by Back.
     * @param Closure(): void $restore Puts the editor back where it was.
     */
    public function __construct(
        public string $label,
        public Closure $restore,
    ) {
    }
}
