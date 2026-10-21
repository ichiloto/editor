<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\Storage\SourceSetPlan;
use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;
use RuntimeException;

/**
 * Moves a character's own skills from their actor file onto their Mastery
 * Map, so the map is the one place they are learned. Each skill the actor
 * file says they know becomes a node known from the start; each learnable
 * becomes a node costing the EXP its requirement asked for, for the author to
 * place and price; skills their role teaches stay with the role. The actor's
 * learned lists and learnables are then emptied. Saves are untouched: a
 * character keeps what a save says they know.
 *
 * @package Ichiloto\Editor\Database
 */
final class MasteryAdoption
{
    private function __construct()
    {
    }

    /**
     * @return array{plan: SourceSetPlan, nodes: list<string>}|null The plan and the skills it adds, or null when the
     * actor file holds no skills of its own.
     * @throws RuntimeException When the actor is unknown, the project declares no regions, or a file cannot be rewritten.
     */
    public static function plan(string $projectRoot, string $actorId): ?array
    {
        return ProjectDirectoryContext::run($projectRoot, static function (string $root) use ($actorId): ?array {
            $regions = MasteryCatalog::load($root . '/assets')->regions;
            if ($regions === []) {
                throw new RuntimeException('Declare the Mastery regions first: a node needs a region to sit in.');
            }
            $actorPaths = glob($root . '/assets/Data/Actors/*.php') ?: [];
            $mapPaths = glob($root . '/assets/' . MasteryCatalog::DIRECTORY . '/*.php') ?: [];
            $classesPath = $root . '/assets/Data/classes.php';
            $payloads = array_map(PhpDataFile::getComparableValue(...),
                PhpDataFile::evaluateIsolatedFiles([...$actorPaths, ...$mapPaths, ...(is_file($classesPath) ? [$classesPath] : [])], $root));
            $actorPayloads = array_slice($payloads, 0, count($actorPaths));
            $mapPayloads = array_slice($payloads, count($actorPaths), count($mapPaths));
            $classes = is_file($classesPath) ? (array) end($payloads) : [];

            $actorIndex = array_find_key($actorPayloads, static fn(mixed $payload): bool => is_array($payload)
                && (($payload['data']['id'] ?? $payload['data']['name'] ?? null) === $actorId));
            if ($actorIndex === null) {
                throw new RuntimeException(sprintf('No actor file has the id %s.', $actorId));
            }
            $actorPath = $actorPaths[$actorIndex];
            $actor = $actorPayloads[$actorIndex];
            $role = array_find($classes, static fn(mixed $class): bool => is_array($class) && ($class['name'] ?? null) === ($actor['data']['class'] ?? null));
            $taught = array_map(static fn(array $grant): string => strval($grant['skill'] ?? ''), (array) ($role['skillsToLearn'] ?? []));

            $mapIndex = array_find_key($mapPayloads, static fn(mixed $payload): bool => is_array($payload) && ($payload['actor'] ?? null) === $actorId);
            $map = $mapIndex === null ? ['actor' => $actorId, 'nodes' => []] : $mapPayloads[$mapIndex];
            $onMap = array_map(static fn(array $node): string => strval($node['skill'] ?? ''), (array) ($map['nodes'] ?? []));
            $order = max([0, ...array_map(static fn(array $node): int => intval($node['order'] ?? 0), (array) ($map['nodes'] ?? []))]);
            $ids = array_map(static fn(array $node): string => strval($node['id'] ?? ''), (array) ($map['nodes'] ?? []));
            $added = [];
            $emptied = $actor;
            foreach (['abilities', 'magic'] as $book) {
                foreach ((array) ($actor['data'][$book]['learned'] ?? []) as $skill) {
                    $added[] = ['skill' => strval($skill), 'cost' => 0];
                }
                foreach ((array) ($actor['data'][$book]['learnables'] ?? []) as $learnable) {
                    $added[] = ['skill' => strval($learnable['skill'] ?? ''), 'cost' => max(0, intval($learnable['requirement']['experienceRequired'] ?? 0))];
                }
                if (isset($actor['data'][$book])) {
                    $emptied['data'][$book]['learned'] = [];
                    $emptied['data'][$book]['learnables'] = [];
                }
            }
            if ($emptied === $actor) {
                return null;
            }
            $nodes = [];
            foreach ($added as ['skill' => $skill, 'cost' => $cost]) {
                if ($skill === '' || in_array($skill, $taught, true) || in_array($skill, $onMap, true)) {
                    continue;
                }
                $id = Slug::unique($skill, $ids, 'node');
                $ids[] = $id;
                $onMap[] = $skill;
                $map['nodes'][] = $nodes[] = ['id' => $id, 'skill' => $skill, 'region' => $regions[0]->id, 'cost' => $cost,
                    'opensAtLevel' => 1, 'order' => ++$order];
            }

            $actorSource = (string) file_get_contents($actorPath);
            $watched = [$actorPath => $actorSource];
            foreach ($mapPaths as $path) {
                $watched[$path] = (string) file_get_contents($path);
            }
            $originals = [$actorPath => $actorSource];
            $proposals = [$actorPath => ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($actorSource), $actor, $emptied)->source];
            $before = [$actorPath => $actor];
            $after = [$actorPath => $emptied];
            $created = null;
            if ($nodes !== []) {
                $mapPath = $mapIndex === null
                    ? $root . '/assets/' . MasteryCatalog::DIRECTORY . '/' . Slug::of($actorId) . '.php'
                    : $mapPaths[$mapIndex];
                if ($mapIndex === null && is_file($mapPath)) {
                    throw new RuntimeException(sprintf('%s already exists for another map.', basename($mapPath)));
                }
                $created = $mapIndex === null ? $mapPath : null;
                $originals[$mapPath] = $mapIndex === null ? null : $watched[$mapPath];
                $proposals[$mapPath] = $mapIndex === null
                    ? "<?php\n\nreturn " . PhpValueExporter::export($map) . ";\n"
                    : ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($watched[$mapPath]), $mapPayloads[$mapIndex], $map)->source;
                $before[$mapPath] = $mapIndex === null ? null : $mapPayloads[$mapIndex];
                $after[$mapPath] = $map;
            }

            return ['plan' => new SourceSetPlan(
                $root,
                $watched,
                $originals,
                $proposals,
                $before,
                $after,
                // A map the plan creates is its own change, not part of the family it read.
                static fn(): array => array_values(array_diff([$actorPath, ...(glob($root . '/assets/' . MasteryCatalog::DIRECTORY . '/*.php') ?: [])], [$created])),
                static fn(string $path, string $staged): mixed => PhpDataFile::getComparableValue(PhpDataFile::evaluateIsolated($staged, $root)),
                sprintf('move of %s\'s skills onto their Mastery Map', $actorId),
                'The actor and Mastery Map files',
            ), 'nodes' => array_column($nodes, 'skill')];
        });
    }
}
