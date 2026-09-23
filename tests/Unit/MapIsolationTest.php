<?php

declare(strict_types=1);

use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\Playtest\PlaytestOverlay;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Engine\Field\MapGridSource;

it('opens valid maps alongside multiple read-only invalid maps and reports each source', function (): void {
    $root = makeTemporaryProject('ichiloto-map-isolation-');
    $mapsRoot = $root . '/assets/Maps';
    $executed = $root . '/grid-executed';

    foreach (['bad-a', 'bad-b'] as $name) {
        $directory = $mapsRoot . '/' . $name;
        ProjectMap::createBlank($directory, $name, $name, 3, 2);
        file_put_contents(
            "{$directory}/{$name}.map.php",
            "<?php\nfile_put_contents(" . var_export($executed, true) . ", 'yes');\nreturn 'bad';\n",
        );
    }

    $workspace = ProjectWorkspace::fromProject($root);
    $badA = $workspace->maps[array_search('bad-a', $workspace->mapIds, true)];
    $good = $workspace->maps[array_search('test-map', $workspace->mapIds, true)];
    $issues = (new ProjectValidator())->validate($workspace);
    $badIssues = array_values(array_filter($issues, static fn ($issue): bool => str_contains($issue->message, 'Map source is read-only')));

    expect(is_file($executed))->toBeFalse()
        ->and($badA->getGridSourceIssue())->toContain('bad-a.map.php')
        ->and($workspace->getCanvasLines(array_search('bad-a', $workspace->mapIds, true), 40, 10))->toContain('Read-only: repair this map before editing.')
        ->and($good->getGridSourceIssue())->toBeNull()
        ->and($badIssues)->toHaveCount(2);

    expect(fn () => $badA->setMapField('name', 'Changed'))->toThrow(MapSourceRefusal::class, 'read-only');
    expect(fn () => $badA->save())->toThrow(MapSourceRefusal::class, 'read-only');
    expect(fn () => $workspace->deleteMap(array_search('bad-a', $workspace->mapIds, true)))->toThrow(MapSourceRefusal::class, 'read-only');

    $good->setTileSymbol(1, 1, 'x');
    expect($good->isDirty())->toBeTrue();
});

it('refuses a cinematic playtest event grid before evaluating its map data', function (): void {
    $root = makeTemporaryProject('ichiloto-map-playtest-');
    $directory = $root . '/assets/Maps/test-map';
    $executed = $root . '/executed';
    file_put_contents($root . '/assets/Data/system.php', '<?php return [];');
    file_put_contents($directory . '/test-map.data.php', "<?php file_put_contents(" . var_export($executed, true) . ", 'data'); return []; ");
    file_put_contents($directory . '/test-map.event.php', "<?php file_put_contents(" . var_export($executed, true) . ", 'event'); return 'bad'; ");

    expect(fn () => PlaytestOverlay::createForCinematic($root, 'test-map', 1, 1, 'intro'))
        ->toThrow(InvalidArgumentException::class, 'literal nowdoc');
    expect(is_file($executed))->toBeFalse();
});

it('writes parseable map and event grids when authored rows resemble nowdoc closers', function (): void {
    $root = makeTemporaryProject('ichiloto-map-markers-');
    $directory = $root . '/assets/Maps/marker-map';
    mkdir($directory);
    $map = new ProjectMap(
        'marker-map',
        $directory,
        $directory . '/marker-map.data.php',
        $directory . '/marker-map.map.php',
        $directory . '/marker-map.event.php',
        ['name' => 'Marker Map'],
        ['ICHILOTO_MAP;'],
        ['ICHILOTO_EVENT_MAP;'],
    );

    $map->save();

    expect(MapGridSource::readFile($map->mapPath))->toBe('ICHILOTO_MAP;')
        ->and(MapGridSource::readFile($map->eventPath))->toBe('ICHILOTO_EVENT_MAP;')
        ->and(ProjectMap::fromDirectory($root . '/assets/Maps', $directory)->tileLines)->toBe(['ICHILOTO_MAP;']);
});
