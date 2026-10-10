<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Canvas\ConnectedPieceShaper;
use Ichiloto\Editor\Canvas\PiecePlacer;
use Ichiloto\Engine\Rendering\Tilesets\AutotileShape;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;
use Ichiloto\Engine\Rendering\Tilesets\TileId;

/** Exhaustive cardinal neighbourhoods, shaped by the same services as piece placement. */
final class ConnectedPiecePreview
{
    /** @return list<array<string, mixed>> */
    public static function describe(Tileset $tileset, TilesetPiece $piece, string $assetRoot, array $sheetIssues = []): array
    {
        if ($piece->connects === null) {
            return [];
        }
        $previews = [];
        $usedIssues = [];
        foreach ($piece->shapeTiles as $shapes) {
            foreach ($shapes as $tile) {
                $sheet = TileId::getSheet((int) $tile)?->value;
                if ((int) $tile !== TileId::EMPTY && isset($sheetIssues[$sheet])) {
                    $usedIssues[$sheet] = $sheetIssues[$sheet];
                }
            }
        }
        foreach (self::buildSamples($piece) as $sample) {
            $world = null;
            $issue = $usedIssues === [] ? null : implode(' ', $usedIssues);
            try {
                $world = TilePalette::buildPieceWorld($tileset, $sample['piece'], $assetRoot, 'connection:' . $piece->id . ':' . $sample['mask']);
            } catch (\Throwable $error) {
                $issue = $error->getMessage();
            }
            $previews[] = ['mask' => $sample['mask'], 'label' => $sample['label'], 'shape' => $sample['shape'],
                'picture' => PiecePlacer::buildPicture($sample['piece']), 'operations' => $world?->operations, 'issue' => $issue];
        }

        return $previews;
    }

    /** @return list<array{mask: int, label: string, shape: string, piece: TilesetPiece}> */
    public static function buildSamples(TilesetPiece $piece): array
    {
        if ($piece->connects === null) {
            return [];
        }
        $samples = [];
        $sources = $piece->getSourceShapeGrid();
        for ($mask = 0; $mask < 16; ++$mask) {
            // The empty border prevents Engine's beyond-map-edge rule joining these samples.
            $drawn = [['x' => 2, 'y' => 2]];
            $labels = [];
            foreach ([[0, -1, 'N'], [1, 0, 'E'], [0, 1, 'S'], [-1, 0, 'W']] as $bit => [$dx, $dy, $label]) {
                if (($mask & (1 << $bit)) !== 0) {
                    $drawn[] = ['x' => 2 + $dx, 'y' => 2 + $dy];
                    $labels[] = $label;
                }
            }
            $cells = ConnectedPieceShaper::reshapeCells($piece, $drawn, [], static fn(int $x, int $y): bool => false);
            $glyphs = array_fill(0, 5, array_fill(0, 5, ' '));
            $tiles = array_fill_keys(array_keys($piece->shapeTiles), array_fill(0, 5, array_fill(0, 5, 0)));
            foreach ($cells as $cell) {
                $glyphs[$cell['y']][$cell['x']] = $sources[$cell['shape']];
                foreach ($piece->shapeTiles as $layer => $shapes) {
                    $tiles[$layer][$cell['y']][$cell['x']] = (int) $shapes[$cell['shape']];
                }
            }
            $tiles = array_map(static fn(array $grid): array => array_map(
                static fn(array $row): array => array_map(strval(...), $row), AutotileShape::resolveLayer($grid)), $tiles);
            $samples[] = ['mask' => $mask, 'label' => $labels === [] ? 'Isolated' : implode(' + ', $labels),
                'shape' => $cells[0]['shape'], 'piece' => new TilesetPiece($piece->id, $piece->name, $piece->layer, $glyphs, $tiles)];
        }

        return $samples;
    }
}
