<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Actors;

use Ichiloto\Editor\ActorStatPreview;
use Ichiloto\Editor\Database\RecordChange;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\Database\SummonAssignmentDiagnostics;
use Ichiloto\Editor\EquipmentOptimizationPolicy;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\History\SourceSetRequired;
use Ichiloto\Editor\Inspector\InputControl;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Editor\PermanentGrowthCatalog;
use Ichiloto\Editor\ProjectActor;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use RuntimeException;
use Throwable;

/**
 * How an actor is authored, for the terminal editor and the GUI alike: the
 * rows an actor shows (identity, class, attacks, summons, level, stats, its
 * nature, and previews of its resolved stats and of what Optimize would
 * choose), and what an edit to one of them does. The actor itself, with its
 * identity rules and its source-preserving save, is ProjectActor's.
 *
 * Some rows choose what the panes show rather than hold a value the project
 * stores: which natural variant the rows edit, which earned growth the stat
 * preview assumes, which slot the Optimize preview fills. Those choices are
 * this service's, per actor, and change nothing on disk.
 */
final class ActorAuthoring
{
    /**
     * The row that chooses which natural variant the actor rows edit. It is
     * a view of the pane, not a value the project stores.
     */
    public const string VARIANT_FIELD = '__actor_variant';

    /**
     * The row that chooses which permanent growth the preview assumes the
     * party has earned. Earned growth lives in a save, not in a project, so
     * this is a fixture for looking at and nothing the editor writes.
     */
    public const string GROWTH_FIELD = '__actor_growth';

    /**
     * The row that chooses which kind of slot the Optimize preview fills.
     */
    public const string OPTIMIZE_SLOT_FIELD = '__actor_optimize_slot';

    /** What the growth row reads when the preview assumes nothing was earned. */
    public const string NO_ASSUMED_GROWTH = '(none earned yet)';

    /** What the growth row reads when the preview assumes all of it was. */
    public const string ALL_ASSUMED_GROWTH = '(everything defined)';

    /** What the Attack Skill row reads when the actor uses the Engine's own attack. */
    public const string BUILT_IN_ATTACK = '(Built-in attack)';

    /** What an actor without a counter attack reads as. */
    public const string NO_COUNTER = '(no counter)';

    /** @var array<string, string> Which variant each actor's rows are editing. */
    private array $variantSelections = [];

    /** @var array<string, string> Which growth each actor's preview assumes. */
    private array $growthSelections = [];

    /** @var array<string, string> Which slot each actor's Optimize preview fills. */
    private array $optimizeSlots = [];

    /**
     * Applies one actor row: a choice of what the panes show, which records
     * nothing, or a value of the actor, as one undo step. A value the actor's
     * own rules refuse - renaming an actor that has no identity yet, an
     * attack style the Engine does not know - leaves the actor as it was.
     *
     * @param ProjectWorkspace $workspace The project.
     * @param int $index The actor.
     * @param string $field The row's field id.
     * @param string $rawValue The value as entered.
     * @param string $label The row's label, which names the undo step.
     * @throws RecordRefusal When the actor is gone or the value is refused.
     */
    public function applyField(ProjectWorkspace $workspace, int $index, string $field, string $rawValue, string $label): RecordChange
    {
        $actor = $workspace->actorDatabase->getActorByIndex($index)
            ?? throw new RecordRefusal(sprintf('actors has no record %d.', $index));

        // Choices about the panes, not values the project stores.
        if ($field === self::VARIANT_FIELD) {
            $this->variantSelections[$actor->getDefinitionId()] = trim($rawValue);

            return new RecordChange(null, $index);
        }

        if ($field === self::GROWTH_FIELD) {
            $this->growthSelections[$actor->getDefinitionId()] = trim($rawValue);

            return new RecordChange(null, $index);
        }

        if ($field === self::OPTIMIZE_SLOT_FIELD) {
            $this->optimizeSlots[$actor->getDefinitionId()] = trim($rawValue);

            return new RecordChange(null, $index);
        }

        $before = $actor->getData();

        try {
            $workspace->actorDatabase->setField($index, $field, self::coerceActorFieldValue($field, $rawValue));
        } catch (RuntimeException $refused) {
            $actor->restoreData($before);

            throw new RecordRefusal($refused->getMessage(), previous: $refused);
        }

        $after = $actor->getData();

        if ($after === $before) {
            return new RecordChange(null, $index);
        }

        return new RecordChange(new GenericCommand(
            sprintf('%s edit', $label),
            static fn() => $actor->restoreData($after),
            static fn() => $actor->restoreData($before),
        ), $index);
    }

