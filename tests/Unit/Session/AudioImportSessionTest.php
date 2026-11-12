<?php

declare(strict_types=1);

use Ichiloto\Editor\Assets\AssetImport;
use Ichiloto\Editor\Assets\AudioAdmission;

/*
 * Bringing a sound into a project over the session request a GUI's asset
 * picker sends (`assets.import`), through the same planned, read-back and
 * undoable write as an image: into its role's folder, where the game finds
 * it by name, and only when the game's players play it. The sounds are tiny
 * synthetic fixtures; no test reads a game's content.
 */

/** A folder outside the project holding a copy of each sound fixture, as an author's downloads would. */
function writeIncomingSounds(string $root): string
{
    $incoming = rememberTemporaryProject($root . '-incoming');
    mkdir($incoming, 0o777, true);
    foreach (['tone-lame.mp3', 'tone-mpeg2-plain.mp3', 'tone.wav', 'tone-vorbis.ogg'] as $name) {
        copy(fixturePath('Audio/' . $name), "{$incoming}/{$name}");
    }

    return $incoming;
}

it('brings a sound effect into its role\'s folder byte for byte, names it as a field stores it, and undoes it', function () {
    $root = createBuildingProject();
    $request = createBuildingRpc($root);
    $incoming = writeIncomingSounds($root);
    $target = $root . '/assets/Audio/SFX/Switch-On.mp3';

    $imported = expectAnswer($request('assets.import', ['source' => "{$incoming}/tone-lame.mp3", 'destination' => 'Audio/SFX/Switch-On.mp3']));
    expect($imported)->toBe(['kind' => 'audio', 'path' => 'Audio/SFX/Switch-On.mp3', 'format' => 'mp3', 'duration' => 0.25, 'sampleRate' => 44100,
        'channels' => 1, 'role' => 'sfx', 'name' => 'Switch-On', 'changed' => true])
        ->and(file_get_contents($target))->toBe(file_get_contents("{$incoming}/tone-lame.mp3"))
        ->and(array_column(expectAnswer($request('references.list', ['category' => 'sfx'])), 'value'))->toContain('Switch-On');

    // The same sound again changes nothing; another one asks first, then replaces it.
    expect(expectAnswer($request('assets.import', ['source' => "{$incoming}/tone-lame.mp3", 'destination' => $target]))['changed'])->toBeFalse()
        ->and(expectRefusal($request('assets.import', ['source' => "{$incoming}/tone-mpeg2-plain.mp3", 'destination' => 'Audio/SFX/Switch-On.mp3'])))
        ->toBe('assets/Audio/SFX/Switch-On.mp3 already holds another sound. Choose another name, or replace it.');
    expectAnswer($request('assets.import', ['source' => "{$incoming}/tone-mpeg2-plain.mp3", 'destination' => 'Audio/SFX/Switch-On.mp3', 'replace' => true]));
    expect(file_get_contents($target))->toBe(file_get_contents("{$incoming}/tone-mpeg2-plain.mp3"));

    expectAnswer($request('history.undo'));
    expect(file_get_contents($target))->toBe(file_get_contents("{$incoming}/tone-lame.mp3"));
    expectAnswer($request('history.undo'));
    expect(is_file($target))->toBeFalse()->and(is_dir(dirname($target)))->toBeFalse();
    expectAnswer($request('history.redo'));
    expect(file_get_contents($target))->toBe(file_get_contents("{$incoming}/tone-lame.mp3"));

    // Music goes into its own folder, any supported format.
    expect(expectAnswer($request('assets.import', ['source' => "{$incoming}/tone-vorbis.ogg", 'destination' => "{$root}/assets/Audio/BGM/Theme.ogg"])))
        ->toMatchArray(['path' => 'Audio/BGM/Theme.ogg', 'role' => 'bgm', 'name' => 'Theme', 'format' => 'ogg']);
});

