<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\Storage\SourceSetPlan;
use RuntimeException;
use Throwable;

/**
 * Plans registering a project's own save migration class as the next step of
 * its save compatibility manifest: the content version rises by exactly one
 * and the step names the class, which the game runs on saves made before it.
 *
 * The Engine reads each step as either a migration class or declared
 * position edits, never both, so a class step carries nothing else. The
 * manifest is rewritten through the source-preserving writer, and the staged
 * manifest is read back as the game reads it ({@see ProjectMigrationProbe}),
 * so a class that does not load from the project or does not implement the
 * Engine's ContentMigrationInterface is refused before anything is written.
 *
 * @package Ichiloto\Editor\Maps
 */
final class SaveMigrationRegistration
{
    private function __construct()
    {
    }

    /**
     * @return array{plan: SourceSetPlan, from: int, to: int, class: string}
     * @throws RuntimeException When the class is not named, or the manifest cannot be read or take the step; nothing is written.
     */
    public static function plan(string $projectRoot, string $projectId, string $class): array
    {
        $class = ltrim(trim($class), '\\');
        if ($class === '') {
            throw new RuntimeException('Name the migration class to register.');
        }
        $manifestPath = $projectRoot . '/' . SaveManifestStep::PATH;
        if (! is_file($manifestPath)) {
            throw new RuntimeException(sprintf('%s is missing, so no migration can be registered.', SaveManifestStep::PATH));
        }
        $source = (string) file_get_contents($manifestPath);
        try {
            $manifest = PhpDataFile::evaluateIsolated($manifestPath, $projectRoot);
        } catch (Throwable $failure) {
            throw new RuntimeException(sprintf('%s could not be read: %s', SaveManifestStep::PATH, $failure->getMessage()), previous: $failure);
        }
        $next = SaveManifestStep::appendClass($source, $manifest, $class);
        if (is_string($next)) {
            throw new RuntimeException(sprintf('%s cannot take the migration step: %s.', SaveManifestStep::PATH, $next));
        }
        $readBack = static function (string $path, string $staged) use ($projectRoot, $projectId): mixed {
            try {
                return ProjectMigrationProbe::readManifest($projectRoot, $projectId, $staged);
            } catch (RuntimeException $refusal) {
                throw new RuntimeException(sprintf('The game would refuse the migration step: %s; nothing was written.', $refusal->getMessage()), previous: $refusal);
            }
        };
        $plan = new SourceSetPlan($projectRoot, [$manifestPath => $source], [$manifestPath => $source], [$manifestPath => $next['source']],
            [$manifestPath => $manifest], [$manifestPath => $next['value']],
            static fn(): array => [$manifestPath], $readBack, 'save migration registration', 'Save compatibility manifest');
        $version = (int) $next['value']['contentVersion'];

        return ['plan' => $plan, 'from' => $version - 1, 'to' => $version, 'class' => $class];
    }
}
