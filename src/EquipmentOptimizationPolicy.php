<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Throwable;

/**
 * What Optimize would actually choose, and why.
 *
 * The runtime picks a party member's gear through a project-declared policy:
 * weights by stat, narrowed by role, by the kind of slot being filled, and by
 * the two together, plus what an elemental outcome or a special property is
 * worth and what is excluded from automatic selection altogether. A project
 * that declares none of this does not get "no policy" -- it gets the engine's
 * legacy equal-weight sum, which is a compatibility fallback and says nothing
 * about what this project wants.
 *
 * The scoring is the engine's own `DeclaredEquipmentOptimizationPolicy`, run
 * over the project's real definitions, and what is shown is the component map
 * that policy returns. Nothing here reimplements a score, and nothing here
 * writes: a preview reads the project and leaves it as it was.
 *
 * @package Ichiloto\Editor
 */
final class EquipmentOptimizationPolicy
{
    /**
     * Where a project declares its policy.
     */
    public const string RELATIVE_PATH = 'assets/Data/equipment-optimization.php';

    /**
     * The keys the engine's policy constructor takes, in its own order.
     */
    public const array KEYS = [
        'statWeights',
        'roleStatWeights',
        'slotStatWeights',
        'roleSlotStatWeights',
        'elementOutcomeWeights',
        'specialPropertyWeights',
        'excludedDefinitionIds',
        'excludedAvailabilities',
        'excludedAcquisitionPolicies',
    ];

    /**
     * What an element affinity can come to, as the engine names the outcomes
     * it derives from a multiplier.
     */
    public const array OUTCOMES = ['weak', 'resist', 'null', 'absorb', 'neutral'];

    /**
     * The element every defensive outcome matches, whichever element it is.
     */
    public const string ANY_ELEMENT = '*';

    /**
     * Returns the stat keys a weight may be declared for.
     *
     * Accuracy and critical are weighable beside the canonical stats because
     * a piece of equipment carries them, even though neither is a resolved
     * stat layer.
     *
     * @return string[] The keys.
     */
    public static function weightKeys(): array
    {
        return [...ActorStatPreview::statKeys(), 'accuracy', 'critical'];
    }

    /**
     * Returns the kinds of slot a policy can be narrowed to.
     *
     * @return string[] The semantic slot values.
     */
    public static function slotKeys(): array
    {
        if (enum_exists(\Ichiloto\Engine\Entities\Inventory\EquipmentSlotType::class)) {
            return array_map(
                static fn(\Ichiloto\Engine\Entities\Inventory\EquipmentSlotType $slot): string => $slot->value,
                \Ichiloto\Engine\Entities\Inventory\EquipmentSlotType::cases(),
            );
        }

        return ['weapon', 'shield', 'head', 'body', 'accessory'];
    }

    /**
     * Returns whether the engine's policy is reachable, and so whether a
     * preview can be scored at all.
     *
     * @return bool True when it is.
     */
    public static function isAvailable(): bool
    {
        return class_exists(\Ichiloto\Engine\Entities\EquipmentOptimization\DeclaredEquipmentOptimizationPolicy::class)
            && class_exists(\Ichiloto\Engine\Entities\EquipmentOptimization\LegacyEqualWeightEquipmentOptimizationPolicy::class);
    }

