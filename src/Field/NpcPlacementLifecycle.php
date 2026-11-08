<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Events\CommandMapContext;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\ScriptMapState;
use Ichiloto\Engine\Field\NpcPlacementSet;
use InvalidArgumentException;
use Throwable;

/**
 * Where an NPC can stand while the player walks the field: the named
 * placements its authored lifecycle can leave it on, each with the story
 * events that are true whenever it stands there.
 *
 * The Engine keeps one saved placement name per NPC. It starts as `default`
 * and only `commit_npc_placement` changes it, so the NPC rests on a
 * placement a commit chose, or on its default while no commit has run.
 * Story events are only ever recorded, never cleared, so whatever a run is
 * certain to record stays true once it rests:
 *
 * - After a commit, the NPC rests on its placement with every event its run
 *   records at the top level of any list enclosing the commit, and every
 *   event a `then` arm it runs in requires.
 * - Before any commit, it rests on its default. An event recorded only by
 *   runs that also commit the NPC on its map, at the top level of a list
 *   enclosing the record, is then still unrecorded.
 *
 * Runs are followed the way validation follows them ({@see ScriptMapState}):
 * from a map's own scripts and a cinematic's Start Map, through reusable
 * event scripts, arms, lanes and transfers. A commit counts for a map only
 * when the run is certainly on that map there. Everything else in the
 * project is still read for what it writes: a story event written anywhere
 * else, by `sets`, an unvisited script or the Engine itself, proves nothing,
 * and a placement name written as a variable leaves that NPC on any of its
 * placements at any time. Commands the Editor cannot place are judged as
 * possible at any time. A save migration is project code, which the Engine's
 * own load check guards instead.
 *
 * @package Ichiloto\Editor\Field
 */
final class NpcPlacementLifecycle
{
    /** Story events the Engine records itself, by prefix: skits seen, quests closed, cinematics finished. */
    private const array ENGINE_EVENT_PREFIXES = ['skit_seen:', 'quest_completed:', 'quest_declined:', 'cinematic:'];
    /** Keys whose world-state entries are tests, not writes. */
    private const array READ_KEYS = ['conditions', 'revealConditions', 'prerequisites'];
    /** Keys holding a command list a map or cutscene runs. */
    private const array SCRIPT_KEYS = ['script', 'commands', 'finalizer'];

    /** @var list<array{commits: array<string, true>, records: array<string, true>, visits: list<int>, commitVisits: list<int>}> */
    private array $frames = [];
    /** @var list<array{site: string, accompanied: array<string, true>}> Every record_event a run reaches. */
    private array $recordVisits = [];
    /** @var list<array{site: string, maps: list<string>|null, npc: string, placement: string, events: array<string, true>}> */
    private array $commits = [];
    /** @var array<string, string> The story event each record_event command anywhere in the project records, by site. */
    private array $recordSites = [];
    /** @var array<string, array<string, mixed>> Every commit_npc_placement command anywhere in the project, by site. */
    private array $commitSites = [];
    /** @var array<string, true> Story events written other than by record_event. */
    private array $otherWriters = [];
    /** @var array<string, true> Placement selections written as variables. */
    private array $freelyWritten = [];
    /** @var array<string, true> Reusable scripts already walked from an unknown map. */
    private array $unplacedScripts = [];
    /** @var array<string, list<string>> Events recorded only by runs committing the NPC, by map and NPC id. */
    private array $committingEvents = [];

    /**
     * @param array<string, array<int, mixed>> $scripts Reusable event scripts by id.
     * @param array<string, string[]> $npcIdsByMap Each map's NPC ids.
     */
    /**
     * @param (\Closure(array<string, mixed>): ?string)|null $resolveDoor A door command's destination map.
     */
    private function __construct(private readonly array $scripts, private readonly array $npcIdsByMap, private readonly ?\Closure $resolveDoor = null)
    {
    }

