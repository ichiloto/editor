<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Engine\Events\Interpreter\MovementRouteRunner;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourceNode;

/** Map-space views of schema-owned command fields, never a runtime simulation. */
final class MapPlacement
{
    /** Decorate the existing event paths, including inline scripts and nested arms. */
    public static function describeEventFields(array $definition, array $fields): array
    {
        $walk = static function (array $value, array $path) use (&$walk, &$fields): void {
            if (isset($value['type'])) {
                $commandFields = [];
                $original = [];
                foreach ($fields as $i => $field) {
                    $fieldPath = $field['path'] ?? [];
                    if (array_slice($fieldPath, 0, count($path)) !== array_map(strval(...), $path)) {
                        continue;
                    }
                    $tail = array_slice($fieldPath, count($path));
                    $entry = [0];
                    if (in_array($tail[0] ?? '', ['steps', 'waypoints', 'points'], true) && ctype_digit(strval($tail[1] ?? ''))) {
                        $entry[] = (int) $tail[1];
                        $tail = array_slice($tail, 2);
                    }
                    $original[] = $i;
                    $commandFields[] = [...$field, 'entry' => $entry, 'sourceKey' => implode('.', $tail), 'dataPath' => $fieldPath, 'field' => json_encode($fieldPath, JSON_THROW_ON_ERROR)];
                }
                foreach (self::describeFields($value, $commandFields) as $i => $field) {
                    if (isset($field['mapPlacement'])) {
                        $fields[$original[$i]]['mapPlacement'] = $field['mapPlacement'];
                    }
                }
            }
            foreach ($value as $key => $child) {
                if (is_array($child)) {
                    $walk($child, [...$path, $key]);
                }
            }
        };
        $walk($definition, []);
        foreach ($fields as &$field) {
            if (($field['destination'] ?? false) === true) {
                $data = $definition['data'] ?? [];
                $position = $data['spawnPoint'] ?? [];
                $valid = self::isMapPoint($position) && is_string($data['destinationMap'] ?? null);
                $field['mapPlacement'] = ['kind' => 'event-transfer', 'map' => is_string($data['destinationMap'] ?? null) ? $data['destinationMap'] : null,
                    'points' => [['point' => $valid ? [$position['x'], $position['y']] : [0, 0],
                        'fields' => [], 'label' => 'Arrival']],
                    'sourcePaths' => [['data', 'destinationMap'], ['data', 'spawnPoint', 'x'], ['data', 'spawnPoint', 'y']],
                    'issue' => $valid ? null : 'The arrival needs a map reference and literal integer x/y coordinates.'];
            }
        }
        return $fields;
    }

