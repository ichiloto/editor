<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Modal\NoticeContent;

/**
 * The preview game's modal manager: the Engine's own notice and alert queue,
 * with its producer order, owners and cancellation, delivering each one to
 * the preview's screen instead of a blocking window.
 *
 * The game shows a delivered notice in a window that blocks its frame loop
 * until the player acknowledges it, and only then takes the next. The
 * preview's screen owns that wait instead: its owner delivers at the game's
 * frame boundary, never while something on the screen still waits, and holds
 * the scene until the author (or a capture's reading time) acknowledges the
 * page on screen ({@see CinematicPreviewSession}).
 */
final class PreviewNoticeManager extends ModalManager
{
    private ?PreviewPresentation $presentation = null;

    public function __construct(Game $game)
    {
        parent::__construct($game);
    }

    /** Delivers to this screen from now on. */
    public function presentOn(PreviewPresentation $presentation): void
    {
        $this->presentation = $presentation;
    }

    /** Whether anything waits to be delivered. */
    public function hasQueuedNotices(): bool
    {
        return $this->pendingAlerts !== [];
    }

    protected function showPendingNotice(NoticeContent $notice): void
    {
        $this->requirePresentation()->beginNotice($notice, $notice->title, $notice->message);
    }

    protected function showPendingAlert(string $message, string $title): void
    {
        $this->requirePresentation()->beginNotice(null, $title, $message);
    }

    private function requirePresentation(): PreviewPresentation
    {
        return $this->presentation ?? throw new \LogicException('The preview delivered a notice before its screen existed.');
    }
}
