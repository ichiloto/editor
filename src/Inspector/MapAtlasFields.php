<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Inspector;

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Field\MapAtlas;
use Ichiloto\Editor\Field\MapLandmarks;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Field\MapAtlasDefinition;
use Ichiloto\Engine\Field\MapLandmarkCatalog;
use InvalidArgumentException;
use Throwable;

/**
 * The inspector rows that place a map in the atlas, the same in the Terminal and a graphical editor: its scope
 * (local, or the global overworld geography), how the Terminal draws it (places or a floorplan), and for a local
 * map its entry on the parent map, chosen from the landmarks other maps declare. The global map the chain reaches
 * is shown as the Engine resolves it.
 */
final class MapAtlasFields
{
    public const string TARGET = 'map-atlas';

    private function __construct() {}

    /** @return list<array<string, mixed>> */
    public static function describeMapFields(ProjectMap $map, ReferenceCatalog $references): array
    {
        try {
            $block = MapAtlas::readBlock($map);
        } catch (InvalidArgumentException $error) {
            return [['label' => 'Atlas', 'value' => '', 'editable' => false],
                ['label' => '  ! Read-only', 'value' => $error->getMessage(), 'editable' => false]];
        }
        $scope = is_string($block['scope'] ?? null) ? $block['scope'] : MapAtlas::LOCAL;
        $choices = [['label' => 'Local', 'value' => MapAtlas::LOCAL, 'description' => 'A place inside the world: a town, a house, a dungeon.'],
            ['label' => 'Global', 'value' => MapAtlas::GLOBAL, 'description' => 'The overworld geography itself; it has no parent.']];
        $fields = [
            ['label' => 'Atlas', 'value' => '', 'editable' => false],
            // A short enum: picked or stepped through.
            ['label' => '  Scope', 'value' => $scope, 'editable' => true, 'choices' => $choices,
                'options' => array_column($choices, 'value'), 'target' => self::TARGET, 'field' => 'scope'],
        ];
        try {
            $view = MapAtlas::readTerminalView($block);
        } catch (InvalidArgumentException $error) {
            $view = null;
            $fields[] = ['label' => '  ! Problem', 'value' => $error->getMessage(), 'editable' => false];
        }
        if ($view !== null) {
            $explained = ['places' => 'Simplified terrain and buildings from the map\'s Terrain classifications, with a square for each location and a chevron for each route.',
                'floorplan' => 'Rooms and corridors drawn as simple outlines, for interiors.'];
            $known = array_map(static fn(string $value): array => ['label' => ucfirst($value), 'value' => $value,
                'description' => $explained[$value] ?? ''], MapAtlasDefinition::TERMINAL_VIEWS);
            // A short enum: picked or stepped through. What the Terminal draws; graphical maps are unaffected.
            $fields[] = ['label' => '  Terminal View', 'value' => $view, 'editable' => true, 'choices' => $known,
                'options' => array_column($known, 'value'), 'target' => self::TARGET, 'field' => 'terminalView'];
        }
        if ($scope !== MapAtlas::GLOBAL) {
            $parent = is_array($block['parent'] ?? null) ? $block['parent'] : null;
            $current = $parent === null ? '' : MapAtlas::encodeParent((string) ($parent['map'] ?? ''), (string) ($parent['landmark'] ?? ''));
            $fields[] = ['label' => '  Parent Entry', 'value' => $current, 'editable' => true, 'target' => self::TARGET, 'field' => 'parent',
                'choices' => self::describeParentChoices($map, $references, $current)];
        }
        $fields[] = ['label' => '  Global Map', 'value' => self::describeGlobal($map, $block, $references), 'editable' => false];
        return $fields;
    }

    /**
     * The atlas block an edit to one of these rows leaves, or null when it changes nothing.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>|null
     */
    public static function applyValue(ProjectMap $map, array $field, mixed $value): ?array
    {
        $block = MapAtlas::readBlock($map);
        $next = match ((string) ($field['field'] ?? '')) {
            'scope' => MapAtlas::setScope($block, (string) $value),
            'terminalView' => MapAtlas::setTerminalView($block, (string) $value),
            'parent' => MapAtlas::setParent($block, ...(MapAtlas::decodeParent((string) $value) ?? [null, null])),
            default => $block,
        };
        return $next === $block ? null : $next;
    }

    /**
     * Every declared landmark on every other map, those whose transfer leads to this map first: an entrance here is
     * usually the parent entry. A landmark the map names that no longer exists stays listed, said so.
     *
     * @return list<array{label: string, value: string, description: string}>
     */
    private static function describeParentChoices(ProjectMap $map, ReferenceCatalog $references, string $current): array
    {
        $leading = $others = [];
        foreach ($references->listMaps() as $other) {
            if ($other->mapId === $map->mapId) { continue; }
            try {
                $entries = MapLandmarks::readMap($other)->entries;
            } catch (InvalidArgumentException) {
                continue;
            }
            foreach ($entries as $entry) {
                $id = $entry['id'] ?? null;
                if (!is_string($id) || preg_match(MapLandmarkCatalog::ID_PATTERN, $id) !== 1) { continue; }
                $marker = $entry['anchor']['event'] ?? null;
                $leads = $marker !== null
                    && (($other->getEventDefinition((string) $marker)['data'] ?? [])['destinationMap'] ?? null) === $map->mapId;
                $choice = ['label' => sprintf('%s · %s', $other->getDisplayName(), is_string($entry['name']['default'] ?? null) ? $entry['name']['default'] : $id),
                    'value' => MapAtlas::encodeParent($other->mapId, $id),
                    'description' => sprintf('%s, landmark %s%s', $other->mapId, $id, $leads ? '; its door leads here' : '')];
                $leads ? $leading[] = $choice : $others[] = $choice;
            }
        }
        $choices = [['label' => '(none)', 'value' => '', 'description' => 'No map is above this one.'], ...$leading, ...$others];
        if ($current !== '' && !in_array($current, array_column($choices, 'value'), true)) {
            $choices[] = ['label' => $current . ' · no longer declared', 'value' => $current, 'description' => 'Choose an existing entry or none.'];
        }
        return $choices;
    }

    /** Where the chain reaches the global map, as the Engine resolves it, or why it cannot. */
    private static function describeGlobal(ProjectMap $map, array $block, ReferenceCatalog $references): string
    {
        try {
            $global = MapAtlas::resolveGlobal($map, $block, $references->findMap(...), $references->getMapCategoryCatalog());
        } catch (Throwable $error) {
            return '! ' . $error->getMessage();
        }
        return match (true) {
            $global === null => '(none above · set a parent entry)',
            $global['landmark'] === null => '(this map)',
            default => sprintf('%s at %s', $global['map'], $global['landmark']),
        };
    }
}