    /** @param array<string, mixed> $entry @param list<array<string, mixed>> $fields */
    public static function describeFields(array $entry, array $fields): array
    {
        $own = [];
        $nested = [];
        foreach ($fields as $i => $field) {
            $path = $field['entry'] ?? [];
            if (!isset($field['sourceKey'], $field['field'])) {
                continue;
            }
            if (count($path) === 1) {
                $own[$field['sourceKey']] = $i;
            } elseif (count($path) === 2) {
                $nested[$path[1]][$field['sourceKey']] = $i;
            }
        }
        $keys = static fn(array $indexes): array => array_map(static fn(int $i): string => $fields[$i]['field'], $indexes);
        $type = $entry['type'] ?? '';
        if ($type === 'move_route' && isset($own['subject'])) {
            $steps = [];
            $x = $y = 0;
            $issue = null;
            try {
                MovementRouteRunner::validatePathOptions($entry);
            } catch (\Throwable $error) {
                $issue = $error->getMessage();
            }
            if (array_key_exists('waypoints', $entry)) {
                $coordinates = ['x' => null, 'y' => null];
                foreach ((array) $entry['waypoints'] as $i => $waypoint) {
                    if (!is_array($waypoint)) { break; }
                    $axes = array_intersect_key($nested[$i] ?? [], $waypoint, array_flip(['x', 'y']));
                    $coordinates = array_replace($coordinates, array_intersect_key($waypoint, $coordinates));
                    $steps[] = ['point' => [$coordinates['x'], $coordinates['y']], 'fields' => $keys($axes),
                        'label' => 'Waypoint ' . ($i + 1) . ' (authored target only)'];
                }
            } elseif (array_key_exists('retrace', $entry)) {
                $issue ??= 'Retrace geometry belongs to the completed runtime route record; choose its stable reference in Recorded Route, not a fabricated map path.';
            }
            foreach ((array) ($entry['steps'] ?? []) as $i => $step) {
                try {
                    if (!is_array($step) || !is_string($step['direction'] ?? null)) {
                        throw new \InvalidArgumentException('Invalid direction.');
                    }
                    $direction = MovementRouteRunner::directionVector($step['direction']);
                    $count = $step['count'] ?? 1;
                    if (!is_numeric($count) || !is_finite((float) $count) || (float) $count !== (float) (int) $count || $count < 0 || !is_bool($step['faceOnly'] ?? false)) {
                        throw new \InvalidArgumentException('Invalid count or face-only value.');
                    }
                    $from = [$x, $y];
                    if (!($step['faceOnly'] ?? false)) {
                        $x += (int) $direction->x * (int) $count;
                        $y += (int) $direction->y * (int) $count;
                        if (!is_int($x) || !is_int($y)) {
                            throw new \InvalidArgumentException('The route offsets exceed the supported integer range.');
                        }
                    }
                    $steps[] = ['from' => $from, 'point' => [$x, $y], 'faceOnly' => $step['faceOnly'] ?? false,
                        'fields' => $keys($nested[$i] ?? []), 'label' => 'Step ' . ($i + 1)];
                } catch (\Throwable $error) {
                    $issue = sprintf('Step %d: %s', $i + 1, $error->getMessage());
                    break;
                }
            }
            $fields[$own['subject']]['mapPlacement'] = ['kind' => isset($entry['waypoints']) ? 'waypoints' : 'route', 'points' => $steps, 'issue' => $issue,
                'subject' => $entry['subject'] ?? 'player', 'subjectId' => $entry['npcId'] ?? $entry['actorId'] ?? null,
                'sourcePaths' => array_values(array_column(array_filter($fields, static fn(array $field): bool =>
                    in_array($field['sourceKey'] ?? '', ['type', 'subject', 'npcId', 'actorId', 'direction', 'count', 'faceOnly', 'x', 'y', 'retrace', 'remember'], true)), 'dataPath'))];
        }
        $point = static function (array $indexes, array $position, string $kind, ?string $map = null) use (&$fields, $keys, $own): void {
            if (!isset($indexes['x'], $indexes['y'])) {
                return;
            }
            $valid = self::isMapPoint($position);
            $fields[$indexes['x']]['mapPlacement'] = ['kind' => $kind, 'map' => $map,
                'points' => [['point' => $valid ? [$position['x'], $position['y']] : [0, 0],
                    'fields' => $keys($indexes), 'label' => ucfirst($kind)]],
                'sourcePaths' => array_values(array_map(static fn(int $i): array => $fields[$i]['dataPath'] ?? [],
                    [...$indexes, ...array_intersect_key($own, array_flip(['type', 'target.kind', 'operation']))])),
                'issue' => $valid ? null : 'Map placement needs explicit non-negative integer x/y coordinates; relative or invalid axes are not inferred.'];
        };
        if (in_array($type, ['transfer', 'move_player'], true)) {
            $point(array_intersect_key($own, array_flip(['x', 'y', 'map'])), $entry, $type === 'transfer' ? 'transfer' : 'position', is_string($entry['map'] ?? null) ? $entry['map'] : null);
        }
        if (in_array($type, ['camera', 'field_animation'], true) && ($entry['target']['kind'] ?? '') === 'position') {
            $indexes = [];
            foreach (['x', 'y'] as $axis) {
                if (isset($own['target.' . $axis])) {
                    $indexes[$axis] = $own['target.' . $axis];
                }
            }
            $point($indexes, $entry['target'], $type === 'camera' ? 'camera' : 'effect');
        }
        if ($type === 'camera' && ($entry['operation'] ?? '') === 'route') {
            $points = $indexes = $sourcePaths = [];
            foreach (array_intersect_key($own, array_flip(['type', 'operation'])) as $index) {
                $sourcePaths[] = $fields[$index]['dataPath'] ?? [];
            }
            $issue = null;
            $from = null;
            foreach ((array) ($entry['points'] ?? []) as $i => $target) {
                if (!is_array($target) || ($target['kind'] ?? '') !== 'position') {
                    // Actor/screen-relative targets break the known map-space line.
                    $from = null;
                    continue;
                }
                $coordinates = array_intersect_key($nested[$i] ?? [], array_flip(['x', 'y']));
                if (!isset($coordinates['x'], $coordinates['y'])) {
                    continue;
                }
                $valid = self::isMapPoint($target);
                $issue ??= $valid ? null : 'Camera route placement needs explicit non-negative integer x/y coordinates.';
                $indexes[$coordinates['x']] = count($points);
                $position = $valid ? [$target['x'], $target['y']] : [0, 0];
                $points[] = ['point' => $position, 'from' => $from, 'fields' => $keys($coordinates), 'label' => 'Point ' . ($i + 1)];
                $from = $valid ? $position : null;
                foreach ($coordinates as $index) {
                    $sourcePaths[] = $fields[$index]['dataPath'] ?? [];
                }
                if (isset($nested[$i]['kind'])) {
                    $sourcePaths[] = $fields[$nested[$i]['kind']]['dataPath'] ?? [];
                }
            }
            foreach ($indexes as $index => $selected) {
                $fields[$index]['mapPlacement'] = ['kind' => 'camera', 'points' => $points, 'selectedPoint' => $selected,
                    'sourcePaths' => $sourcePaths, 'issue' => $issue];
            }
        }
        return $fields;
    }

