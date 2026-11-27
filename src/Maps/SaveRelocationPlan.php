<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Storage\PreparedSave;
use Ichiloto\Editor\Storage\SourceSetPlan;

/**
 * The save relocations one map save needs ({@see SaveRelocationPlanner}):
 * which cells saves move off and where they land, and the manifest rewrite
 * that declares it.
 *
 * @package Ichiloto\Editor\Maps
 */
final class SaveRelocationPlan
{
    /**
     * @param list<array{map: string, cells: list<array{int, int}>, to: array{int, int}}> $relocations
     * @param array<array-key, mixed> $manifest The manifest that will be written with the maps.
     */
    public function __construct(
        public readonly string $mapId,
        public readonly array $relocations,
        public readonly int $contentVersion,
        public readonly array $manifest,
        private readonly SourceSetPlan $sources,
    ) {
    }

    /** What the save also does, in words: for the question and the status line. */
    public string $description {
        get => sprintf('Saves standing where %s is now solid or taken by an NPC move to open floor: save content version %d relocates %s.',
            $this->mapId, $this->contentVersion, implode('; ', $this->describeMoves()));
    }

    /** @return list<string> Each move: x 2, y 5 to x 2, y 6. */
    public function describeMoves(): array
    {
        $moves = [];
        foreach ($this->relocations as $relocation) {
            $from = implode(', ', array_map(static fn(array $cell): string => sprintf('x %d, y %d', ...$cell), $relocation['cells']));
            $moves[] = sprintf('%s to x %d, y %d', $from, ...$relocation['to']);
        }

        return $moves;
    }

    /** Names this exact plan, so the write is the one the author was shown. */
    public function getFingerprint(): string
    {
        return $this->sources->getFingerprint();
    }

    /** @return list<string> The manifest, which the plan writes. */
    public function getChangedPaths(): array
    {
        return $this->sources->getChangedPaths();
    }

    /**
     * Prepares the manifest step for the transaction that installs its maps.
     * A committed relocation stays in the save format when map edits are undone.
     */
    public function prepareApply(): PreparedSave
    {
        return $this->sources->prepareApply(sprintf('save relocations for %s', $this->mapId));
    }
}
