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
 * enemy uses, what a skill or state counters with, what a node of the
 * mastery tree teaches. Retiring a skill into another moves every one of these to the
 * replacement in their own source, and saves that knew the old skill read it
 * as the replacement.
 *
 * @package Ichiloto\Editor\Database
 */
final class SkillReferences
{
    /** The data files that name skills, relative to the project root. */
    private const array SINGLE_FILES = ['assets/Data/classes.php', 'assets/Data/states.php', 'assets/Data/mastery.php'];
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
     * Plans retiring a skill. With a replacement, every reference moves onto
     * it, a list that already holds the replacement drops the old entry
     * instead of holding the skill twice, and saves that knew the old skill
     * read the replacement. Without one, every reference goes: a list member,
     * the list entry it belongs to (a class grant, a learnable, an enemy
     * action), a counter, or an attack skill (the built-in attack takes over);
     * saves that knew the skill drop it, as the Engine drops any skill it no
     * longer has.
     *
     * @param bool $isMagic Whether the skills are spells (saves keep spells and abilities apart).
     * @return SourceSetPlan|null The plan, or null when nothing names the skill and saves need nothing.
     * @throws RuntimeException When a reference is not a literal the plan can rewrite, or saves already send the skill elsewhere.
     */
    public static function planRetirement(string $projectRoot, string $projectId, string $skill, ?string $replacement, bool $isMagic): ?SourceSetPlan
    {
        return ProjectDirectoryContext::run($projectRoot, static function (string $root) use ($projectId, $skill, $replacement, $isMagic): ?SourceSetPlan {
            $paths = self::findSourcePaths($root);
            $payloads = array_map(PhpDataFile::getComparableValue(...), PhpDataFile::evaluateIsolatedFiles($paths, $root));
            $watched = $originals = $proposals = $before = $after = [];

            foreach ($paths as $index => $path) {
                $source = (string) file_get_contents($path);
                $watched[$path] = $source;
                $payload = $payloads[$index] ?? null;
                [$edits, $moved] = self::planFile($source, $payload, $skill, $replacement);
                if ($edits === []) {
                    continue;
                }
                $originals[$path] = $source;
                $proposals[$path] = PhpArraySourceDocument::parse($source)->withEdits($edits)->source;
                $before[$path] = $payload;
                $after[$path] = $moved;
            }

            $subject = $replacement === null ? sprintf('retirement of %s', $skill) : sprintf('retirement of %s into %s', $skill, $replacement);
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
                    $subject,
                    'Project class, actor, enemy, skill and state files',
                );
            }
            $alias = $replacement === null ? null : SaveManifestAlias::plan($root, $projectId,
                $isMagic ? ContentReferenceCategory::SPELL : ContentReferenceCategory::ABILITY, $skill, $replacement);
            if ($alias !== null) {
                $plans[] = $alias;
            }

            return match (count($plans)) {
                0 => null,
                1 => $plans[0],
                default => SourceSetPlan::combine($subject, 'Project skill references and save compatibility manifest', $plans),
            };
        });
    }

    /**
     * Plans one file's part of a retirement: the source edits, and the value the file reads back as afterwards.
     *
     * @return array{0: list<array{0: int, 1: int, 2: string}>, 1: mixed}
     */
    private static function planFile(string $source, mixed $payload, string $skill, ?string $replacement): array
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
            $removal = self::findRemovalPath($reference);
            $list = array_slice($removal, 0, -1);
            $position = end($removal);
            $holdsReplacement = $replacement !== null && is_int($position) && array_any($references, static fn(array $other): bool =>
                $other['skill'] === $replacement && count($other['path']) > count($list)
                && array_slice($other['path'], 0, count($list)) === $list && $other['path'][count($list)] !== $position);
            if ($replacement === null || $holdsReplacement) {
                $removals[implode("\0", $removal)] = $removal;
                continue;
            }
            $edits[] = $document->replaceValueEdit($path, var_export($replacement, true));
            $payload = self::withValue($payload, $path, $replacement);
        }
        // Within a list, later members go first, so each removal leaves the positions the others name in place.
        $ordered = array_values($removals);
        usort($ordered, static fn(array $a, array $b): int =>
            [implode("\0", array_slice($a, 0, -1)), end($b)] <=> [implode("\0", array_slice($b, 0, -1)), end($a)]);
        foreach ($ordered as $member) {
            $edits[] = $document->removeEntryEdit($member);
            $payload = self::withoutMember($payload, $member);
        }

        return [$edits, $payload];
    }

    /**
     * What goes when a reference goes: the list member itself, the list entry
     * a `skill` field belongs to, the whole counter a counter skill names, or
     * the attack skill field.
     *
     * @param array{path: list<int|string>, skill: string, inList: bool} $reference
     * @return list<int|string>
     */
    private static function findRemovalPath(array $reference): array
    {
        $path = $reference['path'];
        if ($reference['inList'] || end($path) === 'attackSkill') {
            return $path;
        }
        $owner = array_slice($path, 0, -1);

        return is_int(end($owner)) || end($owner) === 'counterAttack' ? $owner : $path;
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
