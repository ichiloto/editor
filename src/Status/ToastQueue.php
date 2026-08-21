<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Status;

/**
 * The snackbar-style queue behind the footer status line.
 *
 * Phase 2 gave statuses types and expiry; this queue stops them overwriting
 * each other. INFO messages stay an ephemeral ticker (they replace other INFO
 * instantly and are dropped while something weightier is on screen), while
 * SUCCESS/WARN/ERROR messages hold the line for their full time-to-live:
 * a higher-severity arrival preempts a lower-severity one (which re-queues),
 * and a same-or-lower-severity arrival waits its turn behind it.
 */
final class ToastQueue
{
    /**
     * The maximum number of toasts waiting behind the current one.
     */
    private const int MAX_PENDING = 5;

    /**
     * The toast currently on screen.
     */
    private ?Toast $current = null;
    /**
     * When the current toast expires.
     */
    private float $expiresAt = 0.0;
    /**
     * @var Toast[] The toasts waiting to be shown, oldest first.
     */
    private array $pending = [];

    /**
     * Publishes a toast according to the severity policy.
     *
     * @param Toast $toast The toast to publish.
     * @param float $now The current monotonic-ish timestamp.
     * @return void
     */
    public function push(Toast $toast, float $now): void
    {
        if ($this->current === null || $now >= $this->expiresAt) {
            $this->promote($toast, $now);
            return;
        }

        if ($this->isDuplicateOfTail($toast)) {
            return;
        }

        $currentRank = $this->rank($this->current->level);
        $newRank = $this->rank($toast->level);

        if ($toast->level === StatusLevel::INFO) {
            // INFO is the ephemeral ticker: replace other INFO instantly,
            // never displace or queue behind weightier messages.
            if ($currentRank === 0) {
                $this->promote($toast, $now);
            }

            return;
        }

        if ($newRank > $currentRank) {
            // Preempt: the weightier message shows now; a displaced
            // non-INFO message gets its turn back afterwards.
            if ($currentRank > 0) {
                array_unshift($this->pending, $this->current);
            }

            $this->promote($toast, $now);
            $this->capPending();
            return;
        }

        $this->pending[] = $toast;
        $this->capPending();
    }

    /**
     * Advances the queue: expires the current toast and promotes the next.
     *
     * @param float $now The current timestamp.
     * @return bool Whether the displayed toast changed.
     */
    public function tick(float $now): bool
    {
        if ($this->current === null || $now < $this->expiresAt) {
            return false;
        }

        $next = array_shift($this->pending);

        if ($next instanceof Toast) {
            $this->promote($next, $now);
            return true;
        }

        $this->current = null;

        return true;
    }

    /**
     * Returns the toast currently on screen.
     *
     * @return Toast|null
     */
    public function current(): ?Toast
    {
        return $this->current;
    }

    /**
     * Returns how many toasts are waiting behind the current one.
     *
     * @return int
     */
    public function pendingCount(): int
    {
        return count($this->pending);
    }

    /**
     * Dismisses the toast currently on screen (optionally only when it has
     * the given level) and promotes the next queued one immediately.
     *
     * Confirmation prompts publish WARN toasts whose lifetime should end
     * with the prompt itself — resolving the prompt dismisses them so the
     * outcome message is not suppressed by a stale prompt.
     *
     * @param float $now The current timestamp.
     * @param StatusLevel|null $level Dismiss only a toast of this level.
     * @return void
     */
    public function dismissCurrent(float $now, ?StatusLevel $level = null): void
    {
        if ($this->current === null || ($level !== null && $this->current->level !== $level)) {
            return;
        }

        $this->expiresAt = $now;
        $this->tick($now);
    }

    /**
     * Drops the current toast and everything queued behind it.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->current = null;
        $this->expiresAt = 0.0;
        $this->pending = [];
    }

    /**
     * Puts a toast on screen and stamps its expiry.
     *
     * @param Toast $toast The toast to display.
     * @param float $now The current timestamp.
     * @return void
     */
    private function promote(Toast $toast, float $now): void
    {
        $this->current = $toast;
        $this->expiresAt = $now + $toast->level->timeToLiveSeconds();
    }

    /**
     * Returns whether the toast repeats the newest visible/queued message.
     *
     * @param Toast $toast The candidate toast.
     * @return bool
     */
    private function isDuplicateOfTail(Toast $toast): bool
    {
        $tail = $this->pending === [] ? $this->current : $this->pending[count($this->pending) - 1];

        return $tail instanceof Toast
            && $tail->message === $toast->message
            && $tail->level === $toast->level;
    }

    /**
     * Drops the oldest queued toasts beyond the pending cap.
     *
     * @return void
     */
    private function capPending(): void
    {
        while (count($this->pending) > self::MAX_PENDING) {
            array_shift($this->pending);
        }
    }

    /**
     * Returns the severity rank of a status level (higher preempts lower).
     *
     * @param StatusLevel $level The level to rank.
     * @return int
     */
    private function rank(StatusLevel $level): int
    {
        return match ($level) {
            StatusLevel::INFO => 0,
            StatusLevel::SUCCESS => 1,
            StatusLevel::WARN => 2,
            StatusLevel::ERROR => 3,
        };
    }
}
