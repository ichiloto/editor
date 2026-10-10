<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourceUnreadable;
use Ichiloto\Editor\IO\AtomicFile;
use Ichiloto\Editor\ProjectDirectoryContext;
use RuntimeException;
use Throwable;

/**
 * One authored PHP data file, loaded with its source header preserved.
 *
 * A file that returns an array literal of data (scalars, arrays and enum
 * cases, not arbitrary objects) is saved by editing its own source:
 * only the values that changed are rewritten where they sit, so comments,
 * nowdocs, variables and layout inside the data survive, and a change that
 * cannot be expressed there is refused rather than flattened. A save also
 * refuses when the file changed on disk since it was read. Any other file
 * keeps everything from `<?php` up to the top-level `return` byte-for-byte
 * and regenerates only the returned expression.
 *
 * A file is editable only when both hold:
 *  - every leaf of the returned value is a scalar, array, or enum case
 *    (a `new Item(...)` payload cannot be regenerated without inventing
 *    source, so those files are browsed, never written); and
 *  - any comment *inside* the returned expression sits where the source is
 *    edited in place: plain data, or a list of constructor calls whose
 *    arguments the record database edits one at a time. A save that would
 *    instead regenerate the returned expression is refused, since it would
 *    drop the comment.
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
     * @param string|null $source The file's bytes as read, which a save edits.
     * @param bool $hasInteriorComment Whether a comment sits inside the returned data.
     */
    private function __construct(
        public readonly string $path,
        public private(set) mixed $payload,
        public readonly string $header,
        public readonly bool $exists,
        public readonly ?string $readOnlyReason,
        private ?string $source = null,
        private bool $hasInteriorComment = false,
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
     * @param-out list<string>|null $fingerprints Stable serialized-value
     *   fingerprints for comparisons that must preserve object identity by
     *   class and state without constructing child-process objects here.
     * @return list<mixed> Each file's returned payload, in the same order.
     */
    public static function evaluateIsolatedFiles(
        array $paths,
        ?string $workingDirectory = null,
        ?array &$fingerprints = null,
    ): array
    {
        if ($paths === []) {
            $fingerprints = [];

            return [];
        }

        return self::evaluateIsolatedPaths($paths, $workingDirectory, $fingerprints);
    }

    /**
     * An isolated payload with object identity stripped but authored values
     * kept, so a staged file can be compared with the value it was planned
     * to read back as.
     */
    public static function getComparableValue(mixed $value): mixed
    {
        if (is_object($value)) {
            $fields = get_object_vars($value);
            $class = $fields['__PHP_Incomplete_Class_Name'] ?? $value::class;
            unset($fields['__PHP_Incomplete_Class_Name']);
            return ['__class' => $class, ...array_map(self::getComparableValue(...), $fields)];
        }
        return is_array($value) ? array_map(self::getComparableValue(...), $value) : $value;
    }

    /**
     * A stable comparison value that includes an object's class and state.
     *
     * Isolated payloads are decoded with object construction disabled. The
     * fingerprint lets callers compare the real parent value with the child
     * value without invoking __wakeup() or __unserialize() in this process.
     */
    public static function valueFingerprint(mixed $value): string
    {
        return hash('sha256', serialize($value));
    }

    /**
     * Runs one ordered set of paths in a fresh PHP process.
     *
     * @param list<string> $paths The paths to require in order.
     * @param-out list<string>|null $fingerprints
     * @return list<mixed> Their returned values.
     */
    private static function evaluateIsolatedPaths(array $paths, ?string $workingDirectory, ?array &$fingerprints): array
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

        // Child evaluation must use the same source roots as this process,
        // including a development Engine selected by the test bootstrap.
        $prefixes = [];
        foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
            $prefixes = array_replace($prefixes, $loader->getPrefixesPsr4());
        }

        $runner = <<<'PHP'
        <?php

        (static function (array $arguments): never {
        $workingDirectory = $arguments[1];
        $autoload = $arguments[2];
        $resultMarker = $arguments[3];
        $prefixes = json_decode($arguments[4], true, flags: JSON_THROW_ON_ERROR);
        $paths = array_slice($arguments, 5);

        if ($autoload !== '') {
            $loader = require $autoload;
            foreach ($prefixes as $prefix => $directories) {
                $loader->setPsr4($prefix, $directories);
            }
        }

        if ($workingDirectory !== '' && is_dir($workingDirectory)) {
            chdir($workingDirectory);
        }

        ob_start();

        $resultExitCode = 0;

        try {
            // Authored files share one static closure activation, matching an
            // ordinary ordered load unit. The array assignment happens only
            // after every require completes, and no runner variables are
            // imported into authored scope, so authored helper names cannot
            // overwrite protocol state.
            $authoredLoader = static function (): array {
                return [
        /*__ICHILOTO_AUTHORED_REQUIRES__*/
                ];
            };
            $payloads = $authoredLoader();
            $fingerprints = array_map(
                static fn(mixed $payload): string => hash('sha256', serialize($payload)),
                $payloads,
            );

            $serializedResult = serialize([
                'payloads' => $payloads,
                'fingerprints' => $fingerprints,
            ]);
        } catch (Throwable $throwable) {
            $resultExitCode = 1;
            $currentPath = '';
            $failureFiles = [$throwable->getFile()];

            foreach ($throwable->getTrace() as $frame) {
                if (is_string($frame['file'] ?? null)) {
                    $failureFiles[] = $frame['file'];
                }
            }

            foreach ($failureFiles as $failureFile) {
                foreach ($paths as $path) {
                    if ($failureFile === $path
                        || (realpath($failureFile) !== false && realpath($failureFile) === realpath($path))
                    ) {
                        $currentPath = $path;

                        break 2;
                    }
                }
            }

            if ($currentPath === '') {
                foreach ($paths as $path) {
                    if (in_array($path, get_included_files(), true)) {
                        $currentPath = $path;
                    }
                }
            }

            $serializedResult = serialize([
                'path' => $currentPath,
                'reason' => $throwable->getMessage(),
            ]);
        }

        ob_end_clean();
        $encodedResult = base64_encode($serializedResult);
        fwrite(STDOUT, $resultMarker . strlen($encodedResult) . ':' . $encodedResult);
        exit($resultExitCode);
        })($argv);
        PHP;
        $authoredRequires = implode("\n", array_map(
            static fn(string $path): string => '                    require ' . var_export($path, true) . ',',
            $paths,
        ));
        $runner = str_replace('/*__ICHILOTO_AUTHORED_REQUIRES__*/', $authoredRequires, $runner);
        $pipes = [];
        $resultMarker = 'ICHILOTO_EVAL_RESULT:' . bin2hex(random_bytes(16)) . ':';
        $arguments = [PHP_BINARY, '-r', substr($runner, strlen("<?php\n")), $workingDirectory ?? '', $autoload ?? '', $resultMarker, json_encode($prefixes, JSON_THROW_ON_ERROR), ...$paths];
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
        $encodedResult = self::extractFramedResult($output, $resultMarker);
        $serializedResult = $encodedResult === null ? false : base64_decode($encodedResult, true);
        $result = $serializedResult === false
            ? false
            : @unserialize($serializedResult, ['allowed_classes' => false]);

        if ($exitCode !== 0) {
            if (is_array($result) && is_string($result['path'] ?? null) && is_string($result['reason'] ?? null)) {
                throw new IsolatedPhpEvaluationFailure($result['path'], $result['reason']);
            }

            throw new RuntimeException(trim($error) ?: 'Authored PHP could not be evaluated.');
        }

        if (! is_array($result)
            || ! is_array($result['payloads'] ?? null)
            || ! is_array($result['fingerprints'] ?? null)
            || count($result['payloads']) !== count($result['fingerprints'])
        ) {
            throw new RuntimeException('Authored PHP returned an unreadable isolated result.');
        }

        $decodedFingerprints = [];

        foreach ($result['fingerprints'] as $fingerprint) {
            if (! is_string($fingerprint)) {
                throw new RuntimeException('Authored PHP returned an unreadable isolated result.');
            }

            $decodedFingerprints[] = $fingerprint;
        }

        $fingerprints = $decodedFingerprints;

        return array_values($result['payloads']);
    }

    /**
     * Extracts a length-prefixed result from stdout that may also contain
     * authored direct writes before or after the frame.
     */
    private static function extractFramedResult(string $output, string $marker): ?string
    {
        $markerPosition = strrpos($output, $marker);

        if ($markerPosition === false) {
            return null;
        }

        $lengthStart = $markerPosition + strlen($marker);
        $lengthEnd = strpos($output, ':', $lengthStart);

        if ($lengthEnd === false) {
            return null;
        }

        $lengthText = substr($output, $lengthStart, $lengthEnd - $lengthStart);

        if ($lengthText === '' || ! ctype_digit($lengthText)) {
            return null;
        }

        $length = (int) $lengthText;
        $encoded = substr($output, $lengthEnd + 1, $length);

        return strlen($encoded) === $length ? $encoded : null;
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

        if ($hasInteriorComment && ! self::isEditedInPlace($source, $payload)) {
            return new self(
                $path,
                $payload,
                $header,
                true,
                sprintf('%s has comments inside its data, which a rewrite would drop', basename($path)),
            );
        }

        return new self($path, $payload, $header, true, null, $source, $hasInteriorComment);
    }

    /**
     * Whether saves edit the file's own source rather than regenerate it:
     * plain data, or a list of constructor calls edited argument by argument.
     */
    private static function isEditedInPlace(string $source, mixed $payload): bool
    {
        if (! self::holdsNonEnumObject($payload) && self::parseArraySource($source) !== null) {
            return true;
        }

        return array_filter(PhpSourceDocument::parse($source)->entryClasses(), static fn(string $class): bool => trim($class) !== '') !== [];
    }

    /** Enum cases use the same source-preserving literal path as other data. */
    private static function holdsNonEnumObject(mixed $value): bool
    {
        if (is_object($value)) {
            return ! $value instanceof \UnitEnum;
        }

        return is_array($value) && array_any($value, self::holdsNonEnumObject(...));
    }

    /** The file as an editable array literal, or null when it is not one. */
    private static function parseArraySource(string $source): ?PhpArraySourceDocument
    {
        try {
            return PhpArraySourceDocument::parse($source);
        } catch (SourceUnreadable) {
            return null;
        }
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
        $contents = $this->composeContents($payload, checkDisk: true);
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create {$directory}.");
        }

        AtomicFile::write($this->path, $contents);
        // The file now reads as this payload, so the next save edits from here.
        $this->payload = $payload;
        $this->source = $contents;
    }

    /**
     * Returns what saving a value would write, refusing what the file's own
     * source cannot take, without writing anything: an edit can be refused
     * when it is made rather than when it is saved.
     *
     * @param mixed $payload The value to return from the file.
     * @param bool $checkDisk Whether a file changed outside the editor is refused too, as a save does.
     * @return string The file's contents.
     * @throws RuntimeException When the file or the value cannot be written.
     */
    public function composeContents(mixed $payload, bool $checkDisk = false): string
    {
        if (! $this->isEditable()) {
            throw new RuntimeException(sprintf('Refusing to overwrite %s: %s.', $this->path, $this->readOnlyReason));
        }

        $reason = PhpValueExporter::describeUnexportable($payload);

        if ($reason !== null) {
            throw new RuntimeException(sprintf('Refusing to write %s: %s.', $this->path, $reason));
        }

        $document = $this->source === null ? null : self::parseArraySource($this->source);

        // Data, including enum cases, is edited in its own source; objects keep the regeneration
        // (or the constructor-argument edits) their files were written for.
        if ($document !== null && is_array($this->payload) && is_array($payload)
            && ! self::holdsNonEnumObject($this->payload) && ! self::holdsNonEnumObject($payload)) {
            if ($checkDisk && @file_get_contents($this->path) !== $this->source) {
                throw new RuntimeException(sprintf(
                    'Refusing to overwrite %s: it changed outside the editor since it was read. Reload it first.',
                    $this->path,
                ));
            }
            $contents = ArraySourceWriter::rewrite($document, $this->payload, $payload)->source;
        } elseif ($this->hasInteriorComment) {
            throw new RuntimeException(sprintf(
                'Refusing to rewrite %s: it has comments inside its data, which rewriting it would drop. Edit the entries one value at a time, or edit the file directly.',
                $this->path,
            ));
        } else {
            $contents = $this->header . 'return ' . PhpValueExporter::export($payload) . ";\n";
        }

        return $contents;
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
