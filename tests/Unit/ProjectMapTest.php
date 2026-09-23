<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectMap;

/**
 * Loads the fixture map fresh from disk.
 */
function fixtureMap(): ProjectMap
{
  $mapsRoot = fixturePath('sample-project/assets/Maps');

  return ProjectMap::fromDirectory($mapsRoot, $mapsRoot . '/test-map');
}

/**
 * Copies the fixture map into a scratch maps root for save tests.
 */
function scratchMapCopy(): array
{
  $root = rememberTemporaryProject(sys_get_temp_dir() . '/ichiloto-editor-test-' . bin2hex(random_bytes(4)));
  $mapsRoot = $root . '/assets/Maps';
  $directory = $mapsRoot . '/test-map';
  mkdir($directory, 0777, true);

  foreach (['data', 'map', 'event'] as $part) {
    copy(
      fixturePath("sample-project/assets/Maps/test-map/test-map.{$part}.php"),
      "{$directory}/test-map.{$part}.php",
    );
  }

  return [$root, ProjectMap::fromDirectory($mapsRoot, $directory)];
}

/**
 * Removes a scratch tree created by scratchMapCopy().
 */
function removeScratchTree(string $root): void
{
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
  );

  foreach ($iterator as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
  }

  rmdir($root);
}

it('parses the fixture map dimensions and styled tiles', function () {
  $map = fixtureMap();

  expect($map->getWidth())->toBe(12)
    ->and($map->getHeight())->toBe(5)
    ->and($map->getDisplayName())->toBe('Test Map')
    ->and($map->getTileSymbol(0, 0))->toBe('#')
    ->and($map->getTileSymbol(3, 1))->toBe('~')
    ->and($map->isDirty())->toBeFalse();
});

it('reads event markers and definitions', function () {
  $map = fixtureMap();

  expect($map->getEventMarkerAt(5, 1))->toBe('E')
    ->and($map->getEventMarkerAt(0, 0))->toBeNull()
    ->and($map->getPlacedEventMarkers())->toBe(['E'])
    ->and($map->getEventDefinition('E'))->toBeArray()
    ->and($map->getEventDefinition('Z'))->toBeNull()
    ->and($map->getEventBounds('E'))->toBe(['x' => 5, 'y' => 1, 'width' => 1, 'height' => 1]);
});

it('marks the map dirty on tile and event mutations', function () {
  $map = fixtureMap();
  $map->setTileSymbol(1, 1, '@');

  expect($map->isDirty())->toBeTrue()
    ->and($map->getTileSymbol(1, 1))->toBe('@');

  $map = fixtureMap();
  $map->setEventSymbol(2, 2, 'T');

  expect($map->isDirty())->toBeTrue()
    ->and($map->getEventMarkerAt(2, 2))->toBe('T');
});

it('renders a preview with the event overlay merged over tiles', function () {
  $map = fixtureMap();
  $withOverlay = $map->renderPreview(12, 5);
  $withoutOverlay = $map->renderPreview(12, 5, showEventOverlay: false);

  expect($withOverlay[0])->toBe('############')
    ->and($withOverlay[1])->toBe('#  ~~E     #')
    ->and($withoutOverlay[1])->toBe('#  ~~~     #');
});

it('renders an offset preview window', function () {
  $map = fixtureMap();
  $lines = $map->renderPreview(4, 2, offsetX: 3, offsetY: 1);

  expect($lines)->toHaveCount(2)
    ->and($lines[0])->toBe('~~E');
});

it('resizes the grid preserving existing content', function () {
  $map = fixtureMap();
  $map->resize(14, 6);

  expect($map->getWidth())->toBe(14)
    ->and($map->getHeight())->toBe(6)
    ->and($map->getTileSymbol(0, 0))->toBe('#')
    ->and($map->getTileSymbol(13, 5))->toBe(' ');

  $map->resize(6, 3);

  expect($map->getWidth())->toBe(6)
    ->and($map->getHeight())->toBe(3)
    ->and($map->getTileSymbol(3, 1))->toBe('~');
});

it('restores a captured grid snapshot', function () {
  $map = fixtureMap();
  $snapshot = $map->captureGridSnapshot();
  $map->setTileSymbol(1, 1, 'X');
  $map->resize(4, 2);

  $map->restoreGridSnapshot($snapshot);

  expect($map->getWidth())->toBe(12)
    ->and($map->getHeight())->toBe(5)
    ->and($map->getTileSymbol(1, 1))->toBe(' ')
    // The snapshot restored the exact loaded content, and dirty is a fact
    // about content now: back to the baseline is back to pristine.
    ->and($map->isDirty())->toBeFalse();
});

