<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Cutscenes\Source\SourceUnreadable;

/**
 * Appends one declarative step to the project's save compatibility manifest:
 * the content version rises by one and existing saves apply the step's
 * position edits (map shifts, removals, relocations) as they load. The
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
        $version = is_array($manifest) ? ($manifest['contentVersion'] ?? null) : null;
        $migrations = is_array($manifest) ? ($manifest['migrations'] ?? []) : null;
        if (! is_int($version) || $version < 0 || ! is_array($migrations) || ! array_is_list($migrations)) {
            return 'its contentVersion or migrations list is not valid, so saves cannot be migrated automatically';
        }
        $next = $manifest;
        $next['contentVersion'] = $version + 1;
        $next['migrations'] = [...$migrations, self::describe($manifest, $edits)];
        try {
            return ['source' => ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($source), $manifest, $next)->source, 'value' => $next];
        } catch (SourceUnreadable|SourcePreservationRefusal $refusal) {
            return rtrim($refusal->getMessage(), '.');
        }
    }
}
