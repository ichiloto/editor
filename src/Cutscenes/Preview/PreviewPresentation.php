<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Events\Interpreter\EventPresentationInterface;
use Ichiloto\Engine\IO\Console\TerminalText;

/**
 * Dialogue and choices as the preview pane presents them.
 *
 * The interpreter hands over text and choice prompts exactly as it would to
 * the field's windows; here they wait for the author (Enter to continue,
 * ↑/↓ and Enter to choose) unless the session is told to advance on its own,
 * which a run to completion or a skip comparison does.
 */
final class PreviewPresentation implements EventPresentationInterface
{
    private ?string $text = null;
    private string $speaker = '';
    /** @var string[]|null */
    private ?array $options = null;
    private string $prompt = '';
    private string $title = '';
    private int $highlighted = 0;
    private ?int $choice = null;
    private bool $confirmed = false;
    public bool $autoAdvance = false;
    /** @var array<int, array{kind: string, text: string, speaker: string}> */
    public array $log = [];

    public function __construct(private readonly PreviewCamera $camera)
    {
    }

    public function beginText(string $text, string $name = ''): void
    {
        $this->reset();
        $this->text = $text;
        $this->speaker = $name;
        $this->log[] = ['kind' => 'text', 'text' => $text, 'speaker' => $name];
    }

    public function beginChoice(string $prompt, array $options, string $title = ''): void
    {
        $this->reset();
        $this->prompt = $prompt;
        $this->title = $title;
        $this->options = array_values(array_map(strval(...), $options));
        $this->log[] = ['kind' => 'choice', 'text' => $prompt, 'speaker' => $title];
    }

    public function update(): void
    {
        if ($this->autoAdvance) {
            if ($this->options !== null && $this->choice === null) {
                $this->choice = 0;
            }

            $this->confirmed = true;
        }
    }

    public function render(): void
    {
        if ($this->text === null && $this->options === null) {
            return;
        }

        $width = $this->camera->screen->getWidth();
        $height = $this->camera->screen->getHeight();
        $boxWidth = max(12, min($width, 60));
        $inner = $boxWidth - 4;
        $rows = [];
        $border = '+' . str_repeat('-', $boxWidth - 2) . '+';
        $rows[] = $border;

        if ($this->text !== null) {
            if ($this->speaker !== '') {
                $rows[] = '| ' . TerminalText::padRight($this->speaker . ':', $inner) . ' |';
            }

            foreach (TerminalText::wrapToWidth($this->text, $inner) as $line) {
                $rows[] = '| ' . TerminalText::padRight($line, $inner) . ' |';
            }

            $rows[] = '| ' . TerminalText::padRight('', $inner - 1) . '▼ |';
        } else {
            if ($this->title !== '') {
                $rows[] = '| ' . TerminalText::padRight($this->title . ':', $inner) . ' |';
            }

            foreach (TerminalText::wrapToWidth($this->prompt, $inner) as $line) {
                $rows[] = '| ' . TerminalText::padRight($line, $inner) . ' |';
            }

            foreach ($this->options ?? [] as $index => $option) {
                $marker = $index === $this->highlighted ? '>' : ' ';
                $rows[] = '| ' . TerminalText::padRight($marker . ' ' . $option, $inner) . ' |';
            }
        }

        $rows[] = $border;
        $x = max(0, intdiv($width - $boxWidth, 2));
        $y = max(0, $height - count($rows) - 1);
        $this->camera->draw($rows, $x, $y);
    }

    public function isComplete(): bool
    {
        if ($this->text === null && $this->options === null) {
            return true;
        }

        return $this->confirmed;
    }

    public function choiceResult(): ?int
    {
        return $this->choice;
    }

    public function reset(): void
    {
        $this->text = null;
        $this->speaker = '';
        $this->options = null;
        $this->prompt = '';
        $this->title = '';
        $this->highlighted = 0;
        $this->choice = null;
        $this->confirmed = false;
    }

    /**
     * Whether something is on screen waiting for the author.
     */
    public function isWaiting(): bool
    {
        return ($this->text !== null || $this->options !== null) && ! $this->confirmed;
    }

    /**
     * A short line for the controls strip.
     */
    public function describeWait(): ?string
    {
        if (! $this->isWaiting()) {
            return null;
        }

        if ($this->options !== null) {
            return sprintf('Choice (%d options): ↑/↓ select, Enter confirm', count($this->options));
        }

        return 'Dialogue waiting: Enter to continue';
    }

    /**
     * Confirms the text, or the highlighted option.
     */
    public function confirm(): bool
    {
        if (! $this->isWaiting()) {
            return false;
        }

        if ($this->options !== null) {
            $this->choice = $this->highlighted;
        }

        $this->confirmed = true;

        return true;
    }

    public function moveHighlight(int $delta): bool
    {
        if ($this->options === null || $this->confirmed) {
            return false;
        }

        $count = count($this->options);
        $this->highlighted = (($this->highlighted + $delta) % $count + $count) % $count;

        return true;
    }
}
