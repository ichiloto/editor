<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Storage;

use RuntimeException;
use Throwable;

/**
 * A save set that was not written: the document that refused, when one did,
 * and whether every file it had touched was put back.
 *
 * @package Ichiloto\Editor\Storage
 */
final class SaveSetRefusal extends RuntimeException
{
    /** Whether the files are exactly as they were before the set. */
    public readonly bool $wasRolledBack;

    /** @var list<string> Paths left in an unknown state when a rollback itself failed. */
    public readonly array $unrestored;

    /**
     * @param string|null $label The document that refused, or null when installing the set failed.
     */
    public function __construct(public readonly ?string $label, Throwable $previous)
    {
        $this->wasRolledBack = ! $previous instanceof FileSetTransactionFailure || $previous->wasRolledBack;
        $this->unrestored = $previous instanceof FileSetTransactionFailure ? array_values($previous->unrestoredPaths) : [];

        parent::__construct($label === null ? $previous->getMessage() : sprintf('%s: %s', $label, $previous->getMessage()), 0, $previous);
    }
}
