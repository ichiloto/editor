<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Engine\Rendering\Tilesets\TileAppearance;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetAppearance;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;
use Ichiloto\Engine\Rendering\Tilesets\TilesetSheet;
use InvalidArgumentException;

/** Sheet selection addresses existing record fields, not a second tile database. */
final class TilesetAuthoring
{
    /** The Engine owns sheet admission; expose omissions rather than interpreting blank previews as valid art. */
    public static function getSheetIssues(Tileset $tileset, string $assetRoot): array
    {
        $usable = $tileset->getUsableSheets($assetRoot)['sheets'] ?? [];
        $issues = [];
        foreach (array_diff_key($tileset->sheets, $usable) as $name => $asset) {
            $issues[$name] = sprintf('Sheet %s (%s) cannot be drawn. Check that its PNG is readable, fits the sheet layout and shares the tileset tile size.', $name, $asset);
        }

        return $issues;
    }

    /** @param array<string, mixed> $data @param list<array<string, mixed>> $rows */
    public static function describePieces(array $data, array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            if (isset($row['key']['field']) && ($row['kind'] ?? 'info') !== 'info') {
                $keys[$row['key']['field']] = $row['key'];
            }
        }
        $pieces = [];
        foreach (array_keys(is_array($data['pieces'] ?? null) ? $data['pieces'] : []) as $index => $id) {
            $dataPiece = $data['pieces'][$id];
            if (! is_array($dataPiece)) {
                continue;
            }
            $prefix = 'piece' . $index;
            $connected = ($dataPiece['connects'] ?? null) === TilesetPiece::LINES;
            $issue = null;
            $blueprint = null;
            try {
                // The glyph footprint remains author-owned even while tile rows are being repaired.
                $glyphData = $dataPiece;
                unset($glyphData['tiles'], $glyphData['effect'], $glyphData['occupancy']);
                $blueprint = TilesetPiece::fromArray((string) $id, $glyphData, 'Tileset authoring');
            } catch (InvalidArgumentException $error) {
                $issue = $error->getMessage();
            }
            $occupancy = null;
            $occupancyIssue = null;
            $occupancyDeclared = array_key_exists('occupancy', $dataPiece);
            if ($occupancyDeclared) {
                try {
                    $occupancy = PhysicalFootprintCodec::exportRows($dataPiece['occupancy']);
                } catch (InvalidArgumentException $error) {
                    $occupancyIssue = $error->getMessage();
                }
                try {
                    TilesetPiece::fromArray((string) $id, $glyphData + ['occupancy' => $dataPiece['occupancy']], 'Tileset authoring');
                } catch (InvalidArgumentException $error) {
                    $occupancyIssue = $error->getMessage();
                }
            }
            $layers = [];
            foreach (array_keys(is_array($dataPiece['tiles'] ?? null) ? $dataPiece['tiles'] : []) as $layerIndex => $name) {
                $field = $prefix . 'Tiles' . $layerIndex . ($connected ? 'Tile' : 'Rows');
                $entries = $dataPiece['tiles'][$name];
                $shapes = $connected ? (is_string($entries) ? array_fill_keys(TilesetPiece::LINE_SHAPES, $entries) : $entries) : [];
                $layers[] = ['name' => (string) $name, 'key' => $keys[$field] ?? null,
                    'shapes' => (object) (is_array($shapes) ? $shapes : [])];
            }
            $pieces[] = ['id' => (string) $id, 'name' => (string) ($dataPiece['name'] ?? $id),
                'layer' => (string) ($dataPiece['layer'] ?? ''), 'connected' => $connected,
                'width' => $blueprint?->width, 'height' => $blueprint?->height,
                'key' => $keys[$prefix . 'Name'] ?? null, 'layers' => $layers, 'issue' => $issue,
                'occupancy' => $occupancy, 'occupancyKey' => $keys[$prefix . 'Occupancy'] ?? null,
                'occupancyDeclared' => $occupancyDeclared, 'occupancyIssue' => $occupancyIssue];
        }

        return $pieces;
    }

    /**
     * The record fields that give tiles or whole sheets a ground appearance
     * ({@see TilesetAppearance}), or take theirs away so they read by their
     * sheet again: each class's list with the selection moved into the class
     * chosen, and out of every other. An autotile is named by its kind.
     * Only the fields that change are returned, as the record row values the
     * Tile Appearance rows take.
     *
     * @param array<string, mixed> $data The tileset record as it stands.
     * @param list<int|string> $selectors Tile identities and sheet names.
     * @return array<string, string> Comma-separated values by record field id.
     * @throws InvalidArgumentException When a selector is neither a tile nor a sheet.
     */
    public static function planAppearance(array $data, array $selectors, ?TileAppearance $appearance): array
    {
        $chosen = [];
        foreach ($selectors as $selector) {
            if (is_string($selector) && TilesetSheet::tryFrom($selector) !== null) {
                $chosen[$selector] = $selector;
            } elseif (is_int($selector) && $selector !== TileId::EMPTY && TileId::isValid($selector)) {
                $chosen[TileId::getFlagId($selector)] = TileId::getFlagId($selector);
            } else {
                throw new InvalidArgumentException(sprintf('%s is neither a tile nor an RPG Maker sheet.', var_export($selector, true)));
            }
        }
        $current = is_array($data[TilesetAppearance::DATA_KEY] ?? null) ? $data[TilesetAppearance::DATA_KEY] : [];
        $fields = [];
        foreach (TileAppearance::cases() as $class) {
            $listed = is_array($current[$class->value] ?? null) ? array_values($current[$class->value]) : [];
            $kept = array_values(array_filter($listed, static fn(mixed $entry): bool
                => ! isset($chosen[is_int($entry) && TileId::isValid($entry) ? TileId::getFlagId($entry) : (string) $entry])));
            $next = $class === $appearance ? [...$kept, ...array_values($chosen)] : $kept;
            if ($next !== $listed) {
                $fields[TilesetAppearance::DATA_KEY . '.' . $class->value] = implode(', ', array_map(strval(...), $next));
            }
        }

        return $fields;
    }

    /**
     * What each tile shows at ground level, for a view of the sheets: its
     * class, or null when unknown, where that comes from (authored for the
     * tile, authored for its sheet, or the sheet kind's default) and its sheet.
     *
     * @param list<int> $tiles
     * @return array<int, array{class: ?string, source: 'tile'|'sheet'|'default', sheet: string}>
     */
    public static function describeAppearance(Tileset $tileset, array $tiles): array
    {
        $described = [];
        foreach ($tiles as $tile) {
            if ($tile === TileId::EMPTY || isset($described[$tile])) {
                continue;
            }
            $sheet = TileId::getSheet($tile)?->value;
            $described[$tile] = ['class' => $tileset->classifyAppearance($tile)?->value, 'sheet' => (string) $sheet,
                'source' => match (true) {
                    isset($tileset->appearance->tiles[TileId::getFlagId($tile)]) => 'tile',
                    ! $tileset->isAbove($tile) && ! $tileset->isTable($tile) && isset($tileset->appearance->sheets[$sheet ?? '']) => 'sheet',
                    default => 'default',
                }];
        }

        return $described;
    }
}