    /**
     * Creates a blank actor at the end of the list, under a fresh identity,
     * as one undo step. Its file is written on save.
     *
     * @return RecordChange The new actor's index.
     */
    public function createActor(ProjectWorkspace $workspace): RecordChange
    {
        $database = $workspace->actorDatabase;
        $index = $database->addActor();
        $actor = $database->getActorByIndex($index)
            ?? throw new RecordRefusal('The new actor could not be created.');

        return new RecordChange(new GenericCommand(
            'Create actor',
            static fn() => $database->insertActor($index, $actor),
            static fn() => $database->removeActor($index),
        ), $index);
    }

    /**
     * Deletes an actor as one undo step. Its file goes on save, which is
     * what makes the undo honest. The change's index is the actor to select
     * next: the one before it, or null when none is left.
     *
     * @throws RecordRefusal When the actor is gone.
     */
    public function deleteActor(ProjectWorkspace $workspace, int $index): RecordChange
    {
        $database = $workspace->actorDatabase;
        $actor = $database->removeActor($index)
            ?? throw new RecordRefusal(sprintf('actors has no record %d.', $index));

        return new RecordChange(new GenericCommand(
            sprintf('Delete actor %s', $actor->getName()),
            static fn() => $database->removeActor($index),
            static fn() => $database->insertActor($index, $actor),
        ), $database->getActors() === [] ? null : max(0, $index - 1));
    }

    /**
     * Freezes the current name of an actor authored without an id as its
     * permanent id, as one undo step written on save. Later renames keep
     * that identity, so saves and references stay valid.
     *
     * @throws RecordRefusal When the actor is gone, already has an id, or its name would collide.
     */
    public function freezeIdentity(ProjectWorkspace $workspace, int $index): RecordChange
    {
        $actor = $workspace->actorDatabase->getActorByIndex($index)
            ?? throw new RecordRefusal(sprintf('actors has no record %d.', $index));
        $before = $actor->getData();

        try {
            ActorIdentityMigration::freezeCurrentName($workspace->actorDatabase, $actor);
        } catch (RuntimeException $refused) {
            $actor->restoreData($before);

            throw new RecordRefusal($refused->getMessage(), previous: $refused);
        }
        $after = $actor->getData();

        return new RecordChange(new GenericCommand(
            'Freeze actor identity',
            static fn() => $actor->restoreData($after),
            static fn() => $actor->restoreData($before),
        ), $index);
    }

    /**
     * Returns the project-wide repair an actor's identity freeze needs when
     * freezing its name is not the whole of it: the other files that name
     * actors by what the repair changes are rewritten with it, at once.
     * Returns null when the freeze alone is the repair.
     *
     * @throws RecordRefusal When the repair cannot be planned.
     */
    public function planIdentityRepair(ProjectWorkspace $workspace, ProjectActor $actor): ?ActorIdentityMigrationPlan
    {
        try {
            $plan = ActorIdentityMigration::planProject($workspace->projectRoot);
        } catch (Throwable $failure) {
            throw new RecordRefusal(sprintf('The actor identity repair could not be planned: %s', $failure->getMessage()), previous: $failure);
        }

        return $plan->getChangedPaths() === [$actor->path] ? null : $plan;
    }

    /**
     * Describes a project-wide identity repair as the file set it writes,
     * for whoever owns the workspace to confirm and write as one step.
     */
    public function describeIdentityRepair(ProjectWorkspace $workspace, ProjectActor $actor, ActorIdentityMigrationPlan $plan): SourceSetRequired
    {
        $paths = array_map(
            static fn(string $path): string => ltrim(substr($path, strlen(rtrim($workspace->projectRoot, DIRECTORY_SEPARATOR))), DIRECTORY_SEPARATOR),
            $plan->getChangedPaths(),
        );

        return new SourceSetRequired(
            $plan->getSourceSet(),
            'Migrate actor identities and references',
            'this actor migration',
            sprintf('Freeze "%s" as its permanent id, repairing actor references in %d files?', $actor->getName(), count($paths)),
            $paths,
        );
    }

