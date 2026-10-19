<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Storage;

use RuntimeException;
use Throwable;

/**
 * A save set whose files were all installed, but where a document could not
 * take up what it wrote: the files on disk are the new ones, and that
 * document no longer knows it.
 *
 * @package Ichiloto\Editor\Storage
 */
final class SaveSetAdoptionFailure extends RuntimeException
{
    /**
     * @param array<string, list<string>> $written The paths each document wrote or removed, by its label.
     * @param array<string, Throwable> $failures Why each document could not take up its files, by its label.
     */
    public function __construct(public readonly array $written, public readonly array $failures)
    {
        parent::__construct(sprintf(
            'Every file was written, but %s could not take up what was written (%s); reload the project before editing further.',
            implode(', ', array_keys($failures)),
            implode('; ', array_map(static fn(Throwable $failure): string => rtrim($failure->getMessage(), '.'), $failures)),
        ), 0, array_values($failures)[0] ?? null);
    }
}
