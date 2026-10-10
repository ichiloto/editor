<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Inspector;

use Ichiloto\Editor\Database\ConditionCodec;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Database\InnPresentationFields;
use Ichiloto\Editor\Database\MovementRouteFields;
use Ichiloto\Editor\Database\ProjectRecord;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordFieldCodec;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Events\EventAuthoring;
use Ichiloto\Editor\Events\EventRefusal;
use Ichiloto\Editor\Events\EventTypeCatalog;
use Ichiloto\Editor\Events\EventTypeDefinition;
use Ichiloto\Editor\Field\MapBgmVariants;
use Ichiloto\Editor\Field\MapEncounters;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Events\Enumerations\ChestType;
use Ichiloto\Engine\Events\Enumerations\LootType;
use Ichiloto\Engine\Events\Triggers\EventCueKind;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandReference;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandRegistry;
use InvalidArgumentException;
use Throwable;

/**
 * The inspector rows of a map and of one of its events, and the edits they
 * make, as every editor interface shows and applies them.
 *
 * A row is a descriptor array (`label`, `value`, and when it can be edited a
 * `control`, `options` or `reference`, with the `target`, `field`, `path` or
 * `marker` its edit is applied through). A row chosen from a picker rather
 * than stepped through carries `choices` (value, label, description). A row
 * inside a list an author adds to and removes from carries its list's place
 * (`list`, `encounterList` or `bgmVariantList`). Nothing here knows a
 * cursor, a selection or a pane: the caller names the map and the event,
 * applies an edit through {@see apply()}, records the command it returns,
 * and shows an {@see InspectorRefusal}'s reason.
 *
 * An inspector for a graphical editor also offers what only a graphical
 * renderer shows (the arena an encounter's battle takes place in). The
 * terminal editor's inspector does not: those values are kept exactly as
 * authored, never offered there.
 */
