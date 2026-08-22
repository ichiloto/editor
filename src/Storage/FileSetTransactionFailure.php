<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Storage;

use RuntimeException;
use Throwable;

/**
 * A paired-file transaction that refused, saying honestly how far back it
 * got.
 *
 * `$wasRolledBack` is true only when every file the transaction had already
 * touched was put back exactly as it was found. When a restoration itself
 * fails the failure says so and names the files whose state on disk is not
 * the state they were loaded in, because a caller that believes a rollback
 * succeeded would go on to trust bytes that are no longer there.
 *
 * @package Ichiloto\Editor\Storage
 */
final class FileSetTransactionFailure extends RuntimeException
{
    /**
     * @param string $reason What could not be done.
     * @param bool $wasRolledBack Whether every touched file was restored.
     * @param string[] $unrestoredPaths The files left in an unknown state.
     */
    public function __construct(
        public readonly string $reason,
        public readonly bool $wasRolledBack = true,
        public readonly array $unrestoredPaths = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(self::describe($reason, $wasRolledBack, $unrestoredPaths), 0, $previous);
    }

    /**
     * @param string[] $unrestoredPaths
     */
    private static function describe(string $reason, bool $wasRolledBack, array $unrestoredPaths): string
    {
        $reason = rtrim($reason, '.');

        if ($wasRolledBack) {
            return $reason . '; nothing was changed.';
        }

        return sprintf(
            '%s, and the files could not be put back: %s. Restore them from the backup before editing further.',
            $reason,
            implode(', ', array_map(basename(...), $unrestoredPaths)),
        );
    }
}
