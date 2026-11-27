<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Database\ConditionReferences;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use InvalidArgumentException;

/** Uses the Engine's visit condition shape and checks the referenced map in this project. */
final class MapVisitConditionValidator
{
    public const string TYPE = 'map_visited';

    /**
     * @param list<string> $mapIds
     * @return list<Issue>
     */
    public static function validateMap(ProjectMap $map, array $mapIds): array
    {
        $data = $map->getEditableData();
        $issues = self::validateMapMetadata($map, $mapIds);
        $sources = [[$map->mapId, $data['bgmVariants'] ?? []]];
        foreach ((array) ($data['events'] ?? []) as $marker => $event) {
            $sources[] = [sprintf('%s event %s', $map->mapId, $marker), $event];
        }
        foreach ((array) ($data['npcs'] ?? []) as $index => $npc) {
            $name = is_array($npc) ? ($npc['name'] ?? $index) : $index;
            $sources[] = [sprintf('%s NPC %s', $map->mapId, is_scalar($name) ? $name : $index), $npc];
        }

        foreach ($sources as [$where, $source]) {
            array_push($issues, ...self::validateSource($source, $where, $mapIds));
        }

        return $issues;
    }

    /**
     * Checks map-owned data such as world objects and landmarks. Events, NPCs and music
     * variants have dedicated source checks in the project validator.
     *
     * @param list<string> $mapIds
     * @return list<Issue>
     */
    public static function validateMapMetadata(ProjectMap $map, array $mapIds): array
    {
        $metadata = array_diff_key($map->getEditableData(), ['events' => true, 'npcs' => true, 'bgmVariants' => true]);

        return self::validateSource($metadata, $map->mapId, $mapIds);
    }

    /**
     * @param list<string> $mapIds
     * @return list<Issue>
     */
    private static function validateSource(mixed $source, string $where, array $mapIds): array
    {
        $issues = [];
        foreach (ConditionReferences::findConditions($source, [self::TYPE]) as $condition) {
            array_push($issues, ...self::validateCondition($condition, $where, $mapIds));
        }

        return $issues;
    }

    /**
     * @param array<array-key, mixed> $condition
     * @param list<string> $mapIds
     * @return list<Issue>
     */
    public static function validateCondition(array $condition, string $where, array $mapIds): array
    {
        try {
            WorldConditionEvaluator::validateAll([$condition], 'Visited Map');
        } catch (InvalidArgumentException $failure) {
            return [Issue::error($where, $failure->getMessage(), 'Use the Visited Map picker.',
                'map_visit.malformed')];
        }

        $mapId = trim($condition['name']);
        if (in_array($mapId, $mapIds, true)) {
            return [];
        }

        return [Issue::error($where, sprintf('Its visit condition names the map "%s", which does not exist.', $mapId),
            'Choose an existing map in the Visited Map picker.', 'map_visit.missing_map')];
    }
}
