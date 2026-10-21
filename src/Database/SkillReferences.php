<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Maps\SaveManifestAlias;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\Storage\SourceSetPlan;
use Ichiloto\Engine\IO\SaveCompatibility\ContentReferenceCategory;
use RuntimeException;

/**
 * Where a project names a skill by its identity (its name): what a class
 * teaches, what an actor knows, can learn, attacks or counters with, what an
 * enemy uses, what a skill or state counters with. Retiring a skill into
 * another moves every one of these to the replacement in their own source,
 * and saves that knew the old skill read it as the replacement.
 *
 * @package Ichiloto\Editor\Database
 */
final class SkillReferences
{
    /** The data files that name skills, relative to the project root. */
    private const array SINGLE_FILES = ['assets/Data/classes.php', 'assets/Data/states.php'];
    /** The folders of one-record files that name skills. */
    private const array FOLDERS = ['assets/Data/Actors', 'assets/Data/Enemies', 'assets/Data/Skills'];

    private function __construct()
    {
    }

    /**
     * Finds every place a data payload names a skill.
     *
     * @param mixed $payload A data file or record as the Engine reads it.
     * @return list<array{path: list<int|string>, skill: string, inList: bool}> Each reference: its path, the skill
     * it names, and whether it is a member of a list of skill names (an actor's learned list) rather than a field.
     */
    public static function findReferences(mixed $payload): array
    {
        $found = [];
        self::walk($payload, [], $found);

        return $found;
    }

    /**
     * Describes what names a skill in the given records, in words.
     *
     * @param iterable<string, mixed> $records Each record by a label naming it.
     * @return list<string>
     */
    public static function describe(iterable $records, string $skill): array
    {
        $uses = [];
        foreach ($records as $label => $payload) {
            foreach (self::findReferences($payload) as $reference) {
                if ($reference['skill'] === $skill) {
                    $uses[] = sprintf('%s (%s)', $label, implode('.', $reference['path']));
                }
            }
        }

        return $uses;
    }

    /**
     * Plans moving every reference to a skill onto its replacement, with the
     * save alias that lets saves which knew the old skill read the
     * replacement. A list that already holds the replacement drops the old
     * entry instead of holding the skill twice.
     *
     * @param bool $isMagic Whether both skills are spells (saves keep spells and abilities apart).
     * @return SourceSetPlan|null The plan, or null when nothing names the skill and saves already follow it.
     * @throws RuntimeException When a reference is not a literal the plan can rewrite, or saves already send the skill elsewhere.
     */
    public static function planRedirect(string $projectRoot, string $projectId, string $skill, string $replacement, bool $isMagic): ?SourceSetPlan
    {
        return ProjectDirectoryContext::run($projectRoot, static function (string $root) use ($projectId, $skill, $replacement, $isMagic): ?SourceSetPlan {
            $paths = self::findSourcePaths($root);
            $payloads = array_map(PhpDataFile::getComparableValue(...), PhpDataFile::evaluateIsolatedFiles($paths, $root));
            $watched = $originals = $proposals = $before = $after = [];

            foreach ($paths as $index => $path) {
                $source = (string) file_get_contents($path);
                $watched[$path] = $source;
                $payload = $payloads[$index] ?? null;
                [$edits, $moved] = self::planMove($source, $payload, $skill, $replacement);
                if ($edits === []) {
                    continue;
                }
                $originals[$path] = $source;
                $proposals[$path] = PhpArraySourceDocument::parse($source)->withEdits($edits)->source;
                $before[$path] = $payload;
                $after[$path] = $moved;
            }

            $plans = [];
            if ($proposals !== []) {
                $plans[] = new SourceSetPlan(
                    $root,
                    $watched,
                    $originals,
                    $proposals,
                    $before,
                    $after,
                    static fn(): array => self::findSourcePaths($root),
                    static fn(string $path, string $staged): mixed => PhpDataFile::getComparableValue(PhpDataFile::evaluateIsolated($staged, $root)),
                    sprintf('move of %s onto %s', $skill, $replacement),
                    'Project class, actor, enemy, skill and state files',
                );
            }
            $alias = SaveManifestAlias::plan($root, $projectId, $isMagic ? ContentReferenceCategory::SPELL : ContentReferenceCategory::ABILITY,
                $skill, $replacement);
            if ($alias !== null) {
                $plans[] = $alias;
            }

            return match (count($plans)) {
                0 => null,
                1 => $plans[0],
                default => SourceSetPlan::combine(sprintf('retirement of %s into %s', $skill, $replacement),
                    'Project skill references and save compatibility manifest', $plans),
            };
        });
    }

