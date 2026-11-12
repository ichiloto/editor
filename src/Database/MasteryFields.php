<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Database\Projections\KeyedListProjection;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;
use Ichiloto\Engine\Progression\Mastery\MasteryMap;
use Ichiloto\Engine\Progression\Mastery\MasteryBranchKind;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeKind;
use Ichiloto\Engine\Progression\Mastery\MasteryPhase;
use Ichiloto\Engine\Progression\Mastery\MasteryTree;
use InvalidArgumentException;
use Throwable;

/**
 * The Mastery categories: the mastery tree every character shares, kept in
 * `assets/Data/mastery.php` as its regions (each with the nodes on its
 * trunk) and its branches (each with its nodes in the order they progress,
 * growing from a region or within another branch of it as a sub-family),
 * and one map per character (`assets/Data/Mastery/*.php`) stating that
 * character's own terms for tree nodes. Both editors write them through
 * these records, and the Engine's own readers refuse a tree or map it could
 * not read before anything is written. A node's place on the graphical map
 * is authored in the GUI editor; the Terminal editor keeps it as it is.
 */
final class MasteryFields
{
    public const string REGIONS = 'mastery_regions';
    /** The {@see ReferenceCatalog::listEntryChoices()} source of what a branch may grow within. */
    public const string WITHIN_CHOICES = 'mastery_within_branches';
    public const string BRANCHES = 'mastery_branches';
    public const string MAPS = 'mastery_maps';
    /** The reference source listing every tree node, by id. */
    public const string NODES = 'mastery_nodes';

    private function __construct()
    {
    }

