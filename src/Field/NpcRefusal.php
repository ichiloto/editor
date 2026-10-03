<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use RuntimeException;
use Throwable;

/**
 * An NPC change that cannot be made as asked, with the reason an author
 * reads and any lines that detail it (what still names an NPC being
 * deleted). Nothing was changed.
 *
 * @package Ichiloto\Editor\Field
 */
final class NpcRefusal extends RuntimeException
{
    /** @param list<string> $details */
    public function __construct(string $message, public readonly array $details = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
