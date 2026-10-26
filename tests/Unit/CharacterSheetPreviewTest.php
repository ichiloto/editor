<?php

declare(strict_types=1);

use Ichiloto\Editor\Field\CharacterSheetPreview;

it('describes four standing direction crops for standard and marked single-character sheets', function (string $asset, int $index, int $width, int $height, int $x, int $y) {
    $root = makeTemporaryProject('sheet-preview-');
    writeTilesetTestPng($root . '/assets/' . $asset, $width, $height);
    $preview = CharacterSheetPreview::describe(['sheet' => $asset, 'index' => $index, 'layer' => 10], $root . '/assets');
    expect($preview)->toBe(['frames' => [
        ['label' => 'South', 'sourceRect' => ['x' => $x, 'y' => $y, 'width' => 4, 'height' => 4]],
        ['label' => 'West', 'sourceRect' => ['x' => $x, 'y' => $y + 4, 'width' => 4, 'height' => 4]],
        ['label' => 'East', 'sourceRect' => ['x' => $x, 'y' => $y + 8, 'width' => 4, 'height' => 4]],
        ['label' => 'North', 'sourceRect' => ['x' => $x, 'y' => $y + 12, 'width' => 4, 'height' => 4]],
    ]]);
})->with([
    ['People.png', 0, 48, 32, 4, 0], ['People.png', 3, 48, 32, 40, 0],
    ['People.png', 4, 48, 32, 4, 16], ['People.png', 7, 48, 32, 40, 16],
    ['$Guard.png', 0, 12, 16, 4, 0], ['!$Door.png', 0, 12, 16, 4, 0], ['$!Door.png', 0, 12, 16, 4, 0],
]);

it('reports malformed sheet data and unavailable owner roots without manufacturing frames', function (mixed $data, bool $hasRoot) {
    $root = makeTemporaryProject('sheet-preview-invalid-');
    writeTilesetTestPng($root . '/assets/People.png', 48, 32);
    $preview = CharacterSheetPreview::describe($data, $hasRoot ? $root . '/assets' : null);
    expect($preview['frames'])->toBe([])->and($preview['issue'])->not->toBeEmpty();
})->with([
    [null, true], [[], true], [['sheet' => 'People.png', 'index' => 8], true],
    [['sheet' => 'People.png', 'index' => '2'], true], [['sheet' => 'People.png', 'index' => []], true],
    [['sheet' => 'People.png', 'layer' => 1000], true], [['sheet' => 'People.png', 'width' => 48], true],
    [['sheet' => '../People.png'], true], [['sheet' => 'People.png'], false],
]);

it('uses runtime filename semantics for character choices rather than inferring from image dimensions', function () {
    expect(CharacterSheetPreview::getIndexOptions('People.png'))->toBe(['0', '1', '2', '3', '4', '5', '6', '7'])
        ->and(CharacterSheetPreview::getIndexOptions('!$Door.png'))->toBe(['0'])
        ->and(CharacterSheetPreview::getIndexOptions('$!Door.png'))->toBe(['0'])
        ->and(CharacterSheetPreview::getIndexOptions('People$Guard.png'))->toHaveCount(8)
        ->and(CharacterSheetPreview::getIndexOptions('../People.png'))->toBe([]);
});