    public static function getRegionSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::REGIONS,
            entryNoun: 'mastery region',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/' . MasteryCatalog::SETTINGS,
            fields: [
                // Branches grow from a region by its id, so the id stays as it was made.
                new RecordField('id', 'Stable Id', isReadOnly: true),
                new RecordField('name', 'Name'),
                // What the discipline covers; the game's Info shows it on the discipline.
                new RecordField('description', 'Summary', removeWhenEmpty: true),
                // What the region's own trunk nodes cover; the game's Info shows it on the family they form.
                new RecordField('trunkDescription', 'Trunk Summary', removeWhenEmpty: true),
            ],
            labelKey: 'name',
            identityKey: 'id',
            blank: ['id' => 'new-region', 'name' => 'New Region'],
            // Nodes on the region's trunk, outside any branch: ungraded applications, until an author places them on a branch.
            subList: new RecordSubList(
                key: 'nodes',
                prefix: 'node',
                singular: 'trunk node',
                fields: self::getTreeNodeFields(trunk: true),
                blank: ['id' => 'new-node', 'kind' => MasteryNodeKind::APPLICATION->value],
                heading: 'Trunk Nodes',
                removeWhenEmpty: true,
            ),
            projection: new KeyedListProjection('regions'),
            saveCheck: self::checkTree(...),
        );
    }

    public static function getBranchSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::BRANCHES,
            entryNoun: 'mastery branch',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/' . MasteryCatalog::SETTINGS,
            fields: [
                new RecordField('id', 'Stable Id', isReadOnly: true),
                RecordField::reference('region', 'Region', self::REGIONS),
                // The branch of the same region it grows within, as a sub-family of that branch's family; none grows from the region.
                // Every other branch is offered, so a move can name its new branch before its region; never the branch itself.
                RecordField::entryChoice('within', 'Within Branch', self::WITHIN_CHOICES, '(the region)'),
                new RecordField('name', 'Name'),
                new RecordField('kind', 'Kind', options: array_column(MasteryBranchKind::cases(), 'value')),
                // An element branch practises one of the project's elements.
                new RecordField('element', 'Element', reference: 'elements', removeWhenEmpty: true, allowsNone: true, displayDefault: '(none)'),
                // What the branch covers; the game's Info shows it on the family and its foundation.
                new RecordField('description', 'Summary', removeWhenEmpty: true),
            ],
            labelKey: 'name',
            identityKey: 'id',
            blank: ['id' => 'new-branch', 'region' => '', 'name' => 'New Branch', 'kind' => MasteryBranchKind::ASPECT->value, 'nodes' => []],
            // A branch grows from a region, so a new one starts on the project's first; without one there is nothing to grow from.
            makeBlank: static function (string $name, string $root): array {
                $region = MasteryCatalog::load($root . '/assets')->regions[0]->id
                    ?? throw new RecordRefusal('Declare a Mastery region first: a branch grows from one.');

                return ['id' => 'new-branch', 'region' => $region, 'name' => $name, 'kind' => MasteryBranchKind::ASPECT->value, 'nodes' => []];
            },
            // The branch's nodes in the order they progress: foundation, graded applications, advanced applications.
            subList: new RecordSubList(
                key: 'nodes',
                prefix: 'node',
                singular: 'node',
                fields: self::getTreeNodeFields(trunk: false),
                blank: ['id' => 'new-node', 'kind' => MasteryNodeKind::APPLICATION->value],
                heading: 'Nodes',
            ),
            projection: new KeyedListProjection('branches'),
            saveCheck: self::checkTree(...),
        );
    }

    public static function getMapSchema(bool $graphical = false): RecordSchema
    {
        $nodeFields = [
            // The tree node these terms are for; the tree owns its skill, region and branch.
            RecordField::reference('id', 'Node', self::NODES),
            // The Mastery points (SP) this character spends to learn it; 0 is learned as soon as it opens.
            new RecordField('cost', 'Cost (SP)', InputControlType::INTEGER),
            new RecordField('opensAtLevel', 'Opens At Level', InputControlType::INTEGER),
            // The ids of the nodes it builds on for this character, in place of its chain step on the tree.
            new RecordField('requires', 'Requires', codec: RecordFieldCodec::CSV_LIST, removeWhenEmpty: true),
            // Menus show ??? for it while it is locked.
            RecordField::boolean('hidden', 'Secret Until Unlocked'),
            ...($graphical ? [
                new RecordField('position.x', 'Map X (0 to 1)', InputControlType::FLOAT, removeWhenEmpty: true),
                new RecordField('position.y', 'Map Y (0 to 1)', InputControlType::FLOAT, removeWhenEmpty: true),
            ] : []),
        ];

        return new RecordSchema(
            key: self::MAPS,
            entryNoun: 'mastery map',
            storage: RecordStorage::DIRECTORY,
            relativePath: 'assets/' . MasteryCatalog::DIRECTORY,
            fields: [RecordField::reference('actor', 'Actor', 'actor_ids')],
            labelKey: 'actor',
            identityKey: 'actor',
            // A map created for a character takes their id; one created without starts under a placeholder to rename.
            blank: ['actor' => 'new-mastery-map', 'nodes' => []],
            subList: new RecordSubList(
                key: 'nodes',
                prefix: 'node',
                singular: 'node',
                fields: $nodeFields,
                blank: ['id' => '', 'cost' => 0, 'opensAtLevel' => 1],
                heading: 'Own Terms',
            ),
            saveCheck: self::checkMap(...),
        );
    }

    /**
     * A tree node's fields. A trunk holds only ungraded applications, so it
     * shows no grade, phase or extension.
     *
     * @return list<RecordField>
     */
    private static function getTreeNodeFields(bool $trunk): array
    {
        return [
            new RecordField('id', 'Id'),
            new RecordField('kind', 'Kind', options: $trunk ? [MasteryNodeKind::APPLICATION->value] : array_column(MasteryNodeKind::cases(), 'value')),
            // A foundation may grant no skill yet; every other node teaches one.
            new RecordField('skill', 'Skill', reference: 'skills', removeWhenEmpty: true, allowsNone: true, displayDefault: '(grants none yet)'),
            // The name shown where no skill gives one, as for a foundation.
            new RecordField('name', 'Name', removeWhenEmpty: true, displayDefault: '(the skill\'s)'),
            ...($trunk ? [] : [
                // Its rung on the branch's ladder of basic applications: 1 for I.
                new RecordField('grade', 'Grade', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '(ungraded)'),
                new RecordField('phase', 'Special Phase', options: ['', ...array_column(MasteryPhase::cases(), 'value')], removeWhenEmpty: true, displayDefault: '(none)'),
                // A full phase extends its demi phase, and an evolution the advanced application it carries further, by node id.
                new RecordField('extends', 'Extends', removeWhenEmpty: true, displayDefault: '(none)'),
            ]),
            // Its price on the tree's terms, for characters whose own map does not price it; without one, only they can learn it.
            new RecordField('cost', 'Cost (SP)', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '(not priced)'),
            new RecordField('opensAtLevel', 'Opens At Level', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '(any)'),
            // Nodes it builds on besides its chain step.
            new RecordField('requires', 'Also Requires', codec: RecordFieldCodec::CSV_LIST, removeWhenEmpty: true),
            RecordField::boolean('hidden', 'Secret Until Unlocked'),
        ];
    }

    /**
     * Why the Engine could not read the mastery tree as it would be saved:
     * a repeated or unknown id, a broken ladder or chain, a skill taught
     * twice, or a skill the project has neither as a skill nor as a draft.
     *
     * @param array<array-key, mixed> $whole The settings file, as it would be saved.
     */
    public static function checkTree(array $whole, string $projectRoot): ?string
    {
        try {
            $tree = MasteryTree::fromArray($whole);
        } catch (InvalidArgumentException $error) {
            return sprintf('The mastery tree could not be read: %s', $error->getMessage());
        }
        $skills = SkillCatalog::load($projectRoot . '/assets');
        foreach ($tree->nodes as $node) {
            if ($node->skill !== null && $skills->findSkill($node->skill) === null && $skills->findDraft($node->skill) === null) {
                return sprintf('Mastery node %s teaches unknown skill "%s".', $node->id, $node->skill);
            }
        }

        return null;
    }

    /**
     * Why the Engine could not read a map as it would be saved: terms for a
     * node the tree lacks, an unknown required node, a loop, or terms it
     * cannot read.
     *
     * @param array<array-key, mixed> $whole The map, as it would be saved.
     */
    public static function checkMap(array $whole, string $projectRoot): ?string
    {
        try {
            MasteryMap::fromArray($whole, MasteryCatalog::load($projectRoot . '/assets')->tree);
        } catch (Throwable $error) {
            return sprintf('The mastery map could not be read: %s', $error->getMessage());
        }

        return null;
    }
}
