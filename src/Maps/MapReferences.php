<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Finding what sends the player to a map by its id: the game's starting
 * position, transfer events on every map (unsaved edits included), and
 * cinematic `transfer` commands at any depth. Knowing them lets an editor
 * warn before deleting a map something still expects; the project validator
 * reports any that remain dangling.
 */
final readonly class MapReferences
{
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
            foreach ($map->getEventDefinitions() as $marker => $definition) {
                if (is_array($definition) && (($definition['data'] ?? [])['destinationMap'] ?? null) === $mapId) {
                    $found[] = sprintf('event %s on %s', $marker, $map->mapId);
                }
            }
        }
        foreach (CutsceneType::cases() as $type) {
            foreach ($this->workspace->cutscenes?->assets($type) ?? [] as $asset) {
                if (self::hasTransferTo($asset->commands(), $mapId)) {
                    $found[] = sprintf('%s %s', $type->noun(), $asset->id);
                }
            }
        }

        return $found;
    }

    /** Whether a command tree, branches included, transfers to the map. */
    private static function hasTransferTo(array $commands, string $mapId): bool
    {
        if (($commands['type'] ?? null) === 'transfer' && ($commands['map'] ?? null) === $mapId) {
            return true;
        }

        return array_any($commands, static fn(mixed $value): bool => is_array($value) && self::hasTransferTo($value, $mapId));
    }
}
