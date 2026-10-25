<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Cutscenes\Source\SourceUnreadable;
use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;
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
