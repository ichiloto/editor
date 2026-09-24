<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneHydration;
use Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession;
use Ichiloto\Engine\Field\MapManager;

it('loads layer-aware cinematic collision and clears all geometry and crop state on unload', function () {
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
            ->and(array_keys($manager->layerTiles2d))->toBe(['buildings', 'detail'])
            ->and(new ReflectionProperty(MapManager::class, 'collisionMap')->getValue($manager))->toBe([[0, 1, 0, 0], [0, 0, 0, 0]]);
        $camera = $scene->previewCamera();
        ob_start();
        $camera->renderLayeredMap($manager->layers);
        $output = ob_get_clean();
        expect($output)->toBe('')
            ->and(implode("\n", $camera->frame()))->toContain('./..', '.xx.');
        $manager->unload();
        expect($manager->layers)->toBeNull()
            ->and($manager->layerTiles2d)->toBe([])
            ->and($manager->tiles2d)->toBeNull()
            ->and($manager->tileMap)->toBe([])
            ->and($manager->mapWidth)->toBe(0)
            ->and($manager->mapHeight)->toBe(0)
            ->and($camera->worldSpace)->toBe([]);
    } finally {
        $preview->dispose();
    }
});