    /**
     * The rows that say which actor this is to a save.
     *
     * @param ProjectActor $actor The actor.
     * @return array<int, array<string, mixed>> The rows.
     */
    private function getActorIdentityFields(ProjectActor $actor): array
    {
        $missingId = ! array_key_exists('id', $actor->getData());
        return [
            ['label' => 'Identity', 'value' => '', 'editable' => false, 'field' => ''],
            [
                'label' => 'Definition Id',
                'value' => $actor->hasDefinitionId() ? $actor->getDefinitionId() : '',
                'editable' => $missingId,
                // Without an id the row's edit is the one-time freeze of its name.
                'action' => $missingId ? sprintf('Freeze "%s" as the permanent id', $actor->getName()) : null,
                'field' => 'id',
                'displayDefault' => $missingId
                    ? sprintf('Enter to freeze the current name (%s) as its permanent id', $actor->getName())
                    : 'Malformed explicit id: correct the authored actor file; automatic replacement is not allowed.',
            ],
        ];
    }

    /**
     * The rows for an actor's own nature: the adjustments it makes to its
     * class baseline, and the named variants of that nature.
     *
     * While an actor declares variants the runtime reads the selected
     * variant's adjustments and ignores the fixed ones, so the rows edit
     * whichever set is actually in force.
     *
     * @param ProjectActor $actor The actor.
     * @return array<int, array<string, mixed>> The rows.
     */
    private function actorNatureFields(ProjectActor $actor): array
    {
        $variants = $actor->getNaturalVariants();
        $selected = $this->getSelectedVariantId($actor);
        $rows = [['label' => 'Nature', 'value' => '', 'editable' => false, 'field' => '']];

        if ($variants !== []) {
            $rows[] = [
                'label' => 'Default Variant',
                'value' => (string) $actor->getDefaultNaturalVariantId(),
                'options' => array_keys($variants),
                'field' => 'defaultNaturalVariantId',
            ];
            $rows[] = [
                'label' => 'Editing Variant',
                'value' => $selected ?? '',
                'options' => array_keys($variants),
                'field' => self::VARIANT_FIELD,
            ];
        }

        // Both layers are real: the runtime adds the selected variant on top
        // of the fixed adjustments rather than replacing them, so both are
        // shown and both are editable.
        $inForce = $actor->getNaturalAdjustmentsFor($selected);
        $fixed = $actor->getActorNaturalAdjustments();
        $rows = [...$rows, ...$this->actorAdjustmentRows(
            $variants === [] ? 'Adjustments' : 'Fixed, always applied',
            'actorNaturalAdjustments',
            $fixed,
        )];

        if ($variants !== [] && $selected !== null) {
            $rows = [...$rows, ...$this->actorAdjustmentRows(
                sprintf('Variant %s, added on top', $selected),
                sprintf('naturalVariants.%s', $selected),
                $variants[$selected] ?? [],
            )];
            $rows[] = [
                'label' => '  In force',
                'value' => $this->describeAdjustments($inForce),
                'editable' => false,
                'field' => '',
            ];
        }

        return $rows;
    }

    /**
     * The rows for one layer of an actor's nature.
     *
     * @param string $heading What the layer is.
     * @param string $prefix The payload path the rows write to.
     * @param array<string, int> $adjustments The layer's adjustments.
     * @return array<int, array<string, mixed>> The rows.
     */
    private function actorAdjustmentRows(string $heading, string $prefix, array $adjustments): array
    {
        $rows = [['label' => '  ' . $heading, 'value' => '', 'editable' => false, 'field' => '']];

        foreach (ActorStatPreview::statKeys() as $key) {
            $amount = $adjustments[$key] ?? 0;
            $rows[] = [
                'label' => '    ' . ucfirst(strtolower((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $key))),
                'value' => (string) $amount,
                'control' => new InputControl(InputControlType::INTEGER, (string) $amount),
                'field' => $prefix . '.' . $key,
            ];
        }

        return $rows;
    }

