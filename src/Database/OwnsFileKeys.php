<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * A projection whose records are the whole of some top-level keys of their file (a catalogue's `actors` and
 * `resources`). The editor writes those keys as data, so each must be written as data in the file: one the file
 * builds with its own code is left read-only rather than replaced by a literal of what the code produced.
 */
interface OwnsFileKeys
{
    /**
     * The top-level keys this projection reads its records from and writes them back to.
     *
     * @return list<string>
     */
    public function listOwnedKeys(): array;
}
