<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Storage;

use Throwable;

/**
 * One transaction over the files that are one logical asset.
 *
 * A cinematic or a summon is a pair of files; a map is a triplet. Installing
 * some members without the rest is not a partial success, it is a broken
 * asset -- a definition with no script, new data over old tiles -- and the
 * same is true of removing some without the rest. This is the single
 * boundary through which such a set is written, first written, overwritten
 * and deleted, so every one of those paths ends with either the complete new
 * state or the complete old one.
 *
 * The shape is stage, then commit:
 *
 *  1. `write()` and `remove()` declare what the new state should be;
 *  2. `stage()` creates the folder if the asset is new, writes every
 *     proposed file beside its destination, and reads each back, so the
 *     caller can evaluate and hydrate the exact bytes that will be
 *     installed;
 *  3. `commit()` takes the backup once, then installs every target;
 *  4. any failure restores every file already touched, and says so.
 *
 * A restoration that itself fails is reported as such rather than swallowed:
 * `FileSetTransactionFailure::$wasRolledBack` is false and the files left
 * in an unknown state are named. Nothing the transaction created survives a
 * refusal -- no temporary file, and no folder it made.
 *
 * @package Ichiloto\Editor\Storage
 */
final class FileSetTransaction
{
    /** A write target: the bytes to install. */
    private const string INTENT_WRITE = 'write';
    /** A removal target. */
    private const string INTENT_REMOVE = 'remove';

    /**
     * @var array<int, array{path: string, intent: string, contents: string|null}> The declared targets, in order.
     */
    private array $targets = [];

    /**
     * @var array<string, string> Staged temporary files by destination path.
     */
    private array $staged = [];

    /**
     * @var array<string, array{contents: string|null, modifiedAt: int|null}> Each target's state before the transaction.
     */
    private array $original = [];

    /**
     * @var string[] Folders this transaction created, deepest last.
     */
    private array $createdFolders = [];

    private bool $isStaged = false;
    private bool $isFinished = false;
    private int $temporaryCounter = 0;

    /**
     * @param string $folder The folder the asset lives in. Targets may name
     *   other folders too -- a map moving between directories -- and any
     *   missing one is created at staging and taken back on failure.
     * @param FileSetOperations $files The filesystem to act on.
     */
    public function __construct(
        private readonly string $folder,
        private readonly FileSetOperations $files = new FilesystemFileSetOperations(),
    ) {
    }

    /**
     * Declares that a file should hold these bytes when the transaction
     * commits.
     */
    public function write(string $path, string $contents): void
    {
        $this->targets[] = ['path' => $path, 'intent' => self::INTENT_WRITE, 'contents' => $contents];
    }

    /**
     * Declares that a file should be gone when the transaction commits.
     */
    public function remove(string $path): void
    {
        $this->targets[] = ['path' => $path, 'intent' => self::INTENT_REMOVE, 'contents' => null];
    }

    /**
     * Whether anything has been declared.
     */
    public function isEmpty(): bool
    {
        return $this->targets === [];
    }

    /**
     * The paths the transaction would change.
     *
     * @return string[]
     */
    public function paths(): array
    {
        return array_map(static fn(array $target): string => $target['path'], $this->targets);
    }

    /**
     * Records what is there now, creates the folder when the asset is new,
     * and writes every proposed file beside its destination.
     *
     * @return array<string, string> The staged temporary file of each write target, by destination.
     * @throws FileSetTransactionFailure When the proposal cannot be staged. Nothing is changed.
     */
    public function stage(): array
    {
        if ($this->isStaged) {
            return $this->staged;
        }

        foreach ($this->targets as $target) {
            $path = $target['path'];

            if (isset($this->original[$path])) {
                continue;
            }

            if ($this->files->isFile($path)) {
                $contents = $this->files->read($path);

                if ($contents === null) {
                    // Unreadable now is unrestorable later: refuse before
                    // touching anything rather than discover it mid-rollback.
                    throw new FileSetTransactionFailure(sprintf('%s could not be read, so it could not be put back if the write failed', basename($path)));
                }

                $this->original[$path] = ['contents' => $contents, 'modifiedAt' => $this->files->modifiedAt($path)];

                continue;
            }

            $this->original[$path] = ['contents' => null, 'modifiedAt' => null];
        }

        foreach ($this->foldersToCreate() as $missing) {
            if (! $this->files->makeDirectory($missing)) {
                $this->discard();

                throw new FileSetTransactionFailure(sprintf('Unable to create %s', $missing));
            }

            $this->createdFolders[] = $missing;
        }

        $this->isStaged = true;

        try {
            foreach ($this->targets as $target) {
                if ($target['intent'] !== self::INTENT_WRITE) {
                    continue;
                }

                $temporary = $this->temporaryPathFor($target['path']);
                $contents = (string) $target['contents'];

                if (! $this->files->write($temporary, $contents)) {
                    throw new FileSetTransactionFailure(sprintf('Unable to write a temporary file beside %s', basename($target['path'])));
                }

                $this->staged[$target['path']] = $temporary;

                if ($this->files->read($temporary) !== $contents) {
                    throw new FileSetTransactionFailure(sprintf('The staged copy of %s did not read back as written', basename($target['path'])));
                }
            }
        } catch (Throwable $throwable) {
            $this->discard();

            throw $throwable;
        }

        return $this->staged;
    }