    /**
     * Describes what an actor's nature comes to once composed.
     *
     * @param array<string, int> $adjustments The composed adjustments.
     * @return string The description.
     */
    private function describeAdjustments(array $adjustments): string
    {
        $parts = [];

        foreach ($adjustments as $key => $amount) {
            if ($amount !== 0) {
                $parts[] = sprintf('%+d %s', $amount, $key);
            }
        }

        return $parts === [] ? 'nothing adjusted' : implode(', ', $parts);
    }

    /**
     * The read-only rows showing what each stat actually comes to.
     *
     * @param ProjectActor $actor The actor.
     * @return array<int, array<string, mixed>> The rows.
     */
    private function actorStatPreviewFields(ProjectWorkspace $workspace, ProjectActor $actor): array
    {
        if (! ActorStatPreview::isAvailable()) {
            return [];
        }

        $catalog = PermanentGrowthCatalog::fromProject($workspace->projectRoot);
        $assumed = $this->assumedGrowthFor($actor);
        $rows = [
            ['label' => 'Resolved Stats', 'value' => '', 'editable' => false, 'field' => ''],
            [
                // Earned growth is save state. What a preview can do is
                // assume some of it, say that it is assuming, and write
                // nothing.
                'label' => '  Assumed Growth',
                'value' => $assumed,
                'options' => [
                    self::NO_ASSUMED_GROWTH,
                    self::ALL_ASSUMED_GROWTH,
                    ...$catalog->ids(),
                ],
                'field' => self::GROWTH_FIELD,
                'displayDefault' => 'assumed for this preview only; the party earns growth in play',
            ],
        ];

        $permanent = match ($assumed) {
            self::NO_ASSUMED_GROWTH => [],
            self::ALL_ASSUMED_GROWTH => $catalog->totalsFor(),
            default => $catalog->totalsFor([$assumed]),
        };

        foreach (ActorStatPreview::resolve($actor, $this->getSelectedVariantId($actor), $permanent) as $row) {
            $rows[] = [
                'label' => '  ' . $row['stat'],
                'value' => ActorStatPreview::describeRow($row),
                'editable' => false,
                'field' => '',
            ];
        }

        return [...$rows, ...$this->actorOptimizeFields($workspace, $actor)];
    }

    /**
     * Returns which permanent growth this actor's preview assumes.
     *
     * @param ProjectActor $actor The actor.
     * @return string The selection.
     */
    private function assumedGrowthFor(ProjectActor $actor): string
    {
        return $this->growthSelections[$actor->getDefinitionId()] ?? self::NO_ASSUMED_GROWTH;
    }

    /**
     * Returns which slot this actor's Optimize preview fills.
     *
     * @param ProjectActor $actor The actor.
     * @return string The semantic slot.
     */
    private function optimizeSlotFor(ProjectActor $actor): string
    {
        $slots = EquipmentOptimizationPolicy::slotKeys();
        $selected = $this->optimizeSlots[$actor->getDefinitionId()] ?? '';

        return in_array($selected, $slots, true) ? $selected : ($slots[0] ?? 'weapon');
    }

    /**
     * The read-only rows showing what Optimize would choose, and why.
     *
     * @param ProjectActor $actor The actor.
     * @return array<int, array<string, mixed>> The rows.
     */
    private function actorOptimizeFields(ProjectWorkspace $workspace, ProjectActor $actor): array
    {
        if (! EquipmentOptimizationPolicy::isAvailable()) {
            return [];
        }

        $root = $workspace->projectRoot;
        $slot = $this->optimizeSlotFor($actor);
        $rows = [
            ['label' => 'Optimize Preview', 'value' => '', 'editable' => false, 'field' => ''],
            [
                // Which policy is scoring is the first thing to know: a
                // project that has declared none is not being scored by its
                // own rules at all.
                'label' => '  Policy',
                'value' => EquipmentOptimizationPolicy::describeSource($root),
                'editable' => false,
                'field' => '',
            ],
            [
                'label' => '  Slot',
                'value' => $slot,
                'options' => EquipmentOptimizationPolicy::slotKeys(),
                'field' => self::OPTIMIZE_SLOT_FIELD,
            ],
        ];
        $ranked = EquipmentOptimizationPolicy::rank($workspace, $actor, $slot);

        if ($ranked === []) {
            $rows[] = [
                'label' => '  (nothing)',
                'value' => 'no equipment this project has fits that slot',
                'editable' => false,
                'field' => '',
            ];

            return $rows;
        }

        foreach ($ranked as $position => $candidate) {
            $rows[] = [
                'label' => sprintf('  %d. %s', $position + 1, $candidate['name']),
                'value' => sprintf(
                    '%d · %s',
                    $candidate['value'],
                    EquipmentOptimizationPolicy::describeRow($candidate),
                ),
                'editable' => false,
                'field' => '',
            ];
        }

        return $rows;
    }

