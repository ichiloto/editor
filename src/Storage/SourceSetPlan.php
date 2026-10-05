<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Storage;

use Closure;
use RuntimeException;
use Throwable;

/**
 * A planned, reversible rewrite of several project sources, written together.
 *
 * The plan holds every source it read (so a file changed or added on disk
 * after planning refuses the write), the original and proposed bytes of each
 * file it changes, and the value each must read back as before and after.
 * Applying and reverting both run one {@see FileSetTransaction} over assets/:
 * stage every file, prove each staged file reads back as planned, commit,
 * and roll back on any failure, so nothing is half written.
 */
final class SourceSetPlan
{
    private bool $applied = false;

    /**
     * @param string $root The project root.
     * @param array<string, string> $watched Every source the plan read, by path.
     * @param array<string, string|null> $originals The bytes of each changed file, null for a
     * file the plan creates: it must not exist until applied, and reverting removes it again.
     * @param array<string, string> $proposals The planned bytes of each changed file.
     * @param array<string, mixed> $beforeValues What each changed file reads back as now.
     * @param array<string, mixed> $afterValues What each changed file must read back as once written.
     * @param Closure(): list<string> $findWatchedPaths The paths of the file family the plan read, as they are on disk now.
     * @param Closure(string, string): mixed $readBack Reads a staged file (destination path, staged path) as a comparable value.
     * @param string $subject What the plan does, for refusals (an actor migration).
     * @param string $family The files it reads, for refusals (Project actor/reference files).
     */
    public function __construct(
        private readonly string $root,
        private readonly array $watched,
        private readonly array $originals,
        private readonly array $proposals,
        private readonly array $beforeValues,
        private readonly array $afterValues,
        private readonly Closure $findWatchedPaths,
        private readonly Closure $readBack,
        private readonly string $subject,
        private readonly string $family,
    ) {}

    /** @return list<string> */
    public function getChangedPaths(): array { return array_keys($this->proposals); }

    /** @return array<string, string|null> Null for a file the plan creates. */
    public function getOriginalSources(): array { return $this->originals; }

    /** @return array<string, string> */
    public function getProposedSources(): array { return $this->proposals; }

    /**
     * What the plan is exactly: every source it read and every byte it would
     * write. An author who reviewed a plan confirms this; a plan made again
     * from changed choices or changed files has another.
     */
    public function getFingerprint(): string
    {
        return hash('sha256', serialize([$this->root, $this->watched, $this->originals, $this->proposals]));
    }

    /** @return list<string> Every changed path. */
    public function apply(): array
    {
        if (! $this->applied) {
            $this->installSources($this->proposals, $this->afterValues);
            $this->applied = true;
        }
        return $this->getChangedPaths();
    }

    public function revert(): void
    {
        if ($this->applied) {
            $this->installSources($this->originals, $this->beforeValues);
            $this->applied = false;
            // A folder only the plan's created file lived in goes with it.
            foreach (array_keys(array_filter($this->originals, static fn(?string $source): bool => $source === null)) as $path) {
                $folder = dirname($path);
                if (is_dir($folder) && (scandir($folder) ?: []) === ['.', '..']) {
                    rmdir($folder);
                }
            }
        }
    }

    /** @param array<string, string|null> $sources Null removes a file. */
    private function installSources(array $sources, array $expectedValues): void
    {
        $this->assertSourcesUnchanged();
        if ($sources === []) { return; }
        $transaction = new FileSetTransaction($this->root . '/assets');
        foreach ($sources as $path => $source) {
            if ($source === null) {
                $transaction->remove($path);
            } else {
                $transaction->write($path, $source);
            }
        }
        try {
            $staged = $transaction->stage();
            $this->assertSourcesUnchanged();
            foreach ($staged as $path => $temporary) {
                $value = ($this->readBack)($path, $temporary);
                if (serialize($value) !== serialize($expectedValues[$path])) {
                    throw new RuntimeException("{$path} would not read back as the planned {$this->subject}; nothing was written.");
                }
            }
            $transaction->commit();
        } catch (Throwable $failure) {
            $transaction->rollBack();
            throw $failure;
        }
    }

    private function assertSourcesUnchanged(): void
    {
        foreach ($this->originals as $path => $source) {
            if ($source === null && is_file($path) !== $this->applied) {
                throw new RuntimeException("{$path} " . ($this->applied ? 'was removed' : 'appeared') . " outside the {$this->subject}; reload and plan again.");
            }
            if ($source === null && $this->applied && file_get_contents($path) !== $this->proposals[$path]) {
                throw new RuntimeException("{$path} changed outside the {$this->subject}; reload and plan again.");
            }
        }
        foreach ($this->watched as $path => $source) {
            $expected = $this->applied ? ($this->proposals[$path] ?? $source) : $source;
            if (! is_file($path) || file_get_contents($path) !== $expected) {
                throw new RuntimeException("{$path} changed outside the {$this->subject}; reload and plan again.");
            }
        }
        $current = ($this->findWatchedPaths)();
        $expected = array_keys($this->watched);
        sort($current, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($current !== $expected) {
            throw new RuntimeException("{$this->family} changed outside the {$this->subject}; reload and plan again.");
        }
    }
}
