<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * The rules an actor's starting summon assignments are held to, shared by
 * the project validator and the actor record pane so both say the same
 * thing: a summon must exist; the actor must be eligible under the summon's
 * wielder policy (by character, by role, or open to all); a story-locked
 * summon cannot be a starting assignment; each id appears once; and an
 * exclusive summon has at most one starting holder across the cast.
 *
 * @package Ichiloto\Editor\Database
 */
final class SummonAssignmentDiagnostics
{
    /**
     * @param array<string, array<string, mixed>> $definitions Summon data arrays by lowercase id.
     */
    public function __construct(private readonly array $definitions)
    {
    }

    /**
     * Builds the diagnostics over every summon folder in a project.
     *
     * @param \Ichiloto\Editor\Cutscenes\CutsceneLibrary|null $library
     */
    public static function fromLibrary(?\Ichiloto\Editor\Cutscenes\CutsceneLibrary $library): self
    {
        $definitions = [];

        foreach ($library?->assets(\Ichiloto\Editor\Cutscenes\CutsceneType::SUMMON) ?? [] as $asset) {
            if ($asset->isDeleted()) {
                continue;
            }

            $definitions[strtolower($asset->id)] = $asset->data();
        }

        return new self($definitions);
    }

    /**
     * The ids the diagnostics know, in authored case.
     *
     * @return string[]
     */
    public function ids(): array
    {
        $ids = [];

        foreach ($this->definitions as $key => $definition) {
            $ids[] = is_string($definition['id'] ?? null) && trim($definition['id']) !== '' ? trim($definition['id']) : (string) $key;
        }

        return $ids;
    }

    /**
     * Judges one actor's assignments.
     *
     * @param mixed $assignments The actor's `summons` value as authored.
     * @return array<int, array{id: string, problems: array<int, array{message: string, hint: string}>}> One row per assignment, in order.
     */
    public function forActor(string $actorName, string $className, mixed $assignments): array
    {
        if (! is_array($assignments) || ! array_is_list($assignments)) {
            return [['id' => '', 'problems' => [['message' => 'Its summon assignments are malformed.', 'hint' => 'Use a list of summon ids.']]]];
        }

        $rows = [];
        $seen = [];

        foreach ($assignments as $assignment) {
            $summonId = is_string($assignment) ? trim($assignment) : '';
            $normalized = strtolower($summonId);
            $problems = [];

            if ($summonId === '') {
                $rows[] = ['id' => '', 'problems' => [['message' => 'It references missing summon "(malformed)".', 'hint' => 'Use an authored summon id.']]];

                continue;
            }

            if (isset($seen[$normalized])) {
                $problems[] = ['message' => 'Its summon assignments contain duplicate ids.', 'hint' => 'List each starting summon at most once.'];
            }

            $seen[$normalized] = true;
            $definition = $this->definitions[$normalized] ?? null;

            if ($definition === null) {
                $problems[] = ['message' => sprintf('It references missing summon "%s".', $summonId), 'hint' => 'Use an authored summon id.'];
                $rows[] = ['id' => $summonId, 'problems' => $problems];

                continue;
            }

            if (! $this->isEligible($definition, $actorName, $className)) {
                $problems[] = ['message' => sprintf('It is not eligible to hold summon "%s".', $summonId), 'hint' => 'Change the actor assignment or the generic wielder policy.'];
            }

            if ($this->isStoryLocked($definition)) {
                $problems[] = ['message' => sprintf('It starts with story-locked summon "%s".', $summonId), 'hint' => 'Remove the starting assignment; preserve legal assignments only in saves after unlock.'];
            }

            $rows[] = ['id' => $summonId, 'problems' => $problems];
        }

        return $rows;
    }

    /**
     * Whether the actor may hold the summon under its wielder policy.
     *
     * @param array<string, mixed> $definition
     */
    public function isEligible(array $definition, string $actorName, string $className): bool
    {
        $wielders = is_array($definition['wielders'] ?? null) ? $definition['wielders'] : null;

        if ($wielders === null) {
            return true;
        }

        $modeValue = $wielders['mode'] ?? 'all';
        $mode = is_string($modeValue) ? strtolower(trim($modeValue)) : '';

        return match ($mode) {
            'characters' => in_array($actorName, (array) ($wielders['characters'] ?? []), true),
            'roles' => in_array($className, (array) ($wielders['roles'] ?? []), true),
            'all' => true,
            default => false,
        };
    }

    /**
     * Whether the summon's availability depends on a story event.
     *
     * @param array<string, mixed> $definition
     */
    public function isStoryLocked(array $definition): bool
    {
        $conditions = is_array($definition['availability']['conditions'] ?? null) ? $definition['availability']['conditions'] : [];

        foreach ($conditions as $condition) {
            if (is_array($condition) && ($condition['type'] ?? null) === 'event') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the summon's tenancy is exclusive.
     */
    public function isExclusive(string $summonId): bool
    {
        $definition = $this->definitions[strtolower(trim($summonId))] ?? null;

        if ($definition === null || ! is_array($definition['wielders'] ?? null)) {
            return false;
        }

        $tenancy = $definition['wielders']['tenancy'] ?? 'shared';

        return is_string($tenancy) && strtolower(trim($tenancy)) === 'exclusive';
    }

    /**
     * Exclusive summons held by more than one actor at the start.
     *
     * @param array<string, string[]> $holders Actor names by lowercase summon id.
     * @return array<string, string[]> Offending summon ids (lowercase) to their holders.
     */
    public function exclusiveConflicts(array $holders): array
    {
        $conflicts = [];

        foreach ($holders as $summonId => $actorNames) {
            if ($this->isExclusive($summonId) && count($actorNames) > 1) {
                $conflicts[$summonId] = $actorNames;
            }
        }

        return $conflicts;
    }

    /**
     * A one-line verdict for one assignment, for the record pane.
     *
     * @param array{id: string, problems: array<int, array{message: string, hint: string}>} $row
     */
    public static function describe(array $row): string
    {
        if ($row['problems'] === []) {
            return '✓ ' . $row['id'];
        }

        return '✗ ' . ($row['id'] !== '' ? $row['id'] . ': ' : '') . implode(' ', array_column($row['problems'], 'message'));
    }
}
