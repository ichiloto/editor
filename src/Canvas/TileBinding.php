<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;

/**
 * One way a tile stands for a glyph: the tile is drawn by a piece role
 * whose glyph sits on a gameplay layer, `dx` columns and `dy` rows from it,
 * so the glyph's cell is the tile's cell less that offset. A tile that no
 * role draws stands for nothing in the terminal and has no binding.
 */
final class TileBinding
{
    /**
     * @param string $layerId The gameplay layer the glyph is on.
     * @param string $glyph The glyph the tile stands for.
     * @param PieceRole $role The role that draws the tile.
     * @param int $dx Columns from the glyph cell to the tile.
     * @param int $dy Rows from the glyph cell to the tile.
     * @param TilesetPiece $piece The piece the role belongs to.
     * @param list<array{dx: int, dy: int, glyph: string, key: string}> $companions The piece's glyph cells that draw no
     *     tile, relative to this role's glyph cell; they come and go with it, as the lower half of a window does.
     */
    public function __construct(
        public readonly string $layerId,
        public readonly string $glyph,
        public readonly PieceRole $role,
        public readonly int $dx,
        public readonly int $dy,
        public readonly TilesetPiece $piece,
        public readonly array $companions = [],
    ) {}

    /** Whether the glyph belongs to a connected piece, whose shape its neighbours decide. */
    public bool $isConnected {
        get => $this->piece->connects !== null;
    }
}
