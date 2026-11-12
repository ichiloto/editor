<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Battle\CrisisAbilityCatalog;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use InvalidArgumentException;

/**
 * Database > Super Moves: the project's conditional finishing techniques,
 * the list the Engine's {@see CrisisAbilityCatalog} reads from
 * `assets/Data/super-moves.php`. Each has a stable id that an actor's
 * `superMove` names, the HP percentage and states that activate it, and its
 * variants, each a skill with the world conditions that choose it.
 *
 * A project that still keeps them in the earlier `crisis-abilities.php` is
 * read from it, read-only, until {@see SuperMoveMigration} moves the file and
 * every actor's `crisisAbility` key together. The Engine refuses a project
 * holding both files, so the Editor does too.
 *
 * @package Ichiloto\Editor\Database
 */
final class SuperMoveFields
{
    /** The Database category, also the reference category actor pages pick a Super Move from. */
    public const string CATEGORY = ReferenceCatalog::SUPER_MOVES;
    /** The catalogue the Engine reads and the Editor writes. */
    public const string CATALOGUE = 'assets/' . CrisisAbilityCatalog::SUPER_MOVE_FILE;
    /** The earlier catalogue the Engine still reads. */
    public const string LEGACY_CATALOGUE = 'assets/' . CrisisAbilityCatalog::FILE;
    /** The actor data key naming a Super Move, the one the Editor writes. */
    public const string ACTOR_KEY = 'superMove';
    /** The earlier actor data key the Engine still reads. */
    public const string LEGACY_ACTOR_KEY = 'crisisAbility';

    private function __construct()
    {
    }

    public static function getSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::CATEGORY, entryNoun: 'Super Move', storage: RecordStorage::LIST_FILE,
            relativePath: self::CATALOGUE,
            fields: [new RecordField('id', 'Id'), new RecordField('name', 'Name'),
                new RecordField('label', 'Badge Label', removeWhenEmpty: true),
                new RecordField('description', 'Description', removeWhenEmpty: true),
                RecordField::reference('iconRole', 'Acquisition Icon Role', ReferenceCatalog::ICON_ROLES,
                    allowsNone: true, noneLabel: '(actor portrait)'),
                new RecordField('hpBelowPercent', 'HP Below %', InputControlType::FLOAT, removeWhenEmpty: true),
                new RecordField('states', 'Activation States', reference: 'states', codec: RecordFieldCodec::CSV_LIST,
                    removeWhenEmpty: true)],
            labelKey: 'id', identityKey: 'id',
            // The Engine reads a Super Move only with an activation and a technique, so a new one starts
            // active below 10% HP (the Engine documentation's example) on the project's first
            // battle-usable basic or special skill; without one there is no technique to offer.
            // That skill is the first the variant picker offers, from the same source.
            makeBlank: static function (string $name, string $root): array {
                $skill = ReferenceCatalog::getSuperMoveSkillNames(SkillCatalog::load($root . '/assets'))[0]
                    ?? throw new RecordRefusal('A new Super Move offers the project\'s first battle-usable basic or special skill. Add one under Skills first.');

                return ['id' => 'new-super-move', 'name' => $name, 'hpBelowPercent' => 10.0, 'variants' => [['skill' => $skill]]];
            },
            subList: new RecordSubList(key: 'variants', prefix: 'variant', singular: 'variant', fields: [
                RecordField::reference('skill', 'Skill', ReferenceCatalog::SUPER_MOVE_SKILLS),
                RecordField::reference('iconRole', 'Acquisition Icon Role', ReferenceCatalog::ICON_ROLES,
                    allowsNone: true, noneLabel: '(use Super Move default)'),
                new RecordField('conditions', 'World Conditions', codec: RecordFieldCodec::CONDITIONS, removeWhenEmpty: true),
            ], blank: ['skill' => '']),
            saveCheck: static function (array $whole, string $root): ?string {
                try {
                    new CrisisAbilityCatalog($whole)->validateVariantConditions();
                } catch (InvalidArgumentException $error) { return $error->getMessage(); }
                return null;
            },
            legacyRelativePath: self::LEGACY_CATALOGUE,
            legacyReadOnlyReason: sprintf('this project still keeps its Super Moves in %s; run Migrate to Super Moves to edit them',
                self::LEGACY_CATALOGUE),
        );
    }
}
