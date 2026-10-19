<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Storage;

use Throwable;

/**
 * Writes prepared saves together, as one {@see FileSetTransaction}.
 *
 * Every document declares its files; every file is staged beside its
 * destination; every document proves its staged bytes. Only then is anything
 * installed, backed up once, and a file that cannot be installed puts back
 * every file already installed. Each document adopts its written state only
 * once the whole set is on disk. So the set ends complete or exactly as it
 * was, and a refusal names the document that refused.
 *
 * @package Ichiloto\Editor\Storage
 */
final class SaveSet
{
    private function __construct()
    {
    }

    /**
     * @param string $folder The folder the set belongs to: the project root for a change set, a document's own
     *     folder for a document saved alone.
     * @param list<PreparedSave> $saves The prepared saves.
     * @param callable(string ...$paths): void|null $backup Receives the existing files about to be replaced or removed.
     * @return array<string, list<string>> The paths each document wrote or removed, by its label; a document
     *     whose save only adopted what its files already held writes none.
     * @throws SaveSetRefusal When a document refuses or a file cannot be installed; nothing is left written.
     * @throws SaveSetAdoptionFailure When every file was written but a document could not take up its files.
     */
    public static function commit(string $folder, array $saves, ?callable $backup = null, ?FileSetOperations $files = null, bool $reserveFolder = false): array
    {
        $transaction = new FileSetTransaction($folder, $files ?? new FilesystemFileSetOperations(), $reserveFolder);
        $written = [];

        foreach ($saves as $save) {
            $before = count($transaction->paths());

            try {
                $save->declare($transaction);
            } catch (Throwable $failure) {
                throw new SaveSetRefusal($save->label, $failure);
            }

            $written[$save->label] = [...$written[$save->label] ?? [], ...array_slice($transaction->paths(), $before)];
        }

        if (! $transaction->isEmpty() || $transaction->hasExpectedSources()) {
            try {
                $staged = $transaction->stage();
            } catch (Throwable $failure) {
                throw new SaveSetRefusal(null, $failure);
            }

            foreach ($saves as $save) {
                try {
                    $save->verify($staged);
                } catch (Throwable $failure) {
                    $transaction->rollBack();

                    throw new SaveSetRefusal($save->label, $failure);
                }
            }

            try {
                $transaction->commit($backup);
            } catch (Throwable $failure) {
                throw new SaveSetRefusal(null, $failure);
            }
        }

        // Every file is on disk: each document takes up what it wrote, and
        // one that cannot does not keep the others from it.
        $failures = [];

        foreach ($saves as $save) {
            try {
                $save->adopt();
            } catch (Throwable $failure) {
                $failures[$save->label] = $failure;
            }
        }

        if ($failures !== []) {
            throw new SaveSetAdoptionFailure($written, $failures);
        }

        return $written;
    }

    /**
     * Writes one document's prepared save alone, refusing as the document
     * itself refuses.
     *
     * @param callable(string ...$paths): void|null $backup
     * @return list<string> Every path written or removed.
     * @throws Throwable Whatever the document or the transaction refused with.
     */
    public static function commitOne(string $folder, PreparedSave $save, ?callable $backup = null, ?FileSetOperations $files = null, bool $reserveFolder = false): array
    {
        try {
            return array_merge(...array_values(self::commit($folder, [$save], $backup, $files, $reserveFolder)));
        } catch (SaveSetRefusal $refusal) {
            throw $refusal->getPrevious() ?? $refusal;
        }
    }
}
