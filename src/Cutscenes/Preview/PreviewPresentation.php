<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Closure;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Events\Interpreter\EventDialoguePresentationInterface;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePageLayout;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePaginationBuilder;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\UI\Interfaces\LayeredPresentationInterface;
use Ichiloto\Engine\UI\Modal\NoticeContent;

/**
 * Dialogue, choices and notices as the preview pane presents them.
 *
 * The interpreter hands over text and choice prompts exactly as it would to
 * the field's windows. A line is paged as the game pages it
 * ({@see DialoguePaginationBuilder}) and each page waits for the author
 * (Enter to continue, ↑/↓ and Enter to choose) unless the session is told to
 * advance on its own, which a run to completion or a skip comparison does.
 * A notice the Engine delivers ({@see PreviewNoticeManager}) waits the same
 * way, page by page ({@see PreviewNoticeOwner}); while it is on screen the
 * scene is held, as the game's blocking notice window holds its frame loop.
 * What is on screen is offered to the Engine's scene composer as the game's
 * own windows are ({@see getActivePresentations()}), so a graphical preview
 * draws it the game's way, and is drawn into the Terminal picture on its own
 * UI layer.
 *
 * Pages and boxes are measured on the preview's text screen, as the game's
 * dialogue is on its console screen, never on the field camera: a graphical
 * field's camera counts physical ground cells, which are far fewer than the
 * text cells a page is laid out in.
 */
final class PreviewPresentation implements EventDialoguePresentationInterface
{
    private ?PreviewDialogueOwner $dialogue = null;
    private ?PreviewChoiceOwner $choice = null;
    private ?PreviewNoticeOwner $notice = null;
    private ?int $result = null;
    private bool $confirmed = false;
    public bool $autoAdvance = false;
    /**
     * The graphical dialogue's page layout while a graphical preview is attached; null pages for the terminal.
     *
     * @var (Closure(string, DialogueContext, string): ?DialoguePageLayout)|null
     */
    public ?Closure $pageLayout = null;
    /** The graphical screen a notice is paged on while a graphical preview is attached; null pages for the terminal. */
    public ?RendererGridConfig $graphicalGrid = null;
    /** @var array<int, array{kind: string, text: string, speaker: string}> */
    public array $log = [];

    /**
     * @param int $screenWidth The preview's text screen, in columns.
     * @param int $screenHeight The preview's text screen, in rows.
     */
    public function __construct(private int $screenWidth, private int $screenHeight, private readonly string $assetRoot = '')
    {
    }

