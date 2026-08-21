<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Camera;

/**
 * A camera that draws into a frame buffer instead of the terminal.
 *
 * Everything the Engine paints during a cinematic — the map, staged actors,
 * NPCs, the player, title cards, narration boxes, field animations, and
 * transition covers — reaches the terminal through the camera, so capturing
 * here gives the preview pane an honest picture of the moment without the
 * Engine ever writing a byte over the editor's own screen.
 */
final class PreviewCamera extends Camera
{
    /** @var string[] The captured rows, one per screen line. */
    private array $frame = [];

    public function __construct(int $width, int $height)
    {
        $this->screen = new Rect(0, 0, max(1, $width), max(1, $height));
        $this->position = new Vector2(0, 0);
        $this->player = null;
        $this->worldSpace = [];
        $this->clearFrame();
    }

    /**
     * Resizes the captured screen.
     */
    public function resize(int $width, int $height): void
    {
        $this->resizeViewport($width, $height);
        $this->clearFrame();
    }

    /**
     * Blanks the frame before the scene is composed again.
     */
    public function clearFrame(): void
    {
        $this->frame = array_fill(0, $this->screen->getHeight(), str_repeat(' ', $this->screen->getWidth()));
    }

    /**
     * Returns the captured rows.
     *
     * @return string[]
     */
    public function frame(): array
    {
        return $this->frame;
    }

    /**
     * Paints the world (the map tiles) into the frame.
     */
    public function renderMap(): void
    {
        $renderOffset = $this->getRenderOffset();
        $visibleWidth = $this->getVisibleWorldWidth();
        $visibleHeight = $this->getVisibleWorldHeight();

        for ($row = 0; $row < $visibleHeight; $row++) {
            $worldRow = $this->worldSpace[$this->position->y + $row] ?? null;

            if ($worldRow === null) {
                continue;
            }

            $content = is_array($worldRow)
                ? implode('', array_slice($worldRow, (int) $this->position->x, $visibleWidth))
                : TerminalText::sliceSymbols((string) $worldRow, (int) $this->position->x, $visibleWidth);

            $this->draw(TerminalText::padRight($content, $visibleWidth), (int) $renderOffset->x, (int) $renderOffset->y + $row);
        }
    }

    public function draw(iterable|string $content, int $x = 0, int $y = 0): void
    {
        $lines = [];

        // A row handed over with a newline inside it would break the
        // terminal's row, so it continues on the next row here as well.
        foreach (is_string($content) ? [$content] : $content as $line) {
            foreach (explode("\n", (string) $line) as $part) {
                $lines[] = $part;
            }
        }

        $height = $this->screen->getHeight();
        $width = $this->screen->getWidth();
        $index = 0;

        foreach ($lines as $line) {
            $row = $y + $index;
            $index++;

            if ($row < 0 || $row >= $height) {
                continue;
            }

            $this->writeRow($row, max(0, min($x, $width - 1)), (string) $line);
        }
    }

    public function renderOnScreen(array $output, Vector2 $worldSpacePosition): void
    {
        $screenSpacePosition = $this->getScreenSpacePosition($worldSpacePosition);
        $this->renderAtScreenPosition($output, $screenSpacePosition);
    }

    public function renderAtScreenPosition(array|string $output, Vector2 $screenSpacePosition): void
    {
        $rows = is_array($output) ? array_values($output) : [$output];

        foreach ($rows as $rowIndex => $row) {
            $this->draw((string) $row, (int) $screenSpacePosition->x, (int) $screenSpacePosition->y + $rowIndex);
        }
    }

    public function start(): void
    {
    }

    public function stop(): void
    {
    }

    public function render(): void
    {
    }

    public function erase(): void
    {
    }

    public function resume(): void
    {
    }

    public function suspend(): void
    {
    }

    public function update(): void
    {
    }

    /**
     * Writes text into one frame row, symbol by symbol, honouring display
     * width so a wide glyph occupies the cells it would on a terminal.
     */
    private function writeRow(int $row, int $x, string $text): void
    {
        $width = $this->screen->getWidth();
        $plain = TerminalText::stripAnsi(TerminalText::formatStyles($text));
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $plain) ?? $plain;
        $cells = TerminalText::visibleSymbols($this->frame[$row] ?? str_repeat(' ', $width));
        $cells = $this->normalizeCells($cells, $width);
        $cursor = $x;

        foreach (TerminalText::visibleSymbols($plain) as $symbol) {
            $symbolWidth = max(1, TerminalText::displayWidth($symbol));

            if ($cursor >= $width || $cursor + $symbolWidth > $width) {
                break;
            }

            $cells[$cursor] = $symbol;

            for ($offset = 1; $offset < $symbolWidth; $offset++) {
                $cells[$cursor + $offset] = '';
            }

            $cursor += $symbolWidth;
        }

        $this->frame[$row] = implode('', $cells);
    }

    /**
     * Expands a row into exactly `$width` cells, one per terminal column.
     *
     * @param string[] $symbols
     * @return string[]
     */
    private function normalizeCells(array $symbols, int $width): array
    {
        $cells = [];
        $column = 0;

        foreach ($symbols as $symbol) {
            $symbolWidth = max(1, TerminalText::displayWidth($symbol));
            $cells[$column] = $symbol;

            for ($offset = 1; $offset < $symbolWidth; $offset++) {
                $cells[$column + $offset] = '';
            }

            $column += $symbolWidth;
        }

        for (; $column < $width; $column++) {
            $cells[$column] = ' ';
        }

        ksort($cells);

        return array_slice($cells, 0, $width, true);
    }
}
