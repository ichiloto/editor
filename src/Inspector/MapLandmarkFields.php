<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Inspector;

use Ichiloto\Editor\Database\ConditionCodec;
use Ichiloto\Editor\Field\MapLandmarks;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Field\MapLandmarkCatalog;
use Ichiloto\Engine\Field\MapLandmarkKind;
use InvalidArgumentException;

/**
 * The inspector rows that author a map's minimap landmarks, the same rows in the Terminal and in a graphical
 * editor: a list of declarations on the map, and on each placed event the one choice of what it is on the minimap.
 * Every anchor and kind is chosen from what the map offers, never typed. Edits return the declarations they leave;
 * the inspector checks them through the Engine and records them as one undo step.
 */
final class MapLandmarkFields
{
    /** Rows of the map's landmark list. */
    public const string TARGET = 'map-landmarks';
    /** An event's own minimap row. */
    public const string EVENT_TARGET = 'event-landmark';
    /** A world object's own minimap row. */
    public const string OBJECT_TARGET = 'object-landmark';
    /** The event row's value when no declaration describes the event. */
    public const string UNDESCRIBED = 'undescribed';

    private function __construct() {}

    /** @return list<array<string, mixed>> */
    public static function describeMapFields(ProjectMap $map): array
    {
        try {
            $landmarks = MapLandmarks::readMap($map);
            $anchors = $landmarks->describeAnchors();
        } catch (InvalidArgumentException $error) {
            return [['label' => 'Minimap Landmarks', 'value' => '', 'editable' => false],
                ['label' => '  ! Read-only', 'value' => $error->getMessage(), 'editable' => false]];
        }
        $automatic = count(array_filter($anchors, static fn(array $anchor): bool => $anchor['transfer'] && $anchor['landmark'] === null));
        $count = count($landmarks->entries);
        $fields = [
            ['label' => 'Minimap Landmarks', 'value' => '', 'editable' => false],
            // Every transfer no landmark describes is still marked, without a name.
            ['label' => '  Unnamed Route Markers', 'value' => (string) $automatic, 'editable' => false],
            // The list's own heading, so adding works on an empty list as on every other inspector list.
            ['label' => sprintf('  Landmarks · %d', $count), 'value' => '', 'editable' => false,
                'target' => self::TARGET, 'landmarkList' => ['index' => max(0, $count - 1)]],
        ];
        $problem = self::findProblem($map, $landmarks->entries);
        if ($problem !== null) {
            $fields[] = ['label' => '  ! Problem', 'value' => $problem, 'editable' => false];
        }
        foreach ($landmarks->entries as $index => $entry) {
            array_push($fields, ...self::describeEntryFields($landmarks, $anchors, $index, $entry));
        }
        return $fields;
    }

    /**
     * An event's minimap row: what the event is on the minimap, chosen among the kinds it may be. A transfer that
     * no landmark describes still shows as an unnamed route; choosing a kind describes it, and choosing the first
     * option removes the description. An event without cells has nothing to mark.
     *
     * @return list<array<string, mixed>>
     */
    public static function describeEventFields(ProjectMap $map, string $marker): array
    {
        return $map->getEventArea($marker) === null ? [] : self::describeAnchorFields($map, ['event' => $marker]);
    }

    /**
     * A world object's minimap row, as an event's: a building or a landmark once described.
     *
     * @return list<array<string, mixed>>
     */
    public static function describeObjectFields(ProjectMap $map, string $id): array
    {
        return self::describeAnchorFields($map, ['object' => $id]);
    }

