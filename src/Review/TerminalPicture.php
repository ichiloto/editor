<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Review;

use Ichiloto\Engine\IO\Console\NormalizedRow;
use Ichiloto\Engine\IO\Console\SgrColorParser;
use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;

/**
 * A rectangle of the Terminal field as the game's camera draws it: one styled
 * symbol per column, row by row, exactly as the field's Console holds it. A
 * wide symbol's second column is {@see NormalizedRow::CONTINUATION}. It is
 * written as ANSI text, which a terminal shows in its own palette, as plain
 * text, or as HTML in the review palette ({@see TerminalPalette}).
 */
final readonly class TerminalPicture
{
    /**
     * @param list<list<string>> $cells Each row's styled symbols, `width` of them.
     */
    public function __construct(public int $width, public int $height, public array $cells)
    {
        if ($width < 1 || $height < 1 || count($cells) !== $height
            || array_any($cells, static fn(array $row): bool => count($row) !== $width)) {
            throw new InvalidArgumentException('A Terminal picture holds height rows of width cells.');
        }
    }

    /**
     * Splits styled Terminal rows, as a Console capture composes them, into
     * their columns: each symbol keeps the style it is drawn in, and a wide
     * symbol takes two columns.
     *
     * @param list<string> $rows
     * @return list<list<string>>
     */
    public static function readRows(array $rows): array
    {
        $cells = [];
        foreach ($rows as $row) {
            $columns = [];
            foreach (TerminalText::visibleSymbols($row) as $symbol) {
                $columns[] = $symbol;
                for ($extra = TerminalText::getSymbolWidth($symbol); $extra > 1; $extra--) {
                    $columns[] = NormalizedRow::CONTINUATION;
                }
            }
            $cells[] = $columns;
        }

        return $cells;
    }

    /** The picture as ANSI text: each row's styled symbols, the style reset at its end. */
    public function renderAnsi(): string
    {
        $text = '';
        foreach ($this->cells as $row) {
            $text .= implode('', array_filter($row, static fn(string $cell): bool => $cell !== NormalizedRow::CONTINUATION)) . "\e[0m\n";
        }

        return $text;
    }

    /** The picture's characters alone, one line per row. */
    public function renderPlainText(): string
    {
        $text = '';
        foreach ($this->cells as $row) {
            foreach ($row as $cell) {
                $text .= $cell === NormalizedRow::CONTINUATION ? '' : TerminalText::stripAnsi($cell);
            }
            $text .= "\n";
        }

        return $text;
    }

    /**
     * The picture as an HTML `<pre>` of coloured runs in the review palette:
     * cells of one foreground and background share a span, and a cell that
     * names no colour shows the palette's own.
     */
    public function renderHtml(): string
    {
        $html = '<pre class="terminal">';
        foreach ($this->cells as $y => $row) {
            $run = '';
            $style = null;
            foreach ($row as $cell) {
                if ($cell === NormalizedRow::CONTINUATION) {
                    continue;
                }
                $colors = SgrColorParser::parse($cell);
                $next = sprintf('color:%s;background:%s',
                    TerminalPalette::formatCss(TerminalPalette::resolveRgb($colors['foreground'], TerminalPalette::DEFAULT_FOREGROUND)),
                    TerminalPalette::formatCss(TerminalPalette::resolveRgb($colors['background'], TerminalPalette::DEFAULT_BACKGROUND)));
                if ($style !== null && $next !== $style) {
                    $html .= self::renderSpan($style, $run);
                    $run = '';
                }
                $style = $next;
                $run .= TerminalText::stripAnsi($cell);
            }
            if ($style !== null) {
                $html .= self::renderSpan($style, $run);
            }
            $html .= $y < $this->height - 1 ? "\n" : '';
        }

        return $html . '</pre>';
    }

    /**
     * The picture as a page of its own: dark, monospaced, every colour kept,
     * needing nothing beside it.
     */
    public function renderPage(string $title): string
    {
        $escaped = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>{$escaped}</title>\n"
            . '<style>' . self::renderStyles() . "</style>\n</head>\n<body>\n<h1>{$escaped}</h1>\n"
            . $this->renderHtml() . "\n</body>\n</html>\n";
    }

    /** The page styles a Terminal picture is shown with, shared by the review page. */
    public static function renderStyles(): string
    {
        $background = TerminalPalette::formatCss(TerminalPalette::DEFAULT_BACKGROUND);
        $foreground = TerminalPalette::formatCss(TerminalPalette::DEFAULT_FOREGROUND);

        return "body{margin:0;padding:16px;background:{$background};color:{$foreground};"
            . 'font:14px/1.4 system-ui,-apple-system,"Segoe UI",sans-serif}'
            . 'h1{font-size:16px;font-weight:600;margin:0 0 12px}'
            . "pre.terminal{margin:0;display:inline-block;padding:8px;background:{$background};"
            . 'font:14px/1.15 Menlo,"SF Mono",Consolas,"DejaVu Sans Mono",monospace;white-space:pre;overflow:auto;max-width:100%}';
    }

    private static function renderSpan(string $style, string $text): string
    {
        return '<span style="' . $style . '">' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
    }
}
