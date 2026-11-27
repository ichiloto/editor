<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Progression;

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\ConditionReferences;
use Ichiloto\Editor\Events\ScriptCommandUses;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Core\WorldConditionType;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandReference;
use Throwable;

/**
 * Finding what names a world power by its stable id: a `world_ability`
 * condition anywhere the project holds conditions (maps, their events and
 * NPCs, every Database page's records, other powers among them, and
 * cinematics), and a registered command field the Engine declares as a
 * `world_ability` reference. Unsaved edits are read as the Editor holds them.
 *
 * Knowing what names a power is what keeps its id while something expects
 * it, and refuses deleting it.
 *
 * @package Ichiloto\Editor\Progression
 */
final readonly class WorldAbilityReferences
{
    public function __construct(private ProjectWorkspace $workspace)
    {
    }

    /**
     * Returns what names the power, in words.
     *
     * @return list<string> Where each reference lives, each once.
     */
    public function describe(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            return [];
        }
        $found = [];
        foreach ($this->workspace->maps as $map) {
            if (ConditionReferences::containsReference($map->getEditableData(), [WorldConditionType::WORLD_ABILITY->value], $id)) {
                $found[] = sprintf('map %s', $map->mapId);
            }
        }
        foreach ($this->workspace->recordDatabases as $category => $database) {
            foreach ($database->getRecords() as $record) {
                $payload = $record->toArray();
                // A power's own conditions are not something else naming it.
                if ($category === WorldAbilityFields::CATEGORY && is_array($payload) && trim(strval($payload['id'] ?? '')) === $id) {
                    continue;
                }
                if (ConditionReferences::containsReference($payload, [WorldConditionType::WORLD_ABILITY->value], $id)) {
                    $found[] = sprintf('%s %s', str_replace('_', ' ', strval($category)), $record->recordId);
                }
            }
        }
        foreach ($this->workspace->cutscenes?->assets(CutsceneType::CINEMATIC) ?? [] as $cinematic) {
            try {
                $source = [$cinematic->data(), $cinematic->partner()];
            } catch (Throwable) {
                continue;
            }
            if (ConditionReferences::containsReference($source, [WorldConditionType::WORLD_ABILITY->value], $id)) {
                $found[] = sprintf('cinematic %s', $cinematic->id);
            }
        }
        $commands = ScriptCommandUses::findReferenceUses($this->workspace, $this->workspace->cutscenes?->assets(CutsceneType::CINEMATIC) ?? [],
            ScriptCommandReference::WORLD_ABILITY)[$id] ?? [];

        return array_values(array_unique([...$found, ...$commands]));
    }
}
