<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Progression;

use Ichiloto\Engine\Core\WorldConditionExclusion;
use Ichiloto\Engine\Core\WorldConditionType;
use Ichiloto\Engine\Progression\WorldAbilityCatalog;
use Ichiloto\Engine\Util\Config\ConfigStore;
use RuntimeException;
use Throwable;

/**
 * The exploration powers a project declares in `assets/Data/world-abilities.php`,
 * read through the Engine's own {@see WorldAbilityCatalog::fromProject()}.
 *
 * The project source owns every ability; the Editor keeps no copy. Each read
 * takes the file as it stands, so a picker, a validation run and a condition
 * evaluation all see the same current definitions. A file the Engine refuses
 * leaves no catalogue and records why, for validation to report: the game
 * refuses to start on it too.
 *
 * Condition evaluation the Editor runs through the Engine (reachability,
 * previews) reads the catalogue the application installed, as the game's own
 * boot installs it. {@see activate()} is how the Editor installs the open
 * project's, and removes a previous project's when this one cannot be read.
 *
 * @package Ichiloto\Editor\Progression
 */
final readonly class ProjectWorldAbilities
{
    /** The source file, relative to the project root. */
    public const string RELATIVE_PATH = 'assets/' . WorldAbilityCatalog::FILE;

    /**
     * @param WorldAbilityCatalog|null $catalog The declared abilities; null when the source cannot be read.
     * @param string|null $loadProblem Why the source cannot be read, when it cannot.
     */
    private function __construct(
        public ?WorldAbilityCatalog $catalog,
        public ?string $loadProblem,
    ) {
    }

    /**
     * Reads a project's current world abilities. A project without the file declares none.
     */
    public static function fromProject(string $projectRoot): self
    {
        try {
            return new self(WorldAbilityCatalog::fromProject(rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'assets'), null);
        } catch (Throwable $error) {
            return new self(null, $error->getMessage());
        }
    }

    /**
     * Reads a project's current world abilities and installs them as the
     * catalogue Engine condition evaluation reads.
     */
    public static function installFromProject(string $projectRoot): self
    {
        $abilities = self::fromProject($projectRoot);
        $abilities->activate();

        return $abilities;
    }

    /**
     * Makes this the catalogue the Engine evaluates `world_ability`
     * conditions against, replacing any earlier project's. A source that
     * cannot be read installs nothing, so no condition is ever answered by
     * another project's abilities.
     */
    public function activate(): void
    {
        if ($this->catalog instanceof WorldAbilityCatalog) {
            ConfigStore::put(WorldAbilityCatalog::class, $this->catalog);

            return;
        }

        ConfigStore::remove(WorldAbilityCatalog::class);
    }

    /**
     * Refuses an evaluation that needs the abilities when the source cannot be read.
     *
     * @throws RuntimeException Naming the source and why the Engine refuses it.
     */
    public function assertReadable(): void
    {
        if ($this->loadProblem !== null) {
            throw new RuntimeException(sprintf('The project\'s world abilities in %s could not be read: %s', self::RELATIVE_PATH, $this->loadProblem));
        }
    }

    /** Whether the project declares the ability. */
    public function hasAbility(string $id): bool
    {
        return $this->catalog?->has(trim($id)) ?? false;
    }

    /**
     * The declared abilities for a picker, in authored order: label by stable id.
     *
     * @return array<string, string>
     */
    public function getLabels(): array
    {
        $labels = [];
        foreach ($this->catalog?->getAbilities() ?? [] as $ability) {
            $labels[$ability->id] = $ability->name === $ability->id ? $ability->id : sprintf('%s (%s)', $ability->name, $ability->id);
        }

        return $labels;
    }

    /**
     * Determines whether two condition lists can never both hold, judged
     * with these abilities ({@see WorldConditionExclusion::proveExclusive()}).
     *
     * A `world_ability` condition naming an ability the project does not
     * declare, or any while the source cannot be read, is one the Engine
     * refuses, so it proves nothing; validation reports the reference itself.
     *
     * @param array<int|string, mixed> $conditions One list.
     * @param array<int|string, mixed> $others The other list.
     */
    public function proveExclusive(array $conditions, array $others): bool
    {
        foreach ([...array_values($conditions), ...array_values($others)] as $condition) {
            if (is_array($condition) && is_string($condition['type'] ?? null)
                && trim($condition['type']) === WorldConditionType::WORLD_ABILITY->value
                && is_string($condition['name'] ?? null) && trim($condition['name']) !== ''
                && ! $this->hasAbility($condition['name'])) {
                return false;
            }
        }

        return WorldConditionExclusion::proveExclusive($conditions, $others, $this->catalog);
    }
}
