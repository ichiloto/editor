<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Assets;

/**
 * What a sound is for in the game: music, or a sound effect. Each has the
 * folder under `assets/Audio` the Engine's audio manager looks in when a
 * field names a track ({@see \Ichiloto\Engine\Audio\AudioManager}), and the
 * reference kind a picker lists its tracks under. The value is that
 * reference kind.
 */
enum AudioRole: string
{
    case Music = 'bgm';
    case SoundEffect = 'sfx';

    /** The folder under `assets/Audio` the game finds this role's tracks in. */
    public function getFolder(): string
    {
        return match ($this) {
            self::Music => 'BGM',
            self::SoundEffect => 'SFX',
        };
    }

    /** The folder relative to the project's assets, as an asset path starts. */
    public function getAssetFolder(): string
    {
        return 'Audio/' . $this->getFolder();
    }

    /** The role whose tracks live in an asset-relative folder, or null when the game finds no tracks there. */
    public static function tryFromAssetFolder(string $folder): ?self
    {
        return array_find(self::cases(), static fn(self $role): bool => $role->getAssetFolder() === $folder);
    }
}
