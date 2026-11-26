<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Cutscenes\Source\SourceUnreadable;
use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Storage\FileSetTransaction;
use Ichiloto\Engine\IO\SaveCompatibility\ContentMigrationResolution;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Appends one step to the project's save compatibility manifest: the content
 * version rises by one and existing saves apply the step as they load, its
 * declared position edits (map shifts, removals, relocations, retirements)
 * or the project migration class it names. The
 * manifest is rewritten through the source-preserving writer, so its
 * comments and layout survive; a manifest it cannot rewrite is reported, not
 * flattened.
 *
 * @package Ichiloto\Editor\Maps
 */
final class SaveManifestStep
{
    /** The manifest, relative to the project root. */
    public const string PATH = 'assets/Data/save-compatibility.php';

    private function __construct()
    {
    }

    /** Protect a stable map ID before a move or deletion, using the sources checked again at commit. */
    public static function protectMapRemoval(string $projectRoot, string $mapId, FileSetTransaction $transaction): void
    {
        $path = $projectRoot . '/' . self::PATH;
        if (! is_file($path)) {
            $transaction->expectSource($path, null);
            return;
        }

        $source = file_get_contents($path);
        if ($source === false) {
            throw new MapSourceRefusal('Could not read the save compatibility manifest.');
        }

        $manifest = PhpDataFile::evaluateIsolated($path, $projectRoot);
        $history = self::readPositionHistory($projectRoot, $manifest);
        $protected = self::getProtectedPositions($projectRoot, $manifest, $history);
        if (! empty($protected[$mapId])) {
            throw new MapSourceRefusal("Retire {$mapId} before removing it. Existing saves can still load on this map.");
        }

        $transaction->expectSource($path, $source);
        if ($history !== null) {
            $transaction->expectSource($history['path'], $history['source']);
        }
    }

    /**
     * Reads the declared authoring snapshot. The bytes also identify the source
     * used by a reviewed save or line edit.
     *
     * @return array{path: string, source: string}|null
     */
    public static function readPositionHistory(string $projectRoot, mixed $manifest): ?array
    {
        if (! is_array($manifest) || ! array_key_exists('positionHistoryFile', $manifest)) {
            return null;
        }

        $filename = $manifest['positionHistoryFile'];
        if (! is_string($filename) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\.json\z/', $filename) !== 1) {
            throw new RuntimeException('positionHistoryFile must name a JSON file in assets/Data.');
        }
        $directory = $projectRoot . '/assets/Data';
        $path = $directory . '/' . $filename;
        $resolvedDirectory = realpath($directory);
        $resolvedPath = realpath($path);
        if ($resolvedDirectory === false || $resolvedPath === false || dirname($resolvedPath) !== $resolvedDirectory || ! is_file($path)) {
            throw new RuntimeException("The declared save position history $filename must exist in assets/Data.");
        }
        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException("Could not read the declared save position history $filename.");
        }

