<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordStorage;
use Ichiloto\Editor\Database\Projections\WholeFileProjection;
use Ichiloto\Engine\Field\PlayerGraphicalSubject;
use InvalidArgumentException;

/** Player appearance owns no actor identity, terminal glyphs or gameplay data. */
final class PlayerPresentationFields
{
    public const string CATEGORY = 'player_presentation';
    public const string LEGACY = '(legacy fixed-player: selector absent)';

    public static function getSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::CATEGORY,
            entryNoun: 'player field appearance',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/Data/Entities/player.php',
            fields: [],
            identityKey: null,
            projection: new WholeFileProjection(['graphicalSubject', 'sprites2d']),
            labelFor: static fn(array $row): string => 'Player Field Appearance',
            fieldsFor: self::getFields(...),
            requireUnchangedSource: true,
        );
    }

    /** @return list<RecordField> */
    public static function getFields(array $data): array
    {
        $fields = [new RecordField('graphicalSubject', 'Graphical Subject',
            options: [self::LEGACY, ...array_map(static fn(PlayerGraphicalSubject $mode): string => $mode->value, PlayerGraphicalSubject::cases())],
            displayDefault: self::LEGACY)];
        $mode = array_key_exists('graphicalSubject', $data) ? $data['graphicalSubject'] : PlayerGraphicalSubject::FIXED_PLAYER->value;
        if (! array_key_exists('graphicalSubject', $data) || $mode === PlayerGraphicalSubject::FIXED_PLAYER->value) {
            $fields = [...$fields, ...CharacterSheetFields::getFields($data['sprites2d'] ?? null, 'sprites2d', 'Fixed Player Field Sheet')];
        } else {
            // Inactive is not unowned: shape changes must retain legacy art.
            $fields = [...$fields, ...array_map(static fn(RecordField $field): RecordField => $field->asReadOnly(),
                CharacterSheetFields::getFields($data['sprites2d'] ?? null, 'sprites2d', 'Inactive Fixed Player Field Sheet'))];
        }

        return $fields;
    }

    public static function describeFields(array $fields, array $data, ?string $assetRoot): array
    {
        $mode = array_key_exists('graphicalSubject', $data) ? $data['graphicalSubject'] : PlayerGraphicalSubject::FIXED_PLAYER->value;
        if (array_key_exists('graphicalSubject', $data) && ! is_string($mode)) {
            foreach ($fields as &$field) {
                if (($field['field'] ?? null) === 'graphicalSubject') {
                    $field['value'] = '(invalid ' . get_debug_type($mode) . ' selector)';
                }
            }
            unset($field);
        }
        if ($mode === PlayerGraphicalSubject::FIXED_PLAYER->value) {
            $fields = CharacterSheetFields::describeFields($fields, $data['sprites2d'] ?? null, 'sprites2d', $assetRoot, worldLayer: false);
        }
        $note = match ($mode) {
            PlayerGraphicalSubject::PARTY_LEADER->value => 'Uses the selected party leader\'s actor images.field2d role. Missing leader art stays missing; no fixed-player substitute. Inactive fixed art is retained.',
            PlayerGraphicalSubject::FIXED_PLAYER->value => 'Uses this player\'s fixed sheet. Actor roles and party order do not change it. Terminal appearance is independent.',
            default => 'Invalid graphicalSubject: optional graphical presentation is refused. Choose a valid mode; no identity is substituted.',
        };
        $fields[] = ['label' => 'Field Appearance Ownership', 'value' => $note, 'editable' => false];

        return $fields;
    }

    public static function applyField(array $data, string $field, string $value, string $assetRoot): array
    {
        if ($field === 'graphicalSubject') {
            if ($value === self::LEGACY) {
                unset($data[$field]);
            } elseif (PlayerGraphicalSubject::tryFrom($value) === null) {
                throw new InvalidArgumentException('Choose fixed-player or party-leader, or leave the selector absent for legacy fixed-player.');
            } else {
                $data[$field] = $value;
            }
        } elseif (str_starts_with($field, 'sprites2d.')) {
            $mode = array_key_exists('graphicalSubject', $data) ? $data['graphicalSubject'] : PlayerGraphicalSubject::FIXED_PLAYER->value;
            if ($mode !== PlayerGraphicalSubject::FIXED_PLAYER->value) {
                throw new InvalidArgumentException('Fixed player art is inactive; choose fixed-player before editing it.');
            }
            $binding = CharacterSheetFields::applyField($data['sprites2d'] ?? null, substr($field, strlen('sprites2d.')), $value, $assetRoot, worldLayer: false);
            if ($binding === null) {
                unset($data['sprites2d']);
            } else {
                $data['sprites2d'] = $binding;
            }
        } else {
            throw new InvalidArgumentException('Unknown player presentation field.');
        }

        return $data;
    }
}
