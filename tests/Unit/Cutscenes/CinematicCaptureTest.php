<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Preview\CinematicCapture;
use Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession;
use Ichiloto\Editor\Review\CinematicCaptureWriter;
use Ichiloto\Editor\Review\SceneFrameRaster;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Progression\WorldAbilityCatalog;
use Ichiloto\Engine\Util\Config\ConfigStore;

/**
 * A captured preview presents the scene graphically from its first command,
 * gives the Engine's composition of every frame at the scene's clock, and
 * holds the scene on a notice until it is acknowledged, as the game does.
 * Everything here is synthetic: a quay, an image lifted above the cover and
 * a world power granted by a switch.
 */

/** The synthetic cinematic: a lifted glow, then a power showcase, then a switch that says the script went on. */
function captureShowcaseDefinition(): CinematicDefinition
{
    return CinematicDefinition::fromArrays([
        'id' => 'capture-showcase', 'name' => 'Capture showcase', 'startMap' => 'harbour',
        'presentation' => ['initial' => 'field'], 'cast' => [], 'skip' => ['policy' => 'forbidden'], 'checkpoints' => [], 'finalizer' => [],
    ], [
        ['type' => 'stage_actor', 'id' => 'glow', 'subject' => ['kind' => 'player'],
            'sprites2d' => ['asset' => 'Graphics/Effects/glow.png', 'cells' => ['width' => 1, 'height' => 1]], 'plane' => 'aboveCover'],
        ['type' => 'wait', 'seconds' => 0.3],
        ['type' => 'remove_actor', 'actorId' => 'glow'],
        ['type' => 'set_switch', 'name' => 'quick_step_granted', 'value' => true],
        ['type' => 'show_power_unlocks', 'announcement' => 'A new power stirs.', 'abilities' => [['ability' => 'quick-step']]],
        ['type' => 'set_switch', 'name' => 'after_showcase', 'value' => true],
    ]);
}

function captureShowcaseProject(): string
{
    $root = cutsceneProject();
    @mkdir($root . '/assets/Graphics/Effects', 0o777, true);
    writeTilesetTestPng($root . '/assets/Graphics/Effects/glow.png', 48, 48);
    file_put_contents($root . '/assets/Data/world-abilities.php', "<?php\n\nreturn " . var_export([
        ['id' => 'quick-step', 'name' => 'Quick Step', 'description' => 'Crosses open ground quickly.',
            'conditions' => [['type' => 'switch', 'name' => 'quick_step_granted']]],
    ], true) . ";\n");

    return $root;
}

/** @return array<string, mixed> */
function captureOptions(): array
{
    return ['x' => 2, 'y' => 3, 'width' => 40, 'height' => 12];
}

it('holds the scene on a power showcase notice until the author acknowledges each one, in the Engine\'s order', function () {
    $root = captureShowcaseProject();
    $preview = CinematicPreviewSession::start($root, captureShowcaseDefinition(), captureOptions());

    try {
        for ($tick = 0; $tick < 40 && $preview->getWaitKind() === null && ! $preview->isFinished(); $tick++) {
            $preview->step();
        }
        expect($preview->getWaitKind())->toBe('notice')
            ->and($preview->status())->toBe(CinematicPreviewSession::STATUS_WAITING)
            ->and(implode("\n", $preview->frame()))->toContain('A new power stirs.');
        $held = $preview->elapsed();
        $preview->step();
        $preview->step();
        expect($preview->elapsed())->toBe($held)
            ->and($preview->snapshot()->values)->not->toHaveKey('switch after_showcase');

        expect($preview->confirm())->toBeTrue();
        for ($tick = 0; $tick < 5 && $preview->getWaitKind() === null; $tick++) {
            $preview->step();
        }
        expect($preview->getWaitKind())->toBe('notice')
            ->and(implode("\n", $preview->frame()))->toContain('Quick Step');
        $preview->confirm();
        expect($preview->runToCompletion(5.0))->toBeTrue()
            ->and($preview->status())->toBe(CinematicPreviewSession::STATUS_COMPLETED)
            ->and($preview->snapshot()->values['switch after_showcase'])->toBeTrue()
            ->and(array_column(array_filter($preview->dialogueLog(), static fn(array $entry): bool => $entry['kind'] === 'notice'), 'text'))
            ->toBe(['A new power stirs.', 'Crosses open ground quickly.']);
    } finally {
        $preview->dispose();
    }
});

it('installs the project\'s world abilities for the field and gives back what was installed before', function () {
    $root = captureShowcaseProject();
    $before = new WorldAbilityCatalog([]);
    ConfigStore::put(WorldAbilityCatalog::class, $before);
    $preview = CinematicPreviewSession::start($root, captureShowcaseDefinition(), captureOptions());

    try {
        expect(WorldAbilityCatalog::getProjectCatalog()->getAbility('quick-step')->name)->toBe('Quick Step');
    } finally {
        $preview->dispose();
        expect(ConfigStore::get(WorldAbilityCatalog::class))->toBe($before);
        ConfigStore::remove(WorldAbilityCatalog::class);
    }
});