    /**
     * Reads every run the project authors.
     *
     * @param ProjectWorkspace $workspace The project, unsaved edits included.
     */
    public static function read(ProjectWorkspace $workspace): self
    {
        // Reusable scripts by the id a `common_event` command or `scriptId` names, as the Engine finds their files.
        $scripts = [];
        foreach ($workspace->getRecordDatabase('common_events')?->getRecords() ?? [] as $record) {
            $payload = (array) $record->toArray();
            $id = trim(strval($payload['__scriptId'] ?? ''));
            if ($id !== '') {
                $scripts[$id] = (array) ($payload['commands'] ?? []);
            }
        }
        $npcIdsByMap = [];
        foreach ($workspace->maps as $map) {
            $npcIdsByMap[$map->mapId] = array_values(array_filter(array_map(
                static fn(mixed $npc): string => is_array($npc) && is_string($npc['id'] ?? null) ? $npc['id'] : '',
                (array) ($map->getEditableData()['npcs'] ?? []),
            ), static fn(string $id): bool => $id !== ''));
        }
        $lifecycle = new self($scripts, $npcIdsByMap, new \Ichiloto\Editor\Maps\MapDoors($workspace)->createDestinationResolver());

        foreach ($workspace->maps as $map) {
            $site = 'map:' . $map->mapId;
            $lifecycle->scan($map->getEditableData(), $site, null, false);
            $lifecycle->walkEntries($map->getEditableData(), $site, ScriptMapState::startOn($map->mapId, $npcIdsByMap[$map->mapId]));
        }

        foreach ($scripts as $id => $commands) {
            $lifecycle->scan($commands, 'common:' . $id, null, false);
        }

        foreach (CutsceneType::cases() as $type) {
            foreach ($workspace->cutscenes?->assets($type) ?? [] as $asset) {
                try {
                    $data = $asset->data();
                    $partner = $asset->partner();
                    $commands = $asset->commands();
                } catch (Throwable) {
                    continue;
                }

                $site = sprintf('cutscene:%s:%s', $type->value, $asset->id);
                $cinematic = $type === CutsceneType::CINEMATIC;
                // A summon or effect plays in battle, where no field run follows it.
                $lifecycle->scan($data, $site . '/data', null, ! $cinematic);
                $lifecycle->scan($cinematic ? (array_is_list($partner) ? [] : array_diff_key($partner, ['commands' => true])) : $partner,
                    $site . '/partner', null, ! $cinematic);

                if ($cinematic) {
                    $lifecycle->scan($commands, $site . '/commands', null, false);
                    $start = is_string($data['startMap'] ?? null) && $data['startMap'] !== ''
                        ? ScriptMapState::startOn($data['startMap'], $npcIdsByMap[$data['startMap']] ?? null)
                        : ScriptMapState::startOn(null, null);
                    $lifecycle->walkScript($commands, $site . '/commands', $start, [], []);
                    $lifecycle->walkEntries($data, $site . '/data', $start);
                }
            }
        }

        // Every other record runs its scripts, if any, somewhere the Editor cannot place.
        foreach ($workspace->recordDatabases as $category => $database) {
            if ($category === 'common_events') {
                continue;
            }

            foreach ($database->getRecords() as $index => $record) {
                $lifecycle->scan((array) $record->toArray(), sprintf('record:%s:%s', $category, $record->recordId !== '' ? $record->recordId : $index), null, true);
            }
        }

        $lifecycle->settle();

        return $lifecycle;
    }

