<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use RuntimeException;

/**
 * Reads a save compatibility manifest as the game reads it: in a process of
 * its own, from the project root, through the project's own autoloader, by
 * the Engine's SaveCompatibilityManifest. A migration step that names a
 * class is accepted only when that class loads from the project and
 * implements the Engine's ContentMigrationInterface, which the Editor's own
 * process cannot prove: it does not load the game's classes.
 *
 * @package Ichiloto\Editor\Maps
 */
final class ProjectMigrationProbe
{
    private const string RUNNER = <<<'PHP'
    <?php

    [, $root, $projectId, $manifestPath] = $argv;
    $fail = static function (string $reason): never {
        fwrite(STDOUT, json_encode(['error' => $reason], JSON_THROW_ON_ERROR));
        exit(0);
    };
    $autoload = $root . '/vendor/autoload.php';
    if (! is_file($autoload)) {
        $fail('the project has no vendor/autoload.php, so the game could not load a migration class; run composer install in it');
    }
    chdir($root);
    ob_start();
    try {
        require $autoload;
        $data = (static fn(string $path): mixed => require $path)($manifestPath);
        if (! is_array($data)) {
            $fail('the manifest does not return an array');
        }
        \Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest::fromArray($projectId, $data, 'assets/Data/save-compatibility.php');
    } catch (Throwable $failure) {
        ob_end_clean();
        $fail(rtrim($failure->getMessage(), '.'));
    }
    ob_end_clean();
    fwrite(STDOUT, json_encode(['manifest' => base64_encode(serialize($data))], JSON_THROW_ON_ERROR));
    PHP;

    private function __construct()
    {
    }

    /**
     * @param string $manifestPath The manifest file to read, a staged copy included.
     * @return array<array-key, mixed> The manifest as the game evaluates it.
     * @throws RuntimeException With the reason the game would refuse it.
     */
    public static function readManifest(string $projectRoot, string $projectId, string $manifestPath): array
    {
        $runner = tempnam(sys_get_temp_dir(), 'ichiloto-migration-probe-');
        if ($runner === false || file_put_contents($runner, self::RUNNER) === false) {
            throw new RuntimeException('The save migration check could not be prepared.');
        }

        try {
            $process = proc_open([PHP_BINARY, $runner, $projectRoot, $projectId, $manifestPath],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $projectRoot);
            if (! is_resource($process)) {
                throw new RuntimeException('The save migration check could not start.');
            }
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        } finally {
            @unlink($runner);
        }

        $answer = json_decode($output, true);
        if (! is_array($answer)) {
            throw new RuntimeException(sprintf('The save migration check failed: %s', trim($errors) !== '' ? trim($errors) : 'it gave no answer'));
        }
        if (is_string($answer['error'] ?? null)) {
            throw new RuntimeException($answer['error']);
        }
        $manifest = unserialize((string) base64_decode((string) ($answer['manifest'] ?? ''), true), ['allowed_classes' => false]);

        return is_array($manifest) ? $manifest : throw new RuntimeException('The save migration check gave no manifest.');
    }
}
