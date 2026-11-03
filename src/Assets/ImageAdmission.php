<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use InvalidArgumentException;

/**
 * An image comes in when the Engine could draw it: a PNG that reads whole,
 * within the Engine's file size and dimension limits
 * ({@see PngAssetPreflight}), anywhere under the assets. Its written copy
 * must read back as the same pixels.
 */
final class ImageAdmission implements AssetAdmission
{
    public function getKind(): AssetKind
    {
        return AssetKind::Image;
    }

    public function getMaxFileBytes(): int
    {
        return PngAssetPreflight::MAX_FILE_BYTES;
    }

    public function describeTooLarge(string $label): string
    {
        return sprintf('%s is larger than the %d MiB the game reads.', $label, intdiv(PngAssetPreflight::MAX_FILE_BYTES, 1024 * 1024));
    }

    public function admit(string $bytes, string $label, string $name): AdmittedAsset
    {
        $header = PngPixels::readHeader($bytes, $label);
        if ($header->width > PngAssetPreflight::MAX_DIMENSION || $header->height > PngAssetPreflight::MAX_DIMENSION) {
            throw new InvalidArgumentException(sprintf('%s is %d x %d pixels; the game draws images of at most %d pixels each way.',
                $label, $header->width, $header->height, PngAssetPreflight::MAX_DIMENSION));
        }
        $pixels = PngPixels::decode($bytes, $label);

        return new AdmittedAsset(['width' => $pixels->width, 'height' => $pixels->height], $pixels->getPixelHash());
    }

    public function checkPath(string $path): void
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'png' || pathinfo($path, PATHINFO_FILENAME) === '') {
            throw new InvalidArgumentException("{$path} is not a PNG's name. Name the image with .png at the end.");
        }
    }

    public function describePlace(string $path): array
    {
        return [];
    }

    public function findNameClashes(string $assets, string $path): array
    {
        return [];
    }

    public function describeNameClash(string $path, string $clash): string
    {
        return "assets/{$clash} is already there.";
    }

    /** The pixels of an image already there, or its bytes' hash when they cannot be read as one. */
    public function readValue(string $bytes, string $path): string
    {
        try {
            return PngPixels::decode($bytes, $path)->getPixelHash();
        } catch (InvalidArgumentException) {
            return hash('sha256', $bytes);
        }
    }

    public function readStagedValue(string $staged, string $path): string
    {
        return $this->readValue((string) file_get_contents($staged), $path);
    }
}
