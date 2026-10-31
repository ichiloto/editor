<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Inspector;

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Field\MapSavePolicy;

/** The Engine's authored map save rule, selected in either editor interface. */
final class MapSavePolicyField
{
    private function __construct() {}

    /** @return array<string, mixed> */
    public static function describeField(ProjectMap $map): array
    {
        $value = $map->hasMapDataField(['savePolicy'])
            ? $map->getMapDataField(['savePolicy']) : MapSavePolicy::SAVE_POINTS->value;
        $policy = is_string($value) ? MapSavePolicy::tryFrom($value) : null;

        return [
            'label' => 'Saving',
            'value' => $policy?->value ?? (is_string($value) ? $value : 'Invalid save policy'),
            'choices' => array_map(static fn(MapSavePolicy $choice): array => [
                'value' => $choice->value,
                'label' => self::getLabel($choice),
                'description' => match ($choice) {
                    MapSavePolicy::SAVE_POINTS => 'Save at authored save points.',
                    MapSavePolicy::ANYWHERE => 'Save anywhere on this map.',
                },
            ], MapSavePolicy::cases()),
            'target' => 'map-data',
            'path' => ['savePolicy'],
        ];
    }

    private static function getLabel(MapSavePolicy $policy): string
    {
        return match ($policy) {
            MapSavePolicy::SAVE_POINTS => 'Save points',
            MapSavePolicy::ANYWHERE => 'Anywhere',
        };
    }
}
