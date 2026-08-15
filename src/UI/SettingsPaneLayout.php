<?php

declare(strict_types=1);

namespace Ichiloto\Editor\UI;

/**
 * The laid-out rows of a settings pane: every field as the lines it needs,
 * a span map from field to lines, and the scroll offset that keeps the
 * selected field in view.
 *
 * A field is one line while its value fits after its label; a longer value
 * wraps onto continuation lines indented under the value column, so a
 * dialogue line or a description can be read in full without entering
 * edit mode. Every consumer that turns a field index into a screen row --
 * the visible lines, the edit caret, a click -- goes through the span map
 * rather than assuming one line per field.
 *
 * With one line per field the scroll policy is `ScrollWindow`'s exactly:
 * the window slides only when the selected field would fall past the last
 * visible line. A taller selected field is kept whole when the pane can
 * hold it, and its first line is always visible.
 *
 * @package Ichiloto\Editor\UI
 */
final class SettingsPaneLayout
{
    /**
     * @param string[] $lines Every line of the pane, wrapped, from the top.
     * @param array<int, array{0: int, 1: int}> $spans Field index => [first line, line count].
     * @param int $offset The first visible line.
     * @param int $visibleRows The rows the pane can show.
     */
    private function __construct(
        public readonly array $lines,
        public readonly array $spans,
        public readonly int $offset,
        public readonly int $visibleRows,
    ) {
    }

    /**
     * Lays out fields for a pane.
     *
     * Each row is `prefix`, `label`, `value` and whether it is editable
     * (`label: value`) or informational (`label · value`). A row flagged
     * `singleLine` (the field being edited, whose buffer is scrolled
     * horizontally around the caret) is never wrapped, so caret arithmetic
     * stays exact.
     *
     * @param array<int, array{prefix: string, label: string, value: string, editable: bool, singleLine?: bool}> $rows The rows.
     * @param int $contentWidth The pane's writable width in columns.
     * @param int $visibleRows The rows the pane can show.
     * @param int $selectedIndex The selected field.
     * @return self The layout.
     */
    public static function layout(array $rows, int $contentWidth, int $visibleRows, int $selectedIndex): self
    {
        $contentWidth = max(1, $contentWidth);
        $visibleRows = max(1, $visibleRows);
        $lines = [];
        $spans = [];

        foreach ($rows as $index => $row) {
            $first = count($lines);
            $fieldLines = self::fieldLines(
                $row['prefix'],
                $row['label'],
                $row['value'],
                $row['editable'],
                ($row['singleLine'] ?? false) === true,
                $contentWidth,
            );
            $lines = [...$lines, ...$fieldLines];
            $spans[$index] = [$first, count($fieldLines)];
        }

        // A selection past the end reads as the last field, as a clamp would.
        [$spanFirst, $spanCount] = $spans[$selectedIndex] ?? ($spans === [] ? [0, 1] : $spans[array_key_last($spans)]);
        $spanLast = $spanFirst + max(1, $spanCount) - 1;
        // Slide only when the selection would fall past the bottom; keep the
        // whole span when it fits, and never lose its first line.
        $offset = min($spanFirst, max(0, $spanLast - $visibleRows + 1));

        return new self($lines, $spans, $offset, $visibleRows);
    }

    /**
     * Returns the lines from the scroll offset on; the window fits them to
     * its height, as it does every pane's lines.
     *
     * @return string[] The visible lines.
     */
    public function visibleLines(): array
    {
        return array_slice($this->lines, $this->offset);
    }

    /**
     * Returns the pane row (0-based) of a field's first line, or null when
     * the field is scrolled out of view.
     *
     * @param int $index The field.
     * @return int|null The row.
     */
    public function rowOfField(int $index): ?int
    {
        if (! isset($this->spans[$index])) {
            return null;
        }

        $row = $this->spans[$index][0] - $this->offset;

        return $row >= 0 && $row < $this->visibleRows ? $row : null;
    }

