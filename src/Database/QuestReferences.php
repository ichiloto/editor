<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\ProjectQuest;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Finding what points at a quest.
 *
 * A quest's id is written into the things that grant it, wait on it, or read
 * its state, and none of those are updated by renaming the quest. Knowing
 * whether anything points at an id is what lets the editor keep the id in
 * step with the name while it is still safe to, and leave it alone once it
 * is not.
 *
 * @package Ichiloto\Editor\Database
 */
final class QuestReferences
{
    public function __construct(private readonly ProjectWorkspace $workspace)
    {
    }

    /**
     * Determines whether anything in the project names the quest.
     *
     * @param string $questId The quest id.
     * @return bool True when something points at it.
     */
    public function exist(string $questId): bool
    {
        return $this->describe($questId) !== [];
    }

    /**
     * Returns what points at the quest, in words.
     *
     * @param string $questId The quest id.
     * @return string[] Where each reference lives.
     */
    public function describe(string $questId): array
    {
        $questId = trim($questId);

        if ($questId === '') {
            return [];
        }

        return [
            ...$this->inQuests($questId),
            ...$this->inSkits($questId),
            ...$this->inScripts($questId),
            ...$this->inMaps($questId),
        ];
    }

    /**
     * Returns the quests waiting on this one.
     *
     * @param string $questId The quest id.
     * @return string[] Where each reference lives.
     */
    private function inQuests(string $questId): array
    {
        $found = [];

        foreach ($this->workspace->questDatabase->getQuests() as $quest) {
            if (! $quest instanceof ProjectQuest || $quest->getId() === $questId) {
                continue;
            }

            if ($this->conditionsName($quest->getPrerequisites(), $questId)) {
                $found[] = sprintf('quest %s', $quest->getId());
            }
        }

        return $found;
    }

    /**
     * Returns the skits waiting on the quest.
     *
     * @param string $questId The quest id.
     * @return string[] Where each reference lives.
     */
    private function inSkits(string $questId): array
    {
        $database = $this->workspace->getRecordDatabase('skits');

        if (! $database instanceof ProjectRecordDatabase) {
            return [];
        }

        $found = [];

        foreach ($database->getRecords() as $record) {
            $skit = (array) $record->toArray();

            if ($this->conditionsName((array) ($skit['conditions'] ?? []), $questId)) {
                $found[] = sprintf('skit %s', strval($skit['id'] ?? '(unnamed)'));
            }
        }

        return $found;
    }

    /**
     * Returns the event scripts granting or testing the quest.
     *
     * @param string $questId The quest id.
     * @return string[] Where each reference lives.
     */
    private function inScripts(string $questId): array
    {
        $database = $this->workspace->getRecordDatabase('common_events');

        if (! $database instanceof ProjectRecordDatabase) {
            return [];
        }

        $found = [];

        foreach ($database->getRecords() as $record) {
            $script = (array) $record->toArray();

            if ($this->commandsName((array) ($script['commands'] ?? []), $questId)) {
                $found[] = sprintf('event script %s', strval($script['__scriptId'] ?? '(unnamed)'));
            }
        }

        return $found;
    }

    /**
     * Returns the map events gated on the quest.
     *
     * @param string $questId The quest id.
     * @return string[] Where each reference lives.
     */
    private function inMaps(string $questId): array
    {
        $found = [];

        foreach ($this->workspace->maps as $map) {
            foreach ((array) ($map->data['events'] ?? []) as $marker => $definition) {
                if (! is_array($definition)) {
                    continue;
                }

                if ($this->conditionsName((array) ($definition['conditions'] ?? []), $questId)) {
                    $found[] = sprintf('%s event %s', $map->mapId, strval($marker));
                }
            }
        }

        return $found;
    }

    /**
     * Determines whether a condition list names the quest.
     *
     * @param array<int, mixed> $conditions The conditions.
     * @param string $questId The quest id.
     * @return bool True when one of them does.
     */
    private function conditionsName(array $conditions, string $questId): bool
    {
        foreach ($conditions as $condition) {
            if (
                is_array($condition)
                && strval($condition['type'] ?? '') === 'quest'
                && strval($condition['name'] ?? '') === $questId
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determines whether a command list names the quest, however deeply a
     * choice or a branch nests it.
     *
     * @param array<int, mixed> $commands The commands.
     * @param string $questId The quest id.
     * @return bool True when one of them does.
     */
    private function commandsName(array $commands, string $questId): bool
    {
        foreach ($commands as $command) {
            if (! is_array($command)) {
                continue;
            }

            if (strval($command['type'] ?? '') === 'accept_quest' && strval($command['id'] ?? '') === $questId) {
                return true;
            }

            if ($this->conditionsName((array) ($command['conditions'] ?? []), $questId)) {
                return true;
            }

            foreach ((array) ($command['options'] ?? []) as $option) {
                if (is_array($option) && $this->commandsName((array) ($option['then'] ?? []), $questId)) {
                    return true;
                }
            }

            foreach (['then', 'else'] as $arm) {
                if ($this->commandsName((array) ($command[$arm] ?? []), $questId)) {
                    return true;
                }
            }
        }

        return false;
    }
}