final readonly class MapInspector
{
    public const string MAP_BGM_NONE = '(None)';
    public const string MAP_KIND_NONE = 'Not set';
    public const string MAP_ARENA_NONE = '(default arena)';
    public const string ENCOUNTER_ARENA_NONE = '(map arena)';

    /** Classification is authored explicitly, never inferred from cue presentation. */
    public const array EVENT_CUE_KIND_CHOICES = [
        ['label' => 'Unclassified (clear kind)', 'value' => '', 'description' => 'Omit kind, preserving legacy unclassified guidance and the rest of the cue.'],
        ['label' => 'Story', 'value' => EventCueKind::STORY->value, 'description' => 'Story guidance that persists at the screen edge.'],
        ['label' => 'Route', 'value' => EventCueKind::ROUTE->value, 'description' => 'Route guidance that belongs to its map cell.'],
    ];

    /** A chest event's presentations, as an author picks one. */
    public const array CHEST_TYPE_CHOICES = [
        ['label' => 'Common', 'value' => ChestType::COMMON->value, 'description' => 'Standard chest presentation for ordinary treasure.'],
        ['label' => 'Rare', 'value' => ChestType::RARE->value, 'description' => 'Highlights a chest that should feel less common.'],
        ['label' => 'Epic', 'value' => ChestType::EPIC->value, 'description' => 'Marks a chest carrying high-value treasure.'],
        ['label' => 'Legendary', 'value' => ChestType::LEGENDARY->value, 'description' => 'Reserved for the most special chest rewards.'],
    ];

    /** What a chest event can give, as an author picks one. */
    public const array LOOT_TYPE_CHOICES = [
        ['label' => 'Item', 'value' => LootType::ITEM->value, 'description' => 'Rewards an item from the project item database.'],
        ['label' => 'Gold', 'value' => LootType::GOLD->value, 'description' => 'Awards currency directly when the chest is opened.'],
        ['label' => 'Experience', 'value' => LootType::EXPERIENCE->value, 'description' => 'Awards experience directly when claimed.'],
        ['label' => 'Skill', 'value' => LootType::SKILL->value, 'description' => 'Rewards a learnable skill identifier.'],
        ['label' => 'Spell', 'value' => LootType::SPELL->value, 'description' => 'Rewards a spell identifier.'],
        ['label' => 'Weapon', 'value' => LootType::WEAPON->value, 'description' => 'Rewards a weapon identifier.'],
        ['label' => 'Armor', 'value' => LootType::ARMOR->value, 'description' => 'Rewards an armor identifier.'],
        ['label' => 'Accessory', 'value' => LootType::ACCESSORY->value, 'description' => 'Rewards an accessory identifier.'],
    ];

    /**
     * @param ReferenceCatalog $references The project's references as the map sees them.
     * @param bool $graphical Whether the editor shows graphical presentation, and so offers it.
     */
    public function __construct(private ReferenceCatalog $references, private bool $graphical = false)
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
            // Chosen from the region names the project's maps use, never typed.
            [
                'label' => 'Region',
                'value' => $map->getRegion(),
                'selectedValue' => $map->getRegion(),
                'reference' => 'map_regions',
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
                'axis' => 'x',
            ],
            [
                'label' => '  Y',
                'value' => (string) $map->getHeight(),
                'control' => new InputControl(InputControlType::INTEGER, (string) $map->getHeight()),
                'target' => 'map-size',
                'field' => 'height',
                'axis' => 'y',
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
                // Chosen, never stepped through: changing an event's type
                // replaces data another type gave it.
                'choices' => array_map(static fn(EventTypeDefinition $type): array => [
                    'label' => $type->label,
                    'value' => $type->label,
                    'description' => $type->description,
                ], EventTypeCatalog::all()),
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
                    'axis' => $axis,
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
                        'axis' => $axis === 'width' ? 'x' : 'y',
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
            $fields = \Ichiloto\Editor\Maps\MapPlacement::describeEventFields($definition, $fields);
        }

        return $fields;
    }

    /**
     * Applies one row's edit to the map and returns the command that undoes
     * and redoes it, already applied; null when nothing changed or the row
     * is not one an edit applies through: the map's kind, which may first
     * ask to clear tiles ({@see changeMapKind()}).
     *
     * @param array<string, mixed> $field The row's descriptor.
     * @throws InspectorRefusal When the edit cannot be made as asked, or the row is a transfer's
     *     destination, which is set with its spawn point ({@see setTransferDestination()}).
     */
    public function apply(ProjectMap $map, array $field, string $rawValue): ?Command
    {
        if (($field['innPresentation'] ?? false) === true) {
            return $this->applyInnPresentationField($map, $field, $rawValue);
        }
        if (isset($field['movementRouteField'])) {
            return $this->applyMovementRouteField($map, $field, $rawValue);
        }
        if (($field['destination'] ?? false) === true) {
            throw new InspectorRefusal('A destination is set with its spawn point; choose both together.');
        }

        $control = self::findControl($field);
        $type = $control?->type ?? InputControlType::TEXT;
        $value = match ($type) {
            InputControlType::INTEGER => (int) trim($rawValue),
            InputControlType::FLOAT => (float) trim($rawValue),
            InputControlType::BOOLEAN => InputControl::parseBoolean($rawValue),
            default => $rawValue,
        };
        $choices = self::findChoiceValues($field);

        if ($control === null && $choices !== null && ! in_array($rawValue, $choices, true)) {
            // A row picked from a list takes one of its choices, whatever
            // interface sent the value.
            throw new InspectorRefusal(sprintf('%s cannot be %s; choose one of: %s.',
                trim((string) ($field['label'] ?? 'That row')), $rawValue, implode(', ', $choices)));
        }

        if (($field['mapConditions'] ?? false) === true) {
            try {
                $value = ConditionCodec::decodeAllStrictly($rawValue);
            } catch (InvalidArgumentException $error) {
                throw new InspectorRefusal($error->getMessage(), previous: $error);
            }
        }

        return match ((string) ($field['target'] ?? 'map')) {
            'map' => $this->applyMapField($map, (string) $field['field'], $value, (string) ($field['label'] ?? 'Field')),
            'map-data' => ($path = array_values((array) ($field['path'] ?? []))) === []
                ? null
                : $this->writeMapData($map, $path, $value === '' ? null : $value, (string) ($field['label'] ?? 'Field')),
            'map-encounters' => $this->applyMapEncounterValue($map, $field, $value),
            'map-bgm-variants' => $this->applyMapBgmVariantValue($map, $field, $value),
            'map-size' => $this->applyMapSize($map, (string) ($field['field'] ?? ''), (int) $value),
            'event' => $this->applyEventField($map, $field, $value),
            'event-type' => $this->applyEventType($map, (string) $field['marker'], $rawValue),
            'event-bounds' => $this->applyEventBounds($map, (string) $field['marker'], (string) $field['field'], (int) $value),
            default => null,
        };
    }

    /**
     * The values a row is picked from: its `choices`, or the `options` it
     * steps through. Null for a row that is typed or referenced.
     *
     * @param array<string, mixed> $field
     * @return list<string>|null
     */
    public static function findChoiceValues(array $field): ?array
    {
        return match (true) {
            is_array($field['choices'] ?? null) => array_values(array_map(static fn(array $choice): string => (string) $choice['value'], $field['choices'])),
            is_array($field['options'] ?? null) => array_values(array_map('strval', $field['options'])),
            default => null,
        };
    }

    /**
     * Sets a transfer event's destination: the map and the spawn point on
     * it, as one undo step.
     *
     * @param ProjectMap $destination The map it leads to.
     * @throws InspectorRefusal When the event is not a transfer or the spawn point is off the destination map.
     */
    public function setTransferDestination(ProjectMap $map, string $marker, ProjectMap $destination, int $x, int $y): ?Command
    {
        $current = $map->getEventDefinition($marker);

        if (! is_array($current) || ! is_array($current['data'] ?? null) || ! array_key_exists('destinationMap', $current['data'])) {
            throw new InspectorRefusal(sprintf('Event %s has no destination to set.', $marker));
        }

        if ($x < 0 || $y < 0 || $x >= $destination->getWidth() || $y >= $destination->getHeight()) {
            throw new InspectorRefusal(sprintf('(%d, %d) is outside %s, which is %dx%d.',
                $x, $y, $destination->mapId, $destination->getWidth(), $destination->getHeight()));
        }

        $next = $current;
        $next['data']['destinationMap'] = $destination->mapId;
        $next['data']['spawnPoint'] = [...(is_array($current['data']['spawnPoint'] ?? null) ? $current['data']['spawnPoint'] : []), 'x' => $x, 'y' => $y];

        if ($next === $current) {
            return null;
        }

        $map->setEventDefinition($marker, $next);

        return new GenericCommand(
            'Destination change',
            static fn() => $map->setEventDefinition($marker, $next),
            static fn() => $map->setEventDefinition($marker, $current),
        );
    }

    /**
     * Adds an entry to the list a row belongs to: a troop to the map's
     * encounters, a music variant, or an entry like its neighbour to an
     * event's list. Null when the row is in a list but nothing changed.
     *
     * @param array<string, mixed> $field
     * @throws InspectorRefusal When the row is in no list, or its list cannot take an entry.
     */
    public function addListEntry(ProjectMap $map, array $field): ?InspectorListEdit
    {
        if (isset($field['innPresentationList'])) { return $this->editInnPresentationList($map, $field, false); }
        if (($field['target'] ?? null) === 'map-encounters' && is_array($field['encounterList'] ?? null)) {
            $encounters = $this->requireEditableEncounters($map);
            $troops = $this->references->valuesFor('troops');
            $available = array_values(array_diff($troops, $encounters->troopNames()));

            if ($available === []) {
                throw new InspectorRefusal($troops === []
                    ? 'This project defines no troops to encounter.'
                    : 'Every troop this project defines is already in this table.');
            }

            $block = $encounters->withTroopAdded($available[0], (int) $field['encounterList']['index']);

            return new InspectorListEdit($this->writeMapData($map, [MapEncounters::KEY], $block, 'Encounter troop add'),
                sprintf('Added %s to the encounter table.', $available[0]));
        }

        if (($field['target'] ?? null) === 'map-bgm-variants' && is_array($field['bgmVariantList'] ?? null)) {
            $variants = $this->requireEditableBgmVariants($map);

            return new InspectorListEdit($this->writeMapData($map, [MapBgmVariants::KEY], $variants->withVariantAdded(), 'Music variant add'),
                sprintf('Added variant %d. Pick its track; it stays inactive until one is chosen.', $variants->count() + 1));
        }

        $list = self::findEventList($field) ?? throw new InspectorRefusal('Nothing here is a list to add to.');
        $marker = (string) $field['marker'];
        $entries = $map->getEventField($marker, $list['path']);
        $entries = is_array($entries) ? array_values($entries) : [];
        // The new entry is shaped like the one it follows -- the same keys,
        // their values cleared -- because an author adding a second shop
        // line means another line like the first, not an empty hole.
        $routePoints = ($field['movementRoutePoints'] ?? false) === true;
        $template = $routePoints ? $list['blank'] : ($entries[$list['index']] ?? ($list['blank'] ?? ($entries === [] ? '' : end($entries))));
        $position = min(count($entries), $list['index'] + 1);
        array_splice($entries, $position, 0, [$routePoints ? $template : self::createBlankLike($template)]);

        return new InspectorListEdit($this->writeEventList($map, $marker, $list['path'], $entries, 'Add list entry'),
            sprintf('Added %s %d.', self::describeListPath($list['path']), $position + 1));
    }

    /**
     * Removes the entry a row belongs to. Removing the last troop disables
     * encounters and the last music variant takes the variants key with it.
     * Null when the row names no entry to remove.
     *
     * @param array<string, mixed> $field
     * @throws InspectorRefusal When the row is in no list, or its list cannot lose an entry.
     */
    public function removeListEntry(ProjectMap $map, array $field): ?InspectorListEdit
    {
        if (isset($field['innPresentationList'])) { return $this->editInnPresentationList($map, $field, true); }
        if (($field['target'] ?? null) === 'map-encounters' && is_array($field['encounterList'] ?? null)) {
            $encounters = $this->requireEditableEncounters($map);
            $index = (int) $field['encounterList']['index'];
            $rows = $encounters->rows();

            if (! array_key_exists($index, $rows)) {
                throw new InspectorRefusal('No encounter troop to remove.');
            }

            $block = $encounters->withTroopRemovedAt($index);

            return new InspectorListEdit($this->writeMapData($map, [MapEncounters::KEY], $block, 'Encounter troop remove'), $block === null
                ? sprintf('Removed %s; this map no longer has random encounters.', $rows[$index]['name'])
                : sprintf('Removed %s from the encounter table.', $rows[$index]['name']));
        }

        if (($field['target'] ?? null) === 'map-bgm-variants' && is_array($field['bgmVariantList'] ?? null)) {
            $variants = $this->requireEditableBgmVariants($map);
            $index = (int) $field['bgmVariantList']['index'];

            if ($variants->count() === 0) {
                throw new InspectorRefusal('There is no music variant here to remove.');
            }

            return new InspectorListEdit($this->writeMapData($map, [MapBgmVariants::KEY], $variants->withVariantRemovedAt($index), 'Music variant remove'),
                sprintf('Removed variant %d.', $index + 1));
        }

        $list = self::findEventList($field) ?? throw new InspectorRefusal('Nothing here is a list entry to remove.');
        $marker = (string) $field['marker'];
        $entries = $map->getEventField($marker, $list['path']);
        $entries = is_array($entries) ? array_values($entries) : [];

        if (! array_key_exists($list['index'], $entries)) {
            return null;
        }

        array_splice($entries, $list['index'], 1);

        return new InspectorListEdit($this->writeEventList($map, $marker, $list['path'], $entries, 'Remove list entry'),
            sprintf('Removed %s %d.', self::describeListPath($list['path']), $list['index'] + 1));
    }

    /**
     * How many tile layers changing the map to this kind would clear: none
     * when the kind is unchanged or the map has no kind yet, since a map
     * without one keeps its tiles, which then draw from the new kind.
     */
    public function countTileLayersClearedBy(ProjectMap $map, string $tilesetId): int
    {
        $this->assertTilesetExists($tilesetId);
        $current = $map->getMapDataField(['tileset']);

        return $current === null || $current === $tilesetId ? 0 : count($map->getTileLayerSources());
    }

    /**
     * Sets the map's kind, clearing its tile layers and their settings when
     * asked, as one undo step. Null when it already was that kind.
     *
     * @throws InspectorRefusal When the project has no tileset with the id.
     * @throws Throwable When the map refuses the change; it is then exactly as it was.
     */
    public function changeMapKind(ProjectMap $map, string $tilesetId, bool $clearTiles): ?Command
    {
        $this->assertTilesetExists($tilesetId);

        if ($map->getMapDataField(['tileset']) === $tilesetId) {
            return null;
        }

        $hadKind = $map->hasMapDataField(['tileset']);
        $oldKind = $map->getMapDataField(['tileset']);
        $oldSources = $map->getTileLayerSources();
        $hadSettings = $map->hasMapDataField([MapGraphics::SETTINGS_KEY]);
        $oldSettings = $map->getMapDataField([MapGraphics::SETTINGS_KEY]);
        $apply = static function () use ($map, $tilesetId, $clearTiles): void {
            $map->setMapDataField(['tileset'], $tilesetId);
            if ($clearTiles) {
                $map->restoreTileLayerSources([]);
                $map->setMapDataField([MapGraphics::SETTINGS_KEY], null);
            }
        };
        $revert = static function () use ($map, $hadKind, $oldKind, $oldSources, $hadSettings, $oldSettings): void {
            $map->setMapDataField(['tileset'], $hadKind ? $oldKind : null);
            $map->restoreTileLayerSources($oldSources);
            $map->setMapDataField([MapGraphics::SETTINGS_KEY], $hadSettings ? $oldSettings : null);
        };

        try {
            $apply();
        } catch (Throwable $failure) {
            // A change refused after the kind was written is undone before
            // it is reported, so the kind never changes without its tiles.
            // Refused at the kind itself, nothing changed to undo.
            if ($map->getMapDataField(['tileset']) === $tilesetId) {
                $revert();
            }
            throw $failure;
        }

        return new GenericCommand('Kind change', $apply, $revert);
    }

    /** @throws InspectorRefusal When the project has no tileset with the id. */
    private function assertTilesetExists(string $tilesetId): void
    {
        if (! array_key_exists($tilesetId, $this->references->labelsFor('tilesets'))) {
            throw new InspectorRefusal(sprintf('There is no tileset %s in assets/%s.', $tilesetId, Tileset::DIRECTORY));
        }
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
        $variants = $this->requireEditableBgmVariants($map);
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
            // An inn's rest stage, as InnOffer reads it.
            $path === ['data', 'presentation'] => ['category' => 'stage_timelines', 'title' => 'Rest Presentation'],
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
        if ($path === ['cue', 'kind']) {
            return $this->applyEventCueKind($map, $marker, $value);
        }
        $held = $map->hasEventField($marker, $path);
        $oldValue = $map->getEventField($marker, $path);
        // Optional data cleared is removed, as it was before it was set.
        $removed = ($field['optional'] ?? false) === true && ($value === null || $value === '');
        if ($removed && ! $held) {
            return null;
        }
        $apply = $removed
            ? static fn() => $map->removeEventField($marker, $path)
            : static fn() => $map->setEventField($marker, $path, $value);
        $restore = $held
            ? static fn() => $map->setEventField($marker, $path, $oldValue)
            : static fn() => $map->removeEventField($marker, $path);
        $apply();

        return new GenericCommand(sprintf('%s edit', $field['label'] ?? 'Event field'), $apply, $restore);
    }

    /** Kind edits own the cue's optional parent so undo also restores its omission. */
    private function applyEventCueKind(ProjectMap $map, string $marker, mixed $value): ?Command
    {
        if (! is_string($value) || ($value !== '' && EventCueKind::tryFrom($value) === null)) {
            throw new InspectorRefusal('Choose story or route, or clear the cue kind.');
        }
        $held = $map->hasEventField($marker, ['cue']);
        $old = $map->getEventField($marker, ['cue']);
        if ($old !== null && ! is_array($old)) {
            throw new InspectorRefusal('The event cue must be an array before its kind can be edited.');
        }
        $next = $old ?? [];
        if ($value === '') {
            if (! array_key_exists('kind', $next)) { return null; }
            unset($next['kind']);
        } else {
            $next['kind'] = $value;
        }
        if ($old === $next) { return null; }
        $apply = static fn() => $map->setEventField($marker, ['cue'], $next);
        $restore = $held ? static fn() => $map->setEventField($marker, ['cue'], $old)
            : static fn() => $map->removeEventField($marker, ['cue']);
        $apply();

        return new GenericCommand('Cue kind edit', $apply, $restore);
    }

    /**
     * Moves or resizes an event through {@see EventAuthoring}, as the canvas
     * does. Its position moves every cell, keeping its shape; a marker
     * painted in another shape than a rectangle is reshaped by painting it.
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

        try {
            if (in_array($axis, ['x', 'y'], true)) {
                $delta = $value - $bounds[$axis];

                return EventAuthoring::moveEvent($map, $marker, $axis === 'x' ? $delta : 0, $axis === 'y' ? $delta : 0, 'Event bounds edit');
            }

            if (! $area->isRectangle) {
                throw new InspectorRefusal(sprintf('Marker %s is painted in its own shape; paint or erase its cells to reshape it.', $marker));
            }

            $bounds[$axis] = $value;

            return EventAuthoring::setEventBounds($map, $marker, $bounds['x'], $bounds['y'], $bounds['width'], $bounds['height']);
        } catch (EventRefusal $refusal) {
            throw new InspectorRefusal($refusal->getMessage(), previous: $refusal);
        }
    }

    /**
     * Makes an event the type an author names by its label.
     *
     * @throws InspectorRefusal When no type has the label or the event cannot take it.
     */
    private function applyEventType(ProjectMap $map, string $marker, string $label): ?Command
    {
        $type = EventTypeCatalog::findByLabel($label) ?? throw new InspectorRefusal(sprintf('There is no event type %s.', $label));

        try {
            return EventAuthoring::setEventType($map, $marker, $type);
        } catch (EventRefusal $refusal) {
            throw new InspectorRefusal($refusal->getMessage(), previous: $refusal);
        }
    }

    /**
     * Writes an event's list back as one undo step; null when it already
     * held exactly these entries.
     *
     * @param list<string> $path The list's path.
     * @param list<mixed> $entries The list as it should be.
     */
    private function writeEventList(ProjectMap $map, string $marker, array $path, array $entries, string $label): ?Command
    {
        $previous = $map->getEventField($marker, $path);
        $previous = is_array($previous) ? array_values($previous) : [];

        if ($previous === $entries) {
            return null;
        }

        $map->setEventField($marker, $path, $entries);

        return new GenericCommand(
            $label,
            static fn() => $map->setEventField($marker, $path, $entries),
            static fn() => $map->setEventField($marker, $path, $previous),
        );
    }

    /**
     * The event list a row belongs to, when it is one an author edits.
     *
     * @param array<string, mixed> $field
     * @return array{path: list<string>, index: int, blank?: mixed}|null
     */
    public static function findEventList(array $field): ?array
    {
        $list = $field['list'] ?? null;

        if (($field['target'] ?? null) !== 'event' || ! is_string($field['marker'] ?? null) || $field['marker'] === '' || ! is_array($list)) {
            return null;
        }

        return [...$list, 'path' => array_values(array_map('strval', (array) $list['path'])), 'index' => (int) $list['index']];
    }

    /**
     * An entry shaped like the given one with nothing filled in.
     *
     * @param mixed $template The entry to copy the shape of.
     * @return mixed The blank entry.
     */
    private static function createBlankLike(mixed $template): mixed
    {
        if (is_array($template)) {
            return array_map(self::createBlankLike(...), $template);
        }

        return match (true) {
            is_int($template) => 0,
            is_float($template) => 0.0,
            is_bool($template) => false,
            default => '',
        };
    }

    /**
     * Names a list in the singular, for what an author reads about it.
     *
     * @param array<int, string> $path The list's path.
     */
    private static function describeListPath(array $path): string
    {
        $leaf = (string) ($path[array_key_last($path)] ?? 'entry');
        $leaf = str_replace(['_', '-'], ' ', $leaf);

        return mb_strtolower(rtrim($leaf, 's'));
    }

    /**
     * Reads a picked arena: one the battle presentation declares, or none.
     *
     * @throws InspectorRefusal When it names an arena the presentation does not declare.
     */
    private function requireArena(string $value, string $none): ?string
    {
        $value = trim($value);

        if ($value === '' || $value === $none) {
            return null;
        }

        if (! in_array($value, $this->references->valuesFor('battle_arenas'), true)) {
            throw new InspectorRefusal(sprintf('"%s" is not an arena the battle presentation declares.', $value));
        }

        return $value;
    }

    /** @throws InspectorRefusal When the map's encounters cannot be edited here. */
    private function requireEditableEncounters(ProjectMap $map): MapEncounters
    {
        $encounters = MapEncounters::fromMap($map);

        if (! $encounters->isSupported()) {
            throw new InspectorRefusal(sprintf('Encounters are read-only here: %s.', $encounters->unsupportedReason()));
        }

        return $encounters;
    }

    /** @throws InspectorRefusal When the map's music variants cannot be edited here. */
    private function requireEditableBgmVariants(ProjectMap $map): MapBgmVariants
    {
        $variants = MapBgmVariants::fromMap($map);

        if (! $variants->isSupported()) {
            throw new InspectorRefusal(sprintf('Music variants are read-only here: %s.', $variants->unsupportedReason()));
        }

        return $variants;
    }

    /**
     * Applies one encounter row's edit through the encounters model.
     *
     * @param array<string, mixed> $field
     * @throws InspectorRefusal When the map's encounters cannot be edited here.
     */
    private function applyMapEncounterValue(ProjectMap $map, array $field, mixed $value): ?Command
    {
        $encounters = $this->requireEditableEncounters($map);
        $index = (int) ($field['index'] ?? 0);
        $block = match ((string) ($field['field'] ?? '')) {
            'rate' => $encounters->withRate((int) $value),
            'tiles' => $encounters->withTiles((string) $value),
            'weight' => $encounters->withWeightAt($index, (int) $value),
            'troop' => $encounters->withTroopAt($index, (string) $value),
            'arena' => $encounters->withArenaAt($index, $this->requireArena((string) $value, self::ENCOUNTER_ARENA_NONE)),
            'mapArena' => $encounters->withMapArena($this->requireArena((string) $value, self::MAP_ARENA_NONE)),
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

            if ($this->graphical) {
                $fields[] = [
                    'label' => '    Arena',
                    'value' => $this->describeArena($row['arena'], self::ENCOUNTER_ARENA_NONE),
                    'reference' => 'battle_arenas',
                    'noneLabel' => self::ENCOUNTER_ARENA_NONE,
                    'target' => 'map-encounters',
                    'field' => 'arena',
                    'index' => $index,
                    'encounterList' => ['index' => $index],
                ];
            }
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

        if ($this->graphical) {
            $fields[] = [
                'label' => '  Arena',
                'value' => $this->describeArena($encounters->mapArena(), self::MAP_ARENA_NONE),
                'reference' => 'battle_arenas',
                'noneLabel' => self::MAP_ARENA_NONE,
                'target' => 'map-encounters',
                'field' => 'mapArena',
            ];
        }

        return $fields;
    }

    /**
     * How an arena reads on a row: its name, what applies when none is set,
     * or a key the battle presentation does not declare, kept and said so.
     */
    private function describeArena(?string $arena, string $none): string
    {
        if ($arena === null) {
            return $none;
        }

        $name = $this->references->labelsFor('battle_arenas')[$arena] ?? null;

        return $name === null ? $arena . ' · not an arena the battle presentation declares' : sprintf('%s (%s)', $name, $arena);
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
                'encoded' => $issue === null ? ConditionCodec::encodeAll($conditions) : null,
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
            // The type's optional data is offered as it reads when absent,
            // and stays out of the stored event until an author sets it.
            $optional = EventTypeCatalog::findByClass(is_string($definition['class'] ?? null) ? $definition['class'] : null)?->optionalData ?? [];
            $hasPresentation = array_key_exists('presentation', $optional) || array_key_exists('presentation', $eventData);
            $presentation = $eventData['presentation'] ?? null;
            if ($hasPresentation) { unset($eventData['presentation'], $optional['presentation']); }
            $fields = array_map(static function (array $field) use ($optional): array {
                $path = array_values((array) ($field['path'] ?? []));
                if (count($path) === 2 && array_key_exists((string) $path[1], $optional)) {
                    $field['optional'] = true;
                }

                return $field;
            }, $this->decorateEventInspectorFields(
                $marker,
                $this->flattenInspectorFields([...$eventData, ...array_diff_key($optional, $eventData)], ['data']),
            ));
            if ($hasPresentation) { $fields = [...$fields, ...$this->buildInnPresentationFields($marker, $presentation)]; }
        }

        $rootFields = array_diff_key($definition, ['class' => true, 'data' => true]);
        // Cue is a generic trigger capability, including for definitions
        // created before the field existed. Supplying an empty editor-only
        // default exposes the opt-in without changing the stored event until
        // the author actually edits it.
        $rootFields['cue'] ??= ['symbol' => '', 'color' => 'bright-yellow'];
        $cueKindFields = [];
        if (is_array($rootFields['cue'])) {
            $hasKind = array_key_exists('kind', $rootFields['cue']);
            $kind = $hasKind ? $rootFields['cue']['kind'] : '';
            $validKind = ! $hasKind || (is_string($kind) && EventCueKind::tryFrom($kind) !== null);
            // A malformed kind remains a single repairable property, not a
            // nested array of freely editable fields. Only this display copy changes.
            unset($rootFields['cue']['kind']);
            $cueKindFields[] = [
                'label' => 'Cue Kind',
                'value' => $validKind ? $kind : '(invalid kind)',
                'selectedValue' => $validKind ? $kind : '(invalid kind)',
                'optionLabels' => array_column(self::EVENT_CUE_KIND_CHOICES, 'label', 'value'),
                'path' => ['cue', 'kind'],
                'optional' => true,
            ];
        }

        return [
            ...$fields,
            ...$this->decorateEventInspectorFields(
                $marker,
                [...$this->flattenInspectorFields($rootFields, []), ...$cueKindFields],
            ),
        ];
    }

    /** Inline routes use the same schema/coercion as command records; the map remains their owner. */
    private function projectMovementRoute(array $route, ?array $ownerCommands = null): ProjectRecordDatabase
    {
        $schema = RecordSchemaCatalog::forKey('common_events')
            ?? throw new \LogicException('The event command schema is unavailable.');
        $records = ProjectRecordDatabase::overOwnedList($schema, '', [['commands' => [$route]]], static function (array $entries): void {}, $this->graphical);
        $records->useAuthoringReferences($ownerCommands === null ? $this->references
            : $this->references->createMovementContext($ownerCommands));
        return $records;
    }

    /** @return list<array<string, mixed>> */
    private function buildMovementRouteFields(array $route, array $path, ?array $parentList): array
    {
        $fields = [];
        $points = MovementRouteFields::getPointList($route);
        foreach ($this->projectMovementRoute($route)->getSettingsFields(0) as $row) {
            if (! isset($row['dataPath']) || ($row['sourceKey'] ?? '') === 'type') { continue; }
            $relative = array_slice($row['dataPath'], 2);
            $row['path'] = [...$path, ...array_map('strval', $relative)];
            $row['movementRouteField'] = $row['field'];
            $row['movementRoutePath'] = $path;
            unset($row['mapPlacement']);
            if (count($relative) > 1 && $points !== null) {
                $row['movementRoutePoints'] = true;
                $row['list'] = ['path' => [...$path, $points->key], 'index' => (int) $relative[1], 'blank' => $points->blank];
            } elseif ($parentList !== null) { $row['list'] = $parentList; }
            $fields[] = $row;
        }
        if ($points !== null) {
            $entries = $points->readEntries($route[$points->key] ?? []);
            $fields[] = ['label' => ucfirst($points->key), 'value' => strval(count($entries)), 'editable' => false,
                'path' => [...$path, $points->key], 'movementRoutePath' => $path, 'movementRoutePoints' => true,
                'list' => ['path' => [...$path, $points->key], 'index' => count($entries) - 1, 'blank' => $points->blank]];
        }
        return $fields;
    }

    private function applyMovementRouteField(ProjectMap $map, array $field, string $raw): ?Command
    {
        $marker = (string) $field['marker'];
        $path = (array) $field['movementRoutePath'];
        $old = $map->getEventField($marker, $path);
        if (! is_array($old) || ($old['type'] ?? '') !== 'move_route') {
            throw new InspectorRefusal('That movement route is no longer present.');
        }
        try {
            $commands = MovementRouteFields::getOwnerCommands($map->getEventDefinition($marker) ?? [], $path);
            $records = $this->projectMovementRoute($old, $commands);
            $records->setField(0, (string) $field['movementRouteField'], $raw);
            $next = $records->getRecordByIndex(0)?->getSubList('commands')[0] ?? $old;
        } catch (\Ichiloto\Editor\Database\RecordRefusal|InvalidArgumentException $error) {
            throw new InspectorRefusal($error->getMessage(), previous: $error);
        }
        if ($old === $next) { return null; }
        $apply = static fn() => $map->setEventField($marker, $path, $next);
        $apply();
        return new GenericCommand('Movement route edit', $apply, static fn() => $map->setEventField($marker, $path, $old));
    }

    /** The same actor/stage pickers and binding lists that Configuration and commands use. */
    private function buildInnPresentationFields(string $marker, mixed $value, array $presentationPath = ['data', 'presentation']): array
    {
        $fields = [];
        foreach (InnPresentationFields::getFields($value, 'presentation', 'Rest Presentation', $this->graphical) as $field) {
            $selected = $field->key === 'presentation' ? $value : (is_array($value) ? ($value['treatment'] ?? '') : '');
            $path = $field->key === 'presentation' ? $presentationPath : [...$presentationPath, 'treatment'];
            $fields[] = [...$this->describeInnField($marker, $field, $selected, $path), 'innPresentationPath' => $presentationPath];
        }
        $list = $this->graphical ? InnPresentationFields::getBindingList($value, 'presentation') : null;
        if ($list === null) { return $fields; }
        $entries = $list->readEntries($value[substr($list->key, strlen('presentation.'))] ?? []);
        $context = ['key' => $list->key, 'index' => -1];
        $listPath = [...$presentationPath, substr($list->key, strlen('presentation.'))];
        $fields[] = ['label' => $list->heading, 'value' => (string) count($entries), 'editable' => false,
            'target' => 'event', 'marker' => $marker, 'path' => $listPath, 'innPresentationPath' => $presentationPath,
            'innPresentationList' => $context, 'list' => ['path' => $listPath, 'index' => -1]];
        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) { continue; }
            foreach ($list->fieldsFor($entry) as $field) {
                $row = $this->describeInnField($marker, $field, $entry[$field->key] ?? '',
                    [...$listPath, (string) $index, $field->key]);
                $row['innPresentationPath'] = $presentationPath;
                $row['innPresentationList'] = [...$context, 'index' => $index];
                $row['innEntryField'] = $field->key;
                $row['list'] = ['path' => $listPath, 'index' => $index];
                $fields[] = $row;
            }
        }
        return $fields;
    }

    private function describeInnField(string $marker, RecordField $field, mixed $value, array $path): array
    {
        $display = $field->codec === RecordFieldCodec::CSV_LIST && is_array($value) ? implode(', ', $value) : ProjectRecord::stringify($value);
        $row = ['label' => $field->label, 'value' => $display, 'selectedValue' => $display, 'path' => $path,
            'marker' => $marker, 'target' => 'event', 'innPresentation' => true, 'optional' => $field->allowsNone];
        if ($field->isReadOnly) { $row['editable'] = false; }
        elseif ($field->reference !== null) {
            $row['reference'] = $field->reference;
            $row['multi'] = $field->codec === RecordFieldCodec::CSV_LIST;
        } else { $row['options'] = $field->options; }
        return $row;
    }

    private function applyInnPresentationField(ProjectMap $map, array $field, string $raw): ?Command
    {
        if (($field['editable'] ?? true) === false) { throw new InspectorRefusal('Graphical rest bindings are preserved read-only in Terminal.'); }
        $marker = (string) $field['marker'];
        $presentationPath = $field['innPresentationPath'];
        $old = $map->getEventField($marker, $presentationPath);
        $record = new ProjectRecord(['presentation' => $old]);
        if (isset($field['innEntryField'])) {
            if (! $this->graphical) { throw new InspectorRefusal('Graphical rest bindings are not authored in Terminal.'); }
            $list = InnPresentationFields::getBindingList($old, 'presentation');
            $index = (int) ($field['innPresentationList']['index'] ?? -1);
            $entries = $list?->readEntries(is_array($old) ? ($old[substr($list->key, strlen('presentation.'))] ?? []) : []) ?? [];
            if ($list === null || ! is_array($entries[$index] ?? null)) { throw new InspectorRefusal('That binding is no longer present.'); }
            $key = (string) $field['innEntryField'];
            $selected = ($field['multi'] ?? false) === true ? array_values(array_filter(array_map(trim(...), explode(',', $raw)), static fn(string $id): bool => $id !== '')) : trim($raw);
            if ($key === $list->keyField && array_any($entries, static fn(array $entry, int $at): bool => $at !== $index && ($entry[$key] ?? null) === $selected)) {
                throw new InspectorRefusal('That actor already has a leader binding.');
            }
            $entries[$index][$key] = $selected;
            $record->setEntries($list, $entries);
        } else {
            $path = array_slice((array) $field['path'], count($presentationPath));
            if ($path !== [] && ! $this->graphical) { throw new InspectorRefusal('Graphical rest bindings are not authored in Terminal.'); }
            $record->set(implode('.', ['presentation', ...$path]), $raw === '' ? null : $raw);
        }
        return $this->writeInnPresentation($map, $marker, $record->get('presentation'), 'Rest presentation edit', $presentationPath);
    }

    private function editInnPresentationList(ProjectMap $map, array $field, bool $remove): ?InspectorListEdit
    {
        if (! $this->graphical) { throw new InspectorRefusal('Graphical rest bindings are not authored in Terminal.'); }
        $marker = (string) $field['marker'];
        $presentationPath = $field['innPresentationPath'];
        $value = $map->getEventField($marker, $presentationPath);
        $list = InnPresentationFields::getBindingList($value, 'presentation');
        if ($list === null || $list->key !== ($field['innPresentationList']['key'] ?? null)) { throw new InspectorRefusal('That binding list is no longer present.'); }
        $record = new ProjectRecord(['presentation' => $value]);
        $entries = $record->getEntries($list);
        $at = (int) $field['innPresentationList']['index'];
        if ($remove) {
            if (! array_key_exists($at, $entries)) { return null; }
            array_splice($entries, $at, 1);
        } else {
            if ($list->keyField !== null && array_any($entries, static fn(array $entry): bool => ($entry[$list->keyField] ?? '') === '')) {
                throw new InspectorRefusal('Finish choosing the unbound leader before adding another binding.');
            }
            array_splice($entries, min(count($entries), $at + 1), 0, [$list->blank]);
        }
        $record->setEntries($list, $entries);
        return new InspectorListEdit($this->writeInnPresentation($map, $marker, $record->get('presentation'), $remove ? 'Rest binding remove' : 'Rest binding add', $presentationPath),
            $remove ? 'Removed rest binding.' : 'Added rest binding; choose its guests and stage before saving.');
    }

    private function writeInnPresentation(ProjectMap $map, string $marker, mixed $value, string $label, array $presentationPath): ?Command
    {
        try { InnPresentationFields::assertValid($value, $this->references, complete: false); }
        catch (InvalidArgumentException $error) { throw new InspectorRefusal($error->getMessage(), previous: $error); }
        $old = $map->getEventField($marker, $presentationPath);
        if ($old === $value) { return null; }
        $held = $map->hasEventField($marker, $presentationPath);
        $apply = $value === null ? static fn() => $map->removeEventField($marker, $presentationPath)
            : static fn() => $map->setEventField($marker, $presentationPath, $value);
        $undo = $held ? static fn() => $map->setEventField($marker, $presentationPath, $old)
            : static fn() => $map->removeEventField($marker, $presentationPath);
        $apply();
        return new GenericCommand($label, $apply, $undo);
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

            $choices = match ($path) {
                ['cue', 'kind'] => self::EVENT_CUE_KIND_CHOICES,
                ['data', 'chestType'] => self::CHEST_TYPE_CHOICES,
                ['data', 'lootType'] => self::LOOT_TYPE_CHOICES,
                default => null,
            };

            if ($choices !== null) {
                // Picked or stepped through, never spelled.
                $field['choices'] = $choices;
                $field['options'] = array_column($choices, 'value');
                unset($field['control']);
            }

            if ($path === ['data', 'destinationMap']) {
                // Set with its spawn point, never alone ({@see setTransferDestination()}).
                $field['destination'] = true;
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
     * A data key as an author reads it: words split at underscores, hyphens
     * and camel case, each capitalised (`spawnPoint` reads Spawn Point).
     */
    private static function formatFieldLabel(string $part): string
    {
        return ucwords(str_replace(['_', '-'], ' ', (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $part)));
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
        $definition = is_string($data['type'] ?? null) ? (ScriptCommandRegistry::getCatalog()->definitions[$data['type']] ?? null) : null;
        foreach ($definition?->fields ?? [] as $field) {
            if ($field->reference !== ScriptCommandReference::STAGE_TIMELINE) { continue; }
            $segments = explode('.', $field->key);
            $value = new ProjectRecord($data)->get($field->key);
            $fields = [...$fields, ...$this->buildInnPresentationFields('', $value, [...$path, ...$segments])];
            // This is only the display copy; the map owner retains every authored key.
            $parent = &$data;
            $present = true;
            foreach (array_slice($segments, 0, -1) as $segment) {
                if (! is_array($parent[$segment] ?? null)) { $present = false; break; }
                $parent = &$parent[$segment];
            }
            if ($present) { unset($parent[end($segments)]); }
            unset($parent);
        }

        if (($data['type'] ?? '') === 'move_route') {
            $fields = [...$fields, ...$this->buildMovementRouteFields($data, $path, $list)];
            // Only the display projection is removed; unrecognised authored keys stay visible and intact.
            foreach (MovementRouteFields::getFields($data) as $field) { unset($data[$field->key]); }
            unset($data['steps'], $data['waypoints'], $data['retrace']);
        }

        foreach ($data as $key => $value) {
            $segment = (string) $key;
            $nextPath = [...$path, $segment];

            if (is_array($value)) {
                // A list of entries -- a shop's stock, an event's dialogue --
                // is something an author adds to and removes from, so every
                // field inside one remembers which list it belongs to.
                if ($this->isInspectorListValue($nextPath, $value)) {
                    $label = implode(' ', array_map(
                        self::formatFieldLabel(...),
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
                        self::formatFieldLabel(...),
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
                        'axis' => 'x',
                    ];
                    $fields[] = [
                        'label' => '  Y',
                        'value' => (string) $value['y'],
                        'control' => new InputControl(
                            is_int($value['y']) ? InputControlType::INTEGER : InputControlType::TEXT,
                            (string) $value['y'],
                        ),
                        'path' => [...$nextPath, 'y'],
                        'axis' => 'y',
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
                    : self::formatFieldLabel($part),
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
