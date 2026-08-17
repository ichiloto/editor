<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * How one category's records are read out of, and folded back into, a file
 * that is not simply a list of them.
 *
 * A data file the runtime reads is shaped for the runtime, not for an editor:
 * a knowledge catalogue holds several lists side by side, and an optimization
 * policy holds nested maps keyed by role and by slot. Neither is a list of
 * records, yet both are edited as records.
 *
 * A projection is the one place that knows how a category's records relate to
 * the file's own shape, in both directions. Everything the file holds that
 * this category does not own is carried through `write()` untouched, so a
 * category never rewrites what is not its own.
 *
 * @package Ichiloto\Editor\Database
 */
interface RecordProjection
{
    /**
     * Returns this category's records, read out of the whole file payload.
     *
     * @param array<string, mixed> $whole The file's payload as authored.
     * @return array<int, array<string, mixed>> The records.
     */
    public function read(array $whole): array;

    /**
     * Returns the whole file payload with this category's records folded in.
     *
     * @param array<string, mixed> $whole The file's payload as authored.
     * @param array<int, array<string, mixed>> $rows This category's records.
     * @return array<string, mixed> The payload to write.
     */
    public function write(array $whole, array $rows): array;
}
