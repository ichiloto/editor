<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

use Ichiloto\Editor\Storage\SourceSetPlan;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetSheet;
use InvalidArgumentException;

/**
 * A tileset's plain sheets (A5 and B to E) cell by cell, as their images lay
 * them out, and new art copied into their free cells.
 *
 * Art for a building often arrives as its own image, such as a B sheet of its
 * own, while the tileset's sheets are already chosen. RPG Maker keeps four
 * upper sheets, so the building's cells go into cells of a sheet that nothing
 * is drawn on yet. Copying takes a rectangle of whole cells from the other
 * image and lays it, pixel for pixel, over free cells of the sheet: cells
 * where every pixel is transparent. Cells that already hold art are never
 * drawn over, and the rest of the sheet keeps every pixel as it was
 * ({@see PngPixels}). Tile identities follow from where the cells land, as
 * RPG Maker numbers them ({@see TileId::getPlainTileAt()}), and nothing else
 * in the project changes: no piece, map or saved game names the new tiles
 * until the author uses them.
 */
final class TilesetSheetCells
{
    /** The sheets cells are copied into: those of plain tiles, drawn whole. */
    public const array PLAIN_SHEETS = [TilesetSheet::A5, TilesetSheet::B, TilesetSheet::C, TilesetSheet::D, TilesetSheet::E];

    private function __construct()
    {
    }

    /**
     * The tileset's plain sheets as their images lay them out: each with its
     * asset, its size in cells and the tile at every cell, row by row.
     *
     * @return list<array{sheet: string, asset: string, columns: int, rows: int, ids: list<list<int>>}>
     */
    public static function describeSheets(Tileset $tileset): array
    {
        $sheets = [];
        foreach (self::PLAIN_SHEETS as $sheet) {
            $asset = $tileset->sheets[$sheet->value] ?? null;
            if (! is_string($asset)) {
                continue;
            }
            $ids = [];
            for ($row = 0; $row < $sheet->getRows(); $row++) {
                for ($column = 0; $column < $sheet->getColumns(); $column++) {
                    $ids[$row][$column] = TileId::getPlainTileAt($sheet, $column, $row);
                }
            }
            $sheets[] = ['sheet' => $sheet->value, 'asset' => $asset, 'columns' => $sheet->getColumns(), 'rows' => $sheet->getRows(), 'ids' => $ids];
        }

        return $sheets;
    }

    /**
     * Which cells of a sheet are free, as its image is now: true where every
     * pixel is transparent. Cell 0, 0 of B is never free, being the empty
     * tile that erases.
     *
     * @return array{sheet: string, asset: string, tileSize: int, free: list<list<bool>>}
     * @throws InvalidArgumentException When the sheet is not a plain sheet of the tileset, or its image cannot be read.
     */
    public static function readFreeCells(Tileset $tileset, string $sheetName, string $assetRoot): array
    {
        [$sheet, $asset, $image, $size] = self::readSheet($tileset, $sheetName, $assetRoot);
        $free = [];
        for ($row = 0; $row < $sheet->getRows(); $row++) {
            for ($column = 0; $column < $sheet->getColumns(); $column++) {
                $free[$row][$column] = ! self::isEmptyTile($sheet, $column, $row) && $image->isTransparent($column * $size, $row * $size, $size, $size);
            }
        }

        return ['sheet' => $sheet->value, 'asset' => $asset, 'tileSize' => $size, 'free' => $free];
    }