    /**
     * Installs every target, after backing up once what is about to go.
     *
     * @param callable(string ...$paths): void|null $backup Receives the existing files about to be replaced or removed.
     * @throws FileSetTransactionFailure When any target cannot be installed.
     */
    public function commit(?callable $backup = null): void
    {
        if ($this->isFinished) {
            return;
        }

        if (! $this->isStaged) {
            $this->stage();
        }

        if ($backup !== null) {
            // Once, before any destructive work, for the whole set. The
            // accepted backup policy normally absorbs its own failures; a
            // callback that throws anyway must not leave the transaction's
            // staging behind -- nothing has been installed, so everything
            // this transaction made is taken back before the throw goes on.
            $doomed = array_values(array_filter(
                $this->paths(),
                fn(string $path): bool => ($this->original[$path]['contents'] ?? null) !== null,
            ));

            if ($doomed !== []) {
                try {
                    $backup(...$doomed);
                } catch (Throwable $backupFailure) {
                    $this->isFinished = true;
                    $this->discard();

                    throw new FileSetTransactionFailure(
                        sprintf('The backup step failed (%s)', rtrim($backupFailure->getMessage(), '.')),
                        previous: $backupFailure,
                    );
                }
            }
        }

        $installed = [];

        foreach ($this->targets as $target) {
            $path = $target['path'];

            if ($target['intent'] === self::INTENT_WRITE) {
                if (! $this->files->move($this->staged[$path], $path)) {
                    $this->undo($installed, sprintf('Unable to replace %s', basename($path)));
                }

                unset($this->staged[$path]);
                $installed[] = $target;

                continue;
            }

            if ($this->original[$path]['contents'] === null) {
                // Already gone: removing it is not work to undo.
                continue;
            }

            if (! $this->files->remove($path)) {
                $this->undo($installed, sprintf('Unable to remove %s', basename($path)));
            }

            $installed[] = $target;
        }

        $this->isFinished = true;
        $this->discardTemporaries();
        $this->removeFolderIfEmptied();
    }

    /**
     * Abandons staged work without installing any of it.
     */
    public function rollBack(): void
    {
        if ($this->isFinished) {
            return;
        }

        $this->isFinished = true;
        $this->discard();
    }

    /**
     * Restores everything already installed, then refuses.
     *
     * @param array<int, array{path: string, intent: string, contents: string|null}> $installed
     * @throws FileSetTransactionFailure Always.
     */
    private function undo(array $installed, string $reason): never
    {
        $unrestored = [];

        foreach (array_reverse($installed) as $target) {
            $path = $target['path'];
            $original = $this->original[$path];

            if ($original['contents'] === null) {
                // It was not there before this transaction.
                if ($this->files->isFile($path) && ! $this->files->remove($path)) {
                    $unrestored[] = $path;
                }

                continue;
            }

            if (! $this->files->write($path, $original['contents'])) {
                $unrestored[] = $path;

                continue;
            }

            if ($original['modifiedAt'] !== null && ! $this->files->setModifiedAt($path, $original['modifiedAt'])) {
                // Put back means put back: the same bytes at the same time,
                // so nothing downstream reads the restoration as an edit. A
                // file whose time could not be restored is not the file that
                // was there, and saying the rollback succeeded would hide it.
                $unrestored[] = $path;
            }
        }

        $this->isFinished = true;
        $this->discardTemporaries();

        if ($unrestored === []) {
            $this->removeFolderIfCreated();
        }

        throw new FileSetTransactionFailure($reason, $unrestored === [], $unrestored);
    }

    /**
     * Drops staged temporaries and any folder this transaction created.
     */
    private function discard(): void
    {
        $this->discardTemporaries();
        $this->removeFolderIfCreated();
    }

    private function discardTemporaries(): void
    {
        foreach ($this->staged as $temporary) {
            if ($this->files->isFile($temporary)) {
                $this->files->remove($temporary);
            }
        }

        $this->staged = [];
    }

    /**
     * The folders the targets need that do not exist yet, parents first.
     *
     * @return string[]
     */
    private function foldersToCreate(): array
    {
        $wanted = [];

        foreach ($this->targets as $target) {
            if ($target['intent'] !== self::INTENT_WRITE) {
                continue;
            }

            $missing = [];

            for ($folder = dirname($target['path']); ! $this->files->isDirectory($folder); $folder = dirname($folder)) {
                array_unshift($missing, $folder);
            }

            foreach ($missing as $folder) {
                if (! in_array($folder, $wanted, true)) {
                    $wanted[] = $folder;
                }
            }
        }

        return $wanted;
    }

    private function removeFolderIfCreated(): void
    {
        foreach (array_reverse($this->createdFolders) as $created) {
            if ($this->files->isDirectory($created) && $this->files->listDirectory($created) === []) {
                $this->files->removeDirectory($created);
            }
        }

        $this->createdFolders = [];
    }

    private function removeFolderIfEmptied(): void
    {
        $writes = array_filter($this->targets, static fn(array $target): bool => $target['intent'] === self::INTENT_WRITE);

        if ($writes !== []) {
            return;
        }

        // Only the set was this asset's; anything else in the folder is its
        // author's, and the folder goes only when nothing is left.
        if ($this->files->isDirectory($this->folder) && $this->files->listDirectory($this->folder) === []) {
            $this->files->removeDirectory($this->folder);
        }
    }

    private function temporaryPathFor(string $path): string
    {
        $this->temporaryCounter++;

        return sprintf('%s.tmp-%d-%d', $path, getmypid(), $this->temporaryCounter);
    }
}
