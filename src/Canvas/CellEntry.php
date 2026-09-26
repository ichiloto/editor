<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Editor\Maps\CellSymbol;

/**
 * Turns typed characters into whole two-column cells.
 *
 * One typed character fills the cell with itself repeated (`#` paints `##`),
 * and a two-column glyph fills the cell as it is. A second one-column
 * character typed as the very next input, before any movement or paint,
 * turns the cell into the pair (`[` then `]` gives `[]`); a third starts
 * over. Any other input in between, or a keystroke aimed at another cell,
 * forgets the first character.
 */
final class CellEntry
{
    /** The one-column character the next keystroke may pair with. */
    private ?string $pending = null;
    /** The cell the pending character was entered at. */
    private string $pendingTarget = '';
    /** Whether the current input entered a character. */
    private bool $entered = false;

    /** Marks the start of one input; call endInput() after it is handled. */
    public function beginInput(): void
    {
        $this->entered = false;
    }

    /** Forgets the pending character unless this input entered one. */
    public function endInput(): void
    {
        if (! $this->entered) {
            $this->pending = null;
        }
    }

    /** Forgets the pending character, so the next keystroke starts a cell. */
    public function reset(): void
    {
        $this->pending = null;
    }

    /**
     * Enters one typed character and returns the cell it paints.
     *
     * @param bool $pairs Whether a quick second keystroke may form a pair.
     * @param string $target Identifies the cell being entered, so a pair
     *   only forms where its first character was entered.
     */
    public function enterCharacter(string $character, bool $pairs = true, string $target = ''): string
    {
        $this->entered = true;
        if (! $pairs || ! CellSymbol::isHalfCell($character)) {
            $this->pending = null;
            return CellSymbol::normalize($character);
        }
        if ($this->completesPair($character, $target)) {
            $cell = $this->pending . $character;
            $this->pending = null;
            return $cell;
        }
        $this->pending = $character;
        $this->pendingTarget = $target;
        return $character . $character;
    }

    /** Whether entering this character at this target would complete a pair. */
    public function completesPair(string $character, string $target = ''): bool
    {
        return $this->pending !== null && $this->pendingTarget === $target && CellSymbol::isHalfCell($character);
    }
}
