<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneHydration;
use Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession;
use Ichiloto\Engine\Field\MapManager;

it('loads layer-aware cinematic collision and clears all geometry on unload', function () {
    $root = layeredMapProject();
    file_put_contents($root . '/assets/Maps/test-map/test-map.event.php',
        Ichiloto\Engine\Field\MapGridSource::buildSource("    \n    ", 'EVENT'));
    file_put_contents($root . '/assets/Maps/collisions.php', <<<'PHP'
<?php
use Ichiloto\Engine\Events\Enumerations\CollisionType;
return ['.' => CollisionType::NONE,
    'buildings' => ['/' => CollisionType::SOLID, 'x' => CollisionType::PASS_THROUGH]];
PHP);
    $definition = CutsceneHydration::cinematic(['id' => 'preview', 'name' => 'Preview'],
        [['type' => 'wait', 'seconds' => 1]], $root);
    $preview = CinematicPreviewSession::start($root, $definition, ['mapId' => 'test-map', 'x' => 0, 'y' => 0]);
    try {
        $scene = new ReflectionProperty($preview, 'scene')->getValue($preview);
        $manager = $scene->previewMap;
        expect($manager->layers?->legacy)->toBeFalse()
            ->and(new ReflectionProperty(MapManager::class, 'collisionMap')->getValue($manager))->toBe([[0, 1, 0, 0], [0, 0, 0, 0]]);
        $camera = $scene->previewCamera();
        ob_start();
        $frame = $preview->frame();
        $output = ob_get_clean();
        // The preview's picture is captured, never written over the editor's screen.
        expect($output)->toBe('')
            // The layers composed, with the player standing on the map's first cell.
            ->and(implode("\n", array_map(Ichiloto\Engine\IO\Console\TerminalText::stripAnsi(...), $frame)))->toContain('/..', '.xx.');
        $manager->unload();
        expect($manager->layers)->toBeNull()
            ->and($manager->tileMap)->toBe([])
            ->and($manager->mapWidth)->toBe(0)
            ->and($manager->mapHeight)->toBe(0)
            ->and($camera->worldSpace)->toBe([]);
    } finally {
        $preview->dispose();
    }
});

it('plays the map\'s own field effects in the preview, as the game\'s field has them in either presentation', function () {
    $root = layeredMapProject();
    file_put_contents($root . '/assets/Maps/test-map/test-map.event.php',
        Ichiloto\Engine\Field\MapGridSource::buildSource("    \n    ", 'EVENT'));
    $data = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($data, str_replace("'events' => [],", "'events' => [], 'fieldEffects' => [['id' => 'glow', 'effect' => 'energy', 'anchor' => ['cell' => ['x' => 1, 'y' => 0]]]],",
        (string) file_get_contents($data)));
    writeTilesetTestPng($root . '/assets/Graphics/strip.png', 8, 2);
    mkdir($root . '/assets/Animations/energy', 0o777, true);
    file_put_contents($root . '/assets/Animations/energy/energy.timeline.php', '<?php return ' . var_export([
        'fps' => 4, 'lengthFrames' => 4, 'playback' => 'loop', 'restFrame' => 2,
        'tracks' => [['id' => 'motes', 'type' => 'image', 'asset' => 'Graphics/strip.png',
            'sheet' => ['columns' => 4, 'rows' => 1], 'cells' => ['width' => 1, 'height' => 1], 'depth' => 'front',
            'keyframes' => array_map(static fn(int $frame): array => ['frame' => $frame, 'sourceFrame' => $frame], range(0, 3))]]], true) . ';');
    $definition = CutsceneHydration::cinematic(['id' => 'preview', 'name' => 'Preview'], [['type' => 'wait', 'seconds' => 1]], $root);
    $preview = CinematicPreviewSession::start($root, $definition, ['mapId' => 'test-map', 'x' => 0, 'y' => 0]);
    try {
        $scene = new ReflectionProperty($preview, 'scene')->getValue($preview);
        expect($scene->fieldEffects->count)->toBe(1);
        // The preview's clock moves the map's effects as a game frame does, the cinematic's commands aside.
        $glow = static fn(): int => new ReflectionProperty($scene->fieldEffects, 'sessions')->getValue($scene->fieldEffects)['map-glow']->playback->currentFrame;
        $before = $glow();
        $preview->step(0.5);
        expect($glow())->not->toBe($before);
        // The graphical view's field has the same effect, drawn its own way.
        $preview->exchangeScene([json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => ['sprite_source_rect',
            'graphical_canvas', 'canvas_overlay', 'frame_viewport', 'field_motion']])], $preview->getSceneSessionId());
        expect($scene->fieldEffects->count)->toBe(1);
        $preview->detachScene();
        expect($scene->fieldEffects->count)->toBe(1);
    } finally {
        $preview->dispose();
    }
});
