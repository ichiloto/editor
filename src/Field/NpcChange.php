<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\History\Command;

/**
 * What an NPC change did, for the interface that asked for it to record and
 * report: the command that redoes and undoes it, where the NPC is now, and
 * what became of its id when a rename would have changed it.
 *
 * @package Ichiloto\Editor\Field
 */
final readonly class NpcChange
{
    /**
     * @param Command|null $command The applied change's command; null when nothing changed.
     * @param int|null $index The NPC's position afterwards; null once it was deleted.
     * @param ProjectNpc|null $npc The NPC afterwards, or as it was when deleted.
     * @param string|null $followedId The id a renamed NPC took from its new name.
     * @param list<string> $idReferences What names the id a renamed NPC kept instead.
     */
    public function __construct(
        public ?Command $command,
        public ?int $index,
        public ?ProjectNpc $npc,
        public ?string $followedId = null,
        public array $idReferences = [],
    ) {
    }
}