    /**
     * The placements an NPC can show on, each with every condition true
     * while it stands there: its own conditions and the story its placement
     * implies. A placement whose story contradicts the NPC's own conditions
     * is left out: the NPC is hidden whenever it stands there. Two stances
     * share the field only when their conditions cannot be proved exclusive.
     *
     * @param string $mapId The NPC's map.
     * @param array<string, mixed> $entry The NPC entry as authored.
     * @param array{x: int, y: int} $root The NPC's root cell, as its own checks read it.
     * @param callable(array<int|string, mixed>, array<int|string, mixed>): bool $proveExclusive Whether two condition lists can never both hold.
     * @return list<array{placement: string, x: int, y: int, conditions: list<mixed>}>
     */
    public function listVisibleStances(string $mapId, array $entry, array $root, callable $proveExclusive): array
    {
        $conditions = is_array($entry['conditions'] ?? null) ? array_values($entry['conditions']) : [];
        $visible = [];

        foreach ($this->listStances($mapId, $entry, $root) as $stance) {
            if ($stance['conditions'] === [] || ! $proveExclusive($conditions, $stance['conditions'])) {
                $visible[] = [...$stance, 'conditions' => [...$conditions, ...$stance['conditions']]];
            }
        }

        return $visible;
    }

    /**
     * The placements an NPC can rest on, with the story event conditions
     * true whenever it stands there. An NPC without named placements, or
     * with ones the Engine refuses, rests on its root cell only.
     *
     * @param array<string, mixed> $entry The NPC entry as authored.
     * @param array{x: int, y: int} $root The NPC's root cell.
     * @return list<array{placement: string, x: int, y: int, conditions: list<array{type: string, name: string, negate?: true}>}>
     */
    private function listStances(string $mapId, array $entry, array $root): array
    {
        $default = ['placement' => NpcPlacementSet::DEFAULT, 'x' => $root['x'], 'y' => $root['y'], 'conditions' => []];

        if (! array_key_exists('placements', $entry) || ! is_string($entry['id'] ?? null)) {
            return [$default];
        }

        try {
            $placements = NpcPlacementSet::fromEntry($entry)->placements;
        } catch (InvalidArgumentException) {
            return [$default];
        }

        $stance = static fn(string $name, array $events): array => [
            'placement' => $name,
            'x' => $placements[$name]['x'],
            'y' => $placements[$name]['y'],
            'conditions' => $events,
        ];

        if (isset($this->freelyWritten[NpcPlacementSet::getStateKey($mapId, $entry['id'])])) {
            return array_map(static fn(string $name): array => $stance($name, []), array_keys($placements));
        }

        $stances = [$stance(NpcPlacementSet::DEFAULT, array_map(
            static fn(string $event): array => ['type' => 'event', 'name' => $event, 'negate' => true],
            $this->committingEvents[$mapId . "\0" . $entry['id']] ?? [],
        ))];

        foreach ($this->commits as $commit) {
            if ($commit['npc'] !== $entry['id'] || ! isset($placements[$commit['placement']])
                || ($commit['maps'] !== null && ! in_array($mapId, $commit['maps'], true))) {
                continue;
            }

            $events = array_keys($commit['events']);
            sort($events);
            $stances[] = $stance($commit['placement'], array_map(static fn(string $event): array => ['type' => 'event', 'name' => $event], $events));
        }

        return array_values(array_unique($stances, SORT_REGULAR));
    }

    /**
     * Walks the scripts a value runs from its own place: command lists under
     * a script key, and the reusable scripts it names by `scriptId`.
     */
    private function walkEntries(mixed $value, string $site, ScriptMapState $start): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $at = $site . '/' . $key;

