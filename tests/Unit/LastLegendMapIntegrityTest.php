<?php

declare(strict_types=1);

use Ichiloto\Editor\Field\MapEncounters;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Production evidence over disposable copies of Last Legend: the maps that
 * carry hand-authored PHP -- comments, use imports, enum expressions,
 * require-backed scripts, prelude variables -- survive editor edits with
 * everything but the edited node byte-identical, and survive exact
 * restoration byte-for-byte. The real checkout is never written.
 */

/**
 * The sha256 and mtime of every file under the disposable copy's maps.
 *
 * @return array<string, array{0: string, 1: int}>
 */
function mapTreeState(string $root): array
{
    $state = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/assets/Maps', FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $state[substr($file->getPathname(), strlen($root) + 1)] = [
                hash_file('sha256', $file->getPathname()),
                (int) filemtime($file->getPathname()),
            ];
        }
    }

    ksort($state);

    return $state;
}

it('opens every Last Legend map, and merely opening, validating and saving clean writes nothing', function () {
    $root = disposableLastLegend('last-legend-map-integrity-');

    if ($root === null) {
        $this->markTestSkipped('No Last Legend checkout is pinned (ICHILOTO_GAME_SRC).');
    }

    foreach (glob($root . '/assets/Maps/**') ?: [] as $ignored) {
        // glob only forces the copy to exist; the workspace does the walk.
    }

    $before = mapTreeState($root);
    $workspace = ProjectWorkspace::fromProject($root);
    expect(count($workspace->maps))->toBeGreaterThanOrEqual(10);
    $preserved = 0;

    foreach ($workspace->maps as $map) {
        if ($map->dataSourceIssue() === null) {
            $preserved++;
        }

        $map->save();
    }

    expect($preserved)->toBe(count($workspace->maps), 'every committed map data source is preservable')
        ->and(mapTreeState($root))->toBe($before, 'no byte and no mtime moved');
});

it('edits one metadata field on the require-and-enum map and restores it byte-for-byte', function () {
    $root = disposableLastLegend('last-legend-map-edit-');

    if ($root === null) {
        $this->markTestSkipped('No Last Legend checkout is pinned (ICHILOTO_GAME_SRC).');
    }

    $directory = $root . '/assets/Maps/overworld/garden-of-roads';

    if (! is_dir($directory)) {
        $this->markTestSkipped('The baseline holds no garden-of-roads map.');
    }

    $before = mapTreeState($root);
    $map = ProjectMap::fromDirectory($root . '/assets/Maps', $directory);
    expect($map->dataSourceIssue())->toBeNull();

    // The disposable metadata edit.
    $original = $map->getMapField('description');
    $map->setMapField('description', 'A disposable verification edit.');
    $map->save();
    $after = mapTreeState($root);
    $changed = array_keys(array_filter(
        $after,
        static fn(array $entry, string $path): bool => ($before[$path] ?? null) !== $entry,
        ARRAY_FILTER_USE_BOTH,
    ));

    expect($changed)->toBe(['assets/Maps/overworld/garden-of-roads/garden-of-roads.data.php'], 'one file changed');
    $data = (string) file_get_contents($map->dataPath);
    expect($data)->toContain('use Ichiloto\Engine\Core\Enumerations\MovementHeading;')
        ->and($data)->toContain("'script' => require dirname(__DIR__, 3) . '/Events/garden-crosswind-crownback.php',")
        ->and($data)->toContain('[MovementHeading::NORTH->value]');

    // Exact restoration: the file returns to its committed bytes.
    if ($original === null) {
        $next = $map->getEditableData();
        unset($next['description']);
        // Restoring an absent key exactly.
        $map->setMapDataField(['description'], null);
    } else {
        $map->setMapField('description', $original);
    }

    $map->save();
    $restored = mapTreeState($root);

    foreach ($before as $path => [$hash]) {
        expect($restored[$path][0])->toBe($hash, $path . ' is byte-identical again');
    }
});

it('round-trips Garden BGM and encounters through the accepted map runtime metadata model', function () {
    $root = disposableLastLegend('last-legend-map-garden-');

    if ($root === null) {
        $this->markTestSkipped('No Last Legend checkout is pinned (ICHILOTO_GAME_SRC).');
    }

    $directory = $root . '/assets/Maps/overworld/garden-of-roads';

    if (! is_dir($directory)) {
        $this->markTestSkipped('The baseline holds no garden-of-roads map.');
    }

    $map = ProjectMap::fromDirectory($root . '/assets/Maps', $directory);
    $before = (string) file_get_contents($map->dataPath);
    $encounters = MapEncounters::fromMap($map);
    $hadEncounters = $encounters->isDeclared();
    $originalEncounters = $map->getMapDataField([MapEncounters::KEY]);
    $originalBgm = $map->getMapDataField(['bgm']);

    // The accepted model edits the block; the writer lands it surgically.
    $map->setMapDataField(['bgm'], 'overworld-music_moonlit-map-a');
    $withTroop = $encounters->isSupported() && $encounters->rows() !== []
        ? $encounters->withRate($encounters->rate() + 1)
        : MapEncounters::of(null)->withTroopAdded('Bat x 2');
    $map->setMapDataField([MapEncounters::KEY], $withTroop);
    $map->save();
    $edited = (string) file_get_contents($map->dataPath);

    expect($edited)->toContain("'bgm' => 'overworld-music_moonlit-map-a'")
        ->and($edited)->toContain('use Ichiloto\Engine\Core\Enumerations\MovementHeading;')
        ->and($edited)->toContain("require dirname(__DIR__, 3)");

    // And back, exactly.
    $map->setMapDataField(['bgm'], is_string($originalBgm) ? $originalBgm : null);
    $map->setMapDataField([MapEncounters::KEY], $hadEncounters ? $originalEncounters : null);
    $map->save();
    expect((string) file_get_contents($map->dataPath))->toBe($before, 'the committed bytes are back');
});
