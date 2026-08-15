<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\ProjectMap;

/**
 * The map's NPCs as a record pane, written back into the map.
 *
 * One `ProjectRecordDatabase` over the map's `npcs`, so the NPC Inspector
 * is the same settings pane -- pickers, condition and write editors,
 * command frames, sub-list keys -- as every Database category. Every write
 * flows back into `ProjectMap::setNpcs()`, which is what the map persists
 * and fingerprints; this class holds no persisted state of its own.
 *
 * Dialogue is presented as variants (plain pages folded into one) and
 * unfolded on the way back, so a file authored as pages is saved as pages.
 *
 * @package Ichiloto\Editor\Field
 */
final class NpcInspector
{
    private ProjectRecordDatabase $records;

    public function __construct(private readonly ProjectMap $map)
    {
        $this->records = $this->build();
    }

    /**
     * Returns the record pane over the map's current NPCs.
     *
     * @return ProjectRecordDatabase The pane.
     */
    public function records(): ProjectRecordDatabase
    {
        return $this->records;
    }

    /**
     * Rebuilds the pane from the map -- after a canvas operation changed
     * the collection outside this pane (create, move, delete, undo).
     *
     * @return void
     */
    public function refresh(): void
    {
        $this->records = $this->build();
    }

    /**
     * Writes the pane's current records back into the map.
     *
     * Called after every pane edit; the map's own no-op guard makes an
     * unchanged write-back free.
     *
     * @return void
     */
    public function commit(): void
    {
        $this->records->save();
    }

    private function build(): ProjectRecordDatabase
    {
        $entries = [];

        foreach ($this->map->getNpcs()->toMapData() as $entry) {
            if (is_array($entry)) {
                $npc = new ProjectNpc($entry);
                $entry['dialogue'] = $npc->getDialogueAsVariants();

                if ($entry['dialogue'] === []) {
                    unset($entry['dialogue']);
                }
            }

            $entries[] = $entry;
        }

        return ProjectRecordDatabase::overOwnedList(
            RecordSchemaCatalog::mapNpcs(),
            $this->map->dataPath,
            $entries,
            function (array $written): void {
                $stored = [];

                foreach ($written as $entry) {
                    if (is_array($entry) && isset($entry['dialogue']) && is_array($entry['dialogue'])) {
                        $entry['dialogue'] = ProjectNpc::dialogueFromVariants($entry['dialogue']);
                    }

                    $stored[] = $entry;
                }

                $this->map->setNpcs(NpcCollection::fromMapData($stored));
            },
        );
    }
}
