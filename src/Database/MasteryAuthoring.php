<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Engine\Progression\Mastery\MasteryLayout;
use Ichiloto\Engine\Progression\Mastery\MasteryMap;
use RuntimeException;
use Throwable;

/**
 * The graphical Mastery Map page's edits: where each node stands, as the
 * Engine's MasteryLayout places it, a node moved on the map, and a link
 * drawn or removed between two nodes. Each is one undo step on the map's
 * record, written on save like any other edit.
 *
 * @package Ichiloto\Editor\Database
 */
final class MasteryAuthoring
{
    /**
     * The map as the page draws it: the regions, and each node with its place
     * (authored, or where the Engine would put it) and what it builds on.
     *
     * @return array{regions: list<array{id: string, name: string}>, nodes: list<array<string, mixed>>, issue: ?string}
     */
    public function readPage(ProjectRecordDatabase $maps, ?ProjectRecordDatabase $regions, int $index): array
    {
        $record = $maps->getRecordByIndex($index) ?? throw new RecordRefusal(sprintf('%s has no record %d.', MasteryFields::MAPS, $index));
        $regionRows = array_map(static fn(ProjectRecord $region): array => ['id' => strval($region->get('id')), 'name' => strval($region->get('name'))],
            $regions?->getRecords() ?? []);
        $entries = $record->getSubList('nodes');
        $places = [];
        $issue = null;
        try {
            $places = MasteryLayout::place(MasteryMap::fromArray((array) $record->toArray(), array_column($regionRows, 'id')), array_column($regionRows, 'id'));
        } catch (Throwable $error) {
            // A map still being authored draws its placed nodes and says what keeps the rest from a place.
            $issue = $error->getMessage();
        }
        $nodes = [];
        foreach ($entries as $entry => $node) {
            $id = strval($node['id'] ?? '');
            $place = $places[$id] ?? (is_array($node['position'] ?? null)
                ? ['x' => floatval($node['position']['x'] ?? 0.5), 'y' => floatval($node['position']['y'] ?? 0.5), 'placed' => true]
                : ['x' => 0.5, 'y' => 0.5, 'placed' => false]);
            $nodes[] = [
                'entry' => $entry,
                'id' => $id,
                'skill' => strval($node['skill'] ?? ''),
                'region' => strval($node['region'] ?? ''),
                'cost' => intval($node['cost'] ?? 0),
                'opensAtLevel' => intval($node['opensAtLevel'] ?? 1),
                'requires' => array_values(array_map('strval', (array) ($node['requires'] ?? []))),
                'x' => $place['x'],
                'y' => $place['y'],
                'placed' => $place['placed'],
            ];
        }

        return ['regions' => $regionRows, 'nodes' => $nodes, 'issue' => $issue];
    }

    /**
     * Places a node on the map, as fractions (0 to 1) of its width and height.
     *
     * @throws RecordRefusal When the map or node is unknown, or the place is off the map.
     */
    public function placeNode(ProjectRecordDatabase $maps, int $index, string $nodeId, float $x, float $y): RecordChange
    {
        if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
            throw new RecordRefusal('A node is placed between 0 and 1 across and down the map.');
        }

        return $this->changeNodes($maps, $index, 'Move node', static function (array $nodes) use ($nodeId, $x, $y): array {
            $entry = self::requireEntry($nodes, $nodeId);
            $nodes[$entry]['position'] = ['x' => round($x, 3), 'y' => round($y, 3)];

            return $nodes;
        });
    }

    /**
     * Makes a node build on another, or stop building on it if it already did.
     *
     * @throws RecordRefusal When a node is unknown, the two are one node, or the link would close a loop.
     */
    public function toggleRequirement(ProjectRecordDatabase $maps, ?ProjectRecordDatabase $regions, int $index, string $nodeId, string $requiredId): RecordChange
    {
        if ($nodeId === $requiredId) {
            throw new RecordRefusal('A node cannot build on itself.');
        }
        $regionIds = array_map(static fn(ProjectRecord $region): string => strval($region->get('id')), $regions?->getRecords() ?? []);

        return $this->changeNodes($maps, $index, 'Link nodes', static function (array $nodes, array $record) use ($nodeId, $requiredId, $regionIds): array {
            $entry = self::requireEntry($nodes, $nodeId);
            self::requireEntry($nodes, $requiredId);
            $requires = array_values(array_map('strval', (array) ($nodes[$entry]['requires'] ?? [])));
            $requires = in_array($requiredId, $requires, true)
                ? array_values(array_diff($requires, [$requiredId]))
                : [...$requires, $requiredId];
            if ($requires === []) {
                unset($nodes[$entry]['requires']);
            } else {
                $nodes[$entry]['requires'] = $requires;
            }
            // The link must leave a map the Engine reads: no loop of nodes requiring each other.
            try {
                MasteryMap::fromArray([...$record, 'nodes' => $nodes], $regionIds);
            } catch (\InvalidArgumentException $error) {
                if (str_contains($error->getMessage(), 'loop')) {
                    throw new RecordRefusal($error->getMessage(), previous: $error);
                }
            }

            return $nodes;
        });
    }

    /**
     * @param callable(list<array<string, mixed>>, array<string, mixed>): list<array<string, mixed>> $change
     * @throws RecordRefusal
     */
    private function changeNodes(ProjectRecordDatabase $maps, int $index, string $label, callable $change): RecordChange
    {
        $record = $maps->getRecordByIndex($index) ?? throw new RecordRefusal(sprintf('%s has no record %d.', MasteryFields::MAPS, $index));
        $list = $maps->schema->subList ?? throw new RecordRefusal('A mastery map has no node list.');
        $before = $record->toArray();
        $nodes = $change(array_values($record->getSubList('nodes')), (array) $before);
        if ($nodes === array_values($record->getSubList('nodes'))) {
            return new RecordChange(null, $index);
        }
        $record->setEntries($list, $nodes);
        $after = $record->toArray();
        try {
            $maps->assertSourceAccepts($index);
        } catch (SourcePreservationRefusal|SourceIdentityConflict|RuntimeException $refused) {
            $record->restorePayload($before);

            throw new RecordRefusal($refused->getMessage(), previous: $refused);
        }

        return new RecordChange(new GenericCommand(
            $label,
            static fn() => $record->restorePayload($after),
            static fn() => $record->restorePayload($before),
        ), $index);
    }

    /** @param list<array<string, mixed>> $nodes */
    private static function requireEntry(array $nodes, string $nodeId): int
    {
        foreach ($nodes as $entry => $node) {
            if (($node['id'] ?? null) === $nodeId) {
                return $entry;
            }
        }

        throw new RecordRefusal(sprintf('This map has no node %s.', $nodeId));
    }
}
