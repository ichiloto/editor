<?php

declare(strict_types=1);

use Ichiloto\Editor\Storage\FileSetOperations;
use Ichiloto\Editor\Storage\FilesystemFileSetOperations;

/**
 * The real filesystem, with named operations made to fail on chosen paths.
 */
final class FailingFileSetOperations implements FileSetOperations
{
    /** @var array<int, string> Every operation performed, as "verb path". */
    public array $calls = [];

    /**
     * @param array<string, array<int, string>> $failures Paths to fail, by verb.
     * @param array<string, callable(string): void> $before One-shot actions
     *   immediately before a named operation, used to reproduce races.
     */
    public function __construct(
        private readonly FileSetOperations $inner = new FilesystemFileSetOperations(),
        private array $failures = [],
        private array $before = [],
    ) {
    }

    private function fails(string $verb, string $path): bool
    {
        $this->calls[] = $verb . ' ' . basename($path);

        if (isset($this->before[$verb])) {
            $action = $this->before[$verb];
            unset($this->before[$verb]);
            $action($path);
        }

        return in_array($path, $this->failures[$verb] ?? [], true);
    }

    public function isFile(string $path): bool
    {
        return $this->inner->isFile($path);
    }

    public function isDirectory(string $path): bool
    {
        return $this->inner->isDirectory($path);
    }

    public function read(string $path): ?string
    {
        return $this->fails('read', $path) ? null : $this->inner->read($path);
    }

    public function write(string $path, string $contents): bool
    {
        return $this->fails('write', $path) ? false : $this->inner->write($path, $contents);
    }

    public function move(string $from, string $to): bool
    {
        return $this->fails('move', $to) ? false : $this->inner->move($from, $to);
    }

    public function remove(string $path): bool
    {
        return $this->fails('remove', $path) ? false : $this->inner->remove($path);
    }

    public function makeDirectory(string $path): bool
    {
        return $this->fails('makeDirectory', $path) ? false : $this->inner->makeDirectory($path);
    }

    public function removeDirectory(string $path): bool
    {
        return $this->fails('removeDirectory', $path) ? false : $this->inner->removeDirectory($path);
    }

    public function listDirectory(string $path): array
    {
        return $this->inner->listDirectory($path);
    }

    public function metadata(string $path): ?array
    {
        return $this->fails('metadata', $path) ? null : $this->inner->metadata($path);
    }

    public function restoreMetadata(string $path, array $metadata): bool
    {
        return $this->fails('restoreMetadata', $path) ? false : $this->inner->restoreMetadata($path, $metadata);
    }
}
