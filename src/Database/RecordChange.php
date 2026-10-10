<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\History\Command;

/**
 * What a record change did, for the interface that asked for it to record
 * and report: the command that redoes and undoes it, and where it landed.
 *
 * @package Ichiloto\Editor\Database
 */
final readonly class RecordChange
{
    /**
     * @param Command|null $command The applied change's command; null when nothing changed.
     * @param int|null $index Where it landed: the record for a record operation (the one to select next after a delete, null when none is left), the item for an item operation.
     * @param array<string, mixed>|null $removed What an item removal took out, as the list held it.
     * @param string|null $note What else the change did that the author should hear about (a renamed quest's id).
     */
    public function __construct(
        public ?Command $command,
        public ?int $index = null,
        public ?array $removed = null,
        public ?string $note = null,
    ) {
    }
}
