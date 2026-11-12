<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\CutsceneSchemas;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Session\EditorSession;

/**
 * An image keyframe's own image: each keyframe of an image track may name an
 * asset-root-relative PNG, picked through the shared asset picker, or show
 * its track's default image, which is optional when every keyframe names
 * one. Edits keep the authored source, and the Engine's validation refuses a
 * save it could not play before a byte is written. Images and timelines are
 * synthetic and replaceable.
 */

/** A synthetic effect whose graphical track draws from a default sheet and a second file, with source worth keeping. */
function makeKeyframeAssetProject(): array
{
    $root = effectProject();
    foreach (['start' => [32, 16], 'follow' => [64, 32], 'ragged' => [33, 16]] as $name => [$width, $height]) {
        writeTilesetTestPng($root . "/assets/Graphics/Effects/synthetic-{$name}.png", $width, $height);
    }
    $file = $root . '/assets/Animations/frame-pair/frame-pair.timeline.php';
    mkdir(dirname($file), 0o777, true);
    file_put_contents($file, <<<'PHP'
<?php
// Keep this comment and the terminal sequence as written.
return ['presentations' => [
    'terminal' => ['fps' => 4, 'lengthFrames' => 4, 'tracks' => [
        ['id' => 'motion', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'duration' => 4, 'content' => '*']]],
    ]],
    'graphical' => ['fps' => 4, 'lengthFrames' => 4, 'tracks' => [
        ['id' => 'motion', 'type' => 'image', 'asset' => 'Graphics/Effects/synthetic-start.png',
            'sheet' => ['columns' => 2, 'rows' => 1],
            'keyframes' => [
                ['frame' => 0, 'duration' => 2, 'sourceFrame' => 1],
                ['frame' => 2, 'duration' => 2, 'asset' => 'Graphics/Effects/synthetic-follow.png', 'sourceFrame' => 0],
            ],
        ],
    ]],
]];
PHP);

    return [$root, $file];
}

/** @return array{EditorSession, int} The session on the graphical sequence, and the effect's index. */
function openKeyframeAssetSession(string $root): array
{
    $session = EditorSession::open($root);
    $index = array_search('frame-pair', $session->listDatabaseRecords('cutscenes/effect')['records'], true);
    $session->selectCutscenePresentation('cutscenes/effect', $index, 'graphical');

    return [$session, $index];
}