            if (in_array($key, self::SCRIPT_KEYS, true) && is_array($child) && array_is_list($child)) {
                $this->walkScript($child, $at, $start, [], []);
            } elseif ($key === 'scriptId' && is_string($child)) {
                $this->walkReusable(trim($child), $start, [], [], null);
            } else {
                $this->walkEntries($child, $at, $start);
            }
        }
    }

    /**
     * Walks one command list in a run. Arms and lanes are lists of their
     * own; a sequence or a reusable script continues the list it is in.
     *
     * @param array<int|string, mixed> $commands The commands.
     * @param string $site Where the list lives.
     * @param list<string> $stack Reusable scripts being followed.
     * @param array<string, true> $guards Story events the arms it runs in require.
     * @param int|null $frame The list it continues, or null to begin one.
     * @return ScriptMapState Where the run is after the list.
     */
    private function walkScript(array $commands, string $site, ScriptMapState $state, array $stack, array $guards, ?int $frame = null): ScriptMapState
    {
        $owned = $frame === null;

        if ($owned) {
            $this->frames[] = ['commits' => [], 'records' => [], 'visits' => [], 'commitVisits' => []];
            $frame = array_key_last($this->frames);
        }

        foreach ($commands as $index => $command) {
            if (! is_array($command)) {
                continue;
            }

            $at = $site . '/' . $index;
            $type = is_string($command['type'] ?? null) ? $command['type'] : '';

            if ($type === 'record_event' && is_scalar($command['name'] ?? null) && trim(strval($command['name'])) !== '') {
                $this->recordVisits[] = ['site' => $at, 'accompanied' => []];
                $this->registerInFrames('visits', array_key_last($this->recordVisits));
                $this->frames[$frame]['records'][trim(strval($command['name']))] = true;
            }

            if ($type === 'commit_npc_placement' && is_string($command['npc'] ?? null) && is_string($command['placement'] ?? null)) {
                if ($state->mapIds !== null && count($state->mapIds) === 1 && $state->concurrentMaps === []) {
                    $this->frames[$frame]['commits'][$state->mapIds[0] . "\0" . $command['npc']] = true;
                }

                $this->commits[] = ['site' => $at, 'maps' => $state->mapIds, 'npc' => $command['npc'], 'placement' => $command['placement'], 'events' => $guards];
                $this->registerInFrames('commitVisits', array_key_last($this->commits));
            }

            if ($type === 'common_event' && is_string($command['id'] ?? null)) {
                $state = $this->walkReusable(trim($command['id']), $state, $stack, $guards, $frame);
            }

            if ($type === 'sequence') {
                $state = $this->walkScript((array) ($command['commands'] ?? []), $at . '/commands', $state, $stack, $guards, $frame);
            }

            if ($type === 'parallel') {
                $state = $state->walkParallelLanes(
                    $command,
                    $this->npcIdsByMap,
                    fn(string $id): ?array => $this->scripts[$id] ?? null,
                    $stack,
                    fn(array $lane, ScriptMapState $start): ScriptMapState => $this->walkScript($lane['commands'], $at . '/' . implode('/', $lane['path']), $start, $stack, $guards),
                    $this->resolveDoor,
                );
            }

            $ends = [];
            foreach (CommandMapContext::getArms($command) as $arm) {
                // Events a `then` arm requires stay recorded once it has run.
                $armGuards = $type === 'branch' && $arm['path'] === ['then'] ? $guards + self::readRequiredEvents($command['conditions'] ?? []) : $guards;
                $ends[] = $this->walkScript($arm['commands'], $at . '/' . implode('/', $arm['path']), $state, $stack, $armGuards);
            }

            if ($ends !== []) {
                $state = ScriptMapState::mergeStates(CommandMapContext::canSkipArms($command) ? [...$ends, $state] : $ends);
            }

            $destination = CommandMapContext::findDestinationMap($command, $this->resolveDoor);
            if ($destination !== null) {
                $state = $state->transferTo($destination, $this->npcIdsByMap);
            }
        }

        if ($owned) {
            $done = array_pop($this->frames);

            foreach ($done['visits'] as $visit) {
                $this->recordVisits[$visit]['accompanied'] += $done['commits'];
            }

            foreach ($done['commitVisits'] as $commit) {
                $this->commits[$commit]['events'] += $done['records'];
            }
        }

        return $state;
    }

    /**
     * Follows a reusable script as part of the run that starts it.
     *
     * @param list<string> $stack Reusable scripts being followed.
     * @param array<string, true> $guards Story events the arms it runs in require.
     */
    private function walkReusable(string $id, ScriptMapState $state, array $stack, array $guards, ?int $frame): ScriptMapState
    {
        if (! isset($this->scripts[$id]) || in_array($id, $stack, true)) {
            return $state;
        }

        return $this->walkScript($this->scripts[$id], 'common:' . $id, $state, [...$stack, $id], $guards, $frame);
    }

    /** Notes a visit in every list the run is in, to learn what each certainly does around it. */
    private function registerInFrames(string $kind, int $visit): void
    {
        foreach (array_keys($this->frames) as $frame) {
            $this->frames[$frame][$kind][] = $visit;
        }
    }

    /**
     * Reads what a value writes, wherever it lives. A reusable script a
     * value outside the followed runs starts is walked from an unknown map.
     */
    private function scan(mixed $value, string $site, int|string|null $key, bool $startsScripts): void
    {
        if (! is_array($value)) {
            return;
        }

        $type = is_string($value['type'] ?? null) ? $value['type'] : '';
        $name = is_scalar($value['name'] ?? null) ? trim(strval($value['name'])) : '';

        if ($name !== '' && ! in_array($key, self::READ_KEYS, true)) {
            match ($type) {
                'record_event' => $this->recordSites[$site] = $name,
                'event' => $this->otherWriters[$name] = true,
                'set_variable', 'variable' => str_starts_with($name, 'npc-placement:') ? $this->freelyWritten[$name] = true : null,
                default => null,
            };
        }

        if ($type === 'commit_npc_placement') {
            $this->commitSites[$site] = $value;
        }

        if ($startsScripts) {
            $called = $type === 'common_event' ? $value['id'] ?? null : $value['scriptId'] ?? null;

            if (is_string($called) && ! isset($this->unplacedScripts[trim($called)])) {
                $this->unplacedScripts[trim($called)] = true;
                $this->walkReusable(trim($called), ScriptMapState::startOn(null, null), [], [], null);
            }
        }

        foreach ($value as $childKey => $child) {
            $this->scan($child, $site . '/' . $childKey, is_int($childKey) ? $key : $childKey, $startsScripts);
        }
    }

    /**
     * Works out, once every run is read, which events prove an NPC has been
     * committed, and treats a commit no run reached as possible at any time.
     */
    private function settle(): void
    {
        $visitsBySite = [];
        foreach ($this->recordVisits as $visit) {
            $visitsBySite[$visit['site']][] = $visit['accompanied'];
        }

        $proofs = [];
        foreach ($this->recordSites as $site => $event) {
            $visits = $visitsBySite[$site] ?? [];
            $proofs[$event][] = $visits === [] ? [] : (count($visits) === 1 ? $visits[0] : array_intersect_key(...$visits));
        }

        foreach ($proofs as $event => $sites) {
            if (isset($this->otherWriters[$event]) || array_any(self::ENGINE_EVENT_PREFIXES, static fn(string $prefix): bool => str_starts_with(strval($event), $prefix))) {
                continue;
            }

            foreach (array_keys(count($sites) === 1 ? $sites[0] : array_intersect_key(...$sites)) as $npc) {
                $this->committingEvents[$npc][] = strval($event);
            }
        }

        $visited = array_fill_keys(array_column($this->commits, 'site'), true);
        foreach ($this->commitSites as $site => $command) {
            if (! isset($visited[$site]) && is_string($command['npc'] ?? null) && is_string($command['placement'] ?? null)) {
                $this->commits[] = ['site' => $site, 'maps' => null, 'npc' => $command['npc'], 'placement' => $command['placement'], 'events' => []];
            }
        }
    }

    /**
     * The story events a branch's conditions require recorded.
     *
     * @return array<string, true>
     */
    private static function readRequiredEvents(mixed $conditions): array
    {
        $events = [];
        foreach (is_array($conditions) ? $conditions : [] as $condition) {
            if (is_array($condition) && ($condition['type'] ?? null) === 'event' && ! (bool) ($condition['negate'] ?? false)
                && is_scalar($condition['name'] ?? null) && trim(strval($condition['name'])) !== '') {
                $events[trim(strval($condition['name']))] = true;
            }
        }

        return $events;
    }
}
