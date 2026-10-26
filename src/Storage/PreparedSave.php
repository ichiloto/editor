<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Storage;

use Closure;

/**
 * One document's save, worked out and not yet written: the files it would
 * write and remove and the sources it read, how it proves the staged bytes
 * are what it holds, and what it adopts once they are installed.
 *
 * Preparing refuses what the document's own source cannot take, before any
 * file is touched. {@see SaveSet} installs one or many prepared saves as one
 * transaction, so several documents are written together or not at all.
 *
 * @package Ichiloto\Editor\Storage
 */
final class PreparedSave
{
    /**
     * @param string $label The document, in words: `map town/inn`, `cinematic harbour-lanterns`.
     * @param Closure(FileSetTransaction): void $declare Declares the writes, removals and expected sources.
     * @param (Closure(array<string, string>): void)|null $verify Proves the staged bytes, given each written
     *     path's staged copy; it throws to refuse the whole set.
     * @param (Closure(): void)|null $adopt Adopts the written state once every file of the set is installed.
     */
    public function __construct(
        public readonly string $label,
        private readonly Closure $declare,
        private readonly ?Closure $verify = null,
        private readonly ?Closure $adopt = null,
    ) {
    }

    /**
     * A save with nothing to write that still moves the document's baseline:
     * an edit that came back to what the file holds.
     *
     * @param Closure(): void $adopt
     */
    public static function adoptOnly(string $label, Closure $adopt): self
    {
        return new self($label, static function (): void {
        }, adopt: $adopt);
    }

    /**
     * The same save, followed by more once its state is adopted: an owner
     * forgetting what the save took away.
     *
     * @param Closure(): void $after
     */
    public function withAdoption(Closure $after): self
    {
        $adopt = $this->adopt;

        return new self($this->label, $this->declare, $this->verify, static function () use ($adopt, $after): void {
            if ($adopt !== null) {
                $adopt();
            }
            $after();
        });
    }

    /** Declares this document's files in a transaction. */
    public function declare(FileSetTransaction $transaction): void
    {
        ($this->declare)($transaction);
    }

    /**
     * Proves the staged bytes before anything is installed.
     *
     * @param array<string, string> $staged Staged copies by destination path.
     */
    public function verify(array $staged): void
    {
        if ($this->verify !== null) {
            ($this->verify)($staged);
        }
    }

    /** Adopts the written state. */
    public function adopt(): void
    {
        if ($this->adopt !== null) {
            ($this->adopt)();
        }
    }
}