        return ['path' => $path, 'source' => $source];
    }

    /**
     * Positions that can exist in a production save, carried through the same edits as the loader.
     *
     * @param array{path: string, source: string}|null $history
     * @return array<string, array<string, array{int, int}>>|null
     */
    public static function getProtectedPositions(string $projectRoot, mixed $manifest, ?array $history): ?array
    {
        if ($history === null || ! is_array($manifest)) {
            return null;
        }
        try {
            $layouts = json_decode($history['source'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new RuntimeException(basename($history['path']) . ' contains invalid JSON.', previous: $failure);
        }

        return SaveCompatibilityManifest::fromArray(
            SaveCompatibilityManifest::readProjectId($projectRoot), $manifest, self::PATH, ContentMigrationResolution::WHEN_REQUIRED,
        )->projectSavePositions($layouts, basename($history['path']));
    }

    /**
     * The step that would be appended, for display or a hand edit.
     *
     * @param array<string, list<array<string, mixed>>> $edits Declared position edits by manifest key.
     * @return array<string, mixed>
     */
    public static function describe(mixed $manifest, array $edits): array
    {
        $version = is_array($manifest) ? ($manifest['contentVersion'] ?? null) : null;

        return ['from' => $version, 'to' => is_int($version) ? $version + 1 : null, ...$edits];
    }

    /**
     * Appends the step.
     *
     * @param string $source The manifest's source.
     * @param mixed $manifest The manifest as evaluated.
     * @param array<string, list<array<string, mixed>>> $edits Declared position edits by manifest key.
     * @return array{source: string, value: array<array-key, mixed>}|string The rewritten manifest, or why it cannot be.
     */
    public static function append(string $source, mixed $manifest, array $edits): array|string
    {
        return self::appendStep($source, $manifest, self::describe($manifest, $edits));
    }

    /**
     * Appends a step that runs a project migration class. The Engine reads a
     * step as a class or as declared position edits, never both, so the step
     * names the class alone.
     *
     * @param string $source The manifest's source.
     * @param mixed $manifest The manifest as evaluated.
     * @param string $class The migration class, fully qualified.
     * @return array{source: string, value: array<array-key, mixed>}|string The rewritten manifest, or why it cannot be.
     */
    public static function appendClass(string $source, mixed $manifest, string $class): array|string
    {
        $version = is_array($manifest) ? ($manifest['contentVersion'] ?? null) : null;

        return self::appendStep($source, $manifest, ['from' => $version, 'to' => is_int($version) ? $version + 1 : null, 'class' => $class]);
    }

    /**
     * Reads a staged manifest back as the game reads it, for a source set
     * plan: through the project's own autoloader ({@see ProjectMigrationProbe})
     * when a step names a project migration class, which the Editor's process
     * cannot load, else by the Engine's SaveCompatibilityManifest here.
     *
     * @param mixed $manifest The manifest as it will be written.
     * @param string $change What the rewrite adds, for the refusal: "the alias".
     * @return \Closure(string, string): mixed
     */
    public static function createReadBack(string $projectRoot, string $projectId, mixed $manifest, string $change): \Closure
    {
        $namesClasses = array_any(is_array($manifest) ? (array) ($manifest['migrations'] ?? []) : [],
            static fn(mixed $step): bool => is_array($step) && isset($step['class']));

        return static function (string $path, string $staged) use ($projectRoot, $projectId, $namesClasses, $change): mixed {
            try {
                if ($namesClasses) {
                    return ProjectMigrationProbe::readManifest($projectRoot, $projectId, $staged);
                }
                $value = PhpDataFile::evaluateIsolated($staged, $projectRoot);
                SaveCompatibilityManifest::fromArray($projectId, is_array($value) ? $value : [], self::PATH);

                return $value;
            } catch (Throwable $refusal) {
                throw new RuntimeException(sprintf('The game would refuse %s: %s; nothing was written.', $change, rtrim($refusal->getMessage(), '.')), previous: $refusal);
            }
        };
    }

    /**
     * @param array<string, mixed> $step
     * @return array{source: string, value: array<array-key, mixed>}|string
     */
    private static function appendStep(string $source, mixed $manifest, array $step): array|string
    {
        $version = is_array($manifest) ? ($manifest['contentVersion'] ?? null) : null;
        $migrations = is_array($manifest) ? ($manifest['migrations'] ?? []) : null;
        if (! is_int($version) || $version < 0 || ! is_array($migrations) || ! array_is_list($migrations)) {
            return 'its contentVersion or migrations list is not valid, so saves cannot be migrated automatically';
        }
        $next = $manifest;
        $next['contentVersion'] = $version + 1;
        $next['migrations'] = [...$migrations, $step];
        try {
            return ['source' => ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($source), $manifest, $next)->source, 'value' => $next];
        } catch (SourceUnreadable|SourcePreservationRefusal $refusal) {
            return rtrim($refusal->getMessage(), '.');
        }
    }
}