    /**
     * Returns the policy a project declares, or null when it declares none.
     *
     * @param string $projectRoot The project root.
     * @return array<string, mixed>|null The authored payload.
     */
    public static function declaredIn(string $projectRoot): ?array
    {
        $path = rtrim($projectRoot, '/') . '/' . self::RELATIVE_PATH;

        if (! is_file($path)) {
            return null;
        }

        try {
            $payload = (static fn(): mixed => require $path)();
        } catch (Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * Returns the engine policy a project's Optimize would actually run.
     *
     * A project that declares nothing runs the legacy fallback, which is what
     * the engine's own registry does; a preview that quietly showed declared
     * numbers instead would be describing a policy the game does not have.
     *
     * @param string $projectRoot The project root.
     * @return object|null The engine policy, or null when it cannot be built.
     */
    public static function enginePolicyFor(string $projectRoot): ?object
    {
        if (! self::isAvailable()) {
            return null;
        }

        $declared = self::declaredIn($projectRoot);

        if ($declared === null) {
            return new \Ichiloto\Engine\Entities\EquipmentOptimization\LegacyEqualWeightEquipmentOptimizationPolicy();
        }

        try {
            return new \Ichiloto\Engine\Entities\EquipmentOptimization\DeclaredEquipmentOptimizationPolicy(
                statWeights: self::mapOf($declared, 'statWeights'),
                roleStatWeights: self::mapOf($declared, 'roleStatWeights'),
                slotStatWeights: self::mapOf($declared, 'slotStatWeights'),
                roleSlotStatWeights: self::mapOf($declared, 'roleSlotStatWeights'),
                elementOutcomeWeights: self::mapOf($declared, 'elementOutcomeWeights'),
                specialPropertyWeights: self::mapOf($declared, 'specialPropertyWeights'),
                excludedDefinitionIds: self::listOf($declared, 'excludedDefinitionIds'),
                excludedAvailabilities: self::listOf($declared, 'excludedAvailabilities'),
                excludedAcquisitionPolicies: self::listOf($declared, 'excludedAcquisitionPolicies'),
            );
        } catch (Throwable) {
            // A policy the engine refuses to build is reported by validation
            // in the engine's own words; a preview simply has nothing to show.
            return null;
        }
    }

    /**
     * Returns whether a project has declared a policy of its own.
     *
     * @param string $projectRoot The project root.
     * @return bool True when it has.
     */
    public static function isDeclaredIn(string $projectRoot): bool
    {
        return self::declaredIn($projectRoot) !== null;
    }

    /**
     * Describes which policy a preview is showing, naming the fallback for
     * what it is.
     *
     * @param string $projectRoot The project root.
     * @return string The description.
     */
    public static function describeSource(string $projectRoot): string
    {
        if (self::isDeclaredIn($projectRoot)) {
            return sprintf('declared by this project · %s', self::RELATIVE_PATH);
        }

        return sprintf(
            'legacy equal weight · a compatibility fallback for projects that declare none, not this project\'s policy · declare one in %s',
            self::RELATIVE_PATH,
        );
    }

    /**
     * Scores every candidate a slot could be filled with, best first.
     *
     * @param ProjectWorkspace $workspace The project.
     * @param ProjectActor $actor The party member the gear is for.
     * @param string $semanticSlot The kind of slot being filled.
     * @return array<int, array<string, mixed>> One row per candidate.
     */
    public static function rank(ProjectWorkspace $workspace, ProjectActor $actor, string $semanticSlot): array
    {
        $policy = self::enginePolicyFor($workspace->projectRoot);

        if ($policy === null || ! class_exists(\Ichiloto\Engine\Entities\EquipmentSlot::class)) {
            return [];
        }

        $character = self::characterFor($workspace, $actor);

        if ($character === null) {
            return [];
        }

        $slotType = \Ichiloto\Engine\Entities\Inventory\EquipmentSlotType::tryFrom($semanticSlot);

        if ($slotType === null) {
            return [];
        }

        $slot = new \Ichiloto\Engine\Entities\EquipmentSlot(
            $semanticSlot,
            '',
            '',
            \Ichiloto\Engine\Entities\Inventory\Equipment::class,
            $slotType,
        );
        $rows = [];

        foreach (self::candidates($workspace) as $equipment) {
            if ($equipment->semanticSlot !== $slotType) {
                continue;
            }

            try {
                $score = $policy->score($character, $slot, $equipment);
            } catch (Throwable) {
                continue;
            }

            $rows[] = [
                'id' => $equipment->id,
                'name' => $equipment->name,
                // A policy returns null for a candidate the project excludes
                // from automatic selection, which is a different thing from
                // scoring zero and reads as one.
                'excluded' => $score === null,
                'value' => $score?->value ?? 0,
                'components' => $score?->components ?? [],
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return [$a['excluded'], $b['value']] <=> [$b['excluded'], $a['value']];
        });

        return $rows;
    }

    /**
     * Describes one ranked candidate the way a settings pane should show it.
     *
     * @param array<string, mixed> $row One row from rank().
     * @return string The description.
     */
    public static function describeRow(array $row): string
    {
        if ($row['excluded'] === true) {
            return 'excluded from automatic selection by this project';
        }

        $components = [];

        /** @var array<string, int> $map */
        $map = is_array($row['components'] ?? null) ? $row['components'] : [];

        foreach ($map as $name => $contribution) {
            $components[] = sprintf('%s %+d', $name, $contribution);
        }

        return $components === []
            ? 'nothing this policy weighs'
            : implode(', ', $components);
    }

    /**
     * Returns the project's equipment definitions.
     *
     * @param ProjectWorkspace $workspace The project.
     * @return array<int, object> The equipment.
     */
    private static function candidates(ProjectWorkspace $workspace): array
    {
        if (! class_exists(\Ichiloto\Engine\Entities\Inventory\Equipment::class)) {
            return [];
        }

        $candidates = [];

        foreach (['weapons', 'armors'] as $category) {
            $database = $workspace->getRecordDatabase($category);

            if (! $database instanceof ProjectRecordDatabase) {
                continue;
            }

            foreach ($database->getRecords() as $record) {
                $payload = $record->toArray();

                if ($payload instanceof \Ichiloto\Engine\Entities\Inventory\Equipment) {
                    $candidates[] = $payload;
                }
            }
        }

        return $candidates;
    }

    /**
     * The stats a character cannot be built without.
     *
     * A policy scores a piece of equipment against a role, and reads nothing
     * else about the character. An actor mid-authoring rarely has a whole
     * stat block yet, so the ones it has are laid over zeroes: the numbers
     * make no difference to a score, and refusing to preview until a stat
     * block is finished would make the preview useless exactly when it is
     * most wanted.
     */
    private const array REQUIRED_STATS = [
        'currentHp' => 0,
        'currentMp' => 0,
        'currentAp' => 0,
        'attack' => 0,
        'defence' => 0,
        'magicAttack' => 0,
        'magicDefence' => 0,
    ];

    /**
     * Builds the engine character a policy scores against.
     *
     * A policy reads one thing about a character -- the name of its role --
     * and a real character is what the engine's contract takes, so one is
     * built rather than a stand-in that might diverge from it. The role is
     * named after the actor's class directly instead of resolved through the
     * class store, because the store's miss path writes a warning into the
     * project's debug log, and a preview must not leave anything behind.
     *
     * @param ProjectWorkspace $workspace The project.
     * @param ProjectActor $actor The actor.
     * @return object|null The character, or null when the project cannot build one.
     */
    private static function characterFor(ProjectWorkspace $workspace, ProjectActor $actor): ?object
    {
        if (! class_exists(\Ichiloto\Engine\Entities\Character::class)
            || ! class_exists(\Ichiloto\Engine\Entities\Roles\CharacterRole::class)) {
            return null;
        }

        try {
            return ProjectDirectoryContext::run(
                $workspace->projectRoot,
                static function () use ($actor): mixed {
                    $character = \Ichiloto\Engine\Entities\Character::fromArray([
                        'name' => $actor->getName(),
                        'currentExp' => 0,
                        'stats' => [...self::REQUIRED_STATS, ...$actor->getStats()],
                    ]);
                    $character->role = new \Ichiloto\Engine\Entities\Roles\CharacterRole(
                        $character,
                        $actor->getClassName(),
                    );

                    return $character;
                },
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Reads one nested map out of an authored payload.
     *
     * @param array<string, mixed> $payload The payload.
     * @param string $key The key.
     * @return array<string, mixed> The map.
     */
    private static function mapOf(array $payload, string $key): array
    {
        return is_array($payload[$key] ?? null) ? $payload[$key] : [];
    }

    /**
     * Reads one list of names out of an authored payload.
     *
     * @param array<string, mixed> $payload The payload.
     * @param string $key The key.
     * @return string[] The names.
     */
    private static function listOf(array $payload, string $key): array
    {
        $values = is_array($payload[$key] ?? null) ? $payload[$key] : [];

        return array_values(array_map(
            static fn(mixed $value): string => is_scalar($value) ? strval($value) : '',
            $values,
        ));
    }
}