it('round-trips nested event fields', function () {
  $map = fixtureMap();

  expect($map->getEventField('E', ['data', 'loot']))->toBe('S-Potion')
    ->and($map->getEventField('E', ['data', 'missing']))->toBeNull();

  $map->setEventField('E', ['data', 'loot'], 'Elixir');

  expect($map->getEventField('E', ['data', 'loot']))->toBe('Elixir')
    ->and($map->isDirty())->toBeTrue();
});

it('removes event definitions', function () {
  $map = fixtureMap();
  $map->removeEventDefinition('E');

  expect($map->getEventDefinition('E'))->toBeNull()
    ->and($map->isDirty())->toBeTrue();
});

it('rewrites event bounds as a filled rectangle', function () {
  $map = fixtureMap();
  $map->setEventBounds('E', 1, 1, 2, 2);

  expect($map->getEventBounds('E'))->toBe(['x' => 1, 'y' => 1, 'width' => 2, 'height' => 2])
    ->and($map->getEventMarkerAt(5, 1))->toBeNull();
});

it('never treats metadata as a rename: the loaded path is identity', function () {
  $map = fixtureMap();

  expect($map->willMoveOnSave())->toBeFalse();

  // The name and region are display metadata. Deriving a folder from them
  // made every map whose metadata did not slug-match its path a permanent
  // rename target; now the save target is the identity, always.
  $map->setMapField('name', 'Renamed Map');
  $map->setMapField('region', 'Somewhere Entirely Else');

  expect($map->willMoveOnSave())->toBeFalse()
    ->and($map->getSaveTarget()['mapId'])->toBe('test-map');
});

it('saves in place and preserves styled tile formatting', function () {
  [$root, $map] = scratchMapCopy();

  try {
    $map->setTileSymbol(1, 2, '@');
    $savedMapId = $map->save();

    expect($savedMapId)->toBe('test-map')
      ->and($map->isDirty())->toBeFalse();

    $rawMap = (string) file_get_contents($root . '/assets/Maps/test-map/test-map.map.php');

    // The styled water run survives the round trip and the edit lands.
    expect($rawMap)->toContain('<blue>~~~</blue>')
      ->and(ProjectMap::fromDirectory($root . '/assets/Maps', $root . '/assets/Maps/test-map')->getTileSymbol(1, 2))->toBe('@');
  } finally {
    removeScratchTree($root);
  }
});

it('saves a renamed map in place, at its stable path', function () {
  [$root, $map] = scratchMapCopy();

  try {
    $map->setMapField('name', 'Harbor Town');
    $savedMapId = $map->save();

    // Doors transfer to test-map, saves record test-map: an ordinary save
    // must never relocate what everything else points at.
    expect($savedMapId)->toBe('test-map')
      ->and(is_dir($root . '/assets/Maps/test-map'))->toBeTrue()
      ->and(is_dir($root . '/assets/Maps/harbor-town'))->toBeFalse()
      ->and(ProjectMap::fromDirectory($root . '/assets/Maps', $root . '/assets/Maps/test-map')->getDisplayName())
        ->toBe('Harbor Town');
  } finally {
    removeScratchTree($root);
  }
});

it('moves a map only through the explicit operation, which fails closed', function () {
  [$root, $map] = scratchMapCopy();

  try {
    mkdir($root . '/assets/Maps/taken');

    // A collision is rejected outright, and the map keeps its identity.
    expect(fn() => $map->moveTo('taken'))->toThrow(RuntimeException::class)
      ->and($map->mapId)->toBe('test-map');

    $moved = $map->moveTo('harbor/waterfront');

    expect($moved->mapId)->toBe('harbor/waterfront')
      ->and(is_dir($root . '/assets/Maps/harbor/waterfront'))->toBeTrue()
      ->and(is_file($root . '/assets/Maps/harbor/waterfront/waterfront.map.php'))->toBeTrue()
      ->and(is_dir($root . '/assets/Maps/test-map'))->toBeFalse()
      // The relocated map is freshly loaded, so it starts clean.
      ->and($moved->isDirty())->toBeFalse();
  } finally {
    removeScratchTree($root);
  }
});

