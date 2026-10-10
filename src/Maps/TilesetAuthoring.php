<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
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
                unset($glyphData['tiles'], $glyphData['effect']);
                $blueprint = TilesetPiece::fromArray((string) $id, $glyphData, 'Tileset authoring');
            } catch (InvalidArgumentException $error) {
                $issue = $error->getMessage();
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
                'key' => $keys[$prefix . 'Name'] ?? null, 'layers' => $layers, 'issue' => $issue];
        }

        return $pieces;
    }
}
