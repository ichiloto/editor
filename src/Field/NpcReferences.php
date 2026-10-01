<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Throwable;

/**
 * Finding what names an NPC's stable id.
 *
 * A `move_route` whose subject is `npc` names it by `npcId`, a camera or
 * cinematic subject of kind `npc` by `npcId` or `id`, and a dialogue event by
 * its data's `npcId`. Knowing what points at an id is what lets the editor
 * refuse to delete an NPC something still expects, and keep the id of one
 * something already names when it is renamed.
 *
 * NPC ids are map-local, so a reference is only a reference from that map's
 * own context: its map-authored events and their scripts, its NPCs' inline
 * scripts and variant scripts, the reusable event scripts those trigger, and
 * the cinematics that start on the map.
 *
 * @package Ichiloto\Editor\Field
 */
final class NpcReferences
{
    public function __construct(private readonly ProjectWorkspace $workspace)
    {
    }

    /**
     * Returns what names the id, in words.
     *
     * @param ProjectMap $map The map the NPC lives on.
     * @param string $npcId The id.
     * @return string[] Where each reference lives.
     */
    public function describe(ProjectMap $map, string $npcId): array
    {
        $npcId = trim($npcId);

        if ($npcId === '') {
            return [];
        }

        $found = [];
        $usedScripts = [];

        // Map-authored events: inline scripts, and the reusable scripts they name.
        foreach ($map->getEventDefinitions() as $marker => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $data = (array) ($definition['data'] ?? []);

            if (trim(strval($data['npcId'] ?? '')) === $npcId || $this->commandsName((array) ($data['script'] ?? []), $npcId)) {
                $found[] = sprintf('%s event %s', $map->mapId, strval($marker));
            }

            $scriptId = trim(strval($data['scriptId'] ?? ''));

            if ($scriptId !== '') {
                $usedScripts[$scriptId] = sprintf('%s event %s', $map->mapId, strval($marker));
            }
        }

        // The map's other NPCs: inline scripts and dialogue-variant scripts.
        foreach ($map->getNpcs()->all() as $other) {
            $label = sprintf('NPC %s', $other->getName());

            if ($this->commandsName($other->getScript(), $npcId)) {
                $found[] = $label . ' script';
            }

            foreach ($other->getDialogueAsVariants() as $index => $variant) {
                if ($this->commandsName((array) ($variant['script'] ?? []), $npcId)) {
                    $found[] = sprintf('%s dialogue variant %d', $label, $index + 1);
                }
            }
        }

        // Reusable scripts this map triggers.
        $database = $this->workspace->getRecordDatabase('common_events');

        if ($database instanceof ProjectRecordDatabase) {
            foreach ($database->getRecords() as $record) {
                $script = (array) $record->toArray();
                $scriptId = strval($script['__scriptId'] ?? '');

                if (! isset($usedScripts[$scriptId])) {
                    continue;
                }

                if ($this->commandsName((array) ($script['commands'] ?? []), $npcId)) {
                    $found[] = sprintf('event script %s (via %s)', $scriptId, $usedScripts[$scriptId]);
                }
            }
        }

        // Cinematics that start on this map: their cast and commands.
        foreach ($this->workspace->cutscenes?->assets(CutsceneType::CINEMATIC) ?? [] as $asset) {
            try {
                $data = $asset->data();
                $commands = $asset->commands();
            } catch (Throwable) {
                continue;
            }

            if (trim(strval($data['startMap'] ?? '')) !== $map->mapId) {
                continue;
            }

            if ($this->commandsName([...(array) ($data['cast'] ?? []), ...(array) ($data['finalizer'] ?? []), ...$commands], $npcId)) {
                $found[] = sprintf('cinematic %s', $asset->id);
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Determines whether commands name the NPC, however deeply nested: a
     * `move_route` for subject `npc`, or any subject, target or cast entry
     * of kind `npc`, by `npcId` or `id`.
     *
     * @param array<int|string, mixed> $commands The commands.
     * @param string $npcId The id.
     * @return bool True when one does.
     */
    private function commandsName(array $commands, string $npcId): bool
    {
        foreach ($commands as $node) {
            if (! is_array($node)) {
                continue;
            }

            $namesNpc = strtolower(trim(strval($node['kind'] ?? $node['subject'] ?? ''))) === 'npc';

            if ($namesNpc && trim(strval($node['npcId'] ?? $node['id'] ?? '')) === $npcId) {
                return true;
            }

            if ($this->commandsName($node, $npcId)) {
                return true;
            }
        }

        return false;
    }
}
