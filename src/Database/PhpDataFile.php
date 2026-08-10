<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use RuntimeException;
use Throwable;

/**
 * One authored PHP data file, loaded with its source header preserved.
 *
 * The editor's contract with an author's file is deliberately narrow: it
 * regenerates **only the returned expression**. Everything from `<?php` up to
 * the top-level `return` — the file docblock, `use` imports, blank lines, the
 * explanatory comment above a cutscene — is kept byte-for-byte and re-emitted
 * verbatim on save.
 *
 * A file is editable only when both hold:
 *  - every leaf of the returned value is a scalar, array, or enum case
 *    (a `new Item(...)` payload cannot be regenerated without inventing
 *    source, so those files are browsed, never written); and
 *  - no comment sits *inside* the returned expression, because a rewrite
 *    would silently drop it.
 *
 * When either fails the file reports a read-only reason instead, and the
 * editor surfaces that reason rather than risking the author's work.
 */
final class PhpDataFile
{
    /**
     * @param string $path The data file path.
     * @param mixed $payload The value the file returns (null when absent).
     * @param string $header The verbatim source preceding the top-level `return`.
     * @param bool $exists Whether the file is present on disk.
     * @param string|null $readOnlyReason Why the file cannot be rewritten.
     */
    private function __construct(
        public readonly string $path,
        public readonly mixed $payload,
        public readonly string $header,
        public readonly bool $exists,
        public readonly ?string $readOnlyReason,
    ) {
    }

    /**
     * Loads and probes a data file.
     *
     * A missing file is not an error — the database starts empty and the file
     * is created on the first save.
     *
     * @param string $path The data file path.
     * @return self
     */
    public static function load(string $path): self
    {
        if (! is_file($path)) {
            return new self($path, null, "<?php\n\n", false, null);
        }

        $source = (string) file_get_contents($path);

        try {
            $payload = require $path;
        } catch (Throwable $throwable) {
            return new self(
                $path,
                null,
                "<?php\n\n",
                true,
                sprintf('%s could not be evaluated (%s)', basename($path), $throwable->getMessage()),
            );
        }

        $sourceMetadata = PhpDataSource::inspect($source);
        $header = $sourceMetadata['header'];
        $hasInteriorComment = $sourceMetadata['hasInteriorComment'];
        $offendingClass = PhpValueExporter::findUnexportableClass($payload);

        if ($offendingClass !== null) {
            return new self(
                $path,
                $payload,
                $header,
                true,
                sprintf(
                    '%s is authored as PHP constructor calls (%s), which cannot be regenerated from the loaded values',
                    basename($path),
                    $offendingClass,
                ),
            );
        }

        $reason = PhpValueExporter::describeUnexportable($payload);

        if ($reason !== null) {
            return new self($path, $payload, $header, true, sprintf('%s contains %s', basename($path), $reason));
        }

        if ($hasInteriorComment) {
            return new self(
                $path,
                $payload,
                $header,
                true,
                sprintf('%s has comments inside its data, which a rewrite would drop', basename($path)),
            );
        }

        return new self($path, $payload, $header, true, null);
    }

    /**
     * Returns whether the file can be rewritten safely.
     *
     * @return bool
     */
    public function isEditable(): bool
    {
        return $this->readOnlyReason === null;
    }

    /**
     * Writes a new returned value, preserving the source header verbatim.
     *
     * @param mixed $payload The value to return from the file.
     * @return void
     */
    public function save(mixed $payload): void
    {
        if (! $this->isEditable()) {
            throw new RuntimeException(sprintf('Refusing to overwrite %s: %s.', $this->path, $this->readOnlyReason));
        }

        $reason = PhpValueExporter::describeUnexportable($payload);

        if ($reason !== null) {
            throw new RuntimeException(sprintf('Refusing to write %s: %s.', $this->path, $reason));
        }

        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create {$directory}.");
        }

        self::writeTransactionally(
            $this->path,
            $this->header . 'return ' . PhpValueExporter::export($payload) . ";\n",
        );
    }

    /**
     * Writes a file through a temp-file swap.
     *
     * @param string $path The destination file.
     * @param string $contents The file contents.
     * @return void
     */
    public static function writeTransactionally(string $path, string $contents): void
    {
        $temporaryPath = $path . '.tmp';

        if (file_put_contents($temporaryPath, $contents) === false) {
            throw new RuntimeException("Unable to write temporary file for {$path}.");
        }

        if (! rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new RuntimeException("Unable to replace {$path}.");
        }
    }

}
