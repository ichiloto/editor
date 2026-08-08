<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Navigation;

/**
 * A bounded back-stack of visited editor locations.
 *
 * Go-to-definition pushes where the author came from and then jumps; Back
 * pops the newest entry and restores it. The stack is capped so a long
 * authoring session cannot grow it without bound — the oldest entries are
 * evicted first, exactly like the command history.
 */
final class NavigationStack
{
    /**
     * @var NavigationEntry[] The remembered origins, oldest first.
     */
    private array $entries = [];

    /**
     * @param int $capacity The maximum number of retained entries.
     */
    public function __construct(private readonly int $capacity = 50)
    {
    }

    /**
     * Remembers one origin.
     *
     * @param NavigationEntry $entry The origin to remember.
     * @return void
     */
    public function push(NavigationEntry $entry): void
    {
        $this->entries[] = $entry;

        while (count($this->entries) > max(1, $this->capacity)) {
            array_shift($this->entries);
        }
    }

    /**
     * Pops the newest origin and restores it.
     *
     * @return NavigationEntry|null The restored entry, or null when empty.
     */
    public function back(): ?NavigationEntry
    {
        $entry = array_pop($this->entries);

        if (! $entry instanceof NavigationEntry) {
            return null;
        }

        ($entry->restore)();

        return $entry;
    }

    /**
     * Returns the newest origin without restoring it.
     *
     * @return NavigationEntry|null
     */
    public function peek(): ?NavigationEntry
    {
        return $this->entries === [] ? null : $this->entries[count($this->entries) - 1];
    }

    /**
     * Returns whether an origin is available.
     *
     * @return bool
     */
    public function canGoBack(): bool
    {
        return $this->entries !== [];
    }

    /**
     * Returns the number of retained origins.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Empties the stack (workspace reloads invalidate every snapshot).
     *
     * @return void
     */
    public function clear(): void
    {
        $this->entries = [];
    }
}