    /**
     * @param array<string, string> $anchor
     * @return list<array<string, mixed>>
     */
    private static function describeAnchorFields(ProjectMap $map, array $anchor): array
    {
        $key = isset($anchor['event']) ? ['target' => self::EVENT_TARGET, 'marker' => $anchor['event']]
            : ['target' => self::OBJECT_TARGET, 'path' => [$anchor['object']]];
        try {
            $landmarks = MapLandmarks::readMap($map);
        } catch (InvalidArgumentException $error) {
            return [['label' => 'Minimap', 'value' => '! ' . $error->getMessage(), 'editable' => false]];
        }
        $index = $landmarks->findAnchorIndex($anchor);
        $route = $landmarks->isRouteAnchor($anchor);
        $choices = [['label' => $route ? 'Unnamed route marker' : 'Not on the minimap', 'value' => self::UNDESCRIBED, 'description' => $route
            ? 'Every transfer is marked at once; describe it to name it or keep it secret.'
            : 'Only described places are marked.']];
        foreach ($landmarks->findKindsFor($anchor) as $kind) {
            $choices[] = ['label' => self::describeKind($kind), 'value' => $kind->value, 'description' => self::explainKind($kind)];
        }
        $entry = $index === null ? null : $landmarks->entries[$index];
        // A short enum: picked or stepped through, as an event's cue kind is.
        $fields = [['label' => 'Minimap', 'value' => is_string($entry['kind'] ?? null) ? $entry['kind'] : self::UNDESCRIBED,
            'editable' => true, 'choices' => $choices, 'options' => array_column($choices, 'value'), ...$key]];
        if ($entry !== null) {
            $fields[] = ['label' => '  Landmark', 'value' => sprintf('%s · %s', $entry['id'] ?? '?', self::describeName($entry)), 'editable' => false];
        }
        return $fields;
    }

    /**
     * The declarations an edit to one of these rows leaves, or null when it changes nothing.
     *
     * @param array<string, mixed> $field
     * @return list<array<string, mixed>>|null
     * @throws InvalidArgumentException When the edit cannot be made as asked.
     */
    public static function applyValue(ProjectMap $map, array $field, mixed $value): ?array
    {
        $landmarks = MapLandmarks::readMap($map);
        $anchor = match ($field['target'] ?? null) {
            self::EVENT_TARGET => ['event' => (string) $field['marker']],
            self::OBJECT_TARGET => ['object' => (string) ($field['path'][0] ?? '')],
            default => null,
        };
        if ($anchor !== null) {
            $index = $landmarks->findAnchorIndex($anchor);
            if ($value === self::UNDESCRIBED) { return $index === null ? null : $landmarks->removeLandmark($index); }
            $kind = self::requireKind($landmarks, $anchor, (string) $value);
            return $index === null ? $landmarks->addLandmark($anchor, $kind)['entries'] : $landmarks->setKind($index, $kind);
        }
        $index = (int) ($field['index'] ?? -1);
        $entry = $landmarks->entries[$index] ?? throw new InvalidArgumentException('That landmark is no longer here; read the inspector again.');
        $anchor = MapLandmarks::readAnchor($entry);
        return match ((string) ($field['field'] ?? '')) {
            'anchor' => $landmarks->setAnchor($index, MapLandmarks::decodeAnchor((string) $value)),
            'kind' => $landmarks->setKind($index, self::requireKind($landmarks, $anchor
                ?? throw new InvalidArgumentException('Choose this landmark\'s anchor first.'), (string) $value)),
            'name' => $landmarks->setName($index, (string) $value),
            'nameMessage' => $landmarks->setNameMessage($index, (string) $value),
            'secret' => $landmarks->setSecret($index, (bool) $value),
            'approach' => $value === true
                ? (array_key_exists('approachDistance', $entry) && $entry['approachDistance'] === null ? $landmarks->resetApproachDistance($index) : null)
                : $landmarks->setApproachDistance($index, null),
            'approachDistance' => $landmarks->setApproachDistance($index, self::requireDistance($value)),
            'revealConditions' => $landmarks->setRevealConditions($index, is_array($value) ? $value : []),
            default => null,
        };
    }

    /**
     * Describes the next place no landmark describes yet, in map order: events, then world objects, then legacy
     * transfers. Its anchor row then chooses any other.
     *
     * @return array{entries: list<array<string, mixed>>, summary: string}
     */
    public static function addEntry(ProjectMap $map): array
    {
        $landmarks = MapLandmarks::readMap($map);
        $anchors = $landmarks->describeAnchors();
        $free = array_find($anchors, static fn(array $anchor): bool => $anchor['landmark'] === null)
            ?? throw new InvalidArgumentException($anchors === []
                ? 'This map has no placed event, world object or legacy transfer to mark.'
                : 'Every event, world object and legacy transfer here already anchors a landmark.');
        $added = $landmarks->addLandmark($free['anchor']);
        return ['entries' => $added['entries'], 'summary' => sprintf('Added landmark %s on %s; choose its anchor and kind.', $added['id'], $free['label'])];
    }

