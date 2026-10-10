<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Inspector;

use RuntimeException;
use Throwable;

/**
 * An inspector edit that cannot be made as asked, with the reason an author
 * reads and any lines that detail it (the NPCs a shrink would strand).
 */
final class InspectorRefusal extends RuntimeException
{
    /** @param list<string> $details */
    public function __construct(string $message, public readonly array $details = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
