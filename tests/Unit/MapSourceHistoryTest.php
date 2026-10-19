<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;

it('restores authored data bytes after removal save and value undo without losing the rewrite basis', function (array $path, bool $layerHistory) {
    $root = makeTemporaryProject('editor-source-history-');
    $file = $root . '/assets/Maps/test-map/test-map.data.php';
    $source = <<<'PHP'
    <?php
    $note = 'kept reference';
    return [
      'name' => 'Source history fixture',
      'description' => strtoupper('kept'),
      'note' => $note,
      'region' => 'original',
      'events' => [
        'E' => [
          'class' => 'Ichiloto\Engine\Events\Triggers\ChestEventTrigger',
          'data' => ['lootType' => 'gold', 'loot' => 2],
          'cue' => [
            // Preserve the independently authored condition and expression.
            'symbol' => '!', 'color' => 'bright-blue',
            'kind' => strtolower('STORY'),
          ],
        ],
      ],
    ];
    PHP;
    file_put_contents($file, $source);
    $originalData = (static fn(string $path): array => require $path)($file);
    $map = ProjectWorkspace::fromProject($root)->getMapByIndex(0);
    $old = $map->getMapDataField($path);
    $map->setMapDataField($path, null);
    $map->save();
    expect($map->isDirty())->toBeFalse()
        ->and($map->hasMapDataField($path))->toBeFalse();
    $removed = file_get_contents($file);

    if ($layerHistory) {
        $before = $map->captureLayerSnapshot();
        $map->setMapField('region', 'temporary layer edit');
        $after = $map->captureLayerSnapshot();
        $map->restoreLayerSnapshot($before);
        $map->restoreLayerSnapshot($after);
        $map->restoreLayerSnapshot($before);
        $map->save();
        expect(file_get_contents($file))->toBe($removed);
    }

    $map->setMapDataField($path, $old);
    expect($map->getMapDataField([]))->toBe($originalData);
    $map->save();
    expect(file_get_contents($file))->toBe($source)
        ->and($map->isDirty())->toBeFalse()
        ->and(ProjectWorkspace::fromProject($root)->getMapByIndex(0)->getMapDataField($path))->toBe($old);
    $map->setMapDataField($path, null);
    $map->save();
    expect(file_get_contents($file))->toBe($removed);
    $map->setMapDataField($path, $old);
    $map->setMapField('region', 'later unrelated edit');
    $map->save();
    expect(file_get_contents($file))->toBe(str_replace("'region' => 'original'", "'region' => 'later unrelated edit'", $source));
})->with([
    'computed map field' => [['description']],
    'variable map field' => [['note']],
    'computed event field' => [['events', 'E', 'cue', 'kind']],
    'parent containing expression' => [['events', 'E', 'cue']],
])->with([false, true]);

it('keeps the saved validation checkpoint when replaying layer history with incomplete command drafts', function (array $command) {
    $root = makeTemporaryProject('editor-source-checkpoint-');
    $map = ProjectWorkspace::fromProject($root)->getMapByIndex(0);
    $saved = $map->captureLayerSnapshot();
    $hashes = sourceHashTree($root);
    $map->setMapDataField(['events', 'D'], [
        'class' => \Ichiloto\Engine\Events\Triggers\ScriptEventTrigger::class,
        'data' => ['script' => [$command]],
    ]);
    $draft = $map->captureLayerSnapshot();
    $map->restoreLayerSnapshot($saved);
    $map->restoreLayerSnapshot($draft);
    expect(fn() => $map->save())->toThrow(InvalidArgumentException::class)
        ->and(sourceHashTree($root))->toBe($hashes)
        ->and($map->isDirty())->toBeTrue();
    $map->restoreLayerSnapshot($saved);
    $map->save();
    expect(sourceHashTree($root))->toBe($hashes)->and($map->isDirty())->toBeFalse();
})->with([
    'route draft' => [['type' => 'move_route', 'subject' => 'player', 'waypoints' => []]],
    'inn draft' => [['type' => 'inn', 'presentation' => ['treatment' => 'leader', 'leaders' => ['' => '']]]],
]);
