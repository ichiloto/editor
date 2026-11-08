<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Events\CommandMapContext;

/**
 * Where an event script can be while validation walks it: the maps it can
 * be on, the NPCs present on every one of them, and the maps a concurrent
 * parallel lane can transfer it to at any point.
 *
 * Unknown maps or map contents stay unknown (null) rather than becoming an
 * empty list, so nothing is reported against contents validation cannot see.
 * The walk itself follows {@see \Ichiloto\Editor\Events\CommandMapContext}.
 *
 * @package Ichiloto\Editor\Validation
 */
final class ScriptMapState
{
    /** The possible maps for a message, or null when unknown. */
    public ?string $description {
        get => $this->mapIds === null ? null : implode(' or ', $this->mapIds);
    }

    /**
     * @param list<string>|null $mapIds The maps the script can be on, or null when unknown.
     * @param list<string>|null $npcIds The NPCs on every one of those maps, or null when unknown.
     * @param list<string> $concurrentMaps Maps a concurrent parallel lane can transfer to; an empty id names no map.
     */
    public function __construct(
        public readonly ?array $mapIds,
        public readonly ?array $npcIds,
        public readonly array $concurrentMaps = [],
    )
    {
    }

    /**
     * The state of a script that starts on one map.
     *
     * @param string[]|null $npcIds The map's NPCs, or null when unknown.
     */
    public static function startOn(?string $mapId, ?array $npcIds): self
    {
        return new self($mapId !== null ? [$mapId] : null, $npcIds !== null ? array_values($npcIds) : null);
    }

    /**
     * Joins states a script can be in at one point: any of their maps, and
     * only the NPCs present on all of them.
     *
     * @param non-empty-list<self> $states
     */
    public static function mergeStates(array $states): self
    {
        $mapLists = array_map(static fn(self $state): ?array => $state->mapIds, $states);
        $npcLists = array_map(static fn(self $state): ?array => $state->npcIds, $states);

        return new self(
            in_array(null, $mapLists, true) ? null : array_values(array_unique(array_merge(...$mapLists))),
            in_array(null, $npcLists, true) ? null : array_values(array_intersect(...$npcLists)),
            array_values(array_unique(array_merge(...array_map(static fn(self $state): array => $state->concurrentMaps, $states)))),
        );
    }

    /**
     * The state after a transfer: on its destination, or on any map a
     * concurrent lane can transfer to afterwards.
     *
     * @param array<string, string[]> $npcIdsByMap Each known map's NPCs.
     */
    public function transferTo(string $destination, array $npcIdsByMap): self
    {
        return self::mergeStates(array_map(
            fn(string $map): self => $this->arriveOn($map, $npcIdsByMap),
            [$destination, ...$this->concurrentMaps],
        ));
    }

    /**
     * The state on arriving at one map. An empty map id names no map: the
     * current maps stay, with unknown NPCs.
     *
     * @param array<string, string[]> $npcIdsByMap Each known map's NPCs.
     */
    public function arriveOn(string $map, array $npcIdsByMap): self
    {
        return new self(
            $map !== '' ? [$map] : $this->mapIds,
            $map !== '' && array_key_exists($map, $npcIdsByMap) ? array_values($npcIdsByMap[$map]) : null,
            $this->concurrentMaps,
        );
    }

    /**
     * The same state with other maps a concurrent lane can transfer to.
     *
     * @param list<string> $maps
     */
    public function setConcurrentMaps(array $maps): self
    {
        return new self($this->mapIds, $this->npcIds, array_values(array_unique($maps)));
    }

    /**
     * Walks a parallel command's lanes from this state as the Engine runs
     * them: together, on the one current map, which a transfer in any lane
     * changes for every lane. Each lane starts here or on any map a sibling
     * lane transfers to, and its own transfers can be overtaken by a
     * sibling's. The block ends where its transferring lanes can finish, or
     * here when no lane transfers.
     *
     * @param array<string, mixed> $command The parallel command.
     * @param array<string, string[]> $npcIdsByMap Each known map's NPCs.
     * @param callable(string): ?array<int, mixed> $findCommonEvent The commands of a reusable event script, or null when it is unknown.
     * @param list<string> $commonEventStack Reusable event scripts being followed.
     * @param callable(array{path: list<int|string>, commands: array<int, mixed>}, self): self $walkLane Walks one lane from its start state, returning its end state.
     * @param (\Closure(array<string, mixed>): ?string)|null $resolveDoor A door command's destination map ({@see CommandMapContext::findDestinationMap()}).
     * @return self The state after the block.
     */
    public function walkParallelLanes(array $command, array $npcIdsByMap, callable $findCommonEvent, array $commonEventStack, callable $walkLane,
        ?\Closure $resolveDoor = null): self
    {
        $lanes = CommandMapContext::getLanes($command);
        $destinations = array_map(
            static fn(array $lane): array => CommandMapContext::listTransferDestinations($lane['commands'], $findCommonEvent, $commonEventStack, $resolveDoor),
            $lanes,
        );
        $ends = [];

        foreach ($lanes as $index => $lane) {
            $siblings = array_values(array_unique(array_merge([], ...array_values(array_diff_key($destinations, [$index => true])))));
            $laneStart = self::mergeStates([
                $this,
                ...array_map(fn(string $map): self => $this->arriveOn($map, $npcIdsByMap), $siblings),
            ])->setConcurrentMaps([...$this->concurrentMaps, ...$siblings]);
            $end = $walkLane($lane, $laneStart);

            if ($destinations[$index] !== []) {
                $ends[] = $end;
            }
        }

        return $ends !== [] ? self::mergeStates($ends)->setConcurrentMaps($this->concurrentMaps) : $this;
    }
}
