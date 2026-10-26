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
 * cinematic subject of kind `npc` by `npcId` or `id`, a dialogue event by
 * its data's `npcId`, and any map event by its `speakers`. Knowing what points at an id is what lets the editor
 * refuse to delete an NPC something still expects, and keep the id of one
 * something already names when it is renamed.
 *
 * NPC ids are map-local, so a reference is only a reference from that map's
 * own context: its map-authored events and their scripts, its NPCs' inline
 * scripts and variant scripts, the cinematics that start on the map, and the
 * reusable event scripts any of those reach, by an event's `scriptId` or a
 * `common_event` command, followed from script to script as the interpreter
 * runs them.
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
            $label = sprintf('%s event %s', $map->mapId, strval($marker));
            $script = (array) ($data['script'] ?? []);

            if (self::readText($data['npcId'] ?? null) === $npcId || $this->commandsName($script, $npcId)) {
                $found[] = $label;
            } elseif (in_array($npcId, array_map(self::readText(...), (array) ($definition['speakers'] ?? [])), true)) {
                $found[] = $label . ' (speaker)';
            }

            $scriptId = self::readText($data['scriptId'] ?? null);

            if ($scriptId !== '') {
                $usedScripts[$scriptId] ??= $label;
            }

            self::collectStartedScripts($script, $label, $usedScripts);
        }

        // The map's other NPCs: inline scripts and dialogue-variant scripts.
        foreach ($map->getNpcs()->all() as $other) {
            $label = sprintf('NPC %s', $other->getName());

            if ($this->commandsName($other->getScript(), $npcId)) {
                $found[] = $label . ' script';
            }

            self::collectStartedScripts($other->getScript(), $label . ' script', $usedScripts);

            foreach ($other->getDialogueAsVariants() as $index => $variant) {
                $variantLabel = sprintf('%s page %d', $label, $index + 1);
                $script = (array) ($variant['script'] ?? []);

                if ($this->commandsName($script, $npcId)) {
                    $found[] = $variantLabel;
                } elseif (in_array($npcId, array_map(self::readText(...), (array) ($variant['speakers'] ?? [])), true)) {
                    $found[] = $variantLabel . ' (speaker)';
                }

                self::collectStartedScripts($script, $variantLabel, $usedScripts);
                $scriptId = self::readText($variant['scriptId'] ?? null);

                if ($scriptId !== '') {
                    $usedScripts[$scriptId] ??= $variantLabel;
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

            if (self::readText($data['startMap'] ?? null) !== $map->mapId) {
                continue;
            }

            $cinematic = [...(array) ($data['cast'] ?? []), ...(array) ($data['finalizer'] ?? []), ...$commands];
            $label = sprintf('cinematic %s', $asset->id);

            if ($this->commandsName($cinematic, $npcId)) {
                $found[] = $label;
            }

            self::collectStartedScripts($cinematic, $label, $usedScripts);
        }

        // Reusable scripts this map reaches, and the ones those start in turn.
        // Each is read once, so scripts that start each other end the walk.
        $database = $this->workspace->getRecordDatabase('common_events');
        $scripts = [];

        if ($database instanceof ProjectRecordDatabase) {
            foreach ($database->getRecords() as $record) {
                $script = (array) $record->toArray();
                $scripts[strval($script['__scriptId'] ?? '')] ??= (array) ($script['commands'] ?? []);
            }
        }

        $read = [];

        while (($pending = array_diff_key($usedScripts, $read)) !== []) {
            foreach ($pending as $scriptId => $via) {
                $scriptId = strval($scriptId);
                $read[$scriptId] = true;
                $commands = $scripts[$scriptId] ?? [];
                $label = sprintf('event script %s', $scriptId);

                if ($this->commandsName($commands, $npcId)) {
                    $found[] = sprintf('%s (via %s)', $label, $via);
                }

                self::collectStartedScripts($commands, $label, $usedScripts);
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Notes the reusable event scripts commands start, however deeply nested,
     * each under the first owner found starting it.
     *
     * @param array<int|string, mixed> $commands The commands.
     * @param string $owner Where the commands live, in words.
     * @param array<string, string> $started Owners by script id, added to.
     */
    private static function collectStartedScripts(array $commands, string $owner, array &$started): void
    {
        foreach ($commands as $node) {
            if (! is_array($node)) {
                continue;
            }

            // Only a common_event command's id names a script; elsewhere an
            // id may be any reference, structured ones included.
            $scriptId = ($node['type'] ?? null) === 'common_event' ? self::readText($node['id'] ?? null) : '';

            if ($scriptId !== '') {
                $started[$scriptId] ??= $owner;
            }

            self::collectStartedScripts($node, $owner, $started);
        }
    }

    /**
     * Determines whether commands name the NPC, however deeply nested: a
     * `move_route` for subject `npc`, or any subject, target or cast entry
     * of kind `npc`, by `npcId` or `id`. A structured subject such as
     * `['kind' => 'npc', 'id' => ...]` is itself a nested reference, read
     * when the walk reaches it, as the Engine's subject resolver reads it.
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

            if (strtolower(self::readText($node['kind'] ?? $node['subject'] ?? null)) === 'npc'
                && self::readText($node['npcId'] ?? $node['id'] ?? null) === $npcId) {
                return true;
            }

            if ($this->commandsName($node, $npcId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns an authored id, kind or map id as trimmed text, or an empty
     * string when it is not text. A structured value, such as a subject
     * written as `['kind' => 'npc', 'id' => ...]`, is a reference of its own
     * that the walk reads where it stands, not text of the node holding it.
     */
    private static function readText(mixed $value): string
    {
        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }
}
