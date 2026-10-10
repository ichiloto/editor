<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use InvalidArgumentException;
use RuntimeException;

/** Read-time character crops; authored data never owns image dimensions or layout. */
final class CharacterSheetPreview
{
    /** @return array{frames: list<array{label: string, sourceRect: array{x: int, y: int, width: int, height: int}}>, issue?: string} */
    public static function describe(mixed $data, ?string $assetRoot): array
    {
        try {
            if (! is_array($data)) {
                throw new InvalidArgumentException('A character sheet requires sheet, index and layer data.');
            }
            if ($assetRoot === null) {
                throw new RuntimeException('The current owner has no project asset root for character-sheet preview.');
            }
            $sheet = NpcCharacterSheet::validate($data);
            $image = PngAssetPreflight::inspect($assetRoot, $sheet->asset);
            $size = $sheet->getFrameSize($image['width'], $image['height']);
            $frames = [];
            foreach (['South' => MovementHeading::SOUTH, 'West' => MovementHeading::WEST,
                'East' => MovementHeading::EAST, 'North' => MovementHeading::NORTH] as $label => $heading) {
                $frames[] = ['label' => $label, 'sourceRect' => $sheet->getFrame($heading, 1, $size)->sourceRect->toArray()];
            }

            return ['frames' => $frames];
        } catch (InvalidArgumentException|RuntimeException $issue) {
            return ['frames' => [], 'issue' => $issue->getMessage()];
        }
    }

    /** @return list<string> Character identity choices, independent of stale optional index/layer values. */
    public static function getIndexOptions(string $asset): array
    {
        try {
            $sheet = new CharacterSheet($asset);

            return array_map(strval(...), range(0, $sheet->isSingleCharacter ? 0
                : CharacterSheet::SHEET_CHARACTER_COLUMNS * CharacterSheet::SHEET_CHARACTER_ROWS - 1));
        } catch (InvalidArgumentException) {
            return [];
        }
    }
}
