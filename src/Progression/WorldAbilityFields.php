<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Progression;

use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordFieldCodec;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordStorage;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Engine\Progression\WorldAbilityCatalog;
use InvalidArgumentException;

/**
 * Database > World Powers: the project's exploration powers, the list
 * `assets/Data/world-abilities.php` returns and the Engine's
 * {@see WorldAbilityCatalog} reads. Each power has a stable id that
 * `world_ability` conditions and power commands name, a name and
 * description players read, the conditions that unlock it, an optional
 * theme icon role and an optional first-use prompt.
 *
 * The id follows the name until something names the power, then stays, and
 * a named power cannot be deleted ({@see WorldAbilityReferences}). The
 * Engine's own catalogue judges every save: unknown or cyclic dependencies,
 * duplicate ids and malformed prompts are refused before a byte is written.
 * A first-use prompt's hints name semantic input actions, which the Editor
 * cannot list yet; they are kept as written.
 *
 * @package Ichiloto\Editor\Progression
 */
final class WorldAbilityFields
{
    /** The Database category, also the reference category pickers list powers under. */
    public const string CATEGORY = ReferenceCatalog::WORLD_ABILITIES;

    private function __construct()
    {
    }

    public static function getSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::CATEGORY,
            entryNoun: 'world power',
            storage: RecordStorage::LIST_FILE,
            relativePath: ProjectWorldAbilities::RELATIVE_PATH,
            fields: [
                new RecordField('id', 'Id', isReadOnly: true),
                new RecordField('name', 'Name'),
                new RecordField('description', 'Description', removeWhenEmpty: true),
                // Every condition must hold; a power may wait on another.
                new RecordField('conditions', 'Unlocks When', removeWhenEmpty: true, codec: RecordFieldCodec::CONDITIONS),
                RecordField::reference('iconRole', 'Icon Role', ReferenceCatalog::ICON_ROLES, allowsNone: true, noneLabel: '(no icon)'),
                new RecordField('firstUse.message', 'First-Use Message', removeWhenEmpty: true, displayDefault: '(no first-use prompt)'),
                RecordField::reference('firstUse.effect', 'First-Use Effect', 'effects', allowsNone: true, noneLabel: '(no effect)'),
                new RecordField('firstUse.hints', 'First-Use Hints', isReadOnly: true),
            ],
            labelKey: 'name',
            identityKey: 'id',
            blank: ['id' => 'new-power', 'name' => 'New Power'],
            identityFollowsLabel: true,
            saveCheck: self::checkSource(...),
        );
    }

    /**
     * Why the Engine could not read the powers as a save would write them, or null when it can.
     *
     * @param array<array-key, mixed> $whole Every power, as it would be saved.
     */
    public static function checkSource(array $whole, string $projectRoot = ''): ?string
    {
        try {
            new WorldAbilityCatalog(array_values($whole));
        } catch (InvalidArgumentException $error) {
            return $error->getMessage();
        }

        return null;
    }
}
