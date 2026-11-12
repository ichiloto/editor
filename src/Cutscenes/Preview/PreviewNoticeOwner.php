<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\UI\Enumerations\PresentationPriority;
use Ichiloto\Engine\UI\Interfaces\LayeredPresentationInterface;
use Ichiloto\Engine\UI\Interfaces\ModalPresentationProviderInterface;
use Ichiloto\Engine\UI\Modal\ModalPresentation;
use Ichiloto\Engine\UI\Modal\NoticeContent;
use Ichiloto\Engine\UI\Modal\NoticePageModel;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\TerminalControlText;

/**
 * A notice or alert on the preview's screen, page by page, until the author
 * acknowledges each page. The game shows the same announcement in its
 * blocking notice window ({@see \Ichiloto\Engine\UI\Modal\PagedAlertModal}); a
 * preview has no blocking loop, so it holds the scene while this waits. The
 * Engine's scene composer reads it as it reads the game's notice window.
 *
 * The pages are the Engine's own ({@see NoticePageModel}), the model the
 * game's notice window pages with: on a graphical presentation with a menu
 * theme, the theme's reading area on the screen's canvas measures them;
 * otherwise the Terminal window's wrapped lines are taken six rows at a
 * time, less the rows its control hints keep. This owner supplies only the
 * preview screen's bounds and its hint rows.
 */
final class PreviewNoticeOwner implements LayeredPresentationInterface, ModalPresentationProviderInterface
{
    /** The notice window's width on the Terminal screen, as the game's alert windows are. */
    private const int WINDOW_WIDTH = 50;
    /** The most reading rows one Terminal notice page holds. */
    private const int TERMINAL_ROWS = 6;

    /** @var non-empty-list<string> The pages, as the Engine's model made them. */
    public array $pages { get => $this->model->pages; }
    private NoticePageModel $model;
    private Rect $bounds;

    /**
     * @param RendererGridConfig|null $graphicalGrid The graphical screen the notice is laid out on; null on the Terminal.
     */
    public function __construct(
        public readonly ?NoticeContent $notice,
        string $title,
        public readonly string $message,
        int $screenWidth,
        int $screenHeight,
        ?RendererGridConfig $graphicalGrid,
        string $assetRoot,
    ) {
        $source = new ModalPresentation($title, $message, ['OK'], 0, singleConfirmation: true, notice: $notice, sourceMessage: $message);
        $width = min(self::WINDOW_WIDTH, max(4, $screenWidth));
        $this->model = $this->paginateGraphically($source, $graphicalGrid, $assetRoot)
            ?? NoticePageModel::paginateTerminal($source, max(1, $width - 4), min(self::TERMINAL_ROWS, max(1, $screenHeight - 4)),
                count($this->getHintRows(max(1, $width - 4))));
        $height = min($screenHeight, max(array_map(fn(string $page): int => count($this->getTerminalRows($page, $width - 4)), $this->pages)) + 3);
        $this->bounds = new Rect(max(0, intdiv($screenWidth - $width, 2)), max(0, intdiv($screenHeight - $height, 2)), $width, $height);
    }

    public function getModalPresentation(): ModalPresentation
    {
        return $this->model->getModalPresentation();
    }

    public function getPresentationBounds(): Rect
    {
        return $this->bounds;
    }

    public function getPresentationPriority(): PresentationPriority
    {
        return PresentationPriority::MODAL;
    }

    /** The window's title, which a long Terminal title gives up to the message, as the game's does. */
    public function getTitle(): string
    {
        return $this->model->title;
    }

    /** The page shown, from the first. */
    public function getPage(): int
    {
        return $this->model->index;
    }

    public function hasNextPage(): bool
    {
        return $this->model->hasNextPage;
    }

    /** Moves to the next page; false on the last, which the author's acknowledgement closes instead. */
    public function turnPage(): bool
    {
        return $this->model->turnPage();
    }

    /**
     * The Terminal window's rows for a page: its reading lines and the control hints the notice names.
     *
     * @return list<string>
     */
    public function getTerminalRows(string $page, int $columns): array
    {
        return [...TerminalText::wrapParagraphsToWidth($page, max(1, $columns)), ...$this->getHintRows($columns)];
    }

    private function paginateGraphically(ModalPresentation $source, ?RendererGridConfig $grid, string $assetRoot): ?NoticePageModel
    {
        $theme = $grid === null ? null : MenuPresentationCatalog::load($assetRoot);
        if ($grid === null || $theme === null) {
            return null;
        }

        return NoticePageModel::paginateCanvas($source, new PresentationCanvas(
            min(PresentationCanvas::DEFAULT_WIDTH, $grid->columns * $grid->cellWidth),
            min(PresentationCanvas::DEFAULT_HEIGHT, $grid->rows * $grid->cellHeight)), $theme);
    }

    /** @return list<string> */
    private function getHintRows(int $columns): array
    {
        $text = $this->notice === null ? '' : TerminalControlText::formatHints($this->notice->getActionHints());

        return $text === '' ? [] : ['', ...TerminalText::wrapParagraphsToWidth($text, max(1, $columns))];
    }
}
