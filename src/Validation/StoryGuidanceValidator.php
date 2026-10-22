<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectQuest;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Quests\QuestObjectiveType;
use Ichiloto\Engine\Quests\QuestWaymarkSubject;

/**
 * Reports gaps in the wayfinder's guidance along the story: a gate the
 * player must open to go on, which no tracked objective leads to.
 *
 * A gate is a story flag (a story event or switch) that a main quest needs
 * before it can start, or that a door's `whenBlocked` transfer needs before
 * it lets the player through. Waymarks, and the edge arrows and objective
 * marks drawn from them, only come from the objectives of active quests, so
 * a gate no objective leads to leaves the player with nothing to follow.
 *
 * An objective leads to a gate when it tracks the gate's flag and has a
 * waymark to follow, or when one of its waymarks marks an event or NPC page
 * that sets the flag. It must be able to lead before the flag is set: its
 * quest cannot need the flag to start, nor start only where the flag is set,
 * and its waymark cannot wait for the flag. Optional quests guide nothing on
 * the story path and gate nothing on it. A flag that no event, NPC page,
 * event script or cinematic sets is left to the checks that own it.
 */
final class StoryGuidanceValidator
{
    private const string HINT = 'Track the step with an objective of the quest that is active then, with a waymark on '
        . 'where the player opens it, so the wayfinder keeps leading.';

    /** @var array<string, array{flags: array<string, true>, quests: array<string, true>}> Effects by script id, memoized. */
    private array $scriptEffects = [];

    /** @var array<string, array{flags: array<string, true>, quests: array<string, true>}> Effects by cinematic id, memoized. */
    private array $cinematicEffects = [];

    private string $cinematicRoot = '';

    /**
     * @param array<string, array<int, mixed>> $commonEventScripts The project's event scripts by id, as validation read them.
     */
    public function __construct(private readonly array $commonEventScripts)
    {
    }

    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $this->scriptEffects = [];
        $this->cinematicEffects = [];
        $this->cinematicRoot = $workspace->projectRoot . '/assets/Cutscenes/Cinematics';

        $quests = array_values(array_filter($workspace->getQuests(),
            static fn(ProjectQuest $quest): bool => $quest->getId() !== '' && ! (bool) ($quest->toArray()['optional'] ?? false)));
        $units = $this->readUnits($workspace);
        $setters = [];
        $starters = [];
        foreach ($units as $index => $unit) {
            foreach (array_keys($unit['flags']) as $flag) {
                $setters[$flag][] = $index;
            }
            foreach (array_keys($unit['quests']) as $questId) {
                $starters[$questId][] = $index;
            }
        }

        $issues = [];
        foreach ($this->readGates($workspace, $quests) as $gate) {
            $flag = $gate['flag'];
            if (! isset($setters[$flag]) || $this->isLedTo($flag, $quests, $units, $setters[$flag], $starters)) {
                continue;
            }
            $issues[] = new Issue(Severity::WARNING, $gate['where'], sprintf(
                '%s needs "%s", but no objective of a main quest leads the player to it, so the wayfinder shows nothing until it is set.',
                $gate['what'], $flag,
            ), self::HINT, map: $gate['map']);
        }

