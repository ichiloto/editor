<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

/**
 * A kind of file the Editor brings into a project's assets, known from the
 * name the author gives it there: images the game draws and sounds it
 * plays. Each kind has its own admission ({@see AssetAdmission}); bringing
 * one in is otherwise the same transaction for every kind ({@see AssetImport}).
 */
enum AssetKind: string
{
    case Image = 'image';
    case Audio = 'audio';

    /** The kind a file name's extension names, or null when the game uses no file named so. */
    public static function tryFromName(string $name): ?self
    {
        return match (true) {
            strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'png' => self::Image,
            AudioFormat::tryFromName($name) !== null => self::Audio,
            default => null,
        };
    }

    /** The names an author is asked to give a file the game uses. */
    public static function describeNames(): string
    {
        return sprintf('Name an image with .png at the end, or a sound with %s.', AudioFormat::describeExtensions());
    }

    /** What one file of this kind is called, as a refusal names it. */
    public function getNoun(): string
    {
        return match ($this) {
            self::Image => 'image',
            self::Audio => 'sound',
        };
    }

    /** How a file of this kind is checked before it comes in. */
    public function createAdmission(): AssetAdmission
    {
        return match ($this) {
            self::Image => new ImageAdmission(),
            self::Audio => new AudioAdmission(),
        };
    }
}