it('makes an unchanged save a byte-for-byte no-op', function () {
  [$root, $map] = scratchMapCopy();

  try {
    $before = [];

    foreach (glob($root . '/assets/Maps/test-map/*.php') as $file) {
      $before[basename($file)] = hash_file('sha256', $file);
    }

    $map->save();

    foreach ($before as $name => $hash) {
      expect(hash_file('sha256', $root . '/assets/Maps/test-map/' . $name))
        ->toBe($hash, "{$name} was rewritten by a no-change save.");
    }
  } finally {
    removeScratchTree($root);
  }
});

/**
 * Replaces the scratch map's tile grid and reloads it.
 *
 * @param string[] $rows The grid rows, styling tags included.
 * @return array{0: string, 1: ProjectMap} The map file path and the reloaded map.
 */
function styledScratchMap(string $root, array $rows): array
{
  $mapsRoot = $root . '/assets/Maps';
  $mapFile = $mapsRoot . '/test-map/test-map.map.php';
  file_put_contents($mapFile, "<?php\n\nreturn <<<'ICHILOTO_MAP'\n" . implode("\n", $rows) . "\nICHILOTO_MAP;\n");

  return [$mapFile, ProjectMap::fromDirectory($mapsRoot, $mapsRoot . '/test-map')];
}

it('colours every cell of an authored run, not only its first', function () {
  [$root] = scratchMapCopy();

  try {
    [, $map] = styledScratchMap($root, [
      '############',
      '#<fg=gray>####</>      #',
      '#          #',
      '#          #',
      '############',
    ]);

    foreach ([1, 2, 3, 4] as $x) {
      expect($map->getTileColor($x, 1))->toBe('gray', "cell {$x} of the run");
    }

    expect($map->getTileColor(5, 1))->toBeNull()
      ->and(substr_count($map->renderPreview(12, 5)[1], "\033[90m#\033[0m"))->toBe(4);
  } finally {
    removeScratchTree($root);
  }
});

it('rebuilds an edited row as balanced runs and keeps untouched rows byte-identical', function () {
  [$root] = scratchMapCopy();

  try {
    $rows = [
      '############',
      '#<fg=gray>####</>      #',
      '# <fg=gray;options=bold>~~</> #  #',
      '#          #',
      '############',
    ];
    [$mapFile, $map] = styledScratchMap($root, $rows);

    // Recolour a cell inside the run, and erase the run's last cell.
    $map->setTileCell(2, 1, '#', '<fg=red>', '</>');
    $map->setTileCell(4, 1, ' ', '', '');
    $map->save();

    $saved = explode("\n", (string) file_get_contents($mapFile));

    expect($saved[4])->toBe('#<fg=gray>#</><fg=red>#</><fg=gray>#</>       #')
      ->and($saved[5])->toBe($rows[2])
      ->and($saved[3])->toBe($rows[0]);

    $reloaded = ProjectMap::fromDirectory($root . '/assets/Maps', $root . '/assets/Maps/test-map');

    expect([$reloaded->getTileColor(1, 1), $reloaded->getTileColor(2, 1), $reloaded->getTileColor(3, 1)])
      ->toBe(['gray', 'red', 'gray'])
      ->and($reloaded->getTileColor(4, 1))->toBeNull()
      ->and($reloaded->getTileColor(5, 1))->toBeNull();
  } finally {
    removeScratchTree($root);
  }
});

it('writes a row restored to its loaded cells back as its original bytes', function () {
  [$root] = scratchMapCopy();

  try {
    $rows = [
      '############',
      '#<fg=gray>####</>      #',
      '#          #',
      '#          #',
      '############',
    ];
    [$mapFile, $map] = styledScratchMap($root, $rows);
    $original = (string) file_get_contents($mapFile);
    $style = $map->getTileCellStyle(2, 1);

    $map->setTileCell(2, 1, '#', '<fg=red>', '</>');
    $map->setTileCell(2, 1, '#', $style['prefix'], $style['suffix']);
    $map->setTileCell(9, 3, 'x', '', '');
    $map->save();

    $saved = explode("\n", (string) file_get_contents($mapFile));

    expect($saved[4])->toBe($rows[1])
      ->and($saved[6])->toBe('#        x #')
      ->and(str_replace('#        x #', '#          #', (string) file_get_contents($mapFile)))->toBe($original);
  } finally {
    removeScratchTree($root);
  }
});