it('brings a sound only where the game finds it by name, and only as the format its name says', function () {
    $root = createBuildingProject();
    $request = createBuildingRpc($root);
    $incoming = writeIncomingSounds($root);
    $refuse = static fn(string $source, string $destination): string => expectRefusal($request('assets.import',
        ['source' => "{$incoming}/{$source}", 'destination' => $destination]));

    expect($refuse('tone.wav', 'Audio/Other/Door.wav'))
        ->toBe('Audio/Other/Door.wav is not where the game finds sounds. Sounds go straight into Audio/BGM or Audio/SFX, by what they are for.')
        ->and($refuse('tone.wav', 'Audio/SFX/Doors/Door.wav'))->toContain('is not where the game finds sounds')
        ->and($refuse('tone.wav', 'Graphics/Door.wav'))->toContain('is not where the game finds sounds')
        ->and($refuse('tone.wav', '../Audio/SFX/Door.wav'))->toBe('../Audio/SFX/Door.wav is not a place inside the assets folder.')
        ->and($refuse('tone.wav', dirname($root) . '/Door.wav'))->toBe("Sounds are brought into the project's assets folder. Choose a place inside it.")
        ->and($refuse('tone.wav', 'Audio/SFX/Door.flac'))->toContain('is not the name of a file the game uses')
        ->and($refuse('tone.wav', 'Audio/SFX/Door.mp3'))->toBe('tone.wav is a WAV sound. Name it with .wav at the end.')
        ->and($refuse('tone-vorbis.ogg', 'Audio/SFX/Door.wav'))->toBe('tone-vorbis.ogg is an Ogg sound. Name it with .ogg at the end.');
    file_put_contents("{$incoming}/notes.mp3", 'not a sound');
    expect($refuse('notes.mp3', 'Audio/SFX/Notes.mp3'))->toBe('notes.mp3 is not a sound the game plays.')
        ->and(is_dir($root . '/assets/Audio'))->toBeFalse();
});

it('never brings a sound in beside another of the same name, which the game would play instead', function () {
    $root = createBuildingProject();
    $request = createBuildingRpc($root);
    $incoming = writeIncomingSounds($root);
    expectAnswer($request('assets.import', ['source' => "{$incoming}/tone.wav", 'destination' => 'Audio/SFX/Door.wav']));

    expect(expectRefusal($request('assets.import', ['source' => "{$incoming}/tone-lame.mp3", 'destination' => 'Audio/SFX/Door.mp3'])))
        ->toBe('assets/Audio/SFX/Door.wav already has the name Door, and the game plays sounds by name. Choose another name.')
        ->and(is_file($root . '/assets/Audio/SFX/Door.mp3'))->toBeFalse();

    // A file of that name appearing after the plan is made refuses the write, writing nothing.
    $plan = AssetImport::plan($root, "{$incoming}/tone-lame.mp3", 'Audio/SFX/Bell.mp3');
    copy("{$incoming}/tone.wav", $root . '/assets/Audio/SFX/Bell.wav');
    expect(fn() => $plan->plan->apply())->toThrow(RuntimeException::class, 'Imported sound changed outside the sound import')
        ->and(is_file($root . '/assets/Audio/SFX/Bell.mp3'))->toBeFalse();
});

it('refuses a sound larger or longer than the Editor brings in, before reading all of it', function () {
    $root = createBuildingProject();
    $incoming = writeIncomingSounds($root);
    // A sparse file: its size is all the refusal reads.
    $large = fopen("{$incoming}/Large.wav", 'w');
    ftruncate($large, AudioAdmission::MAX_FILE_BYTES + 1);
    fclose($large);
    // One sample a second, so thirty-one minutes is a small file.
    writeIncomingWav("{$incoming}/Long.wav", rate: 1, frames: 31 * 60);

    expect(fn() => AssetImport::plan($root, "{$incoming}/Large.wav", 'Audio/BGM/Large.wav'))
        ->toThrow(InvalidArgumentException::class, 'Large.wav is larger than the 48 MiB the Editor brings in for a sound. Save long music as MP3 or Ogg and try again.')
        ->and(fn() => AssetImport::plan($root, "{$incoming}/Long.wav", 'Audio/BGM/Long.wav'))
        ->toThrow(InvalidArgumentException::class, 'Long.wav plays for 31 minutes; the Editor brings in sounds of at most 30 minutes.');
});

/** An 8-bit mono PCM WAV of silence. */
function writeIncomingWav(string $path, int $rate, int $frames): void
{
    $fmt = pack('vvVVvv', 1, 1, $rate, $rate, 1, 8);
    file_put_contents($path, 'RIFF' . pack('V', 4 + 8 + 16 + 8 + $frames) . 'WAVE' . 'fmt ' . pack('V', 16) . $fmt . 'data' . pack('V', $frames)
        . str_repeat("\x80", $frames));
}