    /** Takes the preview's new text screen size; the next line is paged and placed on it. */
    public function resizeScreen(int $width, int $height): void
    {
        $this->screenWidth = $width;
        $this->screenHeight = $height;
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
            $this->screenWidth, $this->screenHeight, $layout));
        $this->log[] = ['kind' => 'text', 'text' => $text, 'speaker' => $speaker];
    }

    public function beginChoice(string $prompt, array $options, string $title = ''): void
    {
        $this->reset();
        $options = array_values(array_map(strval(...), $options));
        $width = $this->getBoxWidth();
        $height = count($options) + count(TerminalText::wrapToWidth($prompt, $width - 4)) + ($title === '' ? 2 : 3);
        $this->choice = new PreviewChoiceOwner($title, $prompt, $options, 0, new Rect(
            max(0, intdiv($this->screenWidth - $width, 2)), max(0, $this->screenHeight - $height - 1), $width, $height));
        $this->log[] = ['kind' => 'choice', 'text' => $prompt, 'speaker' => $title];
    }

    /**
     * Puts a delivered notice or alert on screen, after whatever waited
     * there; it waits for the author's acknowledgement page by page. A notice
     * names its artwork and control hints; an alert has none.
     */
    public function beginNotice(?NoticeContent $notice, string $title, string $message): void
    {
        $this->notice = new PreviewNoticeOwner($notice, $title, $message, $this->screenWidth, $this->screenHeight,
            $this->graphicalGrid, $this->assetRoot);
        $this->log[] = ['kind' => 'notice', 'text' => $message, 'speaker' => $title];
    }

    /** Whether a notice is on screen, holding the scene until it is acknowledged. */
    public function isNoticeShown(): bool
    {
        return $this->notice !== null;
    }

    public function update(): void
    {
        if (! $this->autoAdvance) {
            return;
        }
        $this->notice = null;
        if ($this->choice !== null && $this->result === null) {
            $this->result = 0;
        }
        while ($this->dialogue?->turnPage()) {
        }
        $this->confirmed = true;
    }

    public function render(): void
    {
        if ($this->notice !== null) {
            PresentationLayerPolicy::ui($this->notice, fn() => $this->drawNotice($this->notice));
        }
        if ($this->isComplete()) {
            return;
        }
        if ($this->dialogue !== null) {
            PresentationLayerPolicy::ui($this->dialogue, fn() => $this->drawDialogue($this->dialogue));
        } elseif ($this->choice !== null) {
            PresentationLayerPolicy::ui($this->choice, fn() => $this->drawChoice($this->choice));
        }
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
        if ($this->notice !== null) {
            return [$this->notice];
        }

        return $this->isWaiting() ? array_values(array_filter([$this->choice, $this->dialogue])) : [];
    }

    /**
     * Whether something is on screen waiting for the author.
     */
    public function isWaiting(): bool
    {
        return $this->notice !== null || (($this->dialogue !== null || $this->choice !== null) && ! $this->confirmed);
    }

    /**
     * What the author answers now: `notice` (acknowledge), `choice` (move
     * the highlight, then confirm), `dialogue` (confirm), or null when
     * nothing waits.
     */
    public function getWaitKind(): ?string
    {
        return match (true) {
            ! $this->isWaiting() => null,
            $this->notice !== null => 'notice',
            $this->choice !== null => 'choice',
            default => 'dialogue',
        };
    }

    /**
     * A short line for the controls strip.
     */
    public function describeWait(): ?string
    {
        if (! $this->isWaiting()) {
            return null;
        }

        if ($this->notice !== null) {
            $pages = count($this->notice->pages);

            return $pages > 1
                ? sprintf('Notice waiting (page %d of %d): Enter to acknowledge', $this->notice->getPage() + 1, $pages)
                : 'Notice waiting: Enter to acknowledge';
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

        if ($this->notice !== null) {
            if (! $this->notice->turnPage()) {
                $this->notice = null;
            }

            return true;
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

    /**
     * The Terminal dialogue window, in the footprint its owner declares
     * ({@see PreviewDialogueOwner::getPresentationBounds()}), which the
     * Engine frames a cinematic's cast clear of: the speaker in its top
     * border and the page in its fixed rows, as the game's window has them,
     * with the mark that it waits in its bottom border.
     */
    private function drawDialogue(PreviewDialogueOwner $dialogue): void
    {
        $bounds = $dialogue->getPresentationBounds();
        $width = $bounds->getWidth();
        $inner = max(0, $width - 4);
        $speaker = TerminalText::truncateToWidth($dialogue->pagination->speaker, max(0, $width - 3));
        $rows = ['+-' . $speaker . str_repeat('-', max(0, $width - 3 - TerminalText::displayWidth($speaker))) . '+'];
        $lines = explode("\n", $dialogue->getPageText());
        for ($row = 0; $row < $bounds->getHeight() - 2; $row++) {
            $rows[] = '| ' . TerminalText::padRight($lines[$row] ?? '', $inner) . ' |';
        }
        $rows[] = '+' . str_repeat('-', $inner) . '▼-+';
        $this->writeWindow($rows, $bounds->getX(), $bounds->getY());
    }

    /** The Terminal choice window: its title, prompt and options, bordered, in the footprint the choice declares. */
    private function drawChoice(PreviewChoiceOwner $choice): void
    {
        $boxWidth = $this->getBoxWidth();
        $inner = $boxWidth - 4;
        $border = '+' . str_repeat('-', $boxWidth - 2) . '+';
        $rows = [$border];
        if ($choice->title !== '') {
            $rows[] = '| ' . TerminalText::padRight($choice->title . ':', $inner) . ' |';
        }
        foreach (TerminalText::wrapToWidth($choice->prompt, $inner) as $line) {
            $rows[] = '| ' . TerminalText::padRight($line, $inner) . ' |';
        }
        foreach ($choice->options as $index => $option) {
            $marker = $index === $choice->highlighted ? '>' : ' ';
            $rows[] = '| ' . TerminalText::padRight($marker . ' ' . $option, $inner) . ' |';
        }
        $rows[] = $border;
        $this->writeWindow($rows, max(0, intdiv($this->screenWidth - $boxWidth, 2)), max(0, $this->screenHeight - count($rows) - 1));
    }

    /**
     * Writes a window's rows on the text screen, as the game's windows are: not clipped to the field camera.
     *
     * @param list<string> $rows
     */
    private function writeWindow(array $rows, int $x, int $y): void
    {
        foreach (array_slice($rows, 0, $this->screenHeight) as $index => $row) {
            Console::write(TerminalText::truncateToWidth($row, $this->screenWidth - $x), $x, min($y + $index, $this->screenHeight - 1));
        }
    }

    /** The Terminal notice window: its title, the page shown and its button, centred on the text screen. */
    private function drawNotice(PreviewNoticeOwner $notice): void
    {
        $bounds = $notice->getPresentationBounds();
        $inner = $bounds->getWidth() - 4;
        $title = $notice->getTitle();
        $border = '+' . str_repeat('-', $bounds->getWidth() - 2) . '+';
        $top = $title === '' ? $border : TerminalText::truncateToWidth('+-' . $title . str_repeat('-', max(0, $bounds->getWidth() - 3
            - TerminalText::displayWidth($title))) . '+', $bounds->getWidth());
        $rows = [$top];
        foreach ($notice->getTerminalRows($notice->pages[$notice->getPage()], $inner) as $line) {
            $rows[] = '| ' . TerminalText::padRight($line, $inner) . ' |';
        }
        $button = '[ ' . ($notice->hasNextPage() ? 'Next' : 'OK') . ' ]';
        $rows[] = '| ' . TerminalText::padRight(str_repeat(' ', max(0, intdiv($inner - TerminalText::displayWidth($button), 2))) . $button, $inner) . ' |';
        $rows[] = $border;
        foreach (array_slice($rows, 0, $this->screenHeight) as $index => $row) {
            Console::write(TerminalText::truncateToWidth($row, $this->screenWidth - $bounds->getX()), $bounds->getX(),
                min($bounds->getY() + $index, $this->screenHeight - 1));
        }
    }

    private function getBoxWidth(): int
    {
        return $this->dialogue?->pagination->windowWidth ?? max(12, min($this->screenWidth, 60));
    }
}
