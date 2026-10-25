<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Session;

use Ichiloto\Editor\Assets\AssetImport;
use Ichiloto\Editor\Assets\PngPixels;
use Ichiloto\Editor\Assets\TilesetSheetCells;
use Ichiloto\Editor\Database\RecordChange;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\History\AssetFileCommand;
use Ichiloto\Editor\History\CommandGroup;
use Ichiloto\Editor\Maps\TilesetPieceAuthoring;
use Ichiloto\Engine\Field\Enumerations\DoorwayEntrance;
use Ichiloto\Engine\Rendering\Tilesets\TileId;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;
use InvalidArgumentException;
use RuntimeException;

/**
 * Bringing a building's art into a project and defining its piece over that
 * art, as a graphical editor does it: an image brought into the assets for
 * any picker, cells copied into a tileset sheet's free cells, a new piece
 * from a selection of sheet cells, and the glyph and painted door of one of
 * its cells at a time. Each is one undo step. Image files are written at
 * once ({@see AssetFileCommand}); piece edits go through the tileset record's
 * own rows, so they are saved, validated and preserved like any record edit.
 */
trait TilesetArtSession
{
    /**
     * Brings an image into the project's assets ({@see AssetImport}) as one
     * undo step: written at once, not on Save, and removed again by Undo.
     *
     * @param string $destination Where it goes, under the assets: relative to them or absolute.
     * @return array{path: string, width: int, height: int, changed: bool} Its asset path, which a picker row then takes.
     * @throws SessionRefusal When the image cannot be brought in, saying why.
     */
    public function importAsset(string $source, string $destination, bool $replace = false): array
    {
        try {
            $import = AssetImport::plan($this->workspace->projectRoot, $source, $destination, $replace);
        } catch (InvalidArgumentException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }
        if ($import['plan'] !== null) {
            $this->writeAssetPlan(new AssetFileCommand(sprintf('Import %s', $import['path']), $import['plan']));
        }

        return ['path' => $import['path'], 'width' => $import['width'], 'height' => $import['height'], 'changed' => $import['plan'] !== null];
    }

    /**
     * The cells of a tileset record's plain sheets that hold no art yet,
     * unsaved sheet choices included, by tile identity: where copied cells
     * can go. A sheet that cannot be read is named instead.
     *
     * @return array{tileset: string, free: list<int>, issues: list<string>}
     * @throws SessionRefusal When the record changed or the Engine would refuse the tileset.
     */
    public function readTilesetFreeCells(int $index, string $tilesetId): array
    {
        $tileset = $this->requireEditedTileset($index, $tilesetId);
        $assets = rtrim($this->workspace->projectRoot, '/') . '/assets';
        $free = [];
        $issues = [];
        foreach (TilesetSheetCells::describeSheets($tileset) as $sheet) {
            try {
                foreach (TilesetSheetCells::readFreeCells($tileset, $sheet['sheet'], $assets)['free'] as $row => $cells) {
                    foreach ($cells as $column => $isFree) {
                        if ($isFree) {
                            $free[] = $sheet['ids'][$row][$column];
                        }
                    }
                }
            } catch (InvalidArgumentException $error) {
                $issues[] = $error->getMessage();
            }
        }

        return ['tileset' => $tileset->id, 'free' => $free, 'issues' => $issues];
    }

    /**
     * An image cut into cells of the tileset's tile size, for choosing cells
     * to copy: its size in cells and which cells are empty.
     *
     * @return array{source: string, tileSize: int, columns: int, rows: int, empty: list<array{0: int, 1: int}>}
     * @throws SessionRefusal When the image cannot be read or the tileset has no readable sheet to size its cells by.
     */
    public function readImageCells(int $index, string $tilesetId, string $source): array
    {
        $tileset = $this->requireEditedTileset($index, $tilesetId);
        $assets = rtrim($this->workspace->projectRoot, '/') . '/assets';
        $tileSize = $tileset->getUsableSheets($assets)['tileSize']
            ?? throw new SessionRefusal(sprintf('Tileset %s has no sheet that can be read yet, so its cells have no size. Choose its sheets first.', $tileset->name));
        try {
            AssetImport::resolveDestination($assets, $source);
            $bytes = is_file($assets . '/' . $source) ? file_get_contents($assets . '/' . $source) : false;
            $image = PngPixels::decode($bytes === false ? '' : $bytes, $source);
        } catch (InvalidArgumentException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }
        [$columns, $rows] = [intdiv($image->width, $tileSize), intdiv($image->height, $tileSize)];
        $empty = [];
        for ($row = 0; $row < $rows; $row++) {
            for ($column = 0; $column < $columns; $column++) {
                if ($image->isTransparent($column * $tileSize, $row * $tileSize, $tileSize, $tileSize)) {
                    $empty[] = [$column, $row];
                }
            }
        }

        return ['source' => $source, 'tileSize' => $tileSize, 'columns' => $columns, 'rows' => $rows, 'empty' => $empty];
    }

