<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Maps;

use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\Storage\SourceSetPlan;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Exceptions\InvalidSaveCompatibilityManifestException;
use Ichiloto\Engine\IO\SaveCompatibility\DeclaredPositionContentMigration;
use Ichiloto\Engine\IO\SaveCompatibility\MapRetirement;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;
use RuntimeException;
use Throwable;

/**
 * Plans retiring a map in the project's save compatibility manifest: one new
 * step holding one `mapRetirements` entry, so saves standing on the retired
 * map load onto the map its places moved to, at the landings the author
 * chose; a landing may name another successor when the places were split
 * across maps. The content version rises by one. The manifest is rewritten through
 * the source-preserving writer and the staged manifest is read back as the
 * game reads it, so an entry the game would refuse is never written.
 *
 * Whether each landing can be stood on is the caller's to check against the
 * map as it is held ({@see SaveLandingCells}); this plans the manifest alone.
 *
 * @package Ichiloto\Editor\Maps
 */
final class SaveMapRetirement
{
    private function __construct()
    {
    }

    /**
     * Reads a retirement entry as the Engine reads it.
     *
     * @param array<string, mixed> $entry `map`, `into`, `to` and optional `landings`.
     * @throws RuntimeException With the Engine's reason when it is malformed.
     */
    public static function readEntry(array $entry): MapRetirement
    {
        try {
            return MapRetirement::createFromArray($entry, 'The retirement');
        } catch (InvalidSaveCompatibilityManifestException $refusal) {
            throw new RuntimeException($refusal->getMessage(), previous: $refusal);
        }
    }

    /**
     * The retirement of `$map` the manifest's step from `$from` declares, as
     * the Engine reads it; null when that step declares none, as after an
     * undo, or names a project class.
     *
     * @param array<array-key, mixed> $manifest The manifest as evaluated.
     * @throws RuntimeException When the step is malformed.
     */
    public static function findRetirement(array $manifest, int $from, string $map): ?MapRetirement
    {
        foreach ((array) ($manifest['migrations'] ?? []) as $index => $step) {
            if (! is_array($step) || ($step['from'] ?? null) !== $from) {
                continue;
            }
            try {
                $edits = SaveCompatibilityManifest::readDeclaredPositionEdits($step, sprintf('%s migrations[%s]', SaveManifestStep::PATH, strval($index)));
            } catch (InvalidSaveCompatibilityManifestException $refusal) {
                throw new RuntimeException($refusal->getMessage(), previous: $refusal);
            }

            return array_find($edits ?? [], static fn(object $edit): bool => $edit instanceof MapRetirement && $edit->map === $map);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry The entry as written, read first by {@see readEntry()}.
     * @return array{plan: SourceSetPlan, from: int, to: int}
     * @throws RuntimeException When the manifest cannot be read or take the step, or the game would refuse it;
     *     nothing is written.
     */
    public static function plan(string $projectRoot, string $projectId, array $entry): array
    {
        $manifestPath = $projectRoot . '/' . SaveManifestStep::PATH;
        if (! is_file($manifestPath)) {
            throw new RuntimeException(sprintf('%s is missing, so saves on %s cannot move.', SaveManifestStep::PATH, strval($entry['map'] ?? '')));
        }
        $source = (string) file_get_contents($manifestPath);
        try {
            $manifest = PhpDataFile::evaluateIsolated($manifestPath, $projectRoot);
        } catch (Throwable $failure) {
            throw new RuntimeException(sprintf('%s could not be read: %s', SaveManifestStep::PATH, $failure->getMessage()), previous: $failure);
        }
        $next = SaveManifestStep::append($source, $manifest, ['mapRetirements' => [$entry]]);
        if (is_string($next)) {
            throw new RuntimeException(sprintf('%s cannot take the map retirement: %s.', SaveManifestStep::PATH, $next));
        }
        $plan = new SourceSetPlan($projectRoot, [$manifestPath => $source], [$manifestPath => $source], [$manifestPath => $next['source']],
            [$manifestPath => $manifest], [$manifestPath => $next['value']],
            static fn(): array => [$manifestPath], SaveManifestStep::createReadBack($projectRoot, $projectId, $next['value'], 'the map retirement'),
            'save map retirement', 'Save compatibility manifest');
        $version = (int) $next['value']['contentVersion'];

        return ['plan' => $plan, 'from' => $version - 1, 'to' => $version];
    }

    /**
     * Where each landing of the retirement in step `$from` stands once every
     * later declared step has run, as a save landing there moves: a later
     * shift, removal or relocation on the map it lands on (`into`, or the
     * successor the landing names), or a later retirement of that map. A step that names a project class is passed over, as the Editor
     * cannot run project code.
     *
     * @param array<array-key, mixed> $manifest The manifest as evaluated.
     * @param int $from The retirement step's `from` version.
     * @return list<array{map: string, cell: array{int, int}, landing: array{int, int}}> Each landing cell as written on
     *     its own map, and
     *     where it stands now: the map by its current id, through declared map aliases. None when the step does not
     *     retire `$map`, as after an undo.
     * @throws RuntimeException When a step is malformed.
     */
    public static function followLandings(array $manifest, int $from, string $map): array
    {
        $retirement = self::findRetirement($manifest, $from, $map);
        if ($retirement === null) {
            return [];
        }
        $aliases = [];
        foreach ((array) ($manifest['aliases']['maps'] ?? []) as $alias) {
            if (is_array($alias) && is_string($alias['from'] ?? null) && is_string($alias['to'] ?? null)) {
                $aliases[trim($alias['from'])] = trim($alias['to']);
            }
        }
        $steps = [];
        foreach ((array) ($manifest['migrations'] ?? []) as $index => $step) {
            if (is_array($step) && is_int($step['from'] ?? null) && $step['from'] >= $from) {
                try {
                    $steps[$step['from']] = SaveCompatibilityManifest::readDeclaredPositionEdits($step, sprintf('%s migrations[%s]', SaveManifestStep::PATH, strval($index)));
                } catch (InvalidSaveCompatibilityManifestException $refusal) {
                    throw new RuntimeException($refusal->getMessage(), previous: $refusal);
                }
            }
        }
        ksort($steps);
        unset($steps[$from]);

        $landings = [];
        foreach ($retirement->getLandingPlaces() as ['map' => $landingMap, 'cell' => $landing]) {
            $place = ['mapId' => $landingMap, 'position' => new Vector2(...$landing)];
            foreach ($steps as $edits) {
                $place = $edits === null ? $place : new DeclaredPositionContentMigration($edits, $aliases)->movePlace($place['mapId'], $place['position']);
            }
            $current = $place['mapId'];
            for ($seen = []; isset($aliases[$current]) && ! isset($seen[$current]); $current = $aliases[$current]) {
                $seen[$current] = true;
            }
            $landings[] = ['map' => $current, 'cell' => [(int) $place['position']->x, (int) $place['position']->y], 'landing' => $landing];
        }

        return $landings;
    }
}