    /**
     * Returns which natural variant the actor rows are editing.
     *
     * @param ProjectActor $actor The actor.
     * @return string|null The variant id, or null when the actor has none.
     */
    public function getSelectedVariantId(ProjectActor $actor): ?string
    {
        $variants = $actor->getNaturalVariants();

        if ($variants === []) {
            return null;
        }

        $selected = $this->variantSelections[$actor->getDefinitionId()] ?? null;

        return $selected !== null && isset($variants[$selected])
            ? $selected
            : ($actor->getDefaultNaturalVariantId() ?? array_key_first($variants));
    }

    /**
     * The rows an actor shows, in the order the editors list them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describeFields(ProjectWorkspace $workspace, ProjectActor $actor): array
    {
        return [
            ...$this->getActorIdentityFields($actor),
            [
                'label' => 'Name',
                'value' => $actor->getName(),
                'control' => new InputControl(InputControlType::TEXT, $actor->getName()),
                'editable' => $actor->hasDefinitionId(),
                'field' => 'name',
            ],
            [
                'label' => 'Description',
                'value' => $actor->getDescription(),
                'control' => new InputControl(InputControlType::TEXT, $actor->getDescription()),
                'field' => 'description',
            ],
            [
                // The character-class reference: a name from
                // assets/Data/classes.php, written to the actor's
                // data['class'] key (the engine's ClassStore hydrates it
                // into a CharacterRole). ←/→ cycles the picker; Ctrl+G jumps
                // to the class entry.
                'label' => 'Class',
                'value' => $actor->getClassName() === '' ? ProjectActor::CLASS_NONE : $actor->getClassName(),
                'options' => $this->getActorClassOptions($workspace),
                'field' => 'class',
            ],
            [
                // The character's own weapon, part of who they are and with
                // no stats: what their attack looks like when no weapon is
                // equipped. An equipped weapon's type takes its place.
                'label' => 'Attack Style',
                'value' => $actor->getAttackStyle() === '' ? 'Unarmed' : $actor->getAttackStyle(),
                'options' => [ProjectActor::ATTACK_STYLE_UNARMED, ...array_map(
                    static fn(WeaponType $type): string => $type->value, WeaponType::cases())],
                'field' => 'attackStyle',
                'hint' => 'own weapon when none is equipped; no stats',
            ],
            [
                // The basic skill the Attack command uses; a battle-usable
                // basic skill from the catalogue, or the Engine's own attack.
                'label' => 'Attack Skill',
                'value' => $actor->getAttackSkill() === '' ? self::BUILT_IN_ATTACK : $actor->getAttackSkill(),
                'reference' => 'attack_skills',
                'allowsNone' => true,
                'noneLabel' => self::BUILT_IN_ATTACK,
                'field' => 'attackSkill',
            ],
            [
                // The skill this actor responds with when a physical hit
                // lands on it; off unless chosen, picked from the skills the
                // Engine's counter rule accepts.
                'label' => 'Counter Attack',
                'value' => $actor->getCounterAttackSkill() === '' ? self::NO_COUNTER : $actor->getCounterAttackSkill(),
                'reference' => 'counter_skills',
                'allowsNone' => true,
                'noneLabel' => self::NO_COUNTER,
                'field' => 'counterAttack',
            ],
            ...$this->actorSummonFields($workspace, $actor),
            [
                'label' => 'Level',
                'value' => (string) $actor->getLevel(),
                'control' => new InputControl(InputControlType::INTEGER, (string) $actor->getLevel()),
                'field' => 'level',
            ],
            [
                'label' => 'Current Exp',
                'value' => (string) $actor->getCurrentExp(),
                'control' => new InputControl(InputControlType::INTEGER, (string) $actor->getCurrentExp()),
                'field' => 'currentExp',
            ],
            [
                'label' => 'Current HP',
                'value' => (string) $actor->getStat('currentHp'),
                'control' => new InputControl(InputControlType::INTEGER, (string) $actor->getStat('currentHp')),
                'field' => 'currentHp',
            ],
            [
                'label' => 'Current MP',
                'value' => (string) $actor->getStat('currentMp'),
                'control' => new InputControl(InputControlType::INTEGER, (string) $actor->getStat('currentMp')),
                'field' => 'currentMp',
            ],
            [
                'label' => 'Current AP',
                'value' => (string) $actor->getStat('currentAp'),
                'control' => new InputControl(InputControlType::INTEGER, (string) $actor->getStat('currentAp')),
                'field' => 'currentAp',
            ],
            ...$this->actorNatureFields($actor),
            ...$this->actorStatPreviewFields($workspace, $actor),
        ];
    }

    /**
     * Returns the actor's starting summon assignments: a multi-pick over the
     * project's summons, and one verdict row per assignment judged by the
     * same rules the validator applies (existence, wielder eligibility,
     * story locks, duplicates, exclusive tenancy across the cast).
     *
     * @return array<int, array<string, mixed>>
     */
    private function actorSummonFields(ProjectWorkspace $workspace, ProjectActor $actor): array
    {
        $assignments = $actor->getSummons();
        $list = is_array($assignments) ? array_values(array_filter(array_map(static fn(mixed $id): string => is_string($id) ? trim($id) : '', $assignments), static fn(string $id): bool => $id !== '')) : [];
        $rows = [
            [
                'label' => 'Summons',
                'value' => implode(', ', $list),
                'reference' => 'summons',
                'multi' => true,
                'noneLabel' => '(None)',
                'field' => 'summons',
            ],
        ];
        $diagnostics = SummonAssignmentDiagnostics::fromLibrary($workspace->cutscenes);
        $holders = [];

        foreach ($workspace->actorDatabase->getActors() ?? [] as $other) {
            if ($other === $actor) {
                continue;
            }

            foreach ((array) $other->getSummons() as $id) {
                if (is_string($id) && trim($id) !== '') {
                    $holders[strtolower(trim($id))][] = $other->getName();
                }
            }
        }

        foreach ($diagnostics->forActor($actor->getRuntimeId(), $actor->getClassName(), $assignments) as $row) {
            $verdict = SummonAssignmentDiagnostics::describe($row);
            $id = strtolower($row['id']);

            if ($row['problems'] === [] && $diagnostics->isExclusive($id) && isset($holders[$id])) {
                $verdict = sprintf('✗ %s: exclusive, also held by %s.', $row['id'], implode(', ', $holders[$id]));
            }

            $rows[] = ['label' => '  ' . $verdict, 'value' => '', 'editable' => false, 'field' => ''];
        }

        return $rows;
    }

