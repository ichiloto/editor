<?php

declare(strict_types=1);

namespace Ichiloto\Editor\UI;

/**
 * A text area for values with line breaks: a keyframe's ASCII block, a
 * staged actor's sprite rows, a narration.
 *
 * The text is held exactly. Every space -- leading, trailing, between --
 * every backslash, every blank line and every wide glyph is the author's,
 * and a pasted block arrives as the lines it has. Nothing is trimmed,
 * wrapped or normalised on the way in or out; the editor only moves a
 * caret and inserts or removes what was typed.
 *
 * The caret is a row and a column counted in glyphs, so it lands between
 * characters rather than inside a multibyte sequence.
 *
 * @package Ichiloto\Editor\UI
 */
final class MultilineTextEditor
{
    /** @var string[] */
    private array $lines = [''];
    private int $row = 0;
    private int $column = 0;
    private int $scrollRow = 0;
    private int $scrollColumn = 0;
    private bool $isOpen = false;
    private string $fieldId = '';
    private string $label = '';
    private string $original = '';

    /**
     * Opens the editor over a field's current text.
     */
    public function open(string $fieldId, string $label, string $text): void
    {
        $this->fieldId = $fieldId;
        $this->label = $label;
        $this->original = $text;
        $this->lines = explode("\n", str_replace("\r\n", "\n", $text));
        $this->row = count($this->lines) - 1;
        $this->column = mb_strlen($this->lines[$this->row]);
        $this->scrollRow = 0;
        $this->scrollColumn = 0;
        $this->isOpen = true;
    }

    public function close(): void
    {
        $this->isOpen = false;
    }

    public function isOpen(): bool
    {
        return $this->isOpen;
    }

    public function fieldId(): string
    {
        return $this->fieldId;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * Returns the text exactly as it now stands.
     */
    public function text(): string
    {
        return implode("\n", $this->lines);
    }

    public function isChanged(): bool
    {
        return $this->text() !== $this->original;
    }

    /**
     * @return string[]
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * @return array{0: int, 1: int} Row and column of the caret.
     */
    public function caret(): array
    {
        return [$this->row, $this->column];
    }

    /**
     * Handles one key, returning true when it changed text or caret.
     */
    public function handle(string $input): bool
    {
        if ($input === '') {
            return false;
        }

        if (str_contains($input, "\033[A")) {
            return $this->moveRow(-1);
        }

        if (str_contains($input, "\033[B")) {
            return $this->moveRow(1);
        }

        if (str_contains($input, "\033[C")) {
            return $this->moveColumn(1);
        }

        if (str_contains($input, "\033[D")) {
            return $this->moveColumn(-1);
        }

        if (str_contains($input, "\033[H") || str_contains($input, "\033[1~") || $input === "\x01") {
            $this->column = 0;

            return true;
        }

        if (str_contains($input, "\033[F") || str_contains($input, "\033[4~") || $input === "\x05") {
            $this->column = mb_strlen($this->lines[$this->row]);

            return true;
        }

        if (str_contains($input, "\033[5~")) {
            return $this->moveRow(-10);
        }

        if (str_contains($input, "\033[6~")) {
            return $this->moveRow(10);
        }

        if ($input === "\x7f" || $input === "\x08") {
            return $this->backspace();
        }

        if (str_contains($input, "\033[3~")) {
            return $this->deleteForward();
        }

        if ($input === "\n" || $input === "\r" || $input === "\r\n") {
            $this->insertNewline();

            return true;
        }

        if (str_starts_with($input, "\033")) {
            // Any other escape sequence is not text.
            return false;
        }

        // Typed or pasted text, line breaks included.
        $text = str_replace("\r\n", "\n", $input);
        $text = str_replace("\r", "\n", $text);
        $changed = false;

        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $glyph) {
            if ($glyph === "\n") {
                $this->insertNewline();
                $changed = true;

                continue;
            }

            if ($glyph !== "\t" && ctype_cntrl($glyph)) {
                continue;
            }

            $this->insertGlyph($glyph);
            $changed = true;
        }

        return $changed;
    }

