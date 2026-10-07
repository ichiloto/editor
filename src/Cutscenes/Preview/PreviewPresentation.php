<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Closure;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Events\Interpreter\EventDialoguePresentationInterface;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePageLayout;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePaginationBuilder;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\UI\Interfaces\LayeredPresentationInterface;

/**
 * Dialogue and choices as the preview pane presents them.
 *
 * The interpreter hands over text and choice prompts exactly as it would to
 * the field's windows. A line is paged as the game pages it
 * ({@see DialoguePaginationBuilder}) and each page waits for the author
 * (Enter to continue, ↑/↓ and Enter to choose) unless the session is told to
 * advance on its own, which a run to completion or a skip comparison does.
 * What is on screen is offered to the Engine's scene composer as the game's
 * own windows are ({@see getActivePresentations()}), so a graphical preview
 * draws it the game's way, and is drawn into the Terminal picture on its own
 * UI layer.
 */
final class PreviewPresentation implements EventDialoguePresentationInterface
{
    private ?PreviewDialogueOwner $dialogue = null;
    private ?PreviewChoiceOwner $choice = null;
    private ?int $result = null;
    private bool $confirmed = false;
    public bool $autoAdvance = false;
    /**
     * The graphical dialogue's page layout while a graphical preview is attached; null pages for the terminal.
     *
     * @var (Closure(string, DialogueContext, string): ?DialoguePageLayout)|null
     */
    public ?Closure $pageLayout = null;
    /** @var array<int, array{kind: string, text: string, speaker: string}> */
    public array $log = [];

    public function __construct(private readonly PreviewCamera $camera)
    {
    }

    public function beginText(string $text, string $name = ''): void
    {
        $this->beginDialogue($text, $name, new DialogueContext());
    }

    public function beginDialogue(string $text, string $speaker, DialogueContext $context): void
    {
        $this->reset();
        $layout = $this->pageLayout === null ? null : ($this->pageLayout)($speaker, $context, '');
        $this->dialogue = new PreviewDialogueOwner(DialoguePaginationBuilder::buildPagination($text, $speaker, '', $context,
            $this->camera->screen->getWidth(), $this->camera->screen->getHeight(), $layout));
        $this->log[] = ['kind' => 'text', 'text' => $text, 'speaker' => $speaker];
    }

    public function beginChoice(string $prompt, array $options, string $title = ''): void
    {
        $this->reset();
        $options = array_values(array_map(strval(...), $options));
        $width = $this->getBoxWidth();
        $height = count($options) + count(TerminalText::wrapToWidth($prompt, $width - 4)) + ($title === '' ? 2 : 3);
        $this->choice = new PreviewChoiceOwner($title, $prompt, $options, 0, new Rect(
            max(0, intdiv($this->camera->screen->getWidth() - $width, 2)), max(0, $this->camera->screen->getHeight() - $height - 1), $width, $height));
        $this->log[] = ['kind' => 'choice', 'text' => $prompt, 'speaker' => $title];
    }

    public function update(): void
    {
        if (! $this->autoAdvance) {
            return;
        }
        if ($this->choice !== null && $this->result === null) {
            $this->result = 0;
        }
        while ($this->dialogue?->turnPage()) {
        }
        $this->confirmed = true;
    }

    public function render(): void
    {
        if ($this->isComplete()) {
            return;
        }
        $owner = $this->dialogue ?? $this->choice;
        PresentationLayerPolicy::ui($owner, fn() => $this->drawBox());
    }

    public function isComplete(): bool
    {
        return ($this->dialogue === null && $this->choice === null) || $this->confirmed;
    }

    public function choiceResult(): ?int
    {
        return $this->result;
    }

    public function reset(): void
    {
        $this->dialogue = null;
        $this->choice = null;
        $this->result = null;
        $this->confirmed = false;
    }

    /**
     * What is on screen waiting for the author, as the Engine's presentation
     * owners, the one taking input first.
     *
     * @return list<LayeredPresentationInterface>
     */
    public function getActivePresentations(): array
    {
        return $this->isWaiting() ? array_values(array_filter([$this->choice, $this->dialogue])) : [];
    }

    /**
     * Whether something is on screen waiting for the author.
     */
    public function isWaiting(): bool
    {
        return ($this->dialogue !== null || $this->choice !== null) && ! $this->confirmed;
    }

    /**
     * A short line for the controls strip.
     */
    public function describeWait(): ?string
    {
        if (! $this->isWaiting()) {
            return null;
        }

        if ($this->choice !== null) {
            return sprintf('Choice (%d options): ↑/↓ select, Enter confirm', count($this->choice->options));
        }

        $pages = count($this->dialogue->pagination->pages);

        return $pages > 1
            ? sprintf('Dialogue waiting (page %d of %d): Enter to continue', $this->dialogue->getPage() + 1, $pages)
            : 'Dialogue waiting: Enter to continue';
    }

    /**
     * Turns to the dialogue's next page, or confirms its last page or the
     * highlighted option.
     */
    public function confirm(): bool
    {
        if (! $this->isWaiting()) {
            return false;
        }

        if ($this->dialogue?->turnPage()) {
            return true;
        }

        if ($this->choice !== null) {
            $this->result = $this->choice->highlighted;
        }

        $this->confirmed = true;

        return true;
    }

    public function moveHighlight(int $delta): bool
    {
        if ($this->choice === null || $this->confirmed) {
            return false;
        }

        $count = count($this->choice->options);
        $this->choice->highlighted = (($this->choice->highlighted + $delta) % $count + $count) % $count;

        return true;
    }

    /** The Terminal window: the dialogue's page or the choice, bordered, where the game places it. */
    private function drawBox(): void
    {
        $boxWidth = $this->getBoxWidth();
        $inner = $boxWidth - 4;
        $border = '+' . str_repeat('-', $boxWidth - 2) . '+';
        $rows = [$border];

        if ($this->dialogue !== null) {
            $speaker = $this->dialogue->pagination->speaker;
            if ($speaker !== '') {
                $rows[] = '| ' . TerminalText::padRight($speaker . ':', $inner) . ' |';
            }
            foreach (explode("\n", $this->dialogue->getPageText()) as $line) {
                foreach (TerminalText::wrapToWidth($line, $inner) as $wrapped) {
                    $rows[] = '| ' . TerminalText::padRight($wrapped, $inner) . ' |';
                }
            }
            $rows[] = '| ' . TerminalText::padRight('', $inner - 1) . '▼ |';
        } elseif ($this->choice !== null) {
            if ($this->choice->title !== '') {
                $rows[] = '| ' . TerminalText::padRight($this->choice->title . ':', $inner) . ' |';
            }
            foreach (TerminalText::wrapToWidth($this->choice->prompt, $inner) as $line) {
                $rows[] = '| ' . TerminalText::padRight($line, $inner) . ' |';
            }
            foreach ($this->choice->options as $index => $option) {
                $marker = $index === $this->choice->highlighted ? '>' : ' ';
                $rows[] = '| ' . TerminalText::padRight($marker . ' ' . $option, $inner) . ' |';
            }
        }

        $rows[] = $border;
        $width = $this->camera->screen->getWidth();
        $height = $this->camera->screen->getHeight();
        $this->camera->draw($rows, max(0, intdiv($width - $boxWidth, 2)), max(0, $height - count($rows) - 1));
    }

    private function getBoxWidth(): int
    {
        return $this->dialogue?->pagination->windowWidth ?? max(12, min($this->camera->screen->getWidth(), 60));
    }
}