    /**
     * Returns the actor class picker options.
     *
     * The list is the project's own class names from
     * `assets/Data/classes.php`, prefixed with the "none" sentinel that
     * clears the reference.
     *
     * @return string[]
     */
    private function getActorClassOptions(ProjectWorkspace $workspace): array
    {
        return [
            ProjectActor::CLASS_NONE,
            ...$workspace->getRecordDatabase('classes')?->getEntryLabels() ?? [],
        ];
    }

    /**
     * Returns an edited actor value as the field's own type.
     *
     * Most actor numbers are quantities that cannot go below zero, but an
     * actor's nature is an adjustment: being slower than the class baseline
     * is a legitimate thing to author, so those keep their sign.
     *
     * @param string $field The field identifier.
     * @param string $rawValue The raw edited value.
     * @return string|int The coerced value.
     */
    private static function coerceActorFieldValue(string $field, string $rawValue): string|int
    {
        if (in_array($field, ['name', 'description', 'class', 'attackStyle', 'attackSkill', 'counterAttack', 'id', 'defaultNaturalVariantId', 'summons'], true)) {
            return trim($rawValue);
        }

        if (str_starts_with($field, 'actorNaturalAdjustments.') || str_starts_with($field, 'naturalVariants.')) {
            return intval(trim($rawValue));
        }

        return max(0, intval($rawValue));
    }
}
