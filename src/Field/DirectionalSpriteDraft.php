<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\ProjectRecord;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteAnchor;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\DirectionalGraphicalSpriteSet;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use RuntimeException;

/** A staged directional definition, not persisted until all four roles validate together. */
final class DirectionalSpriteDraft
{
    private ProjectRecord $record;

    public function __construct(?array $data)
    {
        $this->record = new ProjectRecord($data ?? []);
    }

    public function getData(): array
    {
        return (array) $this->record->toArray();
    }

    public function getMode(): string
    {
        return $this->record->get('mode') === 'sheet' ? 'sheet' : 'poses';
    }

    public function setMode(string $mode): void
    {
        if ($mode === $this->getMode()) { return; }
        if (array_diff_key($this->getData(), ['mode' => true]) !== []) {
            throw new RuntimeException('Cancel the draft or remove existing art before changing representation. Existing poses/sheets are never converted silently.');
        }
        $this->record->set('mode', $mode === 'sheet' ? 'sheet' : null);
    }

    /** Fields describe existing Engine roles and units; references are picker-only. */
    public function getFields(string $direction): array
    {
        $sheet = $this->getMode() === 'sheet';
        $prefix = $sheet ? 'directions.' . $direction : $direction;
        $fields = [['field' => $prefix . '.asset', 'label' => ucfirst($direction) . ' PNG', 'reference' => 'png_assets']];
        foreach ($sheet ? ['columns', 'rows', 'frames'] : [] as $key) {
            $fields[] = ['field' => $prefix . '.' . $key, 'label' => ucfirst($key), 'type' => InputControlType::INTEGER];
        }
        $shared = $sheet ? '' : $direction . '.';
        foreach ($sheet ? ['width', 'height', 'frameWidth', 'frameHeight', 'idleFrame', 'frameDurationMs', 'stepDurationMs', 'layer'] : ['width', 'height', 'layer'] as $key) {
            $fields[] = ['field' => $shared . $key, 'label' => ucfirst($key) . (in_array($key, ['width', 'height'], true) ? ' (logical px)' : ''), 'type' => InputControlType::INTEGER];
        }
        $fields[] = ['field' => $shared . 'anchor', 'label' => 'Anchor', 'options' => array_column(PresentationSpriteAnchor::cases(), 'value')];
        if (! $sheet) {
            foreach (['x', 'y', 'width', 'height'] as $key) {
                $fields[] = ['field' => $direction . '.sourceRect.' . $key, 'label' => 'Optional crop ' . $key . ' (px)', 'type' => InputControlType::INTEGER];
            }
        }
        return array_map(fn(array $field): array => $field + ['value' => $this->record->getDisplayValue($field['field'])], $fields);
    }

    public function setField(string $direction, string $key, string $value): void
    {
        foreach ($this->getFields($direction) as $field) {
            if ($field['field'] !== $key) { continue; }
            if (isset($field['options']) && ! in_array($value, $field['options'], true)) {
                throw new RuntimeException('Choose a supported anchor.');
            }
            $parsed = $value === '' ? null : $value;
            if (($field['type'] ?? null) === InputControlType::INTEGER && $value !== '') {
                $parsed = filter_var($value, FILTER_VALIDATE_INT);
                if ($parsed === false) { throw new RuntimeException('Enter a supported whole number.'); }
            }
            $this->record->set($key, $parsed);
            return;
        }
        throw new RuntimeException('The selected sprite field no longer exists.');
    }

    public function validate(?string $assetRoot = null): DirectionalGraphicalSpriteSet
    {
        $set = DirectionalGraphicalSpriteSet::fromArray($this->getData());
        foreach (ProjectNpc::DIRECTIONS as $direction) {
            $definition = $set->$direction;
            if ($definition->layer < PresentationLayerPolicy::WORLD || $definition->layer >= PresentationLayerPolicy::UI) {
                throw new RuntimeException('Automatic Game world sprites require layers 0..999; UI layers are reserved.');
            }
            if ($assetRoot !== null) {
                $sheet = $definition->sheet;
                $crop = $sheet === null ? $definition->sourceRect : new SpriteSourceRect(0, 0,
                    $sheet->frameWidth * $sheet->columns, $sheet->frameHeight * $sheet->rows);
                PngAssetPreflight::inspect($assetRoot, $definition->asset, $crop);
            }
        }
        return $set;
    }
}