    private static function isMapPoint(mixed $position): bool
    {
        return is_array($position) && is_int($position['x'] ?? null) && is_int($position['y'] ?? null)
            && $position['x'] >= 0 && $position['y'] >= 0;
    }

    /** Object member order is transport trivia, but types and list order are not. */
    public static function areValuesEqual(mixed $left, mixed $right): bool
    {
        if (!is_array($left) || !is_array($right)) {
            return $left === $right;
        }
        if (array_is_list($left) !== array_is_list($right) || count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $key => $value) {
            if (!array_key_exists($key, $right) || !self::areValuesEqual($value, $right[$key])) {
                return false;
            }
        }
        return true;
    }

    /** Refuse even unchanged expressions; a click must not depend on evaluated PHP. */
    public static function getSourceIssue(PhpArraySourceDocument $document, array $paths): ?string
    {
        foreach ($paths as $path) {
            $node = $document->root();
            foreach ($path as $segment) {
                if ($node->kind !== SourceNode::ARRAY || $node->hasOpaqueKey) {
                    return 'Map placement requires literal source fields, not expressions, variables or spread arrays.';
                }
                $key = is_string($segment) && (string) (int) $segment === $segment ? (int) $segment : $segment;
                $node = $node->entryFor($key)?->value;
                if ($node === null) {
                    // A shared optional field may be inserted into a literal array.
                    break;
                }
            }
            if ($node !== null && $node->kind !== SourceNode::SCALAR) {
                return 'Map placement requires literal source fields, not expressions or variables.';
            }
        }
        return null;
    }

    /** Convert one map click to shared row values, keeping all other authored fields. */
    public static function getChanges(array $placement, int $point, int $x, int $y, ?array $origin, string $map): array
    {
        if (($placement['issue'] ?? null) !== null) {
            throw new \InvalidArgumentException($placement['issue']);
        }
        $selected = $placement['points'][$point] ?? throw new \InvalidArgumentException('Choose an authored point first.');
        $fields = $selected['fields'];
        if ($placement['kind'] === 'waypoints') {
            // A click changes only authored axes. Omitted axes retain the prior runtime coordinate.
            $values = array_intersect_key(['x' => (string) $x, 'y' => (string) $y], $fields);
            if ($values === []) {
                throw new \InvalidArgumentException('Author at least one waypoint axis before placing it.');
            }
        } elseif ($placement['kind'] !== 'route') {
            $values = ['x' => (string) $x, 'y' => (string) $y];
            if (isset($fields['map'])) {
                $values['map'] = $map;
            }
        } else {
            if ($origin === null || count($origin) !== 2 || !is_int($origin[0]) || !is_int($origin[1])) {
                throw new \InvalidArgumentException('Choose a preview origin; it is not saved as the actor position.');
            }
            $dx = $x - $origin[0] - $selected['from'][0];
            $dy = $y - $origin[1] - $selected['from'][1];
            if (($dx !== 0 && $dy !== 0) || ($dx === 0 && $dy === 0)) {
                throw new \InvalidArgumentException('Choose a cell in one cardinal direction from the previous point.');
            }
            $direction = null;
            foreach (MovementRouteRunner::DIRECTIONS as $name) {
                $vector = MovementRouteRunner::directionVector($name);
                if ($vector->x === (float) ($dx <=> 0) && $vector->y === (float) ($dy <=> 0)) {
                    $direction = $name;
                    break;
                }
            }
            $values = ['direction' => $direction];
            if (!$selected['faceOnly']) {
                $values['count'] = (string) (abs($dx) + abs($dy));
            }
        }
        $changes = [];
        foreach ($values as $name => $value) {
            if (!isset($fields[$name]) || $value === null) {
                throw new \InvalidArgumentException('The shared schema does not expose that coordinate.');
            }
            $changes[$fields[$name]] = $value;
        }
        return $changes;
    }
}
