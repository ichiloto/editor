<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Atatusoft\Termutil\IO\Enumerations\Color;
use Atatusoft\Termutil\UI\Windows\Window;

/**
 * Provides editor-safe window rendering without clipping ANSI-colored borders.
 */
final class EditorWindow extends Window
{
    /**
     * @inheritDoc
     */
    public function renderAt(?int $x = null, ?int $y = null): void
    {
        $position = $this->position;
        $positionX = $position['x'] ?? $position[0];
        $positionY = $position['y'] ?? $position[1];

        $leftMargin = $positionX + ($x ?? 0);
        $topMargin = $positionY + ($y ?? 0);

        $this->cursor->moveTo($leftMargin, $topMargin);
        echo $this->renderHorizontalBorder($this->buildBorderLine($this->title, true));

        foreach ($this->buildContentLines() as $index => $line) {
            $this->cursor->moveTo($leftMargin, $topMargin + $index + 1);
            echo $this->renderContentLine($line);
        }

        $this->cursor->moveTo($leftMargin, $topMargin + $this->innerHeight + 1);
        echo $this->renderHorizontalBorder($this->buildBorderLine($this->help, false));
    }

    /**
     * Colors only the full horizontal border line.
     *
     * @param string $line The border line.
     * @return string
     */
    private function renderHorizontalBorder(string $line): string
    {
        if (! $this->foregroundColor instanceof Color) {
            return $line;
        }

        return $this->foregroundColor->value . $line . Color::RESET->value;
    }

    /**
     * Colors only the left and right border characters on a content row.
     *
     * @param string $line The content row.
     * @return string
     */
    private function renderContentLine(string $line): string
    {
        if (! $this->foregroundColor instanceof Color || mb_strlen($line) < 2) {
            return $line;
        }

        $leftBorder = mb_substr($line, 0, 1);
        $middle = mb_substr($line, 1, mb_strlen($line) - 2);
        $rightBorder = mb_substr($line, -1, 1);

        return $this->foregroundColor->value
            . $leftBorder
            . Color::RESET->value
            . $middle
            . $this->foregroundColor->value
            . $rightBorder
            . Color::RESET->value;
    }

    /**
     * Builds a border line using terminal display width.
     *
     * @param string $label The label to show in the border.
     * @param bool $top Whether to resolve the top or bottom border.
     * @return string
     */
    private function buildBorderLine(string $label, bool $top): string
    {
        $leftCorner = $top ? $this->borderPack->topLeft : $this->borderPack->bottomLeft;
        $rightCorner = $top ? $this->borderPack->topRight : $this->borderPack->bottomRight;
        $horizontal = $this->borderPack->horizontal;
        $label = self::truncateToWidth($label, max(0, $this->width - 3));
        $line = $leftCorner . $horizontal . $label;
        $remainingWidth = max(0, $this->width - mb_strwidth($line) - 1);

        return $line . str_repeat($horizontal, $remainingWidth) . $rightCorner;
    }

    /**
     * Builds the window content using terminal display width.
     *
     * @return string[]
     */
    private function buildContentLines(): array
    {
        $innerWidth = max(0, $this->width - 2);
        $leftPadding = max(0, $this->padding->leftPadding);
        $rightPadding = max(0, $this->padding->rightPadding);
        $availableWidth = max(0, $innerWidth - $leftPadding - $rightPadding);
        $lines = [];

        foreach ($this->content as $line) {
            $body = str_repeat(' ', $leftPadding)
                . self::padToWidth(self::truncateToWidth((string) $line, $availableWidth), $availableWidth)
                . str_repeat(' ', $rightPadding);
            $lines[] = $this->borderPack->vertical
                . self::padToWidth(self::truncateToWidth($body, $innerWidth), $innerWidth)
                . $this->borderPack->vertical;
        }

        if ($lines === []) {
            $lines[] = $this->borderPack->vertical . str_repeat(' ', $innerWidth) . $this->borderPack->vertical;
        }

        while (count($lines) < $this->innerHeight) {
            $lines[] = $this->borderPack->vertical . str_repeat(' ', $innerWidth) . $this->borderPack->vertical;
        }

        return array_slice($lines, 0, $this->innerHeight);
    }

    /**
     * Truncates a string to the requested display width.
     *
     * @param string $text The text to truncate.
     * @param int $width The maximum display width.
     * @return string
     */
    private static function truncateToWidth(string $text, int $width): string
    {
        return mb_strimwidth($text, 0, $width, '');
    }

    /**
     * Pads a string to the requested display width.
     *
     * @param string $text The text to pad.
     * @param int $width The desired display width.
     * @return string
     */
    private static function padToWidth(string $text, int $width): string
    {
        $padding = max(0, $width - mb_strwidth($text));

        return $text . str_repeat(' ', $padding);
    }
}
