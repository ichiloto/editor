<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Inspector;

use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\Field\MapEncounters;
use Ichiloto\Editor\ProjectMap;

final class MapEncounterPoolFields
{
    public const string TARGET = 'map-encounter-pools';
    private const string ANY_AREA = '(whole map)';
    private const string ANY_TERRAIN = '(any terrain)';

    /** @return list<array<string, mixed>> */
    public static function describe(MapEncounters $encounters, array $areas, array $terrains, bool $graphical): array
    {
        $pools = $encounters->pools();
        $fields = [[
            'label' => sprintf('  Scoped Encounter Pools · %d', count($pools)),
            'value' => 'First matching pool wins',
            'editable' => false,
            'target' => self::TARGET,
            'field' => 'pools',
            'list' => ['index' => count($pools) - 1, 'path' => [MapEncounters::POOLS_KEY]],
        ]];
        foreach ($pools as $index => $pool) {
            $key = ['target' => self::TARGET, 'index' => $index,
                'list' => ['index' => $index, 'path' => [MapEncounters::POOLS_KEY]]];
            $fields[] = ['label' => '    Priority', 'value' => (string) ($index + 1),
                'options' => array_map(strval(...), range(1, count($pools))), 'field' => 'order', ...$key];
            foreach (['area' => [$areas, self::ANY_AREA], 'terrain' => [$terrains, self::ANY_TERRAIN]] as $field => [$choices, $none]) {
                $current = is_string($pool[$field] ?? null) ? $pool[$field] : $none;
                $fields[] = ['label' => '    ' . ucfirst($field), 'value' => $current,
                    'options' => array_values(array_unique([$none, ...$choices, $current])), 'field' => $field, ...$key];
            }
            $troops = is_array($pool['troops'] ?? null) ? $pool['troops'] : [];
            $troopList = ['index' => count($troops) - 1, 'path' => [MapEncounters::POOLS_KEY, (string) $index, 'troops']];
            $fields[] = ['label' => sprintf('    Troops · %d', count($troops)), 'value' => '', 'editable' => false,
                'field' => 'troops', ...$key, 'list' => $troopList];
            foreach (array_keys($troops) as $position => $name) {
                $entry = $troops[$name];
                $weight = is_array($entry) ? ($entry['weight'] ?? null) : $entry;
                $arena = is_array($entry) ? ($entry[MapEncounters::ARENA_KEY] ?? null) : null;
                $row = [...$key, 'path' => [(string) $name], 'list' => [...$troopList, 'index' => $position]];
                $fields[] = ['label' => '      Troop', 'value' => (string) $name,
                    'field' => 'troop', 'reference' => 'troops', ...$row];
                $fields[] = ['label' => '      Weight', 'value' => is_scalar($weight) ? (string) $weight : '',
                    'field' => 'weight', 'control' => new InputControl(InputControlType::INTEGER, (string) (int) $weight), ...$row];
                if ($graphical) {
                    $fields[] = ['label' => '      Arena', 'value' => is_string($arena) ? $arena : MapInspector::ENCOUNTER_ARENA_NONE,
                        'field' => 'arena', 'reference' => 'battle_arenas', 'noneLabel' => MapInspector::ENCOUNTER_ARENA_NONE, ...$row];
                }
            }
        }
        return $fields;
    }

