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

    public function __construct(private string $text, private ?string $source = null, private bool $legacyTags = false)
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
        $this->cells = self::parseLines($this->lines, $legacyTags);
        $this->originalCells = $this->cells;
    }

    public static function parseLines(array $lines, bool $legacyTags = false): array
    {
        // Equal cells share PHP copy-on-write values, not mutable references.
        // Keep the pool local to this parse so closed maps retain no cache.
        $cellValues = [];
        return array_map(static function (string $line) use ($legacyTags, &$cellValues): array {
            preg_match_all('/<[^>]+>|[^<]+|</u', $line, $matches);
            $cells = $tags = [];
            foreach ($matches[0] as $segment) {
                if (preg_match('/^<[^\/][^>]*>$/u', $segment) === 1
                    && ($legacyTags || TerminalText::stripAnsi($segment . 'x</>') === 'x')) {
                    $tags[] = $segment;
                } elseif (preg_match('/^<\/[^>]*>$/u', $segment) === 1 && ($legacyTags || $tags !== [])) {
                    array_pop($tags);
                } else {
                    foreach (TerminalText::visibleSymbols($segment) as $symbol) {
                        preg_match('/\A((?:\x1b\[[0-9;]*m)*)(.*?)((?:\x1b\[[0-9;]*m)*)\z/us', $symbol, $styled);
                        $glyph = $styled[2] ?? $symbol;
                        $prefix = implode('', $tags) . ($styled[1] ?? '');
                        $suffix = ($styled[3] ?? '') . str_repeat('</>', count($tags));
                        $cells[] = $cellValues[$prefix][$suffix][$glyph] ??= [
                            'symbol' => $glyph, 'prefix' => $prefix, 'suffix' => $suffix,
                        ];
                    }
                }
            }
            return $cells;
        }, $lines);
    }

    public function getSymbols(): array
    {
        return array_map(static fn(array $row): array => array_column($row, 'symbol'), $this->cells);
    }

    public function captureSnapshot(): array
    {
        return ['text' => $this->text, 'source' => $this->source, 'cells' => $this->cells, 'legacyTags' => $this->legacyTags];
    }

    public static function createFromSnapshot(array $snapshot): self
    {
        $grid = new self($snapshot['text'], $snapshot['source'], $snapshot['legacyTags']);
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
                $style = [$cell['prefix'], $cell['suffix']];
                if ($style !== $open) {
                    $line .= ($open[1] ?? '') . $style[0];
                    $open = $style;
                }
                $line .= $cell['symbol'];
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
            return;
        }
        foreach ($this->cells as &$row) {
            if (count($row) >= $at) {
                array_splice($row, $at, 0, array_fill(0, $count, $blank));
            }
        }
        unset($row);
    }
}