    /**
     * Plans copying a rectangle of whole cells from another image into free
     * cells of a sheet, the other image cut into cells of the sheet's tile
     * size from its top-left.
     *
     * @param array{0: int, 1: int} $from The source rectangle's top-left cell.
     * @param array{0: int, 1: int} $size Its width and height in cells.
     * @param array{0: int, 1: int} $to The sheet cell its top-left lands on.
     * @return array{plan: SourceSetPlan, tiles: list<list<int>>, sheet: string, asset: string} The write, and the tile
     *     identity of each copied cell, in the source rectangle's rows.
     * @throws InvalidArgumentException When the cells cannot be copied there, saying why.
     */
    public static function planCopy(string $projectRoot, Tileset $tileset, string $sheetName, string $source, array $from, array $size,
        array $to): array
    {
        $assetRoot = rtrim($projectRoot, '/') . '/assets';
        [$sheet, $asset, $image, $tileSize] = self::readSheet($tileset, $sheetName, $assetRoot);
        [$width, $height] = $size;
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('Choose at least one cell to copy.');
        }
        if ($to[0] < 0 || $to[1] < 0 || $to[0] + $width > $sheet->getColumns() || $to[1] + $height > $sheet->getRows()) {
            throw new InvalidArgumentException(sprintf('%d x %d cells do not fit sheet %s at %d, %d: it is %d x %d cells. Nothing was copied.',
                $width, $height, $sheet->value, $to[0], $to[1], $sheet->getColumns(), $sheet->getRows()));
        }
        try {
            SpriteValidation::validateAssetPath($source);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException("{$source} is not an image in the assets folder.");
        }
        $sourcePath = $assetRoot . '/' . $source;
        $sourceBytes = is_file($sourcePath) ? file_get_contents($sourcePath) : false;
        if ($sourceBytes === false) {
            throw new InvalidArgumentException("{$source} cannot be read.");
        }
        $pixels = PngPixels::decode($sourceBytes, $source);
        [$sourceColumns, $sourceRows] = [intdiv($pixels->width, $tileSize), intdiv($pixels->height, $tileSize)];
        if ($from[0] < 0 || $from[1] < 0 || $from[0] + $width > $sourceColumns || $from[1] + $height > $sourceRows) {
            throw new InvalidArgumentException(sprintf('%s is %d x %d cells of %d pixels, so it has no %d x %d cells at %d, %d.',
                $source, $sourceColumns, $sourceRows, $tileSize, $width, $height, $from[0], $from[1]));
        }
        if ($pixels->isTransparent($from[0] * $tileSize, $from[1] * $tileSize, $width * $tileSize, $height * $tileSize)) {
            throw new InvalidArgumentException("Those cells of {$source} are empty, so there is nothing to copy.");
        }
        $taken = [];
        $tiles = [];
        for ($row = 0; $row < $height; $row++) {
            for ($column = 0; $column < $width; $column++) {
                [$x, $y] = [$to[0] + $column, $to[1] + $row];
                if (self::isEmptyTile($sheet, $x, $y)) {
                    throw new InvalidArgumentException('Cell 0, 0 of sheet B is the empty tile, which erases. Copy into other cells; nothing was copied.');
                }
                if (! $image->isTransparent($x * $tileSize, $y * $tileSize, $tileSize, $tileSize)) {
                    $taken[] = "{$x}, {$y}";
                }
                $tiles[$row][$column] = TileId::getPlainTileAt($sheet, $x, $y);
            }
        }
        if ($taken !== []) {
            throw new InvalidArgumentException(sprintf('Sheet %s already has art in %s %s. Copy into free cells only; nothing was copied.',
                $sheet->value, count($taken) === 1 ? 'cell' : 'cells', self::listCells($taken)));
        }
        $copied = $image->withPixelsFrom($pixels, $from[0] * $tileSize, $from[1] * $tileSize, $width * $tileSize, $height * $tileSize,
            $to[0] * $tileSize, $to[1] * $tileSize);
        $target = $assetRoot . '/' . $asset;
        $original = (string) file_get_contents($target);
        $readBack = static fn(string $path, string $staged): string => PngPixels::decode((string) file_get_contents($staged), $path)->getPixelHash();
        $plan = new SourceSetPlan($projectRoot, [$target => $original], [$target => $original],
            [$target => $copied->encode()], [$target => $image->getPixelHash()], [$target => $copied->getPixelHash()],
            static fn(): array => [$target], $readBack, 'cell copy', "Sheet {$sheet->value}");

        return ['plan' => $plan, 'tiles' => $tiles, 'sheet' => $sheet->value, 'asset' => $asset];
    }

    /** @return array{0: TilesetSheet, 1: string, 2: PngPixels, 3: int} */
    private static function readSheet(Tileset $tileset, string $sheetName, string $assetRoot): array
    {
        $sheet = TilesetSheet::tryFrom($sheetName);
        if ($sheet === null || ! in_array($sheet, self::PLAIN_SHEETS, true)) {
            throw new InvalidArgumentException(sprintf('Cells are copied into the sheets of whole tiles, A5 and B to E; %s is not one.', $sheetName));
        }
        $asset = $tileset->sheets[$sheet->value] ?? throw new InvalidArgumentException(sprintf(
            'Tileset %s has no sheet %s yet. Choose its image in the inspector first.', $tileset->name, $sheet->value));
        $path = $assetRoot . '/' . $asset;
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            throw new InvalidArgumentException("Sheet {$sheet->value} ({$asset}) cannot be read.");
        }
        $image = PngPixels::decode($bytes, $asset);
        $tileSize = intdiv($image->width, $sheet->getColumns());
        if ($image->width % $sheet->getColumns() !== 0 || $image->height !== $tileSize * $sheet->getRows()) {
            throw new InvalidArgumentException(sprintf('Sheet %s (%s) is %d x %d pixels, which is not %d x %d cells of one size.',
                $sheet->value, $asset, $image->width, $image->height, $sheet->getColumns(), $sheet->getRows()));
        }

        return [$sheet, $asset, $image, $tileSize];
    }

    private static function isEmptyTile(TilesetSheet $sheet, int $column, int $row): bool
    {
        return $sheet === TilesetSheet::B && $column === 0 && $row === 0;
    }

    /** @param list<string> $cells */
    private static function listCells(array $cells): string
    {
        $shown = array_slice($cells, 0, 4);

        return implode('; ', $shown) . (count($cells) > count($shown) ? sprintf(' and %d more', count($cells) - count($shown)) : '');
    }
}
