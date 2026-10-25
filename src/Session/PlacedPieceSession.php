<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Session;

use Ichiloto\Editor\Canvas\PlacedPieces;
use Ichiloto\Editor\Events\EventRefusal;
use Ichiloto\Editor\MapSourceRefusal;

/**
 * The stamped pieces standing on a map, such as buildings, as an author
 * selects, moves and removes them, and gives a painted door its entrance
 * ({@see PlacedPieces}). Each edit is one undo step on the map, saved with it.
 */
trait PlacedPieceSession
{
    /**
     * The placed pieces covering a cell, the smallest first: each with where
     * it stands, its size, whether it has a physical footprint, and its doors
     * with the cell each is entered from and the entrance meeting it.
     *
     * @return array{map: string, revision: int, pieces: list<array<string, mixed>>}
     * @throws SessionRefusal When the map is unknown or its tiles cannot be read.
     */
    public function findPlacedPieces(string $mapId, int $x, int $y): array
    {
        $map = $this->requireMap($mapId);
        try {
            $found = PlacedPieces::findAt($map, $x, $y);
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }

        return ['map' => $map->mapId, 'revision' => $this->getMapRevision($map),
            'pieces' => array_map(static fn(array $copy): array => PlacedPieces::describe($map, $copy['piece'], $copy['x'], $copy['y']), $found)];
    }

    /**
     * Removes a placed piece as one undo step: its glyphs, its tiles and,
     * with `$footprint`, the collision its physical footprint set, which goes
     * back to what the terminal layers left there say.
     *
     * @return array{revision: int, freed: int}
     * @throws SessionRefusal When the map is stale or read-only, or no whole copy stands there.
     */
    public function removePlacedPiece(string $mapId, int $revision, string $pieceId, int $x, int $y, bool $footprint): array
    {
        $map = $this->requireEditablePieceMap($mapId, $revision);
        $piece = $this->requirePiece($map, $pieceId);
        try {
            $removed = PlacedPieces::remove($map, $piece, $x, $y, $footprint, sprintf('Remove %s', $piece->name));
        } catch (MapSourceRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        $this->history->record($removed['command']);

        return ['revision' => $this->getMapRevision($map), 'freed' => $removed['cells']];
    }

    /**
     * Moves a placed piece so its top-left stands on another cell, as one
     * undo step, with its footprint when asked and the entrances at its doors.
     *
     * @return array{revision: int, entrances: list<string>}
     * @throws SessionRefusal When the map is stale or read-only, or the piece cannot be moved whole there.
     */
    public function movePlacedPiece(string $mapId, int $revision, string $pieceId, array $from, array $to, bool $footprint, ?string $color): array
    {
        $map = $this->requireEditablePieceMap($mapId, $revision);
        $piece = $this->requirePiece($map, $pieceId);
        try {
            $moved = PlacedPieces::move($map, $piece, $from[0], $from[1], $to[0], $to[1], $footprint, $color);
        } catch (MapSourceRefusal|EventRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        $this->history->record($moved['command']);

        return ['revision' => $this->getMapRevision($map), 'entrances' => $moved['entrances']];
    }

    /**
     * Places a new entrance on the cell a placed piece's door is entered
     * from, as one undo step. Its destination is set as any transfer's is.
     *
     * @param int $door The door's place in the piece's painted doors, from 0.
     * @return array{revision: int, marker: string, cell: array{0: int, 1: int}}
     * @throws SessionRefusal When the map is stale or read-only, the door is unknown or already has an entrance.
     */
    public function addPlacedPieceEntrance(string $mapId, int $revision, string $pieceId, int $x, int $y, int $door): array
    {
        $map = $this->requireEditablePieceMap($mapId, $revision);
        $piece = $this->requirePiece($map, $pieceId);
        try {
            $added = PlacedPieces::addEntrance($map, $piece, $x, $y, $door);
        } catch (MapSourceRefusal|EventRefusal $refusal) {
            throw new SessionRefusal($refusal->getMessage(), previous: $refusal);
        }
        $this->history->record($added['command']);

        return ['revision' => $this->getMapRevision($map), 'marker' => $added['marker'], 'cell' => $added['cell']];
    }

    /** @throws SessionRefusal When the map is unknown, stale or read-only. */
    private function requireEditablePieceMap(string $mapId, int $revision): \Ichiloto\Editor\ProjectMap
    {
        $map = $this->requireCurrentMap($mapId, $revision);
        if ($map->getGridSourceIssue() !== null) {
            throw new SessionRefusal(sprintf('%s is read-only: %s', $mapId, $map->getGridSourceIssue()));
        }

        return $map;
    }
}
