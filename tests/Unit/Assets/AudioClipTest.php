<?php

declare(strict_types=1);

use Ichiloto\Editor\Assets\AudioFormat;

/*
 * Reading a sound file's own bytes: its format, how long it plays, its rate
 * and channels, and refusing a file that is cut short, damaged or not the
 * format its name says. The fixtures are tiny synthetic tones, committed so
 * no test needs an encoder.
 */

function readAudioFixture(string $name): string
{
    return (string) file_get_contents(fixturePath('Audio/' . $name));
}

/** A PCM WAV of silence, built byte by byte. */
function writeSilentWav(int $rate, int $channels, int $bits, int $frames, ?int $declaredData = null, int $tag = 1): string
{
    $blockAlign = $channels * intdiv($bits, 8);
    $data = str_repeat("\0", $frames * $blockAlign);
    $fmt = pack('vvVVvv', $tag, $channels, $rate, $rate * $blockAlign, $blockAlign, $bits);

    return 'RIFF' . pack('V', 4 + 8 + strlen($fmt) + 8 + strlen($data)) . 'WAVE'
        . 'fmt ' . pack('V', strlen($fmt)) . $fmt . 'LIST' . pack('V', 3) . "abc\0" . 'data' . pack('V', $declaredData ?? strlen($data)) . $data;
}

it('reads how long each format plays from the file itself', function (string $file, AudioFormat $format, int $samples, int $rate, int $channels) {
    $clip = AudioFormat::tryFromName($file)->readClip(readAudioFixture($file), $file);

    expect($clip->format)->toBe($format)
        ->and($clip->samples)->toBe($samples)
        ->and($clip->sampleRate)->toBe($rate)
        ->and($clip->channels)->toBe($channels);
})->with([
    // LAME gapless information: 0.25 s exactly, the encoder's delay and padding and its Info frame left out.
    'MP3 with an Info frame' => ['tone-lame.mp3', AudioFormat::Mp3, 11025, 44100, 1],
    // MPEG-2 frames with no Info frame: twelve frames of 576 samples.
    'MPEG-2 MP3 without tags' => ['tone-mpeg2-plain.mp3', AudioFormat::Mp3, 6912, 22050, 2],
    'Ogg Vorbis' => ['tone-vorbis.ogg', AudioFormat::Ogg, 5568, 22050, 2],
    // Opus counts at 48 kHz, less its pre-skip: 0.25 s.
    'Ogg Opus' => ['tone-opus.ogg', AudioFormat::Ogg, 12000, 48000, 1],
    'PCM WAV' => ['tone.wav', AudioFormat::Wav, 2000, 8000, 1],
]);

it('reads a WAV past chunks it does not use, and an extensible header by its subformat', function () {
    $clip = AudioFormat::Wav->readClip(writeSilentWav(16000, 2, 16, 4000), 'Silence.wav');
    expect($clip->duration)->toBe(0.25)->and($clip->channels)->toBe(2);

    $extensible = static function (int $subformat): string {
        $fmt = pack('vvVVvvvvV', 0xFFFE, 1, 8000, 16000, 2, 16, 22, 16, 4) . pack('v', $subformat) . "\0\0\0\0\x10\0\x80\0\0\xAA\0\x38\x9B\x71";

        return 'RIFF' . pack('V', 4 + 8 + strlen($fmt) + 8 + 800) . 'WAVE' . 'fmt ' . pack('V', strlen($fmt)) . $fmt . 'data' . pack('V', 800)
            . str_repeat("\0", 800);
    };
    expect(AudioFormat::Wav->readClip($extensible(1), 'Extensible.wav')->samples)->toBe(400)
        ->and(fn() => AudioFormat::Wav->readClip($extensible(2), 'Adpcm.wav'))->toThrow(InvalidArgumentException::class, 'Adpcm.wav is compressed');
});

