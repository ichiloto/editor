<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Events;

use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandField;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandFieldKind;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandReference;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandRegistry;

/**
 * Finds the commands a project's scripts run, wherever they are authored:
 * map events, NPCs, event scripts and cinematics, at any depth of branches,
 * lanes and choices. What cannot be read is not visited; validation reports it.
 *
 * @package Ichiloto\Editor\Events
 */
final class ScriptCommandUses
{
    private function __construct() {}

    /**
     * Visits every command, or every command of one type.
     *
     * @param iterable<CutsceneAsset> $cinematics The project's cinematics, as the editor holds them.
     * @param string|null $type Only commands of this type; every command when null.
     * @param \Closure(array<array-key, mixed>, string): void $visit Given the command and where it is.
     */
    public static function visitCommands(ProjectWorkspace $workspace, iterable $cinematics, ?string $type, \Closure $visit): void
    {
        $collect = static function (mixed $value, string $where) use (&$collect, $visit, $type): void {
            if (! is_array($value)) {
                return;
            }

            if (($type === null && is_string($value['type'] ?? null)) || ($type !== null && ($value['type'] ?? null) === $type)) {
                $visit($value, $where);
            }

            foreach ($value as $nested) {
                $collect($nested, $where);
            }
        };

        foreach ($workspace->maps as $map) {
            foreach ((array) ($map->getEditableData()['events'] ?? []) as $marker => $definition) {
                $collect($definition, sprintf('map %s event %s', $map->mapId, strval($marker)));
            }
            foreach ((array) ($map->getEditableData()['npcs'] ?? []) as $index => $npc) {
                $id = is_array($npc) && is_string($npc['id'] ?? null) ? $npc['id'] : strval($index);
                $collect($npc, sprintf('map %s NPC %s', $map->mapId, $id));
            }
        }

        foreach ($workspace->getRecordDatabase('common_events')?->getRecords() ?? [] as $record) {
            $script = (array) $record->toArray();
            $scriptId = trim(strval($script['__scriptId'] ?? ''));
            $collect($script, sprintf('event script %s', $scriptId !== '' ? $scriptId : '(unnamed)'));
        }

        foreach ($cinematics as $cinematic) {
            $collect([$cinematic->data(), $cinematic->partner()], sprintf('cinematic %s', $cinematic->id));
        }
    }

    /**
     * Where registered commands name a resource of one kind, by the
     * resource's identity: every field the command's declaration marks as
     * that kind of reference, list entries included.
     *
     * @param iterable<CutsceneAsset> $cinematics The project's cinematics, as the editor holds them.
     * @return array<string, list<string>> Identity to the places that name it, each once.
     */
    public static function findReferenceUses(ProjectWorkspace $workspace, iterable $cinematics, ScriptCommandReference $reference): array
    {
        $uses = [];
        $read = static function (array $fields, array $data, string $where) use (&$read, &$uses, $reference): void {
            foreach ($fields as $field) {
                $value = ScriptCommandField::readPath($data, $field->key);
                if ($field->kind === ScriptCommandFieldKind::REFERENCE && $field->reference === $reference
                    && is_string($value) && trim($value) !== '') {
                    $uses[trim($value)][] = $where;
                } elseif ($field->kind === ScriptCommandFieldKind::LIST && is_array($value)) {
                    foreach (array_filter($value, is_array(...)) as $entry) {
                        $read($field->fields, $entry, $where);
                    }
                }
            }
        };

        self::visitCommands($workspace, $cinematics, null, static function (array $command, string $where) use ($read): void {
            $definition = is_string($command['type'] ?? null) ? ScriptCommandRegistry::getCatalog()->findDefinition($command['type']) : null;
            if ($definition !== null) {
                $read($definition->fields, $command, $where);
            }
        });

        return array_map(static fn(array $places): array => array_values(array_unique($places)), $uses);
    }
}
