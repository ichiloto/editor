<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Actors;

use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\Storage\FileSetTransaction;
use RuntimeException;
use Throwable;

/** An explicit, reversible source transaction shared by the CLI and TUI. */
final class ActorIdentityMigrationPlan
{
    private bool $applied = false;

    public function __construct(
        private readonly string $root,
        private readonly array $watched,
        private readonly array $originals,
        private readonly array $proposals,
        private readonly array $beforeValues,
        private readonly array $afterValues,
    ) {}

    public function getChangedPaths(): array { return array_keys($this->proposals); }
    public function getOriginalSources(): array { return $this->originals; }
    public function getProposedSources(): array { return $this->proposals; }

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
        }
    }

    private function installSources(array $sources, array $expectedValues): void
    {
        $this->assertSourcesUnchanged();
        if ($sources === []) { return; }
        $transaction = new FileSetTransaction($this->root . '/assets');
        foreach ($sources as $path => $source) { $transaction->write($path, $source); }
        try {
            $staged = $transaction->stage();
            $this->assertSourcesUnchanged();
            foreach ($staged as $path => $temporary) {
                $value = ActorReferenceInventory::getComparableValue(PhpDataFile::evaluateIsolated($temporary, $this->root));
                if (serialize($value) !== serialize($expectedValues[$path])) {
                    throw new RuntimeException("{$path} would not read back as the planned actor migration; nothing was written.");
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
        foreach ($this->watched as $path => $source) {
            $expected = $this->applied ? ($this->proposals[$path] ?? $source) : $source;
            if (! is_file($path) || file_get_contents($path) !== $expected) {
                throw new RuntimeException("{$path} changed outside the migration; reload and plan again.");
            }
        }
        $current = [...(glob($this->root . '/assets/Data/Actors/*.php') ?: []), ...ActorReferenceInventory::getSourcePaths($this->root)];
        $expected = array_keys($this->watched);
        sort($current, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($current !== $expected) {
            throw new RuntimeException('Project actor/reference files changed outside the migration; reload and plan again.');
        }
    }
}
