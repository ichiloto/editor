<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use InvalidArgumentException;
use RuntimeException;

/** Shared graphical sheet controls; only sheet, index and layer are authored. */
final class CharacterSheetFields
{
    /** @return list<RecordField> */
    public static function getFields(mixed $binding, string $prefix, string $label): array
    {
        $fields = [RecordField::reference($prefix . '.sheet', $label, 'png_assets',
            allowsNone: true, noneLabel: 'None: no field sheet')];
        if ($binding === null) {
            return $fields;
        }
        $asset = is_array($binding) && is_string($binding['sheet'] ?? null) ? $binding['sheet'] : '';
        $fields[] = new RecordField($prefix . '.index', 'Sheet Character Index', InputControlType::INTEGER,
            options: CharacterSheetPreview::getIndexOptions($asset), displayDefault: '0');
        $fields[] = new RecordField($prefix . '.layer', 'Graphical Layer', InputControlType::INTEGER,
            displayDefault: (string) CharacterSheet::DEFAULT_LAYER);

        return $fields;
    }

    /** Attach the same current-file four-direction preview used for NPCs. */
    public static function describeFields(array $fields, mixed $binding, string $prefix, ?string $assetRoot, bool $worldLayer = true): array
    {
        if ($binding !== null) {
            foreach ($fields as &$field) {
                if (($field['field'] ?? null) === $prefix . '.sheet' && ($field['editable'] ?? true) !== false) {
                    $field['imagePreview'] = CharacterSheetPreview::describe($binding, $assetRoot, $worldLayer);
                }
            }
            unset($field);
        }

        return $fields;
    }

    /** Refuse invalid selections before changing their owner or its history. */
    public static function applyField(mixed $binding, string $field, string $value, string $assetRoot, bool $worldLayer = true): ?array
    {
        if ($field === 'sheet' && $value === '') {
            return null;
        }
        if (! in_array($field, ['sheet', 'index', 'layer'], true)) {
            throw new InvalidArgumentException('Unknown character sheet field.');
        }
        if ($binding !== null && ! is_array($binding)) {
            throw new InvalidArgumentException('Clear the malformed character sheet before choosing a new one.');
        }
        $draft = $binding ?? [];
        if ($field === 'sheet') {
            $draft['sheet'] = $value;
        } else {
            if (! is_string($draft['sheet'] ?? null) || $draft['sheet'] === '') {
                throw new InvalidArgumentException('Select a field sheet before editing its character index or graphical layer.');
            }
            if (preg_match('/^-?\d+$/D', $value) !== 1 || filter_var($value, FILTER_VALIDATE_INT) === false) {
                throw new InvalidArgumentException('A sheet character index or graphical layer must be an integer.');
            }
            $draft[$field] = (int) $value;
        }
        try {
            if ($worldLayer) {
                NpcCharacterSheet::validate($draft, $assetRoot);
            } else {
                $sheet = CharacterSheet::fromArray($draft);
                $image = PngAssetPreflight::inspect($assetRoot, $sheet->asset);
                $sheet->getFrameSize($image['width'], $image['height']);
            }
        } catch (RuntimeException $refused) {
            throw new InvalidArgumentException($refused->getMessage(), previous: $refused);
        }

        return $draft;
    }
}
