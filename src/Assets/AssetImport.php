<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

use Ichiloto\Editor\Storage\SourceSetPlan;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/**
 * Brings an image from anywhere on the computer into the project's assets,
 * so an asset picker can choose it: a new tileset sheet, a portrait, a
 * building's art. The file is copied byte for byte to the place the author
 * names under `assets/`, and every row that names an image names it by that
 * asset-relative path, never by its bytes or the place it came from.
 *
 * An image is brought in only when the Engine could draw it: a PNG that
 * reads whole, within the Engine's file size and dimension limits
 * ({@see PngAssetPreflight}). A file already at that place is replaced only
 * when the author says so, and bringing in the same bytes again changes
 * nothing.
 */
final class AssetImport
{
    private function __construct()
    {
    }

    /**
     * Plans bringing an image in.
     *
     * @param string $source The image's path on the computer.
     * @param string $destination Where it goes: a path under the project's assets, relative to them or absolute.
     * @param bool $replace Whether a different file already there is replaced.
     * @return array{path: string, width: int, height: int, plan: ?SourceSetPlan} The asset-relative path, the image's
     *     size, and the write to make, or null when the same image is there already.
     * @throws InvalidArgumentException When the image cannot be brought in, saying why.
     */
    public static function plan(string $projectRoot, string $source, string $destination, bool $replace = false): array
    {
        $bytes = self::readSource($source);
        $label = basename($source);
        $header = PngPixels::readHeader($bytes, $label);
        if ($header->width > PngAssetPreflight::MAX_DIMENSION || $header->height > PngAssetPreflight::MAX_DIMENSION) {
            throw new InvalidArgumentException(sprintf('%s is %d x %d pixels; the game draws images of at most %d pixels each way.',
                $label, $header->width, $header->height, PngAssetPreflight::MAX_DIMENSION));
        }
        $pixels = PngPixels::decode($bytes, $label);
        $assets = rtrim($projectRoot, '/') . '/assets';
        $path = self::resolveDestination($assets, $destination);
        $target = $assets . '/' . $path;
        $existing = is_file($target) ? file_get_contents($target) : null;
        if ($existing === false || is_dir($target)) {
            throw new InvalidArgumentException("assets/{$path} cannot be read, so it is not replaced.");
        }
        if ($existing === $bytes) {
            return ['path' => $path, 'width' => $pixels->width, 'height' => $pixels->height, 'plan' => null];
        }
        if ($existing !== null && ! $replace) {
            throw new InvalidArgumentException("assets/{$path} already holds another image. Choose another name, or replace it.");
        }
        $readBack = static fn(string $path, string $staged): string => self::hashPixels((string) file_get_contents($staged), $path);
        $before = $existing === null ? null : self::hashPixels($existing, $path);
        $plan = new SourceSetPlan($projectRoot, $existing === null ? [] : [$target => $existing], [$target => $existing], [$target => $bytes],
            [$target => $before], [$target => $pixels->getPixelHash()],
            static fn(): array => $existing === null ? [] : [$target], $readBack, 'image import', 'Imported image');

        return ['path' => $path, 'width' => $pixels->width, 'height' => $pixels->height, 'plan' => $plan];
    }

    /**
     * An asset-relative image path for a destination given relative to the
     * project's assets or as an absolute path inside them.
     *
     * @throws InvalidArgumentException When it leaves the assets or is not a PNG's name.
     */
    public static function resolveDestination(string $assets, string $destination): string
    {
        $destination = trim(str_replace(DIRECTORY_SEPARATOR, '/', $destination));
        if (str_starts_with($destination, '/')) {
            $root = realpath($assets);
            $folder = self::findExistingAncestor(dirname($destination));
            $resolved = $folder === null ? false : realpath($folder);
            if ($root === false || $resolved === false || ($resolved !== $root && ! str_starts_with($resolved, $root . '/'))) {
                throw new InvalidArgumentException('Images are brought into the project\'s assets folder. Choose a place inside it.');
            }
            $destination = ltrim(substr($resolved, strlen($root)) . substr(dirname($destination), strlen($folder)) . '/' . basename($destination), '/');
        }
        try {
            SpriteValidation::validateAssetPath($destination);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException("{$destination} is not a place inside the assets folder.");
        }
        if (strtolower(pathinfo($destination, PATHINFO_EXTENSION)) !== 'png' || pathinfo($destination, PATHINFO_FILENAME) === '') {
            throw new InvalidArgumentException("{$destination} is not a PNG's name. Name the image with .png at the end.");
        }

        return $destination;
    }

    private static function readSource(string $source): string
    {
        if (! is_file($source) || ! is_readable($source)) {
            throw new InvalidArgumentException(sprintf('%s cannot be read.', $source));
        }
        if ((int) filesize($source) > PngAssetPreflight::MAX_FILE_BYTES) {
            throw new InvalidArgumentException(sprintf('%s is larger than the %d MiB the game reads.', basename($source),
                intdiv(PngAssetPreflight::MAX_FILE_BYTES, 1024 * 1024)));
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

    /** The pixels of an image already there, or its bytes' hash when they cannot be read as one. */
    private static function hashPixels(string $bytes, string $path): string
    {
        try {
            return PngPixels::decode($bytes, $path)->getPixelHash();
        } catch (InvalidArgumentException) {
            return hash('sha256', $bytes);
        }
    }
}