    /**
     * Copies a rectangle of an image's cells into free cells of one of the
     * tileset's plain sheets, its top-left on the cell of a tile
     * ({@see TilesetSheetCells::planCopy()}), as one undo step written at once.
     *
     * @param array{0: int, 1: int} $from The image cell the rectangle starts at.
     * @param array{0: int, 1: int} $size Its width and height in cells.
     * @param int $tile The plain tile whose sheet cell the rectangle's top-left lands on.
     * @return array{sheet: string, asset: string, tiles: list<list<int>>} The tile identities the copied cells now have.
     * @throws SessionRefusal When the cells cannot be copied there, saying why; nothing is written then.
     */
    public function copyTilesetCells(int $index, string $tilesetId, string $source, array $from, array $size, int $tile): array
    {
        $tileset = $this->requireEditedTileset($index, $tilesetId);
        try {
            [$sheet, $column, $row] = TileId::getPlainTileCell($tile);
            $copy = TilesetSheetCells::planCopy($this->workspace->projectRoot, $tileset, $sheet->value, $source, $from, $size, [$column, $row]);
        } catch (InvalidArgumentException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }
        $this->writeAssetPlan(new AssetFileCommand(sprintf('Copy cells into sheet %s', $copy['sheet']), $copy['plan']));

        return ['sheet' => $copy['sheet'], 'asset' => $copy['asset'], 'tiles' => $copy['tiles']];
    }

