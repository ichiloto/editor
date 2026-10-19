<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use RuntimeException;

/** Validates an NPC's optional RPG Maker character sheet exactly as the game will read it. */
final class NpcCharacterSheet
{
    /** @param array<string, mixed> $data */
    public static function validate(array $data, ?string $assetRoot = null): CharacterSheet
    {
        $sheet = CharacterSheet::fromArray($data);
        if ($sheet->layer < PresentationLayerPolicy::WORLD || $sheet->layer >= PresentationLayerPolicy::UI) {
            throw new RuntimeException('Automatic Game world sprites require layers 0..999; UI layers are reserved.');
        }
        if ($assetRoot !== null) {
            $image = PngAssetPreflight::inspect($assetRoot, $sheet->asset);
            $sheet->getFrameSize($image['width'], $image['height']);
        }
        return $sheet;
    }
}
