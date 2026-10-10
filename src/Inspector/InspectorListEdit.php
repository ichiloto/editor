<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Inspector;

use Ichiloto\Editor\History\Command;

/**
 * An entry added to or removed from an inspector list: the command that
 * undoes and redoes it, already applied (null when the data already held
 * the result), and what changed, in the words an author reads.
 */
final readonly class InspectorListEdit
{
    public function __construct(public ?Command $command, public string $summary)
    {
    }
}