    /**
     * Returns the field drawn on a pane row -- a continuation line resolves
     * to its field -- or null for a row past the content.
     *
     * @param int $row The pane row (0-based).
     * @return int|null The field index.
     */
    public function fieldAtRow(int $row): ?int
    {
        $line = $this->offset + $row;

        foreach ($this->spans as $index => [$first, $count]) {
            if ($line >= $first && $line < $first + $count) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Returns one field's lines: the label line, then the value wrapped and
     * indented under the value column.
     *
     * The label is never wrapped; a label wider than the pane is left to be
     * truncated by the window, as it always was. When the label leaves less
     * than a quarter of the pane (and fewer than eight columns) for the
     * value, the value starts on the next line under a hanging indent
     * instead of dribbling a few characters per line.
     *
     * @param string $prefix The selection prefix ('> ' or '  ').
     * @param string $label The label.
     * @param string $value The value.
     * @param bool $editable Whether the row reads `label: value` or `label · value`.
     * @param bool $singleLine Whether to keep the row on one line regardless.
     * @param int $contentWidth The pane's writable width.
     * @return string[] The lines, at least one.
     */
    private static function fieldLines(string $prefix, string $label, string $value, bool $editable, bool $singleLine, int $contentWidth): array
    {
        if ($value === '') {
            return [rtrim($editable ? sprintf('%s%s: ', $prefix, $label) : $prefix . $label)];
        }

        $head = $editable ? sprintf('%s%s: ', $prefix, $label) : sprintf('%s%s · ', $prefix, $label);

        if ($singleLine) {
            return [$head . $value];
        }

        $headWidth = mb_strwidth($head);
        $firstWidth = $contentWidth - $headWidth;
        $hangingIndent = mb_strwidth($prefix) + 2;
        // Continuation lines sit under the value column while that leaves at
        // least half the pane; a long label gets a hanging indent instead.
        $indent = $headWidth <= intdiv($contentWidth, 2) ? $headWidth : $hangingIndent;
        $restWidth = max(1, $contentWidth - $indent);

        if ($firstWidth >= max(8, intdiv($contentWidth, 4)) || mb_strwidth($value) <= $firstWidth) {
            $chunks = self::wrap($value, max(1, $firstWidth), $restWidth);
            $lines = [$head . array_shift($chunks)];
        } else {
            // No room after the label: the value starts on the next line,
            // and the separator goes with it rather than dangling.
            $chunks = self::wrap($value, $restWidth, $restWidth);
            $lines = [$editable ? sprintf('%s%s:', $prefix, $label) : $prefix . $label];
        }

        foreach ($chunks as $chunk) {
            $lines[] = str_repeat(' ', $indent) . $chunk;
        }

        return $lines;
    }

    /**
     * Wraps text into chunks by display width, breaking at spaces when a
     * word boundary is available and inside a word only when the word
     * itself is wider than a line. Multibyte and wide glyphs are measured,
     * never split.
     *
     * @param string $text The text.
     * @param int $firstWidth The width available to the first chunk.
     * @param int $restWidth The width available to every later chunk.
     * @return string[] The chunks, at least one.
     */
    public static function wrap(string $text, int $firstWidth, int $restWidth): array
    {
        $firstWidth = max(1, $firstWidth);
        $restWidth = max(1, $restWidth);
        $chunks = [];
        $current = '';
        $currentWidth = 0;
        $limit = $firstWidth;

        foreach (preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $isSpace = trim($token) === '';
            $tokenWidth = mb_strwidth($token);

            if ($currentWidth + $tokenWidth <= $limit) {
                $current .= $token;
                $currentWidth += $tokenWidth;
                continue;
            }

            if ($isSpace) {
                // A break at this space: the space itself is consumed.
                $chunks[] = rtrim($current);
                $current = '';
                $currentWidth = 0;
                $limit = $restWidth;
                continue;
            }

            if ($current !== '' && trim($current) !== '') {
                // The word does not fit after what is on the line: break
                // before it.
                $chunks[] = rtrim($current);
                $current = '';
                $currentWidth = 0;
                $limit = $restWidth;
            }

            if ($tokenWidth <= $limit) {
                $current = $token;
                $currentWidth = $tokenWidth;
                continue;
            }

            // A single word wider than a line: hard-break it by glyph.
            foreach (mb_str_split($token) as $glyph) {
                $glyphWidth = mb_strwidth($glyph);

                if ($currentWidth + $glyphWidth > $limit && $current !== '') {
                    $chunks[] = rtrim($current);
                    $current = '';
                    $currentWidth = 0;
                    $limit = $restWidth;
                }

                $current .= $glyph;
                $currentWidth += $glyphWidth;
            }
        }

        if ($current !== '' || $chunks === []) {
            $chunks[] = rtrim($current);
        }

        return $chunks;
    }

    /**
     * Wraps a plain line of prose to a width, keeping its leading indent on
     * every continuation line.
     *
     * @param string $line The line, possibly indented.
     * @param int $contentWidth The pane's writable width.
     * @return string[] The lines, at least one.
     */
    public static function wrapProse(string $line, int $contentWidth): array
    {
        $contentWidth = max(1, $contentWidth);
        $indent = strlen($line) - strlen(ltrim($line, ' '));
        $text = ltrim($line, ' ');

        if ($text === '' || mb_strwidth($line) <= $contentWidth) {
            return [$line];
        }

        $width = max(1, $contentWidth - $indent);
        $pad = str_repeat(' ', $indent);

        return array_map(static fn(string $chunk): string => $pad . $chunk, self::wrap($text, $width, $width));
    }
}
