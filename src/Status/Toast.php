<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Status;

/**
 * One footer status message queued for display.
 */
final readonly class Toast
{
    /**
     * @param string $message The one-line footer message.
     * @param StatusLevel $level The message severity.
     * @param string[] $detailLines Longer content retained for the Ctrl+E detail overlay.
     */
    public function __construct(
        public string $message,
        public StatusLevel $level = StatusLevel::INFO,
        public array $detailLines = [],
    ) {
    }
}