    /**
     * A new stamped piece from a rectangle of sheet tiles, as one undo step:
     * its id made from its name, its glyphs on the gameplay layer named, a
     * blank glyph in every cell until the author gives each its own, and the
     * tiles on the tile layer named. Collision, glyphs and doors are the
     * author's to set; nothing is read from the art.
     *
     * @param list<list<int>> $rows The tile identities, row by row, as the sheet selection holds them.
     * @return array{id: string, records: list<string>}
     * @throws SessionRefusal When the record changed, a name is missing or the piece would be refused.
     */
    public function createTilesetPiece(int $index, string $tilesetId, string $name, string $layer, string $tileLayer, array $rows): array
    {
        $this->requireEditedTileset($index, $tilesetId);
        $width = count($rows[0] ?? []);
        if ($width === 0 || ! array_is_list($rows) || array_any($rows, static fn(mixed $row): bool => ! is_array($row) || count($row) !== $width
            || array_any($row, static fn(mixed $tile): bool => ! is_int($tile) || ! ($tile === TileId::EMPTY || TileId::isValid($tile))))) {
            throw new SessionRefusal('Select a rectangle of sheet cells for the piece.');
        }
        foreach (['name' => $name, 'glyph layer' => $layer, 'tile layer' => $tileLayer] as $what => $value) {
            if (trim($value) === '') {
                throw new SessionRefusal(sprintf('Give the piece a %s.', $what));
            }
        }
        $record = $this->requireRecordDatabase('tilesets')->getRecordByIndex($index);
        $data = (array) $record?->toArray();
        $pieces = is_array($data['pieces'] ?? null) ? $data['pieces'] : [];
        try {
            $id = TilesetPieceAuthoring::createPieceId($name, array_map(strval(...), array_keys($pieces)));
        } catch (InvalidArgumentException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }
        $piece = 'piece' . count($pieces);
        $database = $this->requireCategory('tilesets');
        $made = [];
        $change = function (callable $edit) use (&$made): void {
            /** @var RecordChange $applied */
            $applied = $edit();
            if ($applied->command !== null) {
                $made[] = $applied->command;
            }
        };
        try {
            $change(static fn(): RecordChange => $database->addItem($index, [], null, false));
            foreach ([
                'Id' => $id,
                'Name' => trim($name),
                'Layer' => trim($layer),
                'Glyphs' => implode("\n", TilesetPieceAuthoring::createBlankRows($width, count($rows))),
            ] as $field => $value) {
                $change(static fn(): RecordChange => $database->applyField($index, [], $piece . $field, $value, 'New piece'));
            }
            $change(static fn(): RecordChange => $database->addItem($index, [], $piece . 'Name', true));
            $change(static fn(): RecordChange => $database->applyField($index, [], $piece . 'Tiles0Layer', trim($tileLayer), 'New piece'));
            $change(static fn(): RecordChange => $database->applyField($index, [], $piece . 'Tiles0Rows',
                implode("\n", array_map(static fn(array $row): string => implode(' ', $row), $rows)), 'New piece'));
            TilesetPiece::fromArray($id, (array) ((array) $record->toArray())['pieces'][$id], 'New piece');
        } catch (RecordRefusal|InvalidArgumentException|SessionRefusal $refusal) {
            foreach (array_reverse($made) as $command) {
                $command->undo();
            }
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        $this->history->record(new CommandGroup(sprintf('New piece %s', trim($name)), $made));

        return ['id' => $id, 'records' => $database->getRecordLabels()];
    }

    /**
     * Sets one cell's terminal glyph in a stamped piece, as one undo step
     * through its Glyph Rows, every other cell keeping its source
     * ({@see TilesetPieceAuthoring::rewriteGlyph()}). A space clears the cell.
     *
     * @param string|null $color The cell's colour by the brush contract: null keeps its own, '' none.
     * @return array{changed: bool, records: list<string>}
     * @throws SessionRefusal When the piece is gone, connected, or the glyph cannot be used.
     */
    public function setTilesetPieceGlyph(int $index, string $tilesetId, string $pieceId, int $x, int $y, string $symbol, ?string $color): array
    {
        [$piece, $prefix] = $this->requireStampedPiece($index, $tilesetId, $pieceId);
        try {
            $rows = TilesetPieceAuthoring::rewriteGlyph(array_map(strval(...), (array) ($piece['glyphs'] ?? [])), $x, $y, $symbol, $color);
        } catch (InvalidArgumentException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }

        return $this->applyDatabaseRecord('tilesets', $index, ['field' => $prefix . 'Glyphs', 'frame' => []], implode("\n", $rows));
    }

    /**
     * Sets the painted door at one cell of a stamped piece, `on` (walked onto)
     * or `front` (entered from the cell in front), or with null takes it away,
     * as one undo step through its Painted Doors row.
     *
     * @return array{changed: bool, records: list<string>}
     * @throws SessionRefusal When the piece is gone or connected, the cell is outside it, or the entrance is not one.
     */
    public function setTilesetPieceDoor(int $index, string $tilesetId, string $pieceId, int $x, int $y, ?string $entrance): array
    {
        [$piece, $prefix] = $this->requireStampedPiece($index, $tilesetId, $pieceId);
        $relation = $entrance === null ? null : (DoorwayEntrance::tryFrom($entrance)
            ?? throw new SessionRefusal(sprintf('%s is not a door entrance; a door is entered on it or from its front.', $entrance)));
        $glyphs = (array) ($piece['glyphs'] ?? []);
        if ($y < 0 || $x < 0 || ! isset($glyphs[$y]) || $x >= count(\Ichiloto\Engine\IO\Console\TerminalText::parseSourceCells((string) $glyphs[$y]))) {
            throw new SessionRefusal(sprintf('The piece has no cell %d, %d.', $x, $y));
        }
        try {
            $value = TilesetPieceAuthoring::setDoor($piece['doorway'] ?? null, $x, $y, $relation);
        } catch (InvalidArgumentException $error) {
            throw new SessionRefusal('Repair the piece\'s Painted Doors row first: ' . $error->getMessage(), previous: $error);
        }

        return $this->applyDatabaseRecord('tilesets', $index, ['field' => $prefix . 'Doorway', 'frame' => []], $value);
    }

    /**
     * The tileset record at an index, as the Engine reads it with its unsaved edits.
     *
     * @throws SessionRefusal When the record is not that tileset any more, or the Engine would refuse it.
     */
    private function requireEditedTileset(int $index, string $tilesetId): Tileset
    {
        $record = $this->requireRecordDatabase('tilesets')->getRecordByIndex($index)
            ?? throw new SessionRefusal(sprintf('tilesets has no record %d.', $index));
        if ($record->recordId !== $tilesetId) {
            throw new SessionRefusal('That tileset record changed; read the tileset list again.');
        }
        try {
            return Tileset::fromArray($record->recordId, (array) $record->toArray());
        } catch (InvalidArgumentException $error) {
            throw new SessionRefusal(sprintf('Tileset %s cannot be read: %s', $tilesetId, $error->getMessage()), previous: $error);
        }
    }

    /**
     * A stamped piece of a tileset record as authored, with the prefix of its record rows.
     *
     * @return array{0: array<string, mixed>, 1: string}
     * @throws SessionRefusal When the record changed or the piece is gone or connected.
     */
    private function requireStampedPiece(int $index, string $tilesetId, string $pieceId): array
    {
        $record = $this->requireRecordDatabase('tilesets')->getRecordByIndex($index)
            ?? throw new SessionRefusal(sprintf('tilesets has no record %d.', $index));
        if ($record->recordId !== $tilesetId) {
            throw new SessionRefusal('That tileset record changed; read the tileset list again.');
        }
        $pieces = ((array) $record->toArray())['pieces'] ?? [];
        $position = is_array($pieces) ? array_search($pieceId, array_map(strval(...), array_keys($pieces)), true) : false;
        if ($position === false || ! is_array($pieces[$pieceId])) {
            throw new SessionRefusal(sprintf('%s is no longer in tileset %s; choose a piece again.', $pieceId, $tilesetId));
        }
        if (($pieces[$pieceId]['connects'] ?? null) !== null) {
            throw new SessionRefusal(sprintf('%s is drawn as connected cells; its glyphs are set per shape in the inspector.', $pieceId));
        }

        return [$pieces[$pieceId], 'piece' . $position];
    }

    /** Writes an artwork file at once and keeps the write in history, or refuses having written nothing. */
    private function writeAssetPlan(AssetFileCommand $command): void
    {
        try {
            $command->execute();
        } catch (RuntimeException $failure) {
            throw new SessionRefusal($failure->getMessage(), previous: $failure);
        }
        $this->history->record($command);
    }
}
