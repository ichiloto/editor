<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Database\PhpValueExporter;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use InvalidArgumentException;
use JsonException;

/** Wire values are final collision integers; authored PHP keeps Engine enum cases. */
final class PhysicalFootprintCodec
{
    /** @return list<list<CollisionType|null>> */
    public static function decode(string $encoded): array
    {
        try {
            // Objects with numeric keys are not JSON lists.
            $rows = json_decode($encoded, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Physical footprint must be JSON rows of final collision integers or null: ' . $error->getMessage(), previous: $error);
        }

        return self::decodeRows($rows);
    }

    /** @return list<list<CollisionType|null>> Geometry is validated by TilesetPiece, never guessed here. */
    public static function decodeRows(mixed $rows): array
    {
        self::assertRows($rows);
        $decoded = [];
        foreach ($rows as $y => $row) {
            $decoded[$y] = [];
            foreach ($row as $x => $cell) {
                $type = is_int($cell) ? CollisionType::tryFrom($cell) : null;
                if ($cell !== null && (! is_int($cell) || $type === null || $type === CollisionType::PASS_THROUGH)) {
                    throw new InvalidArgumentException("Physical footprint cell {$x}, {$y} must be a final CollisionType integer or null; PASS_THROUGH is not a physical cell.");
                }
                $decoded[$y][$x] = $type;
            }
        }

        return $decoded;
    }

    /** @return list<list<int|null>> Only valid authored cells are projected, without repairing or padding them. */
    public static function exportRows(mixed $rows): array
    {
        self::assertRows($rows);
        $exported = [];
        foreach ($rows as $y => $row) {
            $exported[$y] = [];
            foreach ($row as $x => $type) {
                if ($type !== null && (! $type instanceof CollisionType || $type === CollisionType::PASS_THROUGH)) {
                    throw new InvalidArgumentException("Physical footprint cell {$x}, {$y} must be a final CollisionType case or null.");
                }
                $exported[$y][$x] = $type?->value;
            }
        }

        return $exported;
    }

    /** Display invalid declarations as authored so the record row can repair them, not as an invented valid mask. */
    public static function encode(mixed $rows): string
    {
        if ($rows === null) { return ''; }
        try {
            return json_encode($rows, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Unsupported enum declarations remain visible for explicit row repair.
            return PhpValueExporter::export($rows);
        }
    }

    private static function assertRows(mixed $rows): void
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new InvalidArgumentException('Physical footprint must be a zero-based list of rows.');
        }
        foreach ($rows as $y => $row) {
            if (! is_array($row) || ! array_is_list($row)) {
                throw new InvalidArgumentException("Physical footprint row {$y} must be a zero-based list of cells.");
            }
        }
    }
}