    /**
     * Plans one file's move: the source edits, and the value the file reads back as afterwards.
     *
     * @return array{0: list<array{0: int, 1: int, 2: string}>, 1: mixed}
     */
    private static function planMove(string $source, mixed $payload, string $skill, string $replacement): array
    {
        $references = self::findReferences($payload);
        $mine = array_values(array_filter($references, static fn(array $reference): bool => $reference['skill'] === $skill));
        if ($mine === []) {
            return [[], $payload];
        }
        $document = PhpArraySourceDocument::parse($source);
        $edits = [];
        $removals = [];
        foreach ($mine as $reference) {
            $path = $reference['path'];
            // The list member the reference is, or the list entry (a class's grant, an actor's learnable) it belongs to.
            $member = $reference['inList'] ? $path : array_slice($path, 0, -1);
            $list = array_slice($member, 0, -1);
            $position = end($member);
            $holdsReplacement = is_int($position) && array_any($references, static fn(array $other): bool =>
                $other['skill'] === $replacement && count($other['path']) > count($list)
                && array_slice($other['path'], 0, count($list)) === $list && $other['path'][count($list)] !== $position);
            if ($holdsReplacement) {
                $removals[implode("\0", $member)] = $member;
                continue;
            }
            $edits[] = $document->replaceValueEdit($path, var_export($replacement, true));
            $payload = self::withValue($payload, $path, $replacement);
        }
        // Later members first, so each removal leaves the earlier positions it names in place.
        $ordered = array_values($removals);
        usort($ordered, static fn(array $a, array $b): int => end($b) <=> end($a));
        foreach ($ordered as $member) {
            $edits[] = $document->removeEntryEdit($member);
            $payload = self::withoutMember($payload, $member);
        }

        return [$edits, $payload];
    }

    /** @param list<int|string> $path */
    private static function withValue(mixed $value, array $path, string $replacement): mixed
    {
        if ($path === []) {
            return $replacement;
        }
        $key = array_shift($path);
        $value[$key] = self::withValue($value[$key], $path, $replacement);

        return $value;
    }

    /** @param list<int|string> $path A list member's path; the list closes up behind it. */
    private static function withoutMember(mixed $value, array $path): mixed
    {
        $key = array_shift($path);
        if ($path === []) {
            $wasList = array_is_list($value);
            unset($value[$key]);

            return $wasList ? array_values($value) : $value;
        }
        $value[$key] = self::withoutMember($value[$key], $path);

        return $value;
    }

    /** @param list<array{path: list<int|string>, skill: string, inList: bool}> $found */
    private static function walk(mixed $value, array $path, array &$found): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            $childPath = [...$path, $key];
            if (($key === 'skill' || $key === 'attackSkill') && is_string($child) && trim($child) !== '') {
                $found[] = ['path' => $childPath, 'skill' => $child, 'inList' => false];
                continue;
            }
            if ($key === 'learned' && in_array(end($path), ['abilities', 'magic'], true) && is_array($child)) {
                foreach ($child as $index => $name) {
                    if (is_string($name) && trim($name) !== '') {
                        $found[] = ['path' => [...$childPath, $index], 'skill' => $name, 'inList' => true];
                    }
                }
                continue;
            }
            self::walk($child, $childPath, $found);
        }
    }

    /** @return list<string> Every file that may name a skill, as it is on disk now. */
    private static function findSourcePaths(string $root): array
    {
        $paths = [];
        foreach (self::SINGLE_FILES as $relative) {
            if (is_file($root . '/' . $relative)) {
                $paths[] = $root . '/' . $relative;
            }
        }
        foreach (self::FOLDERS as $relative) {
            foreach (glob($root . '/' . $relative . '/*.php') ?: [] as $path) {
                $paths[] = $path;
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }
}