    /**
     * @param array<string, mixed> $field
     * @return array{entries: list<array<string, mixed>>, summary: string}
     */
    public static function removeEntry(ProjectMap $map, array $field): array
    {
        $landmarks = MapLandmarks::readMap($map);
        $index = (int) ($field['landmarkList']['index'] ?? -1);
        $entry = $landmarks->entries[$index] ?? throw new InvalidArgumentException('There is no landmark here to remove.');
        $anchor = MapLandmarks::readAnchor($entry);
        $route = $anchor !== null && $landmarks->isRouteAnchor($anchor);
        return ['entries' => $landmarks->removeLandmark($index), 'summary' => sprintf('Removed landmark %s%s.', $entry['id'] ?? $index + 1,
            $route ? '; its route shows as an unnamed marker again' : '')];
    }

    /**
     * Why the map's declarations would not load, by the Engine's own check, or null when they would.
     *
     * @param list<array<string, mixed>> $entries
     */
    public static function findProblem(ProjectMap $map, array $entries): ?string
    {
        try {
            MapLandmarks::validateEntries($map, $entries);
            return null;
        } catch (InvalidArgumentException $error) {
            return $error->getMessage();
        }
    }

    /**
     * @param list<array{anchor: array<string, string>, value: string, label: string, transfer: bool, landmark: ?string}> $anchors
     * @return list<array<string, mixed>>
     */
    private static function describeEntryFields(MapLandmarks $landmarks, array $anchors, int $index, array $entry): array
    {
        $row = static fn(array $field): array => [...$field, 'target' => self::TARGET, 'index' => $index, 'landmarkList' => ['index' => $index]];
        $id = is_string($entry['id'] ?? null) ? $entry['id'] : sprintf('(entry %d has no id)', $index + 1);
        $anchor = MapLandmarks::readAnchor($entry);
        $anchorValue = $anchor === null ? '' : MapLandmarks::encodeAnchor($anchor);
        $anchorChoices = array_map(static fn(array $choice): array => ['label' => $choice['label'], 'value' => $choice['value'],
            'description' => $choice['transfer'] ? 'A route: a passage, entrance or exit.' : 'A building or a landmark.'],
            array_values(array_filter($anchors, static fn(array $choice): bool => $choice['landmark'] === null || $choice['value'] === $anchorValue)));
        $kinds = $anchor === null ? [] : $landmarks->findKindsFor($anchor);
        $secret = ($entry['secret'] ?? false) === true;
        $named = isset($entry['name']);
        $conditions = is_array($entry['revealConditions'] ?? null) ? $entry['revealConditions'] : [];
        $fields = [
            // The id is the identity saves remember discoveries by: shown, never edited.
            $row(['label' => '  Landmark ' . $id, 'value' => $anchor === null ? '(no anchor)' : $landmarks->describeAnchor($anchor), 'editable' => false]),
            $row(['label' => '    Anchor', 'value' => $anchorValue, 'editable' => true, 'choices' => $anchorChoices, 'field' => 'anchor']),
            $row(['label' => '    Kind', 'value' => (string) ($entry['kind'] ?? ''), 'editable' => true, 'field' => 'kind',
                'choices' => array_map(static fn(MapLandmarkKind $kind): array => ['label' => self::describeKind($kind),
                    'value' => $kind->value, 'description' => self::explainKind($kind)], $kinds),
                'options' => array_map(static fn(MapLandmarkKind $kind): string => $kind->value, $kinds)]),
            $row(['label' => '    Name', 'value' => $named ? (string) ($entry['name']['default'] ?? '') : '(unnamed · the minimap shows no label)',
                'control' => new InputControl(InputControlType::TEXT, $named ? (string) ($entry['name']['default'] ?? '') : ''), 'field' => 'name']),
        ];
        if ($named) {
            $fields[] = $row(['label' => '    Name Translation Key', 'value' => (string) ($entry['name']['message'] ?? ''),
                'control' => new InputControl(InputControlType::TEXT, (string) ($entry['name']['message'] ?? '')), 'field' => 'nameMessage']);
        }
        $fields[] = $row(['label' => '    Secret', 'value' => $secret ? 'yes · hidden until explicitly revealed' : 'no',
            'control' => new InputControl(InputControlType::BOOLEAN, $secret ? 'true' : 'false'), 'field' => 'secret']);
        if (!$secret) {
            $distance = array_key_exists('approachDistance', $entry) ? $entry['approachDistance'] : MapLandmarkCatalog::DEFAULT_APPROACH_DISTANCE;
            $fields[] = $row(['label' => '    Learned On Approach', 'value' => $distance === null ? 'no · only by its reveal conditions' : 'yes',
                'control' => new InputControl(InputControlType::BOOLEAN, $distance === null ? 'false' : 'true'), 'field' => 'approach']);
            if ($distance !== null) {
                $fields[] = $row(['label' => '    Approach Distance (cells)', 'value' => array_key_exists('approachDistance', $entry)
                    ? (string) $distance : sprintf('(engine default: %d)', MapLandmarkCatalog::DEFAULT_APPROACH_DISTANCE),
                    'control' => new InputControl(InputControlType::INTEGER, (string) $distance), 'field' => 'approachDistance']);
            }
        }
        $fields[] = $row(['label' => '    Revealed When', 'value' => $conditions === []
                ? ($secret ? '(no conditions · stays hidden)' : '(no conditions · learned on approach only)')
                : ConditionCodec::encodeAll($conditions),
            'field' => 'revealConditions', 'mapConditions' => true, 'encoded' => ConditionCodec::encodeAll($conditions),
            'conditionsTitle' => sprintf('Landmark %s Reveal Conditions', $id),
            'conditionsHint' => 'All conditions must hold to reveal the landmark. An empty list removes them.']);
        return $fields;
    }

