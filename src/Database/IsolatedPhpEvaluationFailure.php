<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use RuntimeException;

/**
 * An authored PHP member that failed inside an isolated evaluation batch.
 */
final class IsolatedPhpEvaluationFailure extends RuntimeException
{
    public function __construct(
        public readonly string $path,
        string $reason,
    ) {
        parent::__construct(sprintf('%s: %s', basename($path), $reason));
    }
}
