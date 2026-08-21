<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Finding what names an NPC's stable id.
 *
 * A `move_route` whose subject is `npc` names it by `npcId`; a script's
 * diagnostics name it too. Knowing what points at an id is what lets the
 * editor refuse to delete an NPC something still expects, and say what.
 *
 * NPC ids are map-local, so a reference is only a reference from that map's
 * own context: its map-authored event scripts, its NPCs' inline scripts and
 * variant scripts, and the reusable event scripts those trigger.
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

            if ($this->commandsName((array) ($data['script'] ?? []), $npcId)) {
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

        return array_values(array_unique($found));
    }

    /**
     * Determines whether a command list routes the NPC, however deeply.
     *
     * @param array<int, mixed> $commands The commands.
     * @param string $npcId The id.
     * @return bool True when one does.
     */
    private function commandsName(array $commands, string $npcId): bool
    {
        foreach ($commands as $command) {
            if (! is_array($command)) {
                continue;
            }

            if (
                strval($command['type'] ?? '') === 'move_route'
                && strtolower(trim(strval($command['subject'] ?? 'player'))) === 'npc'
                && trim(strval($command['npcId'] ?? '')) === $npcId
            ) {
                return true;
            }

            foreach ((array) ($command['options'] ?? []) as $option) {
                if (is_array($option) && $this->commandsName((array) ($option['then'] ?? []), $npcId)) {
                    return true;
                }
            }

            foreach (['then', 'else'] as $arm) {
                if ($this->commandsName((array) ($command[$arm] ?? []), $npcId)) {
                    return true;
                }
            }
        }

        return false;
    }
}
