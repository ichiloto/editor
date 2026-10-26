<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\Storage\SourceSetPlan;
use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeKind;
use RuntimeException;

/**
 * Moves a character's own skills from their actor file onto their Mastery
 * Map, so the map is the one place they are learned. Each skill the actor
 * file says they know gets terms known from the start; each learnable gets
 * terms carrying the EXP its requirement asked for as its cost, for the
 * author to reprice in Mastery points (SP); skills their role teaches stay
 * with the role. The terms are for the tree node that teaches the skill; a
 * skill the tree does not place yet gets a node on the first region's trunk,
 * for the author to place on a branch. New terms follow the map's own. The
 * actor's learned lists and learnables are then emptied. Saves are
 * untouched: a character keeps what a save says they know.
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
            $catalog = MasteryCatalog::load($root . '/assets');
            $regions = $catalog->regions;
            if ($regions === []) {
                throw new RuntimeException('Declare the Mastery regions first: a node needs a region to sit in.');
            }
            $actorPaths = glob($root . '/assets/Data/Actors/*.php') ?: [];
            $mapPaths = glob($root . '/assets/' . MasteryCatalog::DIRECTORY . '/*.php') ?: [];
            $classesPath = $root . '/assets/Data/classes.php';
            $settingsPath = $root . '/assets/' . MasteryCatalog::SETTINGS;
            $payloads = array_map(PhpDataFile::getComparableValue(...),
                PhpDataFile::evaluateIsolatedFiles([...$actorPaths, ...$mapPaths, $settingsPath, ...(is_file($classesPath) ? [$classesPath] : [])], $root));
            $actorPayloads = array_slice($payloads, 0, count($actorPaths));
            $mapPayloads = array_slice($payloads, count($actorPaths), count($mapPaths));
            $settings = (array) $payloads[count($actorPaths) + count($mapPaths)];
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
            $onMap = array_map(static fn(array $node): string => strval($node['id'] ?? ''), (array) ($map['nodes'] ?? []));
            $ids = array_keys($catalog->tree->nodes);
            $upgradedSettings = $settings;
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
            $placed = [];
            foreach ($added as ['skill' => $skill, 'cost' => $cost]) {
                $id = $catalog->tree->findSkillNode($skill)?->id ?? $placed[$skill] ?? null;
                if ($skill === '' || in_array($skill, $taught, true) || ($id !== null && in_array($id, $onMap, true))) {
                    continue;
                }
                if ($id === null) {
                    $id = $placed[$skill] = Slug::unique($skill, $ids, 'node');
                    $ids[] = $id;
                    $upgradedSettings['regions'][0]['nodes'][] = ['id' => $id, 'kind' => MasteryNodeKind::APPLICATION->value, 'skill' => $skill];
                }
                $onMap[] = $id;
                $map['nodes'][] = ['id' => $id, 'cost' => $cost, 'opensAtLevel' => 1];
                $nodes[] = $skill;
            }

            $actorSource = (string) file_get_contents($actorPath);
            $watched = [$actorPath => $actorSource, $settingsPath => (string) file_get_contents($settingsPath)];
            foreach ($mapPaths as $path) {
                $watched[$path] = (string) file_get_contents($path);
            }
            $originals = [$actorPath => $actorSource];
            $proposals = [$actorPath => ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($actorSource), $actor, $emptied)->source];
            $before = [$actorPath => $actor];
            $after = [$actorPath => $emptied];
            $created = null;
            if ($upgradedSettings !== $settings) {
                $originals[$settingsPath] = $watched[$settingsPath];
                $proposals[$settingsPath] = ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($watched[$settingsPath]), $settings, $upgradedSettings)->source;
                $before[$settingsPath] = $settings;
                $after[$settingsPath] = $upgradedSettings;
            }
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
                static fn(): array => array_values(array_diff([$actorPath, $settingsPath, ...(glob($root . '/assets/' . MasteryCatalog::DIRECTORY . '/*.php') ?: [])], [$created])),
                static fn(string $path, string $staged): mixed => PhpDataFile::getComparableValue(PhpDataFile::evaluateIsolated($staged, $root)),
                sprintf('move of %s\'s skills onto their Mastery Map', $actorId),
                'The actor, mastery tree and Mastery Map files',
            ), 'nodes' => $nodes];
        });
    }
}