    /**
     * Returns the lines visible in a viewport, scrolled so the caret shows,
     * with the caret's position inside the viewport.
     *
     * @return array{lines: string[], caretRow: int, caretColumn: int, scrollRow: int, scrollColumn: int}
     */
    public function viewport(int $width, int $height): array
    {
        $width = max(1, $width);
        $height = max(1, $height);

        if ($this->row < $this->scrollRow) {
            $this->scrollRow = $this->row;
        } elseif ($this->row >= $this->scrollRow + $height) {
            $this->scrollRow = $this->row - $height + 1;
        }

        $caretWidth = mb_strwidth(mb_substr($this->lines[$this->row], 0, $this->column));

        if ($caretWidth < $this->scrollColumn) {
            $this->scrollColumn = $caretWidth;
        } elseif ($caretWidth >= $this->scrollColumn + $width) {
            $this->scrollColumn = $caretWidth - $width + 1;
        }

        $visible = [];

        for ($index = $this->scrollRow; $index < $this->scrollRow + $height; $index++) {
            $line = $this->lines[$index] ?? null;

            if ($line === null) {
                break;
            }

            $visible[] = self::sliceByWidth($line, $this->scrollColumn, $width);
        }

        return [
            'lines' => $visible,
            'caretRow' => $this->row - $this->scrollRow,
            'caretColumn' => $caretWidth - $this->scrollColumn,
            'scrollRow' => $this->scrollRow,
            'scrollColumn' => $this->scrollColumn,
        ];
    }

    private function moveRow(int $delta): bool
    {
        $target = max(0, min(count($this->lines) - 1, $this->row + $delta));

        if ($target === $this->row) {
            return false;
        }

        $this->row = $target;
        $this->column = min($this->column, mb_strlen($this->lines[$this->row]));

        return true;
    }

    private function moveColumn(int $delta): bool
    {
        $length = mb_strlen($this->lines[$this->row]);

        if ($delta < 0 && $this->column === 0) {
            if ($this->row === 0) {
                return false;
            }

            $this->row--;
            $this->column = mb_strlen($this->lines[$this->row]);

            return true;
        }

        if ($delta > 0 && $this->column >= $length) {
            if ($this->row >= count($this->lines) - 1) {
                return false;
            }

            $this->row++;
            $this->column = 0;

            return true;
        }

        $this->column = max(0, min($length, $this->column + $delta));

        return true;
    }

    private function insertGlyph(string $glyph): void
    {
        $line = $this->lines[$this->row];
        $this->lines[$this->row] = mb_substr($line, 0, $this->column) . $glyph . mb_substr($line, $this->column);
        $this->column++;
    }

    private function insertNewline(): void
    {
        $line = $this->lines[$this->row];
        $head = mb_substr($line, 0, $this->column);
        $tail = mb_substr($line, $this->column);
        $this->lines[$this->row] = $head;
        array_splice($this->lines, $this->row + 1, 0, [$tail]);
        $this->row++;
        $this->column = 0;
    }

    private function backspace(): bool
    {
        if ($this->column > 0) {
            $line = $this->lines[$this->row];
            $this->lines[$this->row] = mb_substr($line, 0, $this->column - 1) . mb_substr($line, $this->column);
            $this->column--;

            return true;
        }

        if ($this->row === 0) {
            return false;
        }

        $previous = $this->lines[$this->row - 1];
        $this->column = mb_strlen($previous);
        $this->lines[$this->row - 1] = $previous . $this->lines[$this->row];
        array_splice($this->lines, $this->row, 1);
        $this->row--;

        return true;
    }

    private function deleteForward(): bool
    {
        $line = $this->lines[$this->row];

        if ($this->column < mb_strlen($line)) {
            $this->lines[$this->row] = mb_substr($line, 0, $this->column) . mb_substr($line, $this->column + 1);

            return true;
        }

        if ($this->row >= count($this->lines) - 1) {
            return false;
        }

        $this->lines[$this->row] = $line . $this->lines[$this->row + 1];
        array_splice($this->lines, $this->row + 1, 1);

        return true;
    }

    /**
     * Returns the part of a line occupying the columns from an offset, for
     * a width, without cutting a wide glyph in half.
     */
    private static function sliceByWidth(string $line, int $fromColumn, int $width): string
    {
        $out = '';
        $column = 0;

        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $glyph) {
            $glyphWidth = max(1, mb_strwidth($glyph));

            if ($column + $glyphWidth <= $fromColumn) {
                $column += $glyphWidth;

                continue;
            }

            if ($column < $fromColumn) {
                // A wide glyph straddling the left edge shows as a blank.
                $out .= ' ';
                $column += $glyphWidth;

                continue;
            }

            if ($column - $fromColumn + $glyphWidth > $width) {
                break;
            }

            $out .= $glyph;
            $column += $glyphWidth;
        }

        return $out;
    }
}
