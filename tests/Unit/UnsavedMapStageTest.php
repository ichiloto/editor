<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Validation\UnsavedMapStage;

/** A one-room project the game can walk: a corridor with the sample chest E at its end, entered at its start. */
function unsavedStageProject(): string
{
    $root = makeTemporaryProject('ichiloto-unsaved-stage-');
    file_put_contents($root . '/assets/Maps/collisions.php', "<?php\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\n"
        . "return ['#' => CollisionType::SOLID, ' ' => CollisionType::NONE];\n");
    file_put_contents($root . '/assets/Data/system.php', '<?php return ' . var_export(['startingPositions' => ['player' => [
        'destinationMap' => 'test-map', 'spawnPoint' => ['x' => 1, 'y' => 1], 'spawnSprite' => ['South'],
    ]]], true) . ';');
    file_put_contents($root . '/assets/Maps/test-map/test-map.map.php', "<?php\n\nreturn <<<'ICHILOTO_MAP'\n#######\n#     #\n#######\nICHILOTO_MAP;\n");
    file_put_contents($root . '/assets/Maps/test-map/test-map.event.php', "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n       \n     E \n       \nICHILOTO_EVENT_MAP;\n");

    return $root;
}

/** The temporary stages left in the temporary directory. */
function countUnsavedStages(): int
{
    return count(glob(sys_get_temp_dir() . '/ichiloto-unsaved-maps-*') ?: []);
}

it('judges reachability by the unsaved map, as saving would leave it, writing nothing of the project', function () {
    $root = unsavedStageProject();
    $before = sourceHashTree($root . '/assets');
    $stages = countUnsavedStages();
    $session = EditorSession::open($root);
    $unreachable = static fn(): array => array_values(array_filter(array_column($session->validateProject(['test-map'])['issues'], 'message'),
        static fn(string $message): bool => str_contains($message, 'can be reached')));
    expect($unreachable())->toBe([]);

    // A wall across the corridor, not yet saved, cuts the chest off.
    $map = $session->readMap('test-map');
    $session->paint('test-map', $map['revision'], $map['baseLayer'], [[3, 1]], '#');

    expect($unreachable())->toBe(['No cell of event E (ChestEventTrigger) at (5, 1) can be reached, so it never fires.'])
        ->and(sourceHashTree($root . '/assets'))->toBe($before)
        ->and(countUnsavedStages())->toBe($stages);
});

it('stages only a workspace with unsaved maps, each written by its own writer beside copies of the rest', function () {
    $root = unsavedStageProject();
    $workspace = ProjectWorkspace::fromProject($root);
    expect(UnsavedMapStage::prepare($workspace))->toBeNull();

    $map = $workspace->maps[array_search('test-map', $workspace->mapIds, true)];
    $map->setMapDataField(['name'], 'Renamed Unsaved');
    $stage = UnsavedMapStage::prepare($workspace);

    expect($stage)->not->toBeNull()
        ->and((require $stage->assetRoot . '/Maps/test-map/test-map.data.php')['name'])->toBe('Renamed Unsaved')
        ->and((require $root . '/assets/Maps/test-map/test-map.data.php')['name'])->toBe('Test Map')
        // Other asset folders are read in place.
        ->and(realpath($stage->assetRoot . '/Data/system.php'))->toBe(realpath($root . '/assets/Data/system.php'))
        ->and($map->isDirty())->toBeTrue();

    $stage->dispose();
    expect(is_dir(dirname($stage->assetRoot)))->toBeFalse()
        ->and(is_file($root . '/assets/Data/system.php'))->toBeTrue();
});