function keyframeAssetRow(EditorSession $session, int $index, string $field): array
{
    return array_find($session->readDatabaseRecord('cutscenes/effect', $index, ['tracks'])['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field) ?? throw new RuntimeException('Missing row ' . $field);
}

it('offers a keyframe image through the shared asset picker, with the track default as its none choice', function (): void {
    $keyframe = array_find(CutsceneSchemas::effectImageKeyframeList()->fields, static fn(RecordField $field): bool => $field->key === 'asset');
    $stage = array_find(CutsceneSchemas::stageImageKeyframeList()->fields, static fn(RecordField $field): bool => $field->key === 'asset');
    $track = array_find(CutsceneSchemas::imageTrackFields(), static fn(RecordField $field): bool => $field->key === 'asset');

    foreach ([$keyframe, $stage] as $field) {
        expect($field->reference)->toBe('png_assets')
            ->and($field->allowsNone)->toBeTrue()
            ->and($field->noneLabel())->toBe(CutsceneSchemas::KEYFRAME_IMAGE_TRACK_DEFAULT);
    }
    expect($track->reference)->toBe('png_assets')
        ->and($track->allowsNone)->toBeTrue()
        ->and($track->noneLabel())->toBe(CutsceneSchemas::TRACK_IMAGE_NONE);

    [$root] = makeKeyframeAssetProject();
    [$session, $index] = openKeyframeAssetSession($root);
    $own = keyframeAssetRow($session, $index, 'track0Keyframe1Asset');
    expect($own)->toMatchArray(['kind' => 'reference', 'reference' => 'png_assets', 'value' => 'Graphics/Effects/synthetic-follow.png',
        'media' => ['kind' => 'image', 'root' => 'assets'], 'noneLabel' => CutsceneSchemas::KEYFRAME_IMAGE_TRACK_DEFAULT])
        ->and(keyframeAssetRow($session, $index, 'track0Keyframe0Asset')['value'])->toBe(CutsceneSchemas::KEYFRAME_IMAGE_TRACK_DEFAULT)
        ->and(array_column($session->listReferences(null, 'png_assets'), 'value'))->toContain('Graphics/Effects/synthetic-follow.png');

    // The timeline names each keyframe's image as authored, never a row's unset label, with that image's size.
    $track = $session->describeCutsceneTimeline('cutscenes/effect', $index)['tracks'][0];
    expect($track['art'])->toMatchArray(['asset' => 'Graphics/Effects/synthetic-start.png', 'width' => 32, 'height' => 16])
        ->and($track['keyframes'][0])->toMatchArray(['asset' => 'Graphics/Effects/synthetic-start.png', 'ownsAsset' => false, 'width' => 32, 'height' => 16])
        ->and($track['keyframes'][1])->toMatchArray(['asset' => 'Graphics/Effects/synthetic-follow.png', 'ownsAsset' => true, 'width' => 64, 'height' => 32])
        ->and($track['keyframes'][1]['assetKey']['field'])->toBe('track0Keyframe1Asset');
});

it('picks and clears keyframe images in the authored source, the track default optional once every keyframe names one', function (): void {
    [$root, $file] = makeKeyframeAssetProject();
    $source = (string) file_get_contents($file);
    $original = require $file;
    [$session, $index] = openKeyframeAssetSession($root);
    $apply = static function (string $field, string $value) use ($session, $index): void {
        $session->applyDatabaseRecord('cutscenes/effect', $index, keyframeAssetRow($session, $index, $field)['key'], $value);
    };

    $apply('track0Keyframe0Asset', 'Graphics/Effects/synthetic-follow.png');
    // Choosing the track default removes the keyframe's own image.
    $apply('track0Keyframe1Asset', CutsceneSchemas::KEYFRAME_IMAGE_TRACK_DEFAULT);
    $session->saveDatabase('cutscenes/effect');
    $saved = require $file;
    expect($saved['presentations']['graphical']['tracks'][0]['keyframes'])->toBe([
        ['frame' => 0, 'duration' => 2, 'sourceFrame' => 1, 'asset' => 'Graphics/Effects/synthetic-follow.png'],
        ['frame' => 2, 'duration' => 2, 'sourceFrame' => 0],
    ])
        ->and($saved['presentations']['terminal'])->toBe($original['presentations']['terminal'])
        ->and(file_get_contents($file))->toContain('// Keep this comment and the terminal sequence as written.');

    // Every keyframe names its own image, so the track needs no default.
    $apply('track0Keyframe1Asset', 'Graphics/Effects/synthetic-start.png');
    $apply('track0Asset', CutsceneSchemas::TRACK_IMAGE_NONE);
    $session->saveDatabase('cutscenes/effect');
    $track = (require $file)['presentations']['graphical']['tracks'][0];
    expect($track)->not->toHaveKey('asset')
        ->and(array_column($track['keyframes'], 'asset'))->toBe(['Graphics/Effects/synthetic-follow.png', 'Graphics/Effects/synthetic-start.png'])
        ->and($session->describeCutsceneTimeline('cutscenes/effect', $index)['tracks'][0]['art']['asset'])->toBe('');

    foreach (range(1, 4) as $step) { $session->undo(); }
    $session->saveDatabase('cutscenes/effect');
    expect(file_get_contents($file))->toBe($source);
});

it('refuses a save the Engine could not play, with its own reason, before anything is written', function (string $field, string $value, string $reason): void {
    [$root, $file] = makeKeyframeAssetProject();
    $source = (string) file_get_contents($file);
    [$session, $index] = openKeyframeAssetSession($root);

    $session->applyDatabaseRecord('cutscenes/effect', $index, keyframeAssetRow($session, $index, $field)['key'], $value);
    expect(fn() => $session->saveDatabase('cutscenes/effect'))->toThrow(RuntimeException::class, $reason);
    expect(file_get_contents($file))->toBe($source);
})->with([
    // The first keyframe shows the track's default, so the default cannot go.
    'no image for a keyframe' => ['track0Asset', CutsceneSchemas::TRACK_IMAGE_NONE, 'needs an asset path or a track asset default'],
    'an image off the sheet grid' => ['track0Keyframe0Asset', 'Graphics/Effects/synthetic-ragged.png', 'does not divide into its sheet grid'],
]);
