<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

use Ichiloto\Editor\Storage\SourceSetPlan;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;
use RuntimeException;

/**
 * Brings a file from anywhere on the computer into the project's assets,
 * so a picker can choose it: a tileset sheet, a portrait or a building's
 * art, a piece of music or a sound effect. The file is copied byte for
 * byte to the place the author names under `assets/`, and every row that
 * names it names it by that asset-relative path (a sound by its name in its
 * role's folder), never by its bytes or the place it came from.
 *
 * The name the author gives it says its kind ({@see AssetKind}), and that
 * kind's admission decides whether the game uses such a file and where it
 * goes ({@see AssetAdmission}): an image the Engine could draw, a sound its
 * players play. Every kind then comes in the same way: one planned write,
 * staged, read back as the file that was checked, then committed, and
 * undone by putting back exactly what was there. A file already at that
 * place is replaced only when the author says so, and bringing in the same
 * bytes again changes nothing.
 */
final class AssetImport
{
    private function __construct()
    {
    }

    /**
     * Plans bringing a file in.
     *
     * @param string $source The file's path on the computer.
     * @param string $destination Where it goes: a path under the project's assets, relative to them or absolute.
     * @param bool $replace Whether a different file already there is replaced.
     * @throws InvalidArgumentException When the file cannot be brought in, saying why.
     */
    public static function plan(string $projectRoot, string $source, string $destination, bool $replace = false): AssetImportPlan
    {
        $name = basename(trim(str_replace(DIRECTORY_SEPARATOR, '/', $destination)));
        $kind = self::getDestinationKind($destination);
        $admission = $kind->createAdmission();
        $bytes = self::readSource($source, $admission);
        $admitted = $admission->admit($bytes, basename($source), $name);
        $assets = rtrim($projectRoot, '/') . '/assets';
        $path = self::resolveDestination($assets, $destination, $kind);
        $facts = $admitted->facts + $admission->describePlace($path);
        $target = $assets . '/' . $path;
        $existing = is_file($target) ? file_get_contents($target) : null;
        if ($existing === false || is_dir($target)) {
            throw new InvalidArgumentException("assets/{$path} cannot be read, so it is not replaced.");
        }
        if ($existing === $bytes) {
            return new AssetImportPlan($kind, $path, $facts, null);
        }
        $clash = $admission->findNameClashes($assets, $path)[0] ?? null;
        if ($clash !== null) {
            throw new InvalidArgumentException($admission->describeNameClash($path, $clash));
        }
        if ($existing !== null && ! $replace) {
            throw new InvalidArgumentException("assets/{$path} already holds another {$kind->getNoun()}. Choose another name, or replace it.");
        }
        $readBack = static fn(string $path, string $staged): string => $admission->readStagedValue($staged, $path);
        $before = $existing === null ? null : $admission->readValue($existing, $path);
        // The file itself when it is there, and any file that would come to share its name.
        $findWatched = static function () use ($assets, $target, $existing, $admission, $path): array {
            if (!self::isOwnedDestination($assets, $target)) {
                throw new RuntimeException("assets/{$path} no longer resolves to a writable place inside the assets folder.");
            }

            return array_merge($existing === null ? [] : [$target],
                array_map(static fn(string $clash): string => "{$assets}/{$clash}", $admission->findNameClashes($assets, $path)));
        };
        $plan = new SourceSetPlan($projectRoot, $existing === null ? [] : [$target => $existing], [$target => $existing], [$target => $bytes],
            [$target => $before], [$target => $admitted->value], $findWatched, $readBack, "{$kind->getNoun()} import",
            'Imported ' . $kind->getNoun());

        return new AssetImportPlan($kind, $path, $facts, $plan);
    }

    /**
     * An asset-relative path for a destination given relative to the
     * project's assets or as an absolute path inside them, checked as a
     * place a file of a kind goes.
     *
     * @throws InvalidArgumentException When it leaves the assets or is not a place that kind goes.
     */
    public static function resolveDestination(string $assets, string $destination, AssetKind $kind): string
    {
        $destination = trim(str_replace(DIRECTORY_SEPARATOR, '/', $destination));
        if (str_starts_with($destination, '/')) {
            $root = realpath($assets);
            $folder = self::findExistingAncestor(dirname($destination));
            $resolved = $folder === null ? false : realpath($folder);
            if ($root === false || $resolved === false || ($resolved !== $root && ! str_starts_with($resolved, $root . '/'))) {
                throw new InvalidArgumentException(sprintf("%ss are brought into the project's assets folder. Choose a place inside it.",
                    ucfirst($kind->getNoun())));
            }
            $destination = ltrim(substr($resolved, strlen($root)) . substr(dirname($destination), strlen($folder)) . '/' . basename($destination), '/');
        }
        try {
            SpriteValidation::validateAssetPath($destination);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException("{$destination} is not a place inside the assets folder.");
        }
        $kind->createAdmission()->checkPath($destination);
        if (!self::isOwnedDestination($assets, $assets . '/' . $destination)) {
            throw new InvalidArgumentException("{$destination} is not a writable place inside the assets folder.");
        }

        return $destination;
    }

    /** Resolves a caller's destination to an asset-relative path that can be used on a project copy. */
    public static function resolveProjectDestination(string $projectRoot, string $destination): string
    {
        return self::resolveDestination(rtrim($projectRoot, '/') . '/assets', $destination,
            self::getDestinationKind($destination));
    }

    private static function getDestinationKind(string $destination): AssetKind
    {
        $name = basename(trim(str_replace(DIRECTORY_SEPARATOR, '/', $destination)));

        return AssetKind::tryFromName($name)
            ?? throw new InvalidArgumentException(sprintf('%s is not the name of a file the game uses. %s', trim($destination), AssetKind::describeNames()));
    }

    private static function isOwnedDestination(string $assets, string $target): bool
    {
        clearstatcache(true);
        $root = realpath($assets);
        $folder = self::findExistingAncestor(dirname($target));
        $resolved = $folder === null ? false : realpath($folder);

        // A leaf link is not a replaceable asset file. A parent link may be
        // traversed only when it resolves within the project's asset root.
        return $root !== false && $resolved !== false && !is_link($target)
            && ($resolved === $root || str_starts_with($resolved, $root . DIRECTORY_SEPARATOR));
    }

    private static function readSource(string $source, AssetAdmission $admission): string
    {
        if (! is_file($source) || ! is_readable($source)) {
            throw new InvalidArgumentException(sprintf('%s cannot be read.', $source));
        }
        if ((int) filesize($source) > $admission->getMaxFileBytes()) {
            throw new InvalidArgumentException($admission->describeTooLarge(basename($source)));
        }
        $bytes = file_get_contents($source);
        if ($bytes === false) {
            throw new InvalidArgumentException(sprintf('%s cannot be read.', $source));
        }

        return $bytes;
    }

    private static function findExistingAncestor(string $folder): ?string
    {
        while (! is_dir($folder)) {
            $parent = dirname($folder);
            if ($parent === $folder) {
                return null;
            }
            $folder = $parent;
        }

        return $folder;
    }
}
