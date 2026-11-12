<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\IO\Console\TerminalText;

/** Editable colour runs, with the authored source and unchanged rows retained. */
final class EditableGrid
{
    /** @var array<int, array<int, array{symbol: string, prefix: string, suffix: string}>> */
    public array $cells;
    private array $originalCells;
    private array $lines;
    private array $separators;
    /** Whether rows were removed: the rows left can equal the authored ones while the grid does not. */
    private bool $rowsRemoved = false;
    /**
     * Each changed row's written line, with the cells it was written from. A row is composed and
     * proven once per change: rows painted earlier are not recomposed when another row changes.
     *
     * @var array<int, array{cells: list<array{symbol: string, prefix: string, suffix: string}>, line: string}>
     */
    private array $composedRows = [];
    /** @var array{lines: list<string>, source: string}|null The last source written, with the rows it holds. */
    private ?array $written = null;
    /** @var array{start: ?int, end: ?int, indent: string, newline: string}|null Where the authored source holds its rows. */
    private ?array $nowdoc = null;

    public function __construct(private string $text, private ?string $source = null)
    {
        $parts = preg_split('/(\r\n|\n|\r)/', rtrim($text, "\r\n"), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [''];
        $this->lines = $this->separators = [];
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $this->lines[] = $part;
            } else {
                $this->separators[] = $part;
            }
        }
        $this->cells = self::parseLines($this->lines);
        $this->originalCells = $this->cells;
    }

    /**
     * Reads rows into cells exactly as the Engine's source parser does
     * ({@see TerminalText::parseSourceCells()}): one grammar for formatter
     * tags, escapes, ANSI and literal delimiters, so the Editor never reads a
     * row the Engine reads differently or would refuse.
     *
     * @param list<string> $lines
     * @return list<list<array{symbol: string, prefix: string, suffix: string}>>
     * @throws MapSourceRefusal When the Engine cannot split a row into independent cells; nothing is read or written.
     */
    public static function parseLines(array $lines): array
    {
        // Equal cells share PHP copy-on-write values, not mutable references.
        // Keep the pool local to this parse so closed maps retain no cache.
        $cellValues = [];
        $rows = [];
        foreach (array_values($lines) as $index => $line) {
            try {
                $cells = TerminalText::parseSourceCells($line);
            } catch (\InvalidArgumentException $error) {
                throw new MapSourceRefusal(sprintf('Row %d cannot be read as independent cells: %s Nothing was written.',
                    $index, $error->getMessage()), previous: $error);
            }
            $row = [];
            foreach ($cells as ['symbol' => $symbol, 'prefix' => $prefix, 'suffix' => $suffix]) {
                $row[] = $cellValues[$prefix][$suffix][$symbol] ??= ['symbol' => $symbol, 'prefix' => $prefix, 'suffix' => $suffix];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function getSymbols(): array
    {
        return array_map(static fn(array $row): array => array_column($row, 'symbol'), $this->cells);
    }

    public function captureSnapshot(): array
    {
        return ['text' => $this->text, 'source' => $this->source, 'cells' => $this->cells];
    }

    public static function createFromSnapshot(array $snapshot): self
    {
        $grid = new self($snapshot['text'], $snapshot['source']);
        $grid->cells = $snapshot['cells'];
        return $grid;
    }

    /**
     * The grid's rows as source. Unchanged rows keep their authored bytes.
     * A changed row is written from its cells' effective styles and composed
     * once per change ({@see composeRow()}), so asking for the source again,
     * as the map's revision fingerprint does on every paint, costs only the
     * rows that changed since the last ask.
     *
     * @return list<string>
     * @throws MapSourceRefusal When a changed row cannot be written as its cells; nothing is written.
     */
    public function getLines(): array
    {
        $lines = [];
        foreach ($this->cells as $index => $cells) {
            if (($this->originalCells[$index] ?? null) === $cells) {
                unset($this->composedRows[$index]);
                $lines[] = $this->lines[$index];
                continue;
            }
            $composed = $this->composedRows[$index] ?? null;
            if ($composed === null || $composed['cells'] !== $cells) {
                $composed = $this->composedRows[$index] = ['cells' => $cells, 'line' => self::composeRow($index, $cells)];
            }
            $lines[] = $composed['line'];
        }
        return $lines;
    }

    public function getSource(): string
    {
        if ($this->cells === $this->originalCells && ! $this->rowsRemoved && $this->source !== null) {
            return $this->source;
        }
        $lines = $this->getLines();
        if ($this->written !== null && $this->written['lines'] === $lines) {
            return $this->written['source'];
        }
        $text = '';
        foreach ($lines as $index => $line) {
            $text .= ($index === 0 ? '' : ($this->separators[$index - 1] ?? $this->separators[0] ?? "\n")) . $line;
        }
        $text .= substr($this->text, strlen(rtrim($this->text, "\r\n")));
        if ($this->source === null) {
            $source = MapGridSource::buildSource($text, 'ICHILOTO_MAP');
            $this->written = ['lines' => $lines, 'source' => $source];
            return $source;
        }

        ['start' => $start, 'end' => $end, 'indent' => $indent, 'newline' => $newline] = $this->nowdoc ??= $this->locateNowdoc();
        $body = $indent . preg_replace('/(\r\n|\n|\r)/', '$1' . $indent, $text) . $newline;
        $source = substr($this->source, 0, $start) . $body . substr($this->source, $end);
        try {
            if (MapGridSource::parseSource($source, '<edited grid>') !== $text) {
                throw new \InvalidArgumentException('Edited grid would not read back identically.');
            }
        } catch (\InvalidArgumentException $error) {
            throw new MapSourceRefusal($error->getMessage() . ' Nothing was written.', previous: $error);
        }
        $this->written = ['lines' => $lines, 'source' => $source];
        return $source;
    }

    /**
     * Writes one changed row with each cell's effective style. The Engine's
     * parser, the one that reads the row at load, reads the composed row
     * back as cells that carry only the style each glyph shows, and proves
     * every cell presents as the row does. The row is written from those
     * cells, so style that shows nothing (an empty colour tag, a colour set
     * and cleared around no glyph) is never replayed into its neighbours.
     * The written row must read back as the cells it was written from:
     * saving it again leaves its bytes as they are. A row that would not
     * settle would change on every save, so it is refused rather than written.
     *
     * @param list<array{symbol: string, prefix: string, suffix: string}> $cells
     * @throws MapSourceRefusal When the row cannot be written as its cells, or would not settle; nothing is written.
     */
    private static function composeRow(int $index, array $cells): string
    {
        $line = self::composeCells($index, $cells);
        $settled = self::composeReadBack($index, $line, $cells);
        if ($settled !== $line && self::composeReadBack($index, $settled, $cells) !== $settled) {
            throw new MapSourceRefusal(sprintf('Row %d would be written differently on every save: the Engine reads its cells back with style the row does not hold. Nothing was written.', $index));
        }
        return $settled;
    }

    /**
     * Reads a written row back through the Engine's parser and composes the
     * cells it reads, each with its effective style.
     *
     * @param list<array{symbol: string, prefix: string, suffix: string}> $cells The cells the row was written from.
     * @throws MapSourceRefusal When the Engine cannot read the row as the glyphs of `$cells`; nothing is written.
     */
    private static function composeReadBack(int $index, string $line, array $cells): string
    {
        try {
            $read = TerminalText::parseSourceCells($line);
        } catch (\InvalidArgumentException $error) {
            throw new MapSourceRefusal(sprintf('Row %d cannot be written as independent cells: %s Nothing was written.',
                $index, $error->getMessage()), previous: $error);
        }
        if (array_column($read, 'symbol') !== array_column($cells, 'symbol')) {
            throw new MapSourceRefusal(sprintf('Row %d would not read back as the cells painted: a cell\'s style changes its glyphs. Nothing was written.', $index));
        }
        return self::composeCells($index, $read);
    }

    /**
     * Composes cells into one row. Neighbours of equal style share one style
     * run; a formatter delimiter or backslash is composed alone by the Engine
     * ({@see TerminalText::composeSourceCell()}), so it can never escape a
     * closing tag or join its neighbours into markup.
     *
     * @param list<array{symbol: string, prefix: string, suffix: string}> $cells
     * @throws MapSourceRefusal When a literal delimiter's style cannot be composed; nothing is written.
     */
    private static function composeCells(int $index, array $cells): string
    {
        $line = '';
        $open = null;
        foreach ($cells as $cell) {
            if (strpbrk($cell['symbol'], '<>\\') !== false) {
                try {
                    $line .= ($open[1] ?? '') . TerminalText::composeSourceCell($cell['symbol'], $cell['prefix'], $cell['suffix']);
                } catch (\InvalidArgumentException $error) {
                    throw new MapSourceRefusal(sprintf('Row %d cannot hold %s in its style: %s Nothing was written.',
                        $index, $cell['symbol'], $error->getMessage()), previous: $error);
                }
                $open = null;
                continue;
            }
            $style = [$cell['prefix'], $cell['suffix']];
            if ($style !== $open) {
                $line .= ($open[1] ?? '') . $style[0];
                $open = $style;
            }
            $line .= $cell['symbol'];
        }
        return $line . ($open[1] ?? '');
    }

    /**
     * Finds where the authored source holds its rows, once: the source is
     * fixed for the grid's life, so later writes splice the same span.
     *
     * @return array{start: ?int, end: ?int, indent: string, newline: string}
     */
    private function locateNowdoc(): array
    {
        $offset = 0;
        $start = $end = null;
        $indent = '';
        $newline = "\n";
        foreach (token_get_all((string) $this->source) as $token) {
            $bytes = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_START_HEREDOC) {
                $start = $offset + strlen($bytes);
                $newline = str_ends_with($bytes, "\r\n") ? "\r\n" : (str_ends_with($bytes, "\r") ? "\r" : "\n");
            }
            if (is_array($token) && $token[0] === T_END_HEREDOC) {
                $end = $offset;
                preg_match('/^[ \t]*/', $bytes, $match);
                $indent = $match[0];
            }
            $offset += strlen($bytes);
        }
        return ['start' => $start, 'end' => $end, 'indent' => $indent, 'newline' => $newline];
    }

    public function resize(int $width, int $height): void
    {
        $blank = ['symbol' => ' ', 'prefix' => '', 'suffix' => ''];
        $this->cells = array_slice($this->cells, 0, $height);
        foreach ($this->cells as &$row) {
            $row = array_pad(array_slice($row, 0, $width), $width, $blank);
        }
        unset($row);
        $this->cells = array_pad($this->cells, $height, array_fill(0, $width, $blank));
    }

    /**
     * Inserts blank rows before row `$at` (axis `y`), each as wide as the
     * row it is inserted beside, or blank cells before column `$at` of every
     * row that reaches it (axis `x`). Every other cell keeps its glyph and
     * style, and rows that only move keep their authored bytes.
     */
    public function insertLines(string $axis, int $at, int $count): void
    {
        $blank = ['symbol' => ' ', 'prefix' => '', 'suffix' => ''];
        if ($axis === 'y') {
            $beside = $this->cells[$at] ?? $this->cells[$at - 1] ?? [];
            $rows = array_fill(0, $count, array_fill(0, count($beside), $blank));
            array_splice($this->cells, $at, 0, $rows);
            // New rows have no authored line, so they are written from their cells.
            array_splice($this->originalCells, $at, 0, array_fill(0, $count, null));
            array_splice($this->lines, $at, 0, array_fill(0, $count, ''));
            $separator = $this->separators[max(0, $at - 1)] ?? $this->separators[0] ?? "\n";
            array_splice($this->separators, min($at, count($this->separators)), 0, array_fill(0, $count, $separator));
            $this->forgetWrittenRows();
            return;
        }
        foreach ($this->cells as &$row) {
            if (count($row) >= $at) {
                array_splice($row, $at, 0, array_fill(0, $count, $blank));
            }
        }
        unset($row);
    }

    /**
     * Removes rows `$at` to `$at + $count - 1` (axis `y`), or those columns
     * from every row that reaches them (axis `x`). Every other cell keeps its
     * glyph and style, and rows that only move keep their authored bytes.
     */
    public function removeLines(string $axis, int $at, int $count): void
    {
        if ($axis === 'y') {
            array_splice($this->cells, $at, $count);
            array_splice($this->originalCells, $at, $count);
            array_splice($this->lines, $at, $count);
            // The separator before each removed row goes with it; the first rows take the one after them.
            array_splice($this->separators, max(0, $at - 1), $count);
            $this->rowsRemoved = true;
            $this->forgetWrittenRows();
            return;
        }
        foreach ($this->cells as &$row) {
            if (count($row) > $at) {
                array_splice($row, $at, $count);
            }
        }
        unset($row);
    }

    /** Rows moved: written rows are keyed by position and the source by its separators, so both are composed afresh. */
    private function forgetWrittenRows(): void
    {
        $this->composedRows = [];
        $this->written = null;
    }
}
