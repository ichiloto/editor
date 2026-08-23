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
     */
    public function __construct(
        private readonly FileSetOperations $inner = new FilesystemFileSetOperations(),
        private array $failures = [],
    ) {
    }

    private function fails(string $verb, string $path): bool
    {
        $this->calls[] = $verb . ' ' . basename($path);

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

    public function modifiedAt(string $path): ?int
    {
        return $this->inner->modifiedAt($path);
    }

    public function setModifiedAt(string $path, int $timestamp): bool
    {
        return $this->fails('setModifiedAt', $path) ? false : $this->inner->setModifiedAt($path, $timestamp);
    }
}
