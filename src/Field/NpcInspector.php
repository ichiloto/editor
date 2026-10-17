<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Database\ReferenceCatalog;
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
 * The rows every interface lists for one NPC come from {@see getFields()}:
 * the record layer's own rows under headings, with the notes an author
 * needs, and field ids untouched so an edit still names the record field.
 *
 * @package Ichiloto\Editor\Field
 */
final class NpcInspector
{
    /**
     * The field id of the note on an NPC without a stable id; acting on it
     * assigns one ({@see NpcAuthoring::assignId()}).
     */
    public const string ASSIGN_ID_FIELD = '__npc_assign_id';

    /**
     * The headings the root rows are grouped under, and the field ids each
     * holds, in the order they are listed.
     */
    private const array SECTIONS = [
        'Identity' => ['id', 'name'],
        'Placement' => ['x', 'y'],
        'Appearance' => ['sprite', 'sprites.north', 'sprites.south', 'sprites.east', 'sprites.west'],
        'Movement' => ['movement', 'directionFix', 'wanderArea.x', 'wanderArea.y', 'wanderArea.width', 'wanderArea.height'],
        'Visibility' => ['conditions'],
        'Interaction' => ['commandListScript'],
        'Completion Writes' => ['sets'],
    ];

    /** The rows that are a cell's coordinates, by field, and the axis each is. */
    private const array AXES = ['x' => 'x', 'y' => 'y'];

    private ProjectRecordDatabase $records;

    public function __construct(public readonly ProjectMap $map, private readonly bool $graphical = false, private readonly ?ReferenceCatalog $references = null)
    {
        $this->records = $this->build();
    }

    /**
     * Returns one NPC's rows in a frame of its commands: at the root, its
     * fields grouped under headings with its identity notes and each
     * dialogue variant headed; inside a script frame, that frame's rows.
     *
     * @param int $index The NPC's position.
     * @param array<int, int|string> $framePath The frame; [] for the NPC itself.
     * @return array<int, array<string, mixed>>|null The field descriptors, or null when the frame no longer resolves.
     */
    public function getFields(int $index, array $framePath = []): ?array
    {
        if ($framePath !== [] && $this->records->getFrameCommands($index, $framePath) === null) {
            return null;
        }

        $fields = $this->records->getFrameSettingsFields($index, $framePath);

        if ($framePath !== []) {
            return $fields;
        }

        $npc = $this->map->getNpcs()->get($index);
        $grouped = [];
        $group = static fn(string $title): array => ['label' => $title, 'value' => '', 'editable' => false];
        $notes = [];

        if ($npc !== null && $npc->getId() === null) {
            // Legacy entry: nothing can name an id it never had, so giving
            // it one is the one identity write that is safe after creation.
            $notes[] = [
                'label' => '  ! No stable id',
                'value' => 'move_route cannot target it; Enter assigns one from the name',
                'editable' => true,
                'field' => self::ASSIGN_ID_FIELD,
            ];
        }

        if ($npc !== null && $npc->scriptShadowsDialogue()) {
            $notes[] = ['label' => '  ! Script replaces dialogue', 'value' => 'the game runs the script', 'editable' => false];
        }

        if ($npc !== null && $npc->getUnknownFields() !== []) {
            $notes[] = ['label' => '  Preserved fields', 'value' => implode(', ', $npc->getUnknownFields()), 'editable' => false];
        }

        $byId = [];

        foreach ($fields as $field) {
            $byId[(string) ($field['field'] ?? '')][] = $field;
        }

        foreach (self::SECTIONS as $title => $ids) {
            $rows = [];

            foreach ($ids as $id) {
                foreach ($byId[$id] ?? [] as $field) {
                    // Wander bounds only matter while wandering; loaded
                    // values are kept, just not shown for a fixed NPC.
                    if (! (str_starts_with($id, 'wanderArea.') && $npc !== null && ! $npc->wanders())) {
                        $rows[] = isset(self::AXES[$id]) ? [...$field, 'axis' => self::AXES[$id]] : $field;
                    }
                }

                unset($byId[$id]);
            }

            if ($rows !== []) {
                $grouped[] = $group($title);
                $grouped = [...$grouped, ...$rows];
            }

            if ($title === 'Identity') {
                $grouped = [...$grouped, ...$notes];
            }

            if ($title === 'Interaction') {
                // Everything left is dialogue: variants, their lines, and
                // their frames, each variant under its own heading.
                [$variantRows, $byId] = self::groupVariantRows($byId);
                $grouped = [...$grouped, ...$variantRows];
            }
        }

        foreach ($byId as $rest) {
            $grouped = [...$grouped, ...$rest];
        }

        return $grouped;
    }

    /**
     * Turns the record pane's variant rows into headed groups: one
     * `Dialogue variant N` heading per variant (with its condition line
     * when it has one), then that variant's rows under short labels --
     * `When`, `Then Set`, `Script Commands`, `Line 1 Speaker`, `Line 1
     * Text` -- so the label no longer eats the pane before the value
     * starts. Field ids are untouched; this is the grouped view's
     * presentation of the record layer's own rows.
     *
     * @param array<string, array<int, array<string, mixed>>> $byId The remaining rows, keyed by field id.
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, array<int, array<string, mixed>>>} The headed rows, and what was left.
     */
    private static function groupVariantRows(array $byId): array
    {
        $singular = ucfirst(RecordSchemaCatalog::mapNpcs()->subList?->singular ?? 'dialogue variant');
        $variants = [];

        foreach ($byId as $id => $rows) {
            if (preg_match('/^variant(\d+)/', $id, $matches) !== 1) {
                continue;
            }

            $variants[intval($matches[1])] = [...($variants[intval($matches[1])] ?? []), ...$rows];
            unset($byId[$id]);
        }

        ksort($variants);
        $headed = [];

        foreach ($variants as $number => $rows) {
            $prefix = sprintf('%s %d ', $singular, $number + 1);
            $when = '';

            foreach ($rows as $row) {
                if (($row['field'] ?? null) === sprintf('variant%dConditions', $number)) {
                    $when = trim((string) ($row['value'] ?? ''));
                }
            }

            // The condition line rides as the heading's value, so it reads
            // "Dialogue variant 2 · when …" and wraps rather than clips.
            $headed[] = [
                'label' => sprintf('%s %d', $singular, $number + 1),
                'value' => $when === '' ? '' : 'when ' . $when,
                'editable' => false,
            ];

            foreach ($rows as $row) {
                $label = (string) ($row['label'] ?? '');

                if (str_starts_with($label, $prefix)) {
                    $row['label'] = substr($label, strlen($prefix));
                }

                $headed[] = $row;
            }
        }

        return [$headed, $byId];
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

        $records = ProjectRecordDatabase::overOwnedList(
            RecordSchemaCatalog::mapNpcs($this->graphical),
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
            graphical: $this->graphical,
        );
        if ($this->references !== null) { $records->useAuthoringReferences($this->references); }
        return $records;
    }
}