    public static function apply(MapInspector $inspector, ProjectMap $map, MapEncounters $encounters, array $field, string $value,
        array $areas, array $terrains, array $troopNames, array $arenas): ?Command
    {
        $pools = $encounters->pools();
        $index = (int) $field['index'];
        $pool = $pools[$index] ?? throw new InspectorRefusal('There is no encounter pool at that position.');
        $key = $field['field'];
        if ($key === 'order') {
            $to = (int) $value - 1;
            if ($to < 0 || $to >= count($pools)) { throw new InspectorRefusal('Choose an existing pool position.'); }
            array_splice($pools, $index, 1);
            array_splice($pools, $to, 0, [$pool]);
        } elseif ($key === 'area' || $key === 'terrain') {
            $choices = $key === 'area' ? $areas : $terrains;
            $none = $key === 'area' ? self::ANY_AREA : self::ANY_TERRAIN;
            if ($value === $none) { unset($pool[$key]); }
            elseif (in_array($value, $choices, true)) { $pool[$key] = $value; }
            else { throw new InspectorRefusal('Choose a scope the map declares.'); }
            if (!isset($pool['area']) && !isset($pool['terrain'])) {
                throw new InspectorRefusal('A scoped pool needs an area or an encounter terrain.');
            }
            $pools[$index] = $pool;
        } else {
            $name = (string) ($field['path'][0] ?? '');
            $entries = $pool['troops'];
            if (!array_key_exists($name, $entries)) { throw new InspectorRefusal('That troop is no longer in the pool.'); }
            if ($key === 'troop') {
                if (!in_array($value, $troopNames, true) || ($value !== $name && array_key_exists($value, $entries))) {
                    throw new InspectorRefusal('Choose a project troop that does not already appear in this pool.');
                }
                $renamed = [];
                foreach ($entries as $id => $entry) { $renamed[$id === $name ? $value : $id] = $entry; }
                $pool['troops'] = $renamed;
            } else {
                $entry = is_array($entries[$name]) ? $entries[$name] : ['weight' => $entries[$name]];
                if ($key === 'weight') {
                    if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                        throw new InspectorRefusal('Choose a positive whole troop weight.');
                    }
                    $entry['weight'] = (int) $value;
                } elseif ($key === 'arena') {
                    if ($value === '' || $value === MapInspector::ENCOUNTER_ARENA_NONE) { unset($entry[MapEncounters::ARENA_KEY]); }
                    elseif (in_array($value, $arenas, true)) { $entry[MapEncounters::ARENA_KEY] = $value; }
                    else { throw new InspectorRefusal('Choose a project battle arena.'); }
                } else {
                    throw new InspectorRefusal('Unknown encounter pool field.');
                }
                $pool['troops'][$name] = is_array($entries[$name]) || count($entry) !== 1 ? $entry : $entry['weight'];
            }
            $pools[$index] = $pool;
        }

        return $inspector->writeMapData($map, [MapEncounters::KEY], $encounters->withPools($pools), 'Encounter pool');
    }

    public static function editList(MapInspector $inspector, ProjectMap $map, MapEncounters $encounters, array $field,
        array $areas, array $terrains, array $troopNames, bool $remove): InspectorListEdit
    {
        $pools = $encounters->pools();
        $index = $field['index'] ?? null;
        $troopList = count($field['list']['path'] ?? []) === 3;
        if ($troopList) {
            $pool = $pools[$index] ?? throw new InspectorRefusal('There is no encounter pool at that position.');
            $entries = is_array($pool['troops'] ?? null) ? $pool['troops'] : [];
            if ($remove) {
                $name = $field['path'][0] ?? null;
                if ($name === null || !array_key_exists($name, $entries)) {
                    throw new InspectorRefusal('Select a pool troop to remove.');
                }
                if (count($entries) === 1) { throw new InspectorRefusal('Remove the whole pool to remove its last troop.'); }
                unset($entries[$name]);
            } else {
                $name = array_values(array_diff($troopNames, array_keys($entries)))[0]
                    ?? throw new InspectorRefusal('Every project troop is already in this pool.');
                $after = $field['path'][0] ?? array_key_last($entries);
                $position = array_search($after, array_keys($entries), true);
                $offset = $position === false ? count($entries) : $position + 1;
                $entries = array_slice($entries, 0, $offset, true) + [$name => 1] + array_slice($entries, $offset, null, true);
            }
            $pools[$index]['troops'] = $entries;
        } elseif ($remove) {
            if ($index === null || !isset($pools[$index])) { throw new InspectorRefusal('Select an encounter pool to remove.'); }
            array_splice($pools, $index, 1);
        } else {
            $scope = $areas !== [] ? ['area' => $areas[0]] : ($terrains !== [] ? ['terrain' => $terrains[0]] : []);
            if ($scope === []) { throw new InspectorRefusal('Declare a map area or encounter terrain first.'); }
            $name = $troopNames[0] ?? throw new InspectorRefusal('This project defines no encounter troops.');
            array_splice($pools, $index === null ? count($pools) : $index + 1, 0, [[...$scope, 'troops' => [$name => 1]]]);
        }

        return new InspectorListEdit(
            $inspector->writeMapData($map, [MapEncounters::KEY], $encounters->withPools($pools), 'Encounter pool'),
            $remove ? 'Removed encounter pool entry.' : 'Added encounter pool entry.',
        );
    }
}