    private static function requireDistance(mixed $value): int
    {
        if (!is_int($value) || $value < MapLandmarkCatalog::MIN_APPROACH_DISTANCE || $value > MapLandmarkCatalog::MAX_APPROACH_DISTANCE) {
            throw new InvalidArgumentException(sprintf('An approach distance is %d to %d cells.',
                MapLandmarkCatalog::MIN_APPROACH_DISTANCE, MapLandmarkCatalog::MAX_APPROACH_DISTANCE));
        }
        return $value;
    }

    /** @param array<string, string> $anchor */
    private static function requireKind(MapLandmarks $landmarks, array $anchor, string $value): MapLandmarkKind
    {
        $kind = MapLandmarkKind::tryFrom($value);
        if ($kind === null || !in_array($kind, $landmarks->findKindsFor($anchor), true)) {
            throw new InvalidArgumentException(sprintf('%s cannot be a %s; choose one of: %s.', $landmarks->describeAnchor($anchor), $value,
                implode(', ', array_map(static fn(MapLandmarkKind $kind): string => $kind->value, $landmarks->findKindsFor($anchor)))));
        }
        return $kind;
    }

    private static function describeName(array $entry): string
    {
        return isset($entry['name']['default']) ? (string) $entry['name']['default'] : 'unnamed';
    }

    private static function describeKind(MapLandmarkKind $kind): string
    {
        return ucfirst($kind->value);
    }

    private static function explainKind(MapLandmarkKind $kind): string
    {
        return match ($kind) {
            MapLandmarkKind::PASSAGE => 'A way through, marked at once.',
            MapLandmarkKind::ENTRANCE => 'A way in, marked at once.',
            MapLandmarkKind::EXIT => 'A way out, marked at once.',
            MapLandmarkKind::BUILDING => 'A building, marked once learned.',
            MapLandmarkKind::LANDMARK => 'A notable place, marked once learned.',
        };
    }
}
