<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Inspector;

use Ichiloto\Editor\Database\ConditionCodec;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Events\EventTypeCatalog;
use Ichiloto\Editor\Field\MapBgmVariants;
use Ichiloto\Editor\Field\MapEncounters;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;

/**
 * The inspector rows of a map and of one of its events, and the edits they
 * make, as every editor interface shows and applies them.
 *
 * A row is a descriptor array (`label`, `value`, and when it can be edited a
 * `control`, `options` or `reference`, with the `target`, `field`, `path` or
 * `marker` its edit is applied through). Nothing here knows a cursor, a
 * selection or a pane: the caller names the map and the event, applies an
 * edit through {@see apply()}, records the command it returns, and shows an
 * {@see InspectorRefusal}'s reason.
 */
final readonly class MapInspector
{
    public const string MAP_BGM_NONE = '(None)';
    public const string MAP_KIND_NONE = 'Not set';

    public function __construct(private ReferenceCatalog $references)
    {
    }

    /**
     * The map's own rows: identity, kind, size, counts, music and encounters.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMapFields(ProjectMap $map): array
    {
        return [
            [
                'label' => 'Name',
                'value' => $map->getDisplayName(),
                'control' => new InputControl(InputControlType::TEXT, $map->getDisplayName()),
                'target' => 'map',
                'field' => 'name',
            ],
            [
                'label' => 'Region',
                'value' => $map->getRegion(),
                'control' => new InputControl(InputControlType::TEXT, $map->getRegion()),
                'target' => 'map',
                'field' => 'region',
            ],
            $this->buildMapKindField($map),
            [
                'label' => 'Description',
                'value' => $map->getDescription(),
                'control' => new InputControl(InputControlType::TEXT, $map->getDescription()),
                'target' => 'map',
                'field' => 'description',
            ],
            [
                'label' => 'Size',
                'value' => '',
                'editable' => false,
            ],
            [
                'label' => '  X',
                'value' => (string) $map->getWidth(),
                'control' => new InputControl(InputControlType::INTEGER, (string) $map->getWidth()),
                'target' => 'map-size',
                'field' => 'width',
            ],
            [
                'label' => '  Y',
                'value' => (string) $map->getHeight(),
                'control' => new InputControl(InputControlType::INTEGER, (string) $map->getHeight()),
                'target' => 'map-size',
                'field' => 'height',
            ],
            [
                'label' => 'Events',
                'value' => (string) $map->getEventDefinitionCount(),
                'editable' => false,
            ],
            [
                'label' => 'Triggers',
                'value' => (string) $map->getTriggerCount(),
                'editable' => false,
            ],
            ...$this->mapRuntimeFields($map),
        ];
    }

    /**
     * One event's rows: its marker, type, position and size (or its painted
     * cells), and its data.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEventFields(ProjectMap $map, string $marker): array
    {
        $definition = $map->getEventDefinition($marker);
        $bounds = $map->getEventBounds($marker);
        $area = $map->getEventArea($marker);
        $fields = [
            [
                'label' => 'Event',
                'value' => $marker,
                'editable' => false,
            ],
            [
                'label' => 'Type',
                'value' => EventTypeCatalog::describeClass(
                    is_array($definition) && is_string($definition['class'] ?? null) ? $definition['class'] : null,
                ),
                'editable' => true,
                'target' => 'event-type',
                'marker' => $marker,
            ],
        ];

        if ($bounds !== null) {
            $fields[] = ['label' => 'Position', 'value' => '', 'editable' => false];
            foreach (['x' => '  X', 'y' => '  Y'] as $axis => $label) {
                $fields[] = [
                    'label' => $label,
                    'value' => (string) $bounds[$axis],
                    'control' => new InputControl(InputControlType::INTEGER, (string) $bounds[$axis]),
                    'target' => 'event-bounds',
                    'marker' => $marker,
                    'field' => $axis,
                ];
            }
            if ($area?->isRectangle ?? true) {
                $fields[] = ['label' => 'Size', 'value' => '', 'editable' => false];
                foreach (['width' => '  X', 'height' => '  Y'] as $axis => $label) {
                    $fields[] = [
                        'label' => $label,
                        'value' => (string) $bounds[$axis],
                        'control' => new InputControl(InputControlType::INTEGER, (string) $bounds[$axis]),
                        'target' => 'event-bounds',
                        'marker' => $marker,
                        'field' => $axis,
                    ];
                }
            } else {
                // Painted in its own shape: the event triggers on exactly these cells.
                $pieces = count($area->findPieces());
                $fields[] = [
                    'label' => 'Cells',
                    'value' => sprintf('%d in %d %s', count($area->cells), $pieces, $pieces === 1 ? 'shape' : 'places'),
                    'editable' => false,
                ];
            }
        }

        if ($definition !== null) {
            $fields = [...$fields, ...$this->buildEventDataFields($marker, $definition)];
        }

        return $fields;
    }

    /**
     * Applies one row's edit to the map and returns the command that undoes
     * and redoes it, already applied; null when nothing changed or the row
     * is not one an edit applies through (an event's type, the map's kind).
     *
     * @param array<string, mixed> $field The row's descriptor.
     * @throws InspectorRefusal When the edit cannot be made as asked.
     */
    public function apply(ProjectMap $map, array $field, string $rawValue): ?Command
    {
        $control = self::findControl($field);
        $type = $control?->type ?? InputControlType::TEXT;
        $value = match ($type) {
            InputControlType::INTEGER => (int) trim($rawValue),
            InputControlType::FLOAT => (float) trim($rawValue),
            InputControlType::BOOLEAN => InputControl::parseBoolean($rawValue),
            default => $rawValue,
        };

        return match ((string) ($field['target'] ?? 'map')) {
            'map' => $this->applyMapField($map, (string) $field['field'], $value, (string) ($field['label'] ?? 'Field')),
            'map-data' => ($path = array_values((array) ($field['path'] ?? []))) === []
                ? null
                : $this->writeMapData($map, $path, $value === '' ? null : $value, (string) ($field['label'] ?? 'Field')),
            'map-encounters' => $this->applyMapEncounterValue($map, $field, $value),
            'map-bgm-variants' => $this->applyMapBgmVariantValue($map, $field, $value),
            'map-size' => $this->applyMapSize($map, (string) ($field['field'] ?? ''), (int) $value),
            'event' => $this->applyEventField($map, $field, $value),
            'event-bounds' => $this->applyEventBounds($map, (string) $field['marker'], (string) $field['field'], (int) $value),
            default => null,
        };
    }

    /**
     * Writes one value into the map's data and returns its command, or null
     * when the data already held it. A null value removes the key.
     *
     * @param list<string> $path
     */
    public function writeMapData(ProjectMap $map, array $path, mixed $value, string $label): ?Command
    {
        $hadValue = $map->hasMapDataField($path);
        $oldValue = $map->getMapDataField($path);
        $map->setMapDataField($path, $value);

        if ($map->getMapDataField($path) === $oldValue && $map->hasMapDataField($path) === $hadValue) {
            return null;
        }

        return new GenericCommand(
            sprintf('%s edit', $label),
            static fn() => $map->setMapDataField($path, $value),
            static fn() => $map->setMapDataField($path, $hadValue ? $oldValue : null),
        );
    }

    /**
     * Applies one music variant row's edit through the variants model, which
     * owns the list's shape and preserves every key it does not edit.
     *
     * @param array<string, mixed> $field
     * @throws InspectorRefusal When the map's variants cannot be edited here.
     */
    public function applyMapBgmVariantValue(ProjectMap $map, array $field, mixed $value): ?Command
    {
        $variants = MapBgmVariants::fromMap($map);

        if (! $variants->isSupported()) {
            throw new InspectorRefusal(sprintf('Music variants are read-only here: %s.', $variants->unsupportedReason()));
        }

        $index = (int) ($field['index'] ?? 0);
        $block = match ((string) ($field['field'] ?? '')) {
            'track' => $variants->withTrackAt($index, (string) $value),
            'conditions' => is_array($value) ? $variants->withConditionsAt($index, $value) : null,
            default => null,
        };

        return $block === null ? null : $this->writeMapData($map, [MapBgmVariants::KEY], $block, sprintf('Music variant %d', $index + 1));
    }

    /**
     * The picker an event data row is chosen from, when its value names
     * another resource.
     *
     * @param array<string, mixed> $field
     * @return array{category: string, title: string}|null
     */
    public static function findEventReference(array $field): ?array
    {
        if (($field['target'] ?? null) !== 'event') {
            return null;
        }

        $path = array_values((array) ($field['path'] ?? []));

        if (($path[0] ?? null) !== 'data' || count($path) < 2) {
            return null;
        }

        $leaf = (string) $path[array_key_last($path)];

        return match (true) {
            $path === ['data', 'scriptId'] => ['category' => 'common_events', 'title' => 'Event Script'],
            $path === ['data', 'cinematicId'] => ['category' => 'cinematics', 'title' => 'Cinematic'],
            $leaf === 'bgm' => ['category' => 'bgm', 'title' => 'Music'],
            $leaf === 'sfx' => ['category' => 'sfx', 'title' => 'Sound Effect'],
            // A shop's stock is data.items.N.item. The leaf alone would also
            // match an unrelated event that happened to call a field "item".
            $leaf === 'item' && ($path[1] ?? null) === 'items' => ['category' => 'inventory', 'title' => 'Item'],
            default => null,
        };
    }

    /**
     * The input a row is typed into, or null when it is read-only or chosen
     * some other way.
     *
     * @param array<string, mixed> $field
     */
    public static function findControl(array $field): ?InputControl
    {
        if (($field['editable'] ?? null) === false) {
            return null;
        }

        $control = $field['control'] ?? null;

        return $control instanceof InputControl ? $control : null;
    }

    /**
     * The map's kind row: the tileset it draws from, chosen from the
     * project's tilesets.
     *
     * @return array<string, mixed>
     */
    public function buildMapKindField(ProjectMap $map): array
    {
        $id = $map->getMapDataField(['tileset']);
        $labels = $this->references->labelsFor('tilesets');

        return [
            'label' => 'Kind',
            'value' => match (true) {
                $id === null => self::MAP_KIND_NONE,
                ! is_string($id) => sprintf('%s · not a tileset id', get_debug_type($id)),
                ! isset($labels[$id]) => sprintf('%s · not in assets/%s', $id, Tileset::DIRECTORY),
                default => $labels[$id],
            },
            'selectedValue' => is_string($id) ? $id : '',
            'reference' => 'tilesets',
            'target' => 'map-kind',
            'field' => 'tileset',
        ];
    }

    private function applyMapField(ProjectMap $map, string $fieldName, mixed $value, string $label): ?Command
    {
        $oldValue = $map->getMapField($fieldName);
        $map->setMapField($fieldName, $value);

        return new GenericCommand(
            sprintf('%s edit', $label),
            static fn() => $map->setMapField($fieldName, $value),
            static fn() => $map->setMapField($fieldName, $oldValue),
        );
    }

    /**
     * Resizes the map, refusing rather than clamping, deleting or truncating
     * when an NPC would be left outside it.
     *
     * @throws InspectorRefusal When NPCs would be stranded; its details name them.
     */
    private function applyMapSize(ProjectMap $map, string $axis, int $value): ?Command
    {
        $newWidth = $axis === 'width' ? max(1, $value) : $map->getWidth();
        $newHeight = $axis === 'height' ? max(1, $value) : $map->getHeight();
        $stranded = $map->describeNpcsStrandedBy($newWidth, $newHeight);

        if ($stranded !== []) {
            // Refuse rather than clamp, delete, or truncate: the author
            // moves, resizes or removes the NPC, then shrinks.
            throw new InspectorRefusal(
                sprintf('Cannot shrink to %dx%d: %d NPC%s would be stranded.', $newWidth, $newHeight, count($stranded), count($stranded) === 1 ? '' : 's'),
                array_map(static fn(string $line): string => '- ' . $line, $stranded),
            );
        }

        $snapshotBefore = $map->captureGridSnapshot();
        $map->resize($newWidth, $newHeight);
        $snapshotAfter = $map->captureGridSnapshot();

        return new GenericCommand(
            'Map resize',
            static fn() => $map->restoreGridSnapshot($snapshotAfter),
            static fn() => $map->restoreGridSnapshot($snapshotBefore),
        );
    }

    /** @param array<string, mixed> $field */
    private function applyEventField(ProjectMap $map, array $field, mixed $value): ?Command
    {
        $marker = (string) $field['marker'];
        $path = (array) ($field['path'] ?? []);
        $oldValue = $map->getEventField($marker, $path);
        $map->setEventField($marker, $path, $value);

        return new GenericCommand(
            sprintf('%s edit', $field['label'] ?? 'Event field'),
            static fn() => $map->setEventField($marker, $path, $value),
            static fn() => $map->setEventField($marker, $path, $oldValue),
        );
    }

    /**
     * Moves or resizes an event. A marker painted in another shape than a
     * rectangle keeps its shape: its position moves every cell, and its cells
     * are reshaped by painting them.
     *
     * @throws InspectorRefusal When the event cannot move or resize as asked.
     */
    private function applyEventBounds(ProjectMap $map, string $marker, string $axis, int $value): ?Command
    {
        $area = $map->getEventArea($marker);

        if ($area === null) {
            return null;
        }

        $bounds = $map->getEventBounds($marker);
        $snapshotBefore = $map->captureGridSnapshot();

        if (! $area->isRectangle) {
            if (! in_array($axis, ['x', 'y'], true)) {
                throw new InspectorRefusal(sprintf('Marker %s is painted in its own shape; paint or erase its cells to reshape it.', $marker));
            }

            $delta = max(0, $value) - $bounds[$axis];
            $refusal = $map->moveEventCells($marker, $axis === 'x' ? $delta : 0, $axis === 'y' ? $delta : 0);

            if ($refusal !== null) {
                throw new InspectorRefusal($refusal);
            }
        } else {
            $bounds[$axis] = max(0, $value);
            $map->setEventBounds($marker, $bounds['x'], $bounds['y'], $bounds['width'], $bounds['height']);
        }

        $snapshotAfter = $map->captureGridSnapshot();

        return new GenericCommand(
            'Event bounds edit',
            static fn() => $map->restoreGridSnapshot($snapshotAfter),
            static fn() => $map->restoreGridSnapshot($snapshotBefore),
        );
    }

    /**
     * Applies one encounter row's edit through the encounters model.
     *
     * @param array<string, mixed> $field
     * @throws InspectorRefusal When the map's encounters cannot be edited here.
     */
    private function applyMapEncounterValue(ProjectMap $map, array $field, mixed $value): ?Command
    {
        $encounters = MapEncounters::fromMap($map);

        if (! $encounters->isSupported()) {
            throw new InspectorRefusal(sprintf('Encounters are read-only here: %s.', $encounters->unsupportedReason()));
        }

        $index = (int) ($field['index'] ?? 0);
        $block = match ((string) ($field['field'] ?? '')) {
            'rate' => $encounters->withRate((int) $value),
            'tiles' => $encounters->withTiles((string) $value),
            'weight' => $encounters->withWeightAt($index, (int) $value),
            'troop' => $encounters->withTroopAt($index, (string) $value),
            default => null,
        };

        if ($block === null && ! $encounters->isDeclared()) {
            return null;
        }

        return $this->writeMapData($map, [MapEncounters::KEY], $block, 'Encounters');
    }
    /**
     * Builds the map's runtime metadata rows: the music it plays and the
     * random encounters it offers.
     *
     * Both are read from what the map actually holds. An absent rate or tile
     * mode is shown as the engine's default in parentheses and is not
     * written until an author sets one, so opening a map never puts a
     * default into a file.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mapRuntimeFields(ProjectMap $map): array
    {
        $bgm = $map->getMapDataField(['bgm']);
        $bgm = is_string($bgm) ? $bgm : '';
        $known = $this->references->valuesFor('bgm');
        $missing = $bgm !== '' && ! in_array($bgm, $known, true);
        $fields = [
            [
                'label' => 'Audio',
                'value' => '',
                'editable' => false,
            ],
            [
                // A track is chosen from what the project has, never spelled.
                // A track the project no longer has stays visible and says
                // so, rather than being quietly swapped for a valid one.
                'label' => '  Background Music',
                'value' => match (true) {
                    $bgm === '' => self::MAP_BGM_NONE,
                    $missing => $bgm . ' · not in assets/Audio/BGM',
                    default => $bgm,
                },
                'reference' => 'bgm',
                'target' => 'map-data',
                'path' => ['bgm'],
                'field' => 'bgm',
            ],
        ];

        $fields = [...$fields, ...$this->mapBgmVariantFields($map, $known)];

        $encounters = MapEncounters::fromMap($map);
        $fields[] = [
            'label' => 'Encounters',
            'value' => $encounters->summary(),
            'editable' => false,
        ];

        if (! $encounters->isSupported()) {
            $fields[] = [
                'label' => '  ! Read-only',
                'value' => (string) $encounters->unsupportedReason(),
                'editable' => false,
            ];

            return $fields;
        }

        $rows = $encounters->rows();
        $fields[] = [
            // The Inspector's own list heading, so Shift+O and Del mean here
            // what they mean on every other list in the pane.
            'label' => sprintf('  Troops · %d', count($rows)),
            'value' => '',
            'editable' => false,
            'target' => 'map-encounters',
            'encounterList' => ['index' => max(0, count($rows) - 1)],
        ];

        foreach ($rows as $index => $row) {
            $weight = $row['weight'];
            $fields[] = [
                'label' => '    Troop',
                'value' => $row['name'],
                'reference' => 'troops',
                'target' => 'map-encounters',
                'field' => 'troop',
                'index' => $index,
                'encounterList' => ['index' => $index],
            ];
            $usable = is_numeric($weight) && (int) $weight >= 1;
            $fields[] = [
                'label' => '    Weight',
                'value' => (is_scalar($weight) ? (string) $weight : '') . ($usable ? '' : ' · the engine drops a troop it cannot weigh'),
                'control' => new InputControl(InputControlType::INTEGER, $usable ? (string) (int) $weight : '1'),
                'target' => 'map-encounters',
                'field' => 'weight',
                'index' => $index,
                'encounterList' => ['index' => $index],
            ];
        }

        if ($rows === []) {
            return $fields;
        }

        $rate = $encounters->authoredRate();
        $fields[] = [
            'label' => '  Rate',
            'value' => $rate === null ? sprintf('(engine default: %d)', MapEncounters::DEFAULT_RATE) : (string) $rate,
            'control' => new InputControl(InputControlType::INTEGER, (string) ($rate ?? MapEncounters::DEFAULT_RATE)),
            'target' => 'map-encounters',
            'field' => 'rate',
        ];
        $tiles = $encounters->authoredTiles();
        $fields[] = [
            'label' => '  Tiles',
            'value' => $tiles === null ? sprintf('(engine default: %s)', MapEncounters::DEFAULT_TILES) : $tiles,
            'options' => MapEncounters::TILE_MODES,
            'target' => 'map-encounters',
            'field' => 'tiles',
        ];

        return $fields;
    }

    /**
     * Builds the ordered Music Variants rows: the map's conditional music,
     * evaluated by the engine in declaration order, first match wins.
     *
     * @param string[] $known The project's BGM tracks.
     * @return array<int, array<string, mixed>>
     */
    private function mapBgmVariantFields(ProjectMap $map, array $known): array
    {
        $variants = MapBgmVariants::fromMap($map);
        $fields = [
            [
                'label' => '  Music Variants',
                'value' => $variants->summary(),
                'editable' => false,
                'target' => 'map-bgm-variants',
                'bgmVariantList' => ['index' => max(0, $variants->count() - 1)],
            ],
        ];

        if (! $variants->isSupported()) {
            $fields[] = [
                'label' => '  ! Read-only',
                'value' => (string) $variants->unsupportedReason(),
                'editable' => false,
            ];

            return $fields;
        }

        foreach ($variants->rows() as $index => $row) {
            $track = $variants->trackAt($index);
            $raw = $variants->rawTrackAt($index);
            $fields[] = [
                'label' => sprintf('    Variant %d Track', $index + 1),
                'value' => match (true) {
                    $track === null && $raw !== null => var_export($raw, true) . ' · not a track name',
                    $track === null || $track === '' => '(no track yet)',
                    ! in_array($track, $known, true) => $track . ' · not in assets/Audio/BGM',
                    default => $track,
                },
                'reference' => 'bgm',
                'target' => 'map-bgm-variants',
                'field' => 'track',
                'index' => $index,
                'bgmVariantList' => ['index' => $index],
            ];

            $issue = $variants->conditionsIssueAt($index);
            $conditions = $variants->conditionsAt($index);
            $fields[] = [
                'label' => sprintf('    Variant %d When', $index + 1),
                'value' => $issue !== null
                    ? '! ' . $issue
                    : ($conditions === [] ? '(always · shadows later variants)' : ConditionCodec::encodeAll($conditions)),
                'target' => 'map-bgm-variants',
                'field' => 'conditions',
                'index' => $index,
                'bgmVariantList' => ['index' => $index],
                'editable' => $issue === null,
                'mapConditions' => $issue === null,
            ];
        }

        return $fields;
    }

    /**
     * Builds editable inspector fields for event data.
     *
     * @param string $marker The event marker.
     * @param array<string, mixed> $definition The event definition.
     * @return array<int, array<string, mixed>>
     */
    public function buildEventDataFields(string $marker, array $definition): array
    {
        $fields = [];
        $eventData = $definition['data'] ?? [];

        if (is_array($eventData)) {
            $fields = $this->decorateEventInspectorFields(
                $marker,
                $this->flattenInspectorFields($eventData, ['data']),
            );
        }

        $rootFields = array_diff_key($definition, ['class' => true, 'data' => true]);
        // Cue is a generic trigger capability, including for definitions
        // created before the field existed. Supplying an empty editor-only
        // default exposes the opt-in without changing the stored event until
        // the author actually edits it.
        $rootFields['cue'] ??= ['symbol' => '', 'color' => 'bright-yellow'];

        return [
            ...$fields,
            ...$this->decorateEventInspectorFields(
                $marker,
                $this->flattenInspectorFields($rootFields, []),
            ),
        ];
    }

    /**
     * Adds event context, pickers, and enum controls to flattened fields.
     *
     * @param array<int, array<string, mixed>> $fields The raw fields.
     * @return array<int, array<string, mixed>>
     */
    private function decorateEventInspectorFields(string $marker, array $fields): array
    {
        $decorated = [];

        foreach ($fields as $field) {
            $field['marker'] = $marker;
            $field['target'] = 'event';

            $path = array_values((array) ($field['path'] ?? []));

            if ($path === ['data', 'mode']) {
                $field['options'] = ['action', 'auto'];
                unset($field['control']);
            }

            $reference = self::findEventReference($field);

            if (is_array($reference)) {
                // A reference is chosen, never spelled: dropping the control
                // is what stops the field being typed into, leaving the
                // picker as the only way to set it.
                $field['reference'] = $reference['category'];
                unset($field['control']);
            }

            $decorated[] = $field;
        }

        return $decorated;
    }

    /**
     * Flattens nested scalar data into editable inspector fields.
     *
     * @param array<string|int, mixed> $data The data to flatten.
     * @param array<int, string> $path The current path.
     * @return array<int, array<string, mixed>>
     */
    private function flattenInspectorFields(array $data, array $path, ?array $list = null): array
    {
        $fields = [];

        foreach ($data as $key => $value) {
            $segment = (string) $key;
            $nextPath = [...$path, $segment];

            if (is_array($value)) {
                // A list of entries -- a shop's stock, an event's dialogue --
                // is something an author adds to and removes from, so every
                // field inside one remembers which list it belongs to.
                if ($this->isInspectorListValue($nextPath, $value)) {
                    $label = implode(' ', array_map(
                        static fn(string $part): string => ucwords(str_replace(['_', '-'], ' ', $part)),
                        ($nextPath[0] ?? null) === 'data' ? array_slice($nextPath, 1) : $nextPath
                    ));
                    $fields[] = [
                        'label' => sprintf('%s · %d', $label, count($value)),
                        'value' => '',
                        'editable' => false,
                        'list' => [
                            'path' => $nextPath,
                            'index' => max(0, count($value) - 1),
                            'blank' => $this->blankInspectorListEntry($nextPath),
                        ],
                    ];

                    foreach ($value as $index => $entry) {
                        $entryPath = [...$nextPath, (string) $index];
                        $entryList = ['path' => $nextPath, 'index' => (int) $index];

                        $fields = [
                            ...$fields,
                            ...(is_array($entry)
                                ? $this->flattenInspectorFields($entry, $entryPath, $entryList)
                                : $this->flattenInspectorFields([(string) $index => $entry], $nextPath, $entryList)),
                        ];
                    }

                    continue;
                }

                if (array_key_exists('x', $value) && array_key_exists('y', $value) && is_scalar($value['x']) && is_scalar($value['y'])) {
                    $label = implode(' ', array_map(
                        static fn(string $part): string => ucwords(str_replace(['_', '-'], ' ', $part)),
                        ($nextPath[0] ?? null) === 'data' ? array_slice($nextPath, 1) : $nextPath
                    ));
                    $fields[] = [
                        'label' => $label,
                        'value' => '',
                        'editable' => false,
                    ];
                    $fields[] = [
                        'label' => '  X',
                        'value' => (string) $value['x'],
                        'control' => new InputControl(
                            is_int($value['x']) ? InputControlType::INTEGER : InputControlType::TEXT,
                            (string) $value['x'],
                        ),
                        'path' => [...$nextPath, 'x'],
                    ];
                    $fields[] = [
                        'label' => '  Y',
                        'value' => (string) $value['y'],
                        'control' => new InputControl(
                            is_int($value['y']) ? InputControlType::INTEGER : InputControlType::TEXT,
                            (string) $value['y'],
                        ),
                        'path' => [...$nextPath, 'y'],
                    ];
                    continue;
                }

                $fields = [...$fields, ...$this->flattenInspectorFields($value, $nextPath, $list)];
                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $displayPath = ($nextPath[0] ?? null) === 'data'
                ? array_slice($nextPath, 1)
                : $nextPath;
            $label = implode(' ', array_map(
                static fn(string $part): string => ctype_digit($part)
                    ? '#' . ((int) $part + 1)
                    : ucwords(str_replace(['_', '-'], ' ', $part)),
                $displayPath
            ));
            $stringValue = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_float($value) => InputControl::formatFloat($value),
                default => (string) $value,
            };
            $controlType = match (true) {
                is_bool($value) => InputControlType::BOOLEAN,
                is_int($value) => InputControlType::INTEGER,
                is_float($value) => InputControlType::FLOAT,
                default => InputControlType::TEXT,
            };
            $leaf = [
                'label' => $label,
                'value' => $stringValue,
                'control' => new InputControl($controlType, $stringValue),
                'path' => $nextPath,
            ];

            if (is_array($list)) {
                $leaf['list'] = $list;
            }

            $fields[] = $leaf;
        }

        return $fields;
    }

    /**
     * Distinguishes authored lists from associative configuration blocks.
     *
     * Empty arrays need an explicit known-list name because PHP cannot tell
     * an empty list from an empty map.
     *
     * @param array<int, string> $path The candidate path.
     * @param array<mixed> $value The candidate value.
     */
    private function isInspectorListValue(array $path, array $value): bool
    {
        if (! array_is_list($value) || array_key_exists('x', $value)) {
            return false;
        }

        if ($value !== []) {
            return true;
        }

        return in_array(
            (string) ($path[array_key_last($path)] ?? ''),
            ['conditions', 'sets', 'dialogue', 'items', 'script', 'steps', 'options'],
            true,
        );
    }

    /**
     * Returns the structured first row for an empty inspector list.
     *
     * @param array<int, string> $path The list path.
     * @return array<string, mixed>
     */
    private function blankInspectorListEntry(array $path): array
    {
        return match ((string) ($path[array_key_last($path)] ?? '')) {
            'conditions' => ['type' => 'switch', 'name' => '', 'value' => true],
            'sets' => ['type' => 'switch', 'name' => '', 'value' => true],
            'script' => ['type' => 'text', 'name' => '', 'text' => ''],
            'steps' => ['direction' => 'down', 'count' => 1, 'faceOnly' => false],
            'options' => ['text' => '', 'then' => []],
            'items' => ['item' => '', 'price' => 0],
            default => ['name' => '', 'text' => ''],
        };
    }

}
