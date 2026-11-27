<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\ConditionReferences;
use Ichiloto\Editor\Events\ScriptCommandUses;
use Ichiloto\Editor\Field\MapAtlas;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Field\MapDoorReference;

/**
 * Finds authored references to a map id for the move and delete reviews.
 * Includes arrival destinations, cinematic start maps, atlas parents and
 * conditions on a saved visit or completed map event. Unsaved edits are included.
 */
final readonly class MapReferences
{
    private const array MAP_CONDITIONS = ['map_visited', 'map_event'];

    public function __construct(private ProjectWorkspace $workspace)
    {
    }

    /**
     * Where each reference lives, in words.
     *
     * @return list<string>
     */
    public function describe(string $mapId): array
    {
        $found = [];
        $positions = $this->workspace->getSystemField('startingPositions');
        if (is_array($positions)) {
            foreach ($positions as $role => $position) {
                if (is_array($position) && ($position['destinationMap'] ?? null) === $mapId) {
                    $found[] = sprintf('the %s starting position (System)', $role);
                }
            }
        }
        foreach ($this->workspace->maps as $map) {
            $data = $map->getEditableData();
            foreach ($map->getEventDefinitions() as $marker => $definition) {
                if (is_array($definition) && (($definition['data'] ?? [])['destinationMap'] ?? null) === $mapId) {
                    $found[] = sprintf('event %s on %s', $marker, $map->mapId);
                }
            }
            foreach (array_values((array) ($data['triggers'] ?? [])) as $index => $trigger) {
                if (is_array($trigger) && ($trigger['destinationMap'] ?? null) === $mapId) {
                    $found[] = sprintf('edge trigger %d on %s', $index + 1, $map->mapId);
                }
            }
            if (ConditionReferences::containsReference($data, self::MAP_CONDITIONS, $mapId)) {
                $found[] = sprintf('conditions on map %s', $map->mapId);
            }
        }
        foreach ($this->workspace->recordDatabases as $category => $database) {
            foreach ($database->getRecords() as $record) {
                if (ConditionReferences::containsReference($record->toArray(), self::MAP_CONDITIONS, $mapId)) {
                    $found[] = sprintf('conditions in %s %s', str_replace('_', ' ', $category), $record->recordId);
                }
            }
        }
        // A local map that names this one as its atlas parent loses its place above.
        array_push($found, ...MapAtlas::describeParentReferences($this->workspace->maps, $mapId));
        $cutscenes = [];
        foreach (CutsceneType::cases() as $type) {
            foreach ($this->workspace->cutscenes?->assets($type) ?? [] as $asset) {
                $cutscenes[] = $asset;
                $data = $asset->data();
                $startMap = $data['startMap'] ?? null;
                if ($type === CutsceneType::CINEMATIC && is_string($startMap) && trim($startMap) === $mapId) {
                    $found[] = sprintf('start map of cinematic %s', $asset->id);
                }
                if (ConditionReferences::containsReference([$data, $asset->partner()], self::MAP_CONDITIONS, $mapId)) {
                    $found[] = sprintf('%s %s', $type->noun(), $asset->id);
                }
            }
        }

        ScriptCommandUses::visitCommands($this->workspace, $cutscenes, null, static function (array $command, string $where) use (&$found, $mapId): void {
            // A transfer through a door names the map the door stands on.
            if (in_array($command['type'], ['transfer', MapDoorReference::COMMAND], true) && ($command['map'] ?? null) === $mapId) {
                $found[] = $where;
            }
        });

        return array_values(array_unique($found));
    }
}
