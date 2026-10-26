<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\UI\Enumerations\PresentationPriority;
use Ichiloto\Engine\UI\Interfaces\LayeredPresentationInterface;
use Ichiloto\Engine\UI\Interfaces\ModalPresentationProviderInterface;
use Ichiloto\Engine\UI\Modal\ModalPresentation;

/**
 * A choice on the preview's screen, with the option the author has
 * highlighted. The Engine's scene composer reads it as it reads the game's
 * choice window ({@see \Ichiloto\Engine\UI\Modal\SelectModal}).
 */
final class PreviewChoiceOwner implements LayeredPresentationInterface, ModalPresentationProviderInterface
{
    /** @param non-empty-list<string> $options */
    public function __construct(
        public readonly string $title,
        public readonly string $prompt,
        public readonly array $options,
        public int $highlighted,
        private readonly Rect $bounds,
    ) {
    }

    public function getModalPresentation(): ModalPresentation
    {
        return new ModalPresentation($this->title, $this->prompt, $this->options, $this->highlighted, true);
    }

    public function getPresentationBounds(): Rect
    {
        return $this->bounds;
    }

    public function getPresentationPriority(): PresentationPriority
    {
        return PresentationPriority::MODAL;
    }
}