it('presents a captured run graphically from its first command, lifting above-cover art onto the stage plane', function () {
    $root = captureShowcaseProject();
    $preview = CinematicPreviewSession::startCapture($root, captureShowcaseDefinition(), SceneFrameRaster::supports(...), captureOptions());

    try {
        $frame = $preview->composeFrame();
        $lifted = array_column($frame->screenOverlay?->images ?? [], 'asset');
        expect($preview->isCaptured())->toBeTrue()
            ->and($frame->world)->not->toBeNull()
            ->and($frame->viewport)->not->toBeNull()
            ->and($lifted)->toContain('Graphics/Effects/glow.png')
            ->and(array_column($frame->sprites, 'asset'))->not->toContain('Graphics/Effects/glow.png')
            // The window's view cannot take a captured field's presentation.
            ->and($preview->exchangeScene([previewWindowReady()], $preview->getSceneSessionId()))->toBe([]);
    } finally {
        $preview->dispose();
    }
});

it('records a moving capture that reads each notice for its reading time while the scene clock holds', function () {
    $root = captureShowcaseProject();
    $preview = CinematicPreviewSession::startCapture($root, captureShowcaseDefinition(), SceneFrameRaster::supports(...), captureOptions());

    try {
        $capture = new CinematicCapture($preview, 10.0, 0.5, 10.0);
        $frames = iterator_to_array($capture->record(), false);
        $notices = array_values(array_filter($frames, static fn($frame): bool => $frame->waitKind === 'notice'));

        expect($capture->gaveUp)->toBeFalse()
            ->and($preview->status())->toBe(CinematicPreviewSession::STATUS_COMPLETED)
            ->and(array_column($capture->acknowledged, 'kind'))->toBe(['notice', 'notice'])
            ->and(count($notices))->toBe(10)
            ->and(array_unique(array_map(static fn($frame): float => $frame->sceneSeconds, array_slice($notices, 0, 5))))->toHaveCount(1)
            ->and($frames[1]->captureSeconds - $frames[0]->captureSeconds)->toEqualWithDelta(0.1, 1e-9)
            ->and(array_column($frames[0]->composition->screenOverlay?->images ?? [], 'asset'))->toContain('Graphics/Effects/glow.png')
            ->and(end($frames)->waitKind)->toBeNull();
    } finally {
        $preview->dispose();
    }
});

it('writes a capture as numbered PNG frames with a manifest an encoder reads, into an empty folder only', function () {
    $root = captureShowcaseProject();
    $preview = CinematicPreviewSession::startCapture($root, captureShowcaseDefinition(), SceneFrameRaster::supports(...), captureOptions());
    $folder = makeTemporaryProject('capture-output-') . '/frames';

    try {
        $writer = new CinematicCaptureWriter($folder, new SceneFrameRaster($root . '/assets'));
        $written = $writer->write(new CinematicCapture($preview, 5.0, 0.4, 10.0), $preview->getSceneGrid());
        $manifest = json_decode((string) file_get_contents($written['manifest']), true);
        $size = getimagesize($folder . '/frame-000000.png');

        expect($written['finished'])->toBeTrue()
            ->and($written['frames'])->toBe(count($manifest['frames']))
            ->and(is_file(sprintf('%s/frame-%06d.png', $folder, $written['frames'] - 1)))->toBeTrue()
            ->and([$size[0], $size[1]])->toBe([$manifest['size']['width'], $manifest['size']['height']])
            ->and($manifest['cinematic'])->toBe('capture-showcase')
            ->and($manifest['fps'])->toEqual(5.0)
            ->and(array_column($manifest['acknowledged'], 'kind'))->toBe(['notice', 'notice'])
            ->and(fn() => $writer->write(new CinematicCapture($preview, 5.0, 0.4, 1.0), $preview->getSceneGrid()))
            ->toThrow(RuntimeException::class, 'holds files');
    } finally {
        $preview->dispose();
    }
});

it('captures a project cinematic through the session where its preview starts, to completion, without changing the project', function () {
    $root = cutsceneProject();
    $hash = static fn(): string => hash('xxh128', implode("\n", array_map(static fn(string $file): string => $file . ':' . hash_file('xxh128', $file),
        array_filter(iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/assets', FilesystemIterator::SKIP_DOTS))),
            'is_file'))));
    $before = $hash();
    $folder = makeTemporaryProject('capture-session-') . '/harbour';
    $session = EditorSession::open($root);

    $written = $session->writeCinematicCapture(0, $folder, 40, 12, 4.0, 0.5, 30.0);
    $manifest = json_decode((string) file_get_contents($written['manifest']), true);

    expect($written['finished'])->toBeTrue()
        ->and($manifest['cinematic'])->toBe('harbour-lanterns')
        ->and($manifest['status'])->toBe(CinematicPreviewSession::STATUS_COMPLETED)
        ->and($written['frames'])->toBe(count(glob($folder . '/frame-*.png')))
        ->and($hash())->toBe($before)
        ->and(fn() => $session->writeCinematicCapture(0, $folder, 40, 12, 4.0, 0.5, 30.0))
        ->toThrow(SessionRefusal::class, 'holds files');
});
