<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\IO\AtomicFile;
use Ichiloto\Editor\ProjectDirectoryContext;
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
     * Evaluates a data file with the working directory pinned.
     *
     * Authored files call asset() and graphics(), and those resolve against
     * the process working directory. Whether a category loads must not depend
     * on where the process happens to stand -- an enemies.php that constructs
     * its sprites read as "could not be evaluated" from any directory but the
     * project's, and the whole category silently went read-only.
     *
     * @param string $path The data file path.
     * @param string|null $workingDirectory The project root to evaluate under.
     * @return mixed The file's payload.
     */
    private static function evaluate(string $path, ?string $workingDirectory): mixed
    {
        if ($workingDirectory === null || ! is_dir($workingDirectory)) {
            return require $path;
        }

        return ProjectDirectoryContext::run(
            $workingDirectory,
            static fn(): mixed => require $path,
        );
    }

    /**
     * Evaluates a file in a fresh PHP process.
     *
     * Validation may need to re-read a file that the current Editor process
     * already loaded. Authored headers can declare named functions or classes,
     * so requiring the file twice in one process can terminate PHP with a
     * redeclaration error. The isolated process also keeps those declarations
     * out of the long-lived Editor runtime.
     */
    public static function evaluateIsolated(string $path, ?string $workingDirectory = null): mixed
    {
        $payloads = self::evaluateIsolatedFiles([$path], $workingDirectory);

        if (! array_key_exists(0, $payloads)) {
            throw new RuntimeException(sprintf('%s returned no isolated result.', basename($path)));
        }

        return $payloads[0];
    }

    /**
     * Evaluates authored PHP files in one fresh process and in the supplied
     * order. Files in one runtime load unit can therefore share declarations
     * and variables without polluting the long-lived Editor process.
     *
     * @param list<string> $paths The source files in runtime load order.
     * @param string|null $workingDirectory The project root to evaluate under.
     * @return list<mixed> Each file's returned payload, in the same order.
     */
    public static function evaluateIsolatedFiles(
        array $paths,
        ?string $workingDirectory = null,
    ): array
    {
        if ($paths === []) {
            return [];
        }

        return self::evaluateIsolatedPaths($paths, $workingDirectory);
    }

    /**
     * Runs one ordered set of paths in a fresh PHP process.
     *
     * @param list<string> $paths The paths to require in order.
     * @return list<mixed> Their returned values.
     */
    private static function evaluateIsolatedPaths(array $paths, ?string $workingDirectory): array
    {

        $autoload = null;

        foreach (get_included_files() as $includedFile) {
            if (basename($includedFile) === 'autoload.php' && basename(dirname($includedFile)) === 'vendor') {
                $autoload = $includedFile;
                break;
            }
        }

        $localAutoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

        if ($autoload === null && is_file($localAutoload)) {
            $autoload = $localAutoload;
        }

        $runner = <<<'PHP'
        <?php

        $workingDirectory = $argv[1];
        $autoload = $argv[2];
        $paths = array_slice($argv, 3);

        if ($autoload !== '') {
            require $autoload;
        }

        if ($workingDirectory !== '' && is_dir($workingDirectory)) {
            chdir($workingDirectory);
        }

        ob_start();

        try {
            $payloads = [];

            foreach ($paths as $path) {
                $payloads[] = require $path;
            }

            $encoded = base64_encode(serialize(['payloads' => $payloads]));
            ob_end_clean();
            echo $encoded;
        } catch (Throwable $throwable) {
            ob_end_clean();
            $failure = base64_encode(serialize([
                'path' => $path ?? '',
                'reason' => $throwable->getMessage(),
            ]));
            fwrite(STDERR, "\nICHILOTO_EVAL_FAILURE:" . $failure);
            exit(1);
        }
        PHP;
        $pipes = [];
        $arguments = [PHP_BINARY, '-r', substr($runner, strlen("<?php\n")), $workingDirectory ?? '', $autoload ?? '', ...$paths];
        $process = proc_open(
            $arguments,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to evaluate authored PHP in an isolated process.');
        }

        fclose($pipes[0]);

        try {
            [$output, $error] = self::readProcessPipes($pipes);
        } catch (Throwable $readFailure) {
            foreach ([1, 2] as $descriptor) {
                if (is_resource($pipes[$descriptor])) {
                    fclose($pipes[$descriptor]);
                }
            }

            proc_terminate($process);
            proc_close($process);

            throw $readFailure;
        }

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $marker = 'ICHILOTO_EVAL_FAILURE:';
            $markerPosition = strrpos($error, $marker);

            if ($markerPosition !== false) {
                $encodedFailure = trim(substr($error, $markerPosition + strlen($marker)));
                $serializedFailure = base64_decode($encodedFailure, true);
                $failure = $serializedFailure === false
                    ? false
                    : @unserialize($serializedFailure, ['allowed_classes' => false]);

                if (is_array($failure) && is_string($failure['path'] ?? null) && is_string($failure['reason'] ?? null)) {
                    throw new IsolatedPhpEvaluationFailure($failure['path'], $failure['reason']);
                }
            }

            throw new RuntimeException(trim($error) ?: 'Authored PHP could not be evaluated.');
        }

        $serialized = base64_decode(trim($output), true);
        $result = $serialized === false ? false : @unserialize($serialized, ['allowed_classes' => false]);

        if (! is_array($result) || ! is_array($result['payloads'] ?? null)) {
            throw new RuntimeException('Authored PHP returned an unreadable isolated result.');
        }

        return array_values($result['payloads']);
    }

    /**
     * Drains stdout and stderr together so neither child pipe can fill while
     * the parent is blocked waiting for the other one.
     *
     * @param array<int, resource> $pipes The process pipes.
     * @return array{0: string, 1: string} Standard output and error.
     */
    private static function readProcessPipes(array $pipes): array
    {
        $streams = [1 => $pipes[1], 2 => $pipes[2]];
        $output = '';
        $error = '';

        foreach ($streams as $stream) {
            stream_set_blocking($stream, false);
        }

        while ($streams !== []) {
            $ready = array_values($streams);
            $write = [];
            $except = [];

            if (stream_select($ready, $write, $except, null) === false) {
                throw new RuntimeException('Unable to read isolated PHP evaluation output.');
            }

            foreach ($streams as $descriptor => $stream) {
                if (! in_array($stream, $ready, true)) {
                    continue;
                }

                $chunk = stream_get_contents($stream);

                if ($chunk === false) {
                    throw new RuntimeException('Unable to read isolated PHP evaluation output.');
                }

                if ($descriptor === 1) {
                    $output .= $chunk;
                } else {
                    $error .= $chunk;
                }

                if (feof($stream)) {
                    fclose($stream);
                    unset($streams[$descriptor]);
                }
            }
        }

        return [$output, $error];
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
    public static function load(string $path, ?string $workingDirectory = null): self
    {
        if (! is_file($path)) {
            return new self($path, null, "<?php\n\n", false, null);
        }

        $source = (string) file_get_contents($path);

        try {
            $payload = self::evaluate($path, $workingDirectory);
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

        AtomicFile::write(
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
