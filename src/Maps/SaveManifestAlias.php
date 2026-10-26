<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Cutscenes\Source\SourceUnreadable;
use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\Storage\SourceSetPlan;
use Ichiloto\Engine\IO\SaveCompatibility\ContentReferenceCategory;
use RuntimeException;
use Throwable;

/**
 * Plans adding one alias to the project's save compatibility manifest: a
 * saved identity that moved (a one-shot event that became an NPC page) is
 * read as its new identity by every save, whatever its content version. An
 * alias needs no version step. The manifest is rewritten through the
 * source-preserving writer and read back as the game reads it, so an alias
 * the game would refuse is never written.
 *
 * @package Ichiloto\Editor\Maps
 */
final class SaveManifestAlias
{
    private function __construct()
    {
    }

    /**
     * @return SourceSetPlan|null The plan, or null when the manifest already has this alias.
     * @throws RuntimeException When the manifest cannot be read or take the alias; nothing is written.
     */
    public static function plan(string $projectRoot, string $projectId, ContentReferenceCategory $category, string $from, string $to): ?SourceSetPlan
    {
        $manifestPath = $projectRoot . '/' . SaveManifestStep::PATH;
        if (! is_file($manifestPath)) {
            throw new RuntimeException(sprintf('%s is missing, so the saved identity %s cannot follow its move.', SaveManifestStep::PATH, $from));
        }
        $source = (string) file_get_contents($manifestPath);
        try {
            $manifest = PhpDataFile::evaluateIsolated($manifestPath, $projectRoot);
        } catch (Throwable $failure) {
            throw new RuntimeException(sprintf('%s could not be read: %s', SaveManifestStep::PATH, $failure->getMessage()), previous: $failure);
        }
        $aliases = is_array($manifest) ? ($manifest['aliases'][$category->value] ?? []) : null;
        if (! is_array($aliases) || ! array_is_list($aliases)) {
            throw new RuntimeException(sprintf('%s aliases.%s is not a list.', SaveManifestStep::PATH, $category->value));
        }
        foreach ($aliases as $alias) {
            if (($alias['from'] ?? null) !== $from) { continue; }
            if (($alias['to'] ?? null) === $to) { return null; }
            throw new RuntimeException(sprintf('%s already sends %s to %s.', SaveManifestStep::PATH, $from, strval($alias['to'] ?? '')));
        }
        $next = $manifest;
        $next['aliases'][$category->value] = [...$aliases, ['from' => $from, 'to' => $to]];
        try {
            $rewritten = ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($source), $manifest, $next)->source;
        } catch (SourceUnreadable|SourcePreservationRefusal $refusal) {
            throw new RuntimeException(sprintf('%s cannot take the alias: %s', SaveManifestStep::PATH, rtrim($refusal->getMessage(), '.')), previous: $refusal);
        }
        $readBack = SaveManifestStep::createReadBack($projectRoot, $projectId, $next, 'the alias');

        return new SourceSetPlan($projectRoot, [$manifestPath => $source], [$manifestPath => $source], [$manifestPath => $rewritten],
            [$manifestPath => $manifest], [$manifestPath => $next],
            static fn(): array => [$manifestPath], $readBack, 'save identity alias', 'Save compatibility manifest');
    }
}