        return $issues;
    }

    /**
     * The story flags gates need: main quests' prerequisites and blocked doors' conditions.
     *
     * @param list<ProjectQuest> $quests The main quests.
     * @return list<array{flag: string, where: string, what: string, map: ?string}>
     */
    private function readGates(ProjectWorkspace $workspace, array $quests): array
    {
        $gates = [];
        foreach ($quests as $quest) {
            foreach (self::readRequiredFlags($quest->getPrerequisites()) as $flag) {
                $gates[] = ['flag' => $flag, 'where' => sprintf('quest %s', $quest->getId()),
                    'what' => sprintf('The quest "%s"', $quest->getName()), 'map' => null];
            }
        }
        foreach ($workspace->maps as $map) {
            foreach ((array) ($map->getEditableData()['events'] ?? []) as $marker => $event) {
                if (! is_array($event) || ! is_string($event['whenBlocked'] ?? null)
                    || ! str_ends_with(strval($event['class'] ?? ''), 'TransferPlayerTrigger')) {
                    continue;
                }
                foreach (self::readRequiredFlags($event['conditions'] ?? []) as $flag) {
                    $gates[] = ['flag' => $flag, 'where' => sprintf('%s event %s', $map->mapId, strval($marker)),
                        'what' => sprintf('The way through event %s', strval($marker)), 'map' => $map->mapId];
                }
            }
        }

        return $gates;
    }

    /**
     * Whether an objective of a main quest leads to a flag before it is set.
     *
     * @param list<ProjectQuest> $quests The main quests.
     * @param list<array{map: string, subject: string, id: string, flags: array<string, true>, quests: array<string, true>}> $units
     * @param list<int> $setterUnits The units that set the flag.
     * @param array<string, list<int>> $starters The units that start each quest.
     */
    private function isLedTo(string $flag, array $quests, array $units, array $setterUnits, array $starters): bool
    {
        foreach ($quests as $quest) {
            // A quest that needs the flag, or starts only where the flag is set, is never active before it.
            $starts = $starters[$quest->getId()] ?? [];
            if (in_array($flag, self::readRequiredFlags($quest->getPrerequisites()), true)
                || ($starts !== [] && array_all($starts, static fn(int $unit): bool => isset($units[$unit]['flags'][$flag])))) {
                continue;
            }
            foreach ($quest->getObjectives() as $objective) {
                $tracksFlag = strval($objective['type'] ?? '') === QuestObjectiveType::FLAG->value
                    && strcasecmp(trim(strval($objective['target'] ?? '')), $flag) === 0;
                foreach ((array) ($objective['waymarks'] ?? []) as $waymark) {
                    if (! is_array($waymark) || in_array($flag, self::readRequiredFlags($waymark['conditions'] ?? []), true)) {
                        continue;
                    }
                    if ($tracksFlag || array_any($setterUnits, static fn(int $unit): bool => self::marks($waymark, $units[$unit]))) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @param array{map: string, subject: string, id: string} $unit */
    private static function marks(array $waymark, array $unit): bool
    {
        return strval($waymark['map'] ?? '') === $unit['map'] && strval($waymark[$unit['subject']] ?? '') === $unit['id'];
    }

    /**
     * The flags a condition list needs set: its positive story events and switches.
     *
     * @return list<string>
     */
    private static function readRequiredFlags(mixed $conditions): array
    {
        $flags = [];
        foreach (is_array($conditions) ? $conditions : [] as $condition) {
            if (! is_array($condition) || (bool) ($condition['negate'] ?? false) || ! is_string($condition['name'] ?? null)) {
                continue;
            }
            $type = $condition['type'] ?? null;
            if ($type === 'event' || ($type === 'switch' && ($condition['value'] ?? true) !== false)) {
                $flags[] = trim($condition['name']);
            }
        }

        return $flags;
    }

    /**
     * What the player can set off and a waymark can mark: every map event,
     * and every page of every NPC (each page runs on its own).
     *
     * @return list<array{map: string, subject: string, id: string, flags: array<string, true>, quests: array<string, true>}>
     */
    private function readUnits(ProjectWorkspace $workspace): array
    {
        $units = [];
        foreach ($workspace->maps as $map) {
            $data = $map->getEditableData();
            foreach ((array) ($data['events'] ?? []) as $marker => $event) {
                $units[] = ['map' => $map->mapId, 'subject' => QuestWaymarkSubject::EVENT->value, 'id' => strval($marker),
                    ...$this->collectEffects($event)];
            }
            foreach ((array) ($data['npcs'] ?? []) as $npc) {
                if (! is_array($npc) || ! is_string($npc['id'] ?? null)) {
                    continue;
                }
                // Sets on the NPC itself follow every conversation, so they belong to each page.
                $shared = $this->collectEffects(['sets' => $npc['sets'] ?? []]);
                $dialogue = is_array($npc['dialogue'] ?? null) ? $npc['dialogue'] : [];
                $pages = array_filter($dialogue, static fn(mixed $page): bool => is_array($page) && ! array_key_exists('text', $page));
                foreach ($pages === [] ? [[]] : $pages as $page) {
                    $effects = $this->collectEffects($page);
                    $units[] = ['map' => $map->mapId, 'subject' => QuestWaymarkSubject::NPC->value, 'id' => $npc['id'],
                        'flags' => $effects['flags'] + $shared['flags'], 'quests' => $effects['quests'] + $shared['quests']];
                }
            }
        }

        return $units;
    }

    /**
     * The flags a piece of content sets and the quests it starts, through
     * its commands, its `sets`, and the event scripts and cinematics it runs.
     * Conditions are read, never run, so they are skipped.
     *
     * @return array{flags: array<string, true>, quests: array<string, true>}
     */
    private function collectEffects(mixed $node): array
    {
        $effects = ['flags' => [], 'quests' => []];
        if (! is_array($node)) {
            return $effects;
        }

        $merge = static function (array $more) use (&$effects): void {
            $effects['flags'] += $more['flags'];
            $effects['quests'] += $more['quests'];
        };

        $type = $node['type'] ?? null;
        $name = is_string($node['name'] ?? null) ? trim($node['name']) : '';
        $id = is_string($node['id'] ?? null) ? trim($node['id']) : '';
        match (true) {
            $type === 'record_event' && $name !== '' => $effects['flags'][$name] = true,
            $type === 'set_switch' && $name !== '' && ($node['value'] ?? true) !== false => $effects['flags'][$name] = true,
            $type === 'accept_quest' && $id !== '' => $effects['quests'][$id] = true,
            $type === 'common_event' && $id !== '' => $merge($this->collectScriptEffects($id)),
            default => null,
        };

        foreach ($node as $key => $value) {
            if (is_string($key) && ($key === 'conditions' || str_ends_with($key, 'Conditions'))) {
                continue;
            }
            if ($key === 'sets' && is_array($value)) {
                foreach ($value as $set) {
                    $merge(self::readSet($set));
                }
                continue;
            }
            if ($key === 'scriptId' && is_string($value)) {
                $merge($this->collectScriptEffects(trim($value)));
                continue;
            }
            if ($key === 'cinematicId' && is_string($value)) {
                $merge($this->collectCinematicEffects(trim($value)));
                continue;
            }
            if (is_array($value)) {
                $merge($this->collectEffects($value));
            }
        }

        return $effects;
    }

    /** @return array{flags: array<string, true>, quests: array<string, true>} */
    private static function readSet(mixed $set): array
    {
        $effects = ['flags' => [], 'quests' => []];
        $name = is_array($set) && is_string($set['name'] ?? null) ? trim($set['name']) : '';
        if ($name === '') {
            return $effects;
        }
        match ($set['type'] ?? null) {
            'event' => $effects['flags'][$name] = true,
            'switch' => ($set['value'] ?? true) !== false ? $effects['flags'][$name] = true : null,
            'quest' => $effects['quests'][$name] = true,
            default => null,
        };

        return $effects;
    }

    /** @return array{flags: array<string, true>, quests: array<string, true>} */
    private function collectScriptEffects(string $id): array
    {
        if (! isset($this->scriptEffects[$id])) {
            // Marked first, so a script that runs itself again ends the walk.
            $this->scriptEffects[$id] = ['flags' => [], 'quests' => []];
            $this->scriptEffects[$id] = $this->collectEffects($this->commonEventScripts[$id] ?? []);
        }

        return $this->scriptEffects[$id];
    }

    /** @return array{flags: array<string, true>, quests: array<string, true>} */
    private function collectCinematicEffects(string $id): array
    {
        if (! isset($this->cinematicEffects[$id])) {
            $this->cinematicEffects[$id] = ['flags' => [], 'quests' => []];
            $sources = [];
            if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $id) === 1) {
                foreach (['data', 'script'] as $part) {
                    $file = "{$this->cinematicRoot}/{$id}/{$id}.{$part}.php";
                    $sources[] = is_file($file) ? (static fn(string $file): mixed => require $file)($file) : [];
                }
            }
            $this->cinematicEffects[$id] = $this->collectEffects($sources);
        }

        return $this->cinematicEffects[$id];
    }
}
