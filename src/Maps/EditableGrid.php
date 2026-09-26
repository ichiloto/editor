<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Engine\Field\MapCell;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;

/**
 * Editable rows of two-column map cells, with the authored source and
 * unchanged rows retained.
 *
 * A cell's symbol is its unstyled text: one two-column glyph or two
 * one-column characters. A cell whose characters share one style keeps it
 * as its prefix and suffix. A cell whose characters are styled differently
 * also lists one style per character, so an edited row writes every
 * character back with its own style; its prefix and suffix are then those
 * of its first visible character, which is the cell's colour.
 */
final class EditableGrid
{
    /** @var array<int, array<int, array{symbol: string, prefix: string, suffix: string, styles?: list<array{prefix: string, suffix: string}>}>> */
    public array $cells;
    private array $originalCells;
    private array $lines;
    private array $separators;

    /**
     * @param string $context Names the grid in refusals, for example its file.
     * @throws InvalidArgumentException When a row is not whole two-column cells.
     */
    public function __construct(
        private string $text,
        private ?string $source = null,
        private bool $legacyTags = false,
        private string $context = 'Map',
    ) {
        $parts = preg_split('/(\r\n|\n|\r)/', rtrim($text, "\r\n"), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [''];
        $this->lines = $this->separators = [];
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $this->lines[] = $part;
            } else {
                $this->separators[] = $part;
            }
        }
        $this->cells = self::parseLines($this->lines, $legacyTags, $context);
        $this->originalCells = $this->cells;
    }

    /**
     * Parses authored rows into cells, keeping each character's style.
     *
     * @param string[] $lines
     * @throws InvalidArgumentException When a row is not whole two-column cells.
     */
    public static function parseLines(array $lines, bool $legacyTags = false, string $context = 'Map'): array
    {
        // Equal cells share PHP copy-on-write values, not mutable references.
        // Keep the pool local to this parse so closed maps retain no cache.
        $cellValues = [];
        $rows = [];
        foreach ($lines as $y => $line) {
            $characters = self::parseCharacters($line, $legacyTags);
            // The Engine owns how characters group into cells and how a
            // misaligned row is refused; the editor only keeps the styles.
            $texts = MapCell::parseRow(implode('', array_column($characters, 'symbol')), "{$context} row {$y}");
            $row = [];
            $offset = 0;
            foreach ($texts as $text) {
                $count = count(MapCell::getCharacters($text));
                $members = array_slice($characters, $offset, $count);
                $offset += $count;
                $symbol = implode('', array_column($members, 'symbol'));
                if ($symbol !== TerminalText::stripAnsi($text)) {
                    throw new InvalidArgumentException("{$context} row {$y} does not split into the characters the Engine reads.");
                }
                $key = serialize($members);
                $row[] = $cellValues[$key] ??= self::createCellFromCharacters($symbol, $members);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Splits one authored row into characters with their own style runs.
     *
     * @return list<array{symbol: string, prefix: string, suffix: string}>
     */
    private static function parseCharacters(string $line, bool $legacyTags): array
    {
        preg_match_all('/<[^>]+>|[^<]+|</u', $line, $matches);
        $characters = $tags = [];
        foreach ($matches[0] as $segment) {
            if (preg_match('/^<[^\/][^>]*>$/u', $segment) === 1
                && ($legacyTags || TerminalText::stripAnsi($segment . 'x</>') === 'x')) {
                $tags[] = $segment;
            } elseif (preg_match('/^<\/[^>]*>$/u', $segment) === 1 && ($legacyTags || $tags !== [])) {
                array_pop($tags);
            } else {
                foreach (TerminalText::visibleSymbols($segment) as $symbol) {
                    preg_match('/\A((?:\x1b\[[0-9;]*m)*)(.*?)((?:\x1b\[[0-9;]*m)*)\z/us', $symbol, $styled);
                    $characters[] = [
                        'symbol' => $styled[2] ?? $symbol,
                        'prefix' => implode('', $tags) . ($styled[1] ?? ''),
                        'suffix' => ($styled[3] ?? '') . str_repeat('</>', count($tags)),
                    ];
                }
            }
        }
        return $characters;
    }

    /** @param list<array{symbol: string, prefix: string, suffix: string}> $members */
    private static function createCellFromCharacters(string $symbol, array $members): array
    {
        $styles = array_map(static fn(array $member): array => ['prefix' => $member['prefix'], 'suffix' => $member['suffix']], $members);
        return self::createCell($symbol, [...($styles[0] ?? ['prefix' => '', 'suffix' => '']), 'styles' => $styles]);
    }

    /**
     * Builds one cell from its unstyled text and a style. Per-character
     * styles apply only when they match the cell's characters and differ;
     * otherwise the whole cell takes the style's prefix and suffix.
     *
     * @param array{prefix: string, suffix: string, styles?: list<array{prefix: string, suffix: string}>} $style
     * @return array{symbol: string, prefix: string, suffix: string, styles?: list<array{prefix: string, suffix: string}>}
     */
    public static function createCell(string $symbol, array $style): array
    {
        $characters = MapCell::getCharacters($symbol);
        $styles = array_values($style['styles'] ?? []);
        if (count($styles) !== count($characters) || count(array_unique(array_map(serialize(...), $styles))) < 2) {
            return ['symbol' => $symbol, 'prefix' => $style['prefix'], 'suffix' => $style['suffix']];
        }
        // The cell's colour is its first visible character's.
        $shown = array_key_first(array_filter($characters, static fn(string $character): bool => trim($character) !== '')) ?? 0;
        return ['symbol' => $symbol, 'prefix' => $styles[$shown]['prefix'], 'suffix' => $styles[$shown]['suffix'], 'styles' => $styles];
    }

    /**
     * A cell's style: its prefix and suffix, and one style per character
     * when its characters are styled differently.
     *
     * @return array{prefix: string, suffix: string, styles?: list<array{prefix: string, suffix: string}>}
     */
    public static function getCellStyle(array $cell): array
    {
        $style = ['prefix' => (string) ($cell['prefix'] ?? ''), 'suffix' => (string) ($cell['suffix'] ?? '')];
        return isset($cell['styles']) ? [...$style, 'styles' => $cell['styles']] : $style;
    }

    /**
     * A cell as styled runs of text: the whole cell when its characters share
     * one style, one run per character otherwise.
     *
     * @return list<array{symbol: string, prefix: string, suffix: string}>
     */
    public static function getCellRuns(array $cell): array
    {
        if (! isset($cell['styles'])) {
            return [['symbol' => $cell['symbol'], 'prefix' => $cell['prefix'], 'suffix' => $cell['suffix']]];
        }
        $runs = [];
        foreach (MapCell::getCharacters($cell['symbol']) as $index => $character) {
            $runs[] = ['symbol' => $character] + $cell['styles'][$index];
        }
        return $runs;
    }

    public function getSymbols(): array
    {
        return array_map(static fn(array $row): array => array_column($row, 'symbol'), $this->cells);
    }

    public function captureSnapshot(): array
    {
        return ['text' => $this->text, 'source' => $this->source, 'cells' => $this->cells,
            'legacyTags' => $this->legacyTags, 'context' => $this->context];
    }

    public static function createFromSnapshot(array $snapshot): self
    {
        $grid = new self($snapshot['text'], $snapshot['source'], $snapshot['legacyTags'], $snapshot['context'] ?? 'Map');
        $grid->cells = $snapshot['cells'];
        return $grid;
    }

    public function getLines(): array
    {
        $lines = [];
        foreach ($this->cells as $index => $cells) {
            if (($this->originalCells[$index] ?? null) === $cells) {
                $lines[] = $this->lines[$index];
                continue;
            }
            $line = '';
            $open = null;
            foreach ($cells as $cell) {
                foreach (self::getCellRuns($cell) as $run) {
                    $style = [$run['prefix'], $run['suffix']];
                    if ($style !== $open) {
                        $line .= ($open[1] ?? '') . $style[0];
                        $open = $style;
                    }
                    $line .= $run['symbol'];
                }
            }
            $lines[] = $line . ($open[1] ?? '');
        }
        return $lines;
    }

    public function getSource(): string
    {
        if ($this->cells === $this->originalCells && $this->source !== null) {
            return $this->source;
        }
        $text = '';
        foreach ($this->getLines() as $index => $line) {
            $text .= ($index === 0 ? '' : ($this->separators[$index - 1] ?? $this->separators[0] ?? "\n")) . $line;
        }
        $text .= substr($this->text, strlen(rtrim($this->text, "\r\n")));
        if ($this->source === null) {
            return MapGridSource::buildSource($text, 'ICHILOTO_MAP');
        }

        $offset = 0;
        $start = $end = null;
        $indent = '';
        $newline = "\n";
        foreach (token_get_all($this->source) as $token) {
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
        $body = $indent . preg_replace('/(\r\n|\n|\r)/', '$1' . $indent, $text) . $newline;
        $source = substr($this->source, 0, $start) . $body . substr($this->source, $end);
        try {
            if (MapGridSource::parseSource($source, '<edited grid>') !== $text) {
                throw new \InvalidArgumentException('Edited grid would not read back identically.');
            }
        } catch (\InvalidArgumentException $error) {
            throw new MapSourceRefusal($error->getMessage() . ' Nothing was written.', previous: $error);
        }
        return $source;
    }

    /** Resizes to whole cells; new cells are blank. */
    public function resize(int $width, int $height): void
    {
        $blank = ['symbol' => MapCell::BLANK, 'prefix' => '', 'suffix' => ''];
        $this->cells = array_slice($this->cells, 0, $height);
        foreach ($this->cells as &$row) {
            $row = array_pad(array_slice($row, 0, $width), $width, $blank);
        }
        unset($row);
        $this->cells = array_pad($this->cells, $height, array_fill(0, $width, $blank));
    }
}