it('refuses a WAV that is cut short, compressed, damaged or silent', function () {
    $wav = writeSilentWav(8000, 1, 16, 800);

    expect(fn() => AudioFormat::Wav->readClip(substr($wav, 0, -10), 'Cut.wav'))
        ->toThrow(InvalidArgumentException::class, 'Cut.wav is cut short: its data chunk runs past the end of the file.')
        ->and(fn() => AudioFormat::Wav->readClip(writeSilentWav(8000, 1, 16, 800, tag: 2), 'Adpcm.wav'))
        ->toThrow(InvalidArgumentException::class, 'Adpcm.wav is compressed in a way the game\'s players do not read. Save it as PCM WAV and try again.')
        ->and(fn() => AudioFormat::Wav->readClip(writeSilentWav(8000, 1, 16, 0), 'Empty.wav'))
        ->toThrow(InvalidArgumentException::class, 'Empty.wav holds no sound.')
        ->and(fn() => AudioFormat::Wav->readClip(substr_replace($wav, pack('v', 3), 32, 2), 'Odd.wav'))
        ->toThrow(InvalidArgumentException::class, 'Odd.wav has a damaged description of its sound.')
        ->and(fn() => AudioFormat::Wav->readClip('not a sound at all', 'Notes.wav'))
        ->toThrow(InvalidArgumentException::class, 'Notes.wav is not a WAV sound.');
});

it('refuses an MP3 with something other than frames or tags after them, and bytes that are no MP3', function () {
    $mp3 = readAudioFixture('tone-mpeg2-plain.mp3');

    expect(fn() => AudioFormat::Mp3->readClip($mp3 . str_repeat('x', 200), 'Damaged.mp3'))
        ->toThrow(InvalidArgumentException::class, 'Damaged.mp3 is damaged')
        ->and(fn() => AudioFormat::Mp3->readClip(str_repeat("\0", 2048), 'Silence.mp3'))
        ->toThrow(InvalidArgumentException::class, 'Silence.mp3 is not an MP3 sound.');
    // An ID3v1 tag after the frames is no part of the sound; a last frame cut short is not played.
    expect(AudioFormat::Mp3->readClip($mp3 . 'TAG' . str_repeat(' ', 125), 'Tagged.mp3')->samples)->toBe(6912)
        ->and(AudioFormat::Mp3->readClip(substr($mp3, 0, -10), 'Cut.mp3')->samples)->toBe(6336);
});

it('refuses an Ogg file with a damaged page, cut short, or holding more than one stream', function () {
    $ogg = readAudioFixture('tone-vorbis.ogg');
    $damaged = $ogg;
    $damaged[strlen($ogg) - 5] = chr(ord($ogg[strlen($ogg) - 5]) ^ 0xFF);

    expect(fn() => AudioFormat::Ogg->readClip($damaged, 'Damaged.ogg'))->toThrow(InvalidArgumentException::class, 'Damaged.ogg is damaged at byte')
        ->and(fn() => AudioFormat::Ogg->readClip(substr($ogg, 0, -5), 'Cut.ogg'))->toThrow(InvalidArgumentException::class, 'Cut.ogg is cut short.')
        ->and(fn() => AudioFormat::Ogg->readClip($ogg . readAudioFixture('tone-opus.ogg'), 'Chained.ogg'))
        ->toThrow(InvalidArgumentException::class, 'Chained.ogg holds more than one stream.')
        ->and(fn() => AudioFormat::Ogg->readClip('RIFF', 'Notes.ogg'))->toThrow(InvalidArgumentException::class, 'Notes.ogg is not an Ogg sound.');
});

it('knows a sound file\'s format from its name and from its bytes, separately', function () {
    expect(AudioFormat::tryFromName('Audio/SFX/Door.MP3'))->toBe(AudioFormat::Mp3)
        ->and(AudioFormat::tryFromName('Door.flac'))->toBeNull()
        ->and(AudioFormat::tryFromBytes(readAudioFixture('tone.wav')))->toBe(AudioFormat::Wav)
        ->and(AudioFormat::tryFromBytes(readAudioFixture('tone-opus.ogg')))->toBe(AudioFormat::Ogg)
        ->and(AudioFormat::tryFromBytes(readAudioFixture('tone-lame.mp3')))->toBe(AudioFormat::Mp3)
        ->and(AudioFormat::tryFromBytes(readAudioFixture('tone-mpeg2-plain.mp3')))->toBe(AudioFormat::Mp3)
        ->and(AudioFormat::tryFromBytes('plain text'))->toBeNull();
});
