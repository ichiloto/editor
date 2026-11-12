<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

use InvalidArgumentException;

/**
 * A sound comes in when the Engine's players play it ({@see AudioFormat}):
 * its bytes are the format its name says, read whole, and it plays for no
 * longer than {@see MAX_DURATION_SECONDS}. It goes straight into the folder
 * of its role ({@see AudioRole}), where the game finds it by the name a
 * field stores: its file name without the extension. The game plays a
 * name, so a sound never comes in beside another file of the same name in
 * another format. Its written copy must read back as the same bytes.
 *
 * The limits are the Editor's, for any game: a sound file of at most
 * {@see MAX_FILE_BYTES} (an editing session keeps an import's bytes for
 * undo), playing at most thirty minutes.
 */
final class AudioAdmission implements AssetAdmission
{
    public const int MAX_FILE_BYTES = 48 * 1024 * 1024;

    public const int MAX_DURATION_SECONDS = 30 * 60;

    public function getKind(): AssetKind
    {
        return AssetKind::Audio;
    }

    public function getMaxFileBytes(): int
    {
        return self::MAX_FILE_BYTES;
    }

    public function describeTooLarge(string $label): string
    {
        return sprintf('%s is larger than the %d MiB the Editor brings in for a sound. Save long music as MP3 or Ogg and try again.',
            $label, intdiv(self::MAX_FILE_BYTES, 1024 * 1024));
    }

    public function admit(string $bytes, string $label, string $name): AdmittedAsset
    {
        $named = AudioFormat::tryFromName($name)
            ?? throw new InvalidArgumentException("{$name} is not a sound's name. Name the sound with " . AudioFormat::describeExtensions() . ' at the end.');
        $actual = AudioFormat::tryFromBytes($bytes)
            ?? throw new InvalidArgumentException("{$label} is not a sound the game plays.");
        if ($actual !== $named) {
            throw new InvalidArgumentException(sprintf('%s is %s %s sound. Name it with .%s at the end.',
                $label, $actual === AudioFormat::Ogg ? 'an' : 'a', $actual->getLabel(), $actual->value));
        }
        $clip = $named->readClip($bytes, $label);
        if ($clip->duration > self::MAX_DURATION_SECONDS) {
            throw new InvalidArgumentException(sprintf('%s plays for %d minutes; the Editor brings in sounds of at most %d minutes.',
                $label, (int) ceil($clip->duration / 60), intdiv(self::MAX_DURATION_SECONDS, 60)));
        }

        return new AdmittedAsset(['format' => $clip->format->value, 'duration' => round($clip->duration, 3), 'sampleRate' => $clip->sampleRate,
            'channels' => $clip->channels], hash('sha256', $bytes));
    }

    public function checkPath(string $path): void
    {
        if (AudioFormat::tryFromName($path) === null || pathinfo($path, PATHINFO_FILENAME) === '') {
            throw new InvalidArgumentException("{$path} is not a sound's name. Name the sound with " . AudioFormat::describeExtensions() . ' at the end.');
        }
        if (AudioRole::tryFromAssetFolder(dirname($path)) === null) {
            throw new InvalidArgumentException(sprintf('%s is not where the game finds sounds. Sounds go straight into %s, by what they are for.',
                $path, implode(' or ', array_map(static fn(AudioRole $role): string => $role->getAssetFolder(), AudioRole::cases()))));
        }
    }

    /** @return array{role: string, name: string} The role and the name a field stores to play it. */
    public function describePlace(string $path): array
    {
        $role = AudioRole::tryFromAssetFolder(dirname($path)) ?? throw new InvalidArgumentException("{$path} is not where the game finds sounds.");

        return ['role' => $role->value, 'name' => pathinfo($path, PATHINFO_FILENAME)];
    }

    public function findNameClashes(string $assets, string $path): array
    {
        $folder = dirname($path);
        $stem = pathinfo($path, PATHINFO_FILENAME);
        $clashes = [];
        foreach (is_dir("{$assets}/{$folder}") ? (scandir("{$assets}/{$folder}") ?: []) : [] as $entry) {
            if ($entry !== basename($path) && ! str_starts_with($entry, '.') && pathinfo($entry, PATHINFO_FILENAME) === $stem) {
                $clashes[] = "{$folder}/{$entry}";
            }
        }

        return $clashes;
    }

    public function describeNameClash(string $path, string $clash): string
    {
        return sprintf('assets/%s already has the name %s, and the game plays sounds by name. Choose another name.',
            $clash, pathinfo($path, PATHINFO_FILENAME));
    }

    public function readValue(string $bytes, string $path): string
    {
        return hash('sha256', $bytes);
    }

    public function readStagedValue(string $staged, string $path): string
    {
        return (string) hash_file('sha256', $staged);
    }
}
