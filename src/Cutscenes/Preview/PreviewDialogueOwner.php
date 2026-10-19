<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePagination;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationProviderInterface;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueSnapshot;
use Ichiloto\Engine\UI\Enumerations\PresentationPriority;
use Ichiloto\Engine\UI\Interfaces\LayeredPresentationInterface;

/**
 * A dialogue line on the preview's screen, page by page as the game pages it
 * ({@see DialoguePagination}), each page whole until the author continues.
 * The Engine's scene composer reads it as it reads the game's dialogue window.
 */
final class PreviewDialogueOwner implements LayeredPresentationInterface, DialoguePresentationProviderInterface
{
    private int $page = 0;

    public function __construct(public readonly DialoguePagination $pagination)
    {
    }

    public function getDialogueSnapshot(): DialogueSnapshot
    {
        return $this->pagination->getSnapshot($this->page);
    }

    public function getPresentationBounds(): Rect
    {
        $origin = $this->pagination->position->getCoordinates($this->pagination->windowWidth, $this->pagination->windowHeight);

        return new Rect((int) $origin->x, (int) $origin->y, $this->pagination->windowWidth, $this->pagination->windowHeight);
    }

    public function getPresentationPriority(): PresentationPriority
    {
        return PresentationPriority::MODAL;
    }

    /** The page shown, from the first. */
    public function getPage(): int
    {
        return $this->page;
    }

    /** The text of the page shown. */
    public function getPageText(): string
    {
        return $this->pagination->pages[$this->page];
    }

    /** Moves to the next page; false on the last, which the author's continue closes instead. */
    public function turnPage(): bool
    {
        if ($this->page >= count($this->pagination->pages) - 1) {
            return false;
        }
        $this->page++;

        return true;
    }
}
