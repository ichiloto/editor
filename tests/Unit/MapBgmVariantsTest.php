<?php

declare(strict_types=1);

use Ichiloto\Editor\Field\MapBgmVariants;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Editor\Validation\Severity;

/**
 * Conditional map music, authored in the ordinary Map Inspector against the
 * engine's `bgmVariants` contract: an ordered list whose first matching
 * variant selects the track, falling back to the static `bgm`, keeping the
 * current music when neither resolves.
 */

/**
 * Replaces the fixture map's data file with exact authored source.
 */
function writeMetadataMapData(string $root, string $source): void
{
    file_put_contents($root . '/assets/Maps/test-map/test-map.data.php', $source);
}

/**
 * The validation issues that mention bgmVariants, in report order.
 *
 * @return Issue[]
 */
function bgmVariantIssues(string $root): array
{
    $workspace = ProjectWorkspace::fromProject($root);

    return array_values(array_filter(
        new ProjectValidator()->validate($workspace),
        static fn(Issue $issue): bool => str_contains($issue->where . ' ' . $issue->message, 'bgmVariants'),
    ));
}

/**
 * Adds one variant through the Inspector list key and returns the editor.
 */
function addVariantThroughInspector(Ichiloto\Editor\Editor $editor): void
{
    selectInspectorField($editor, 'Music Variants');
    callEditorMethod($editor, 'addInspectorListItem');
}

it('shows absent variants without writing, and validates them silently', function () {
    $root = metadataProject();
    backdateProject($root);
    $before = projectFileState($root);
    $editor = metadataEditor($root);

    expect(inspectorValueOf($editor, 'Music Variants'))->toBe('None');

    callEditorMethod($editor, 'saveSelectedMap');

    expect(bgmVariantIssues($root))->toBe([])
        ->and(projectFileState($root))->toBe($before, 'browsing and validating wrote nothing')
        ->and(array_key_exists('bgmVariants', mapDataOf($editor)))->toBeFalse();
});

it('creates a visibly incomplete first variant, with no silently chosen track', function () {
    $root = metadataProject();
    $editor = metadataEditor($root);

    addVariantThroughInspector($editor);

    expect(mapDataOf($editor)['bgmVariants'])->toBe([['track' => '', 'conditions' => []]])
        ->and(inspectorValueOf($editor, 'Variant 1 Track'))->toBe('(no track yet)')
        ->and(inspectorValueOf($editor, 'Music Variants'))->toBe('1 · first match wins');
});

it('stores the track the picker chose, exactly', function () {
    $root = metadataProject();
    $editor = metadataEditor($root);
    addVariantThroughInspector($editor);

    pickInspectorReference($editor, 'Variant 1 Track', 'crypt-theme');

    expect(mapDataOf($editor)['bgmVariants'][0]['track'])->toBe('crypt-theme')
        ->and(inspectorValueOf($editor, 'Variant 1 Track'))->toBe('crypt-theme');
});

it('authors conditions through the shared editor, hosted in the Inspector', function () {
    $root = metadataProject();
    $editor = metadataEditor($root);
    addVariantThroughInspector($editor);

    selectInspectorField($editor, 'Variant 1 When');
    callEditorMethod($editor, 'activateInspectorField');

    $conditionEditor = getEditorProperty($editor, 'conditionEditor');
    expect($conditionEditor->isOpen())->toBeTrue();

    callEditorMethod($editor, 'dispatchInput', 'a');
    callEditorMethod($editor, 'dispatchInput', 't');
    $conditionEditor->setName('crisis_resolved');
    callEditorMethod($editor, 'dispatchInput', "\r");

    expect(mapDataOf($editor)['bgmVariants'][0]['conditions'])
        ->toBe([['type' => 'event', 'name' => 'crisis_resolved']])
        ->and(getEditorProperty($editor, 'mapConditionField'))->toBeNull()
        ->and(inspectorValueOf($editor, 'Variant 1 When'))->toBe('event:crisis_resolved');

    // Esc leaves the conditions alone and releases the hosting.
    selectInspectorField($editor, 'Variant 1 When');
    callEditorMethod($editor, 'activateInspectorField');
    callEditorMethod($editor, 'dispatchInput', 'a');
    callEditorMethod($editor, 'dispatchInput', "\033");

    expect(mapDataOf($editor)['bgmVariants'][0]['conditions'])
        ->toBe([['type' => 'event', 'name' => 'crisis_resolved']])
        ->and(getEditorProperty($editor, 'mapConditionField'))->toBeNull();
});

it('keeps declaration order through save and reopen, and reorders undoably', function () {
    $root = metadataProject();
    $editor = metadataEditor($root);

    addVariantThroughInspector($editor);
    pickInspectorReference($editor, 'Variant 1 Track', 'harbour-theme');
    addVariantThroughInspector($editor);
    pickInspectorReference($editor, 'Variant 2 Track', 'crypt-theme');

    callEditorMethod($editor, 'saveSelectedMap');

    $saved = require $root . '/assets/Maps/test-map/test-map.data.php';
    expect(array_column($saved['bgmVariants'], 'track'))->toBe(['harbour-theme', 'crypt-theme']);

    // Reorder from the first variant's row: declaration order is runtime
    // behavior, so the move is a real, undoable edit.
    selectInspectorField($editor, 'Variant 1 Track');
    callEditorMethod($editor, 'dispatchInput', ']');

    expect(array_column(mapDataOf($editor)['bgmVariants'], 'track'))->toBe(['crypt-theme', 'harbour-theme']);

    // The cursor followed the moved variant.
    [, $index] = inspectorFieldNamed($editor, 'Variant 2 Track');
    expect(getEditorProperty($editor, 'selectedInspectorFieldIndex'))->toBe($index);

    callEditorMethod($editor, 'saveSelectedMap');
    $saved = require $root . '/assets/Maps/test-map/test-map.data.php';
    expect(array_column($saved['bgmVariants'], 'track'))->toBe(['crypt-theme', 'harbour-theme']);

    $reopened = ProjectMap::fromDirectory($root . '/assets/Maps', $root . '/assets/Maps/test-map');
    expect(array_column(MapBgmVariants::fromMap($reopened)->rows(), 'track'))->toBe(['crypt-theme', 'harbour-theme']);

    callEditorMethod($editor, 'dispatchInput', "\x1a");
    expect(array_column(mapDataOf($editor)['bgmVariants'], 'track'))->toBe(['harbour-theme', 'crypt-theme']);

    callEditorMethod($editor, 'dispatchInput', "\x19");
    expect(array_column(mapDataOf($editor)['bgmVariants'], 'track'))->toBe(['crypt-theme', 'harbour-theme']);

    // Save All saves the map like any other dirty asset.
    callEditorMethod($editor, 'dispatchInput', "\x1a");
    callEditorMethod($editor, 'saveAllAssets');
    $saved = require $root . '/assets/Maps/test-map/test-map.data.php';
    expect(array_column($saved['bgmVariants'], 'track'))->toBe(['harbour-theme', 'crypt-theme']);
});

it('keeps a missing or unknown track visible and diagnosable', function () {
    $root = metadataProject();
    $editor = metadataEditor($root);
    addVariantThroughInspector($editor);
    callEditorMethod($editor, 'saveSelectedMap');

    expect(inspectorValueOf($editor, 'Variant 1 Track'))->toBe('(no track yet)');

    $messages = array_map(static fn(Issue $issue): string => $issue->message, bgmVariantIssues($root));
    expect($messages)->toContain('Its track is empty.');

    // A track the project does not have stays visible and says so.
    writeMetadataMapData($root, <<<'PHP'
    <?php

    return [
      'name' => 'Test Map',
      'bgmVariants' => [
        ['track' => 'lost-theme', 'conditions' => []],
      ],
    ];
    PHP);

    $editor = metadataEditor($root);
    expect(inspectorValueOf($editor, 'Variant 1 Track'))->toBe('lost-theme · not in assets/Audio/BGM');

    $issues = bgmVariantIssues($root);
    expect(array_map(static fn(Issue $issue): string => $issue->message, $issues))
        ->toContain('It plays the track "lost-theme", which the project has no file for.');
});

it('reports every shape the engine would silently skip', function () {
    $root = metadataProject();
    writeMetadataMapData($root, <<<'PHP'
    <?php

    return [
      'name' => 'Test Map',
      'bgmVariants' => [
        'nonsense',
        ['track' => 42, 'conditions' => []],
        ['track' => 'harbour-theme', 'conditions' => 'sunny'],
        ['track' => 'harbour-theme', 'conditions' => [['type' => 'weather', 'name' => 'rain']]],
        ['conditions' => []],
      ],
    ];
    PHP);

    $messages = array_map(static fn(Issue $issue): string => $issue->message, bgmVariantIssues($root));

    expect($messages)->toContain('It is string, not an array.')
        ->and($messages)->toContain('Its track is int, not a track name.')
        ->and($messages)->toContain('Its conditions are string, not a list.')
        ->and($messages)->toContain('It uses the unknown condition type "weather".')
        ->and($messages)->toContain('It has no track.');

    // A keyed block cannot be an ordered list at all.
    writeMetadataMapData($root, <<<'PHP'
    <?php

    return [
      'name' => 'Test Map',
      'bgmVariants' => ['quiet' => ['track' => 'harbour-theme']],
    ];
    PHP);

    $messages = array_map(static fn(Issue $issue): string => $issue->message, bgmVariantIssues($root));
    expect($messages)->toContain('Its bgmVariants block is a keyed array, not an ordered list.');
});

it('warns when an unconditional variant makes later variants unreachable', function () {
    $root = metadataProject();
    writeMetadataMapData($root, <<<'PHP'
    <?php

    return [
      'name' => 'Test Map',
      'bgmVariants' => [
        ['track' => 'harbour-theme', 'conditions' => []],
        ['track' => 'crypt-theme', 'conditions' => [['type' => 'switch', 'name' => 'deep']]],
        ['track' => 'crypt-theme'],
      ],
    ];
    PHP);

    $issues = bgmVariantIssues($root);
    $warnings = array_values(array_filter($issues, static fn(Issue $issue): bool => $issue->severity === Severity::WARNING));

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->message)->toBe('bgmVariants variant 1 always matches, so variants 2, 3 can never play.');

    // An unconditional variant with no track shadows nothing: the engine
    // skips it, so no warning pretends otherwise.
    writeMetadataMapData($root, <<<'PHP'
    <?php

    return [
      'name' => 'Test Map',
      'bgmVariants' => [
        ['track' => '', 'conditions' => []],
        ['track' => 'crypt-theme', 'conditions' => [['type' => 'switch', 'name' => 'deep']]],
      ],
    ];
    PHP);

    $warnings = array_values(array_filter(
        bgmVariantIssues($root),
        static fn(Issue $issue): bool => $issue->severity === Severity::WARNING,
    ));
    expect($warnings)->toBe([]);
});

it('preserves unknown variant keys, authored bytes and comments around an edit', function () {
    $root = metadataProject();
    writeMetadataMapData($root, <<<'PHP'
    <?php

    return [
      'name' => 'Test Map',
      'bgm' => 'harbour-theme',
      'bgmVariants' => [
        [
          // Hand-tuned crossfade for the reveal.
          'track' => 'harbour-theme',
          'fadeMs' => 250,
          'conditions' => [['type' => 'event', 'name' => 'revealed']],
        ],
      ],
      'futureLighting' => ['mode' => 'dusk'],
    ];
    PHP);

    $editor = metadataEditor($root);
    pickInspectorReference($editor, 'Variant 1 Track', 'crypt-theme');
    callEditorMethod($editor, 'saveSelectedMap');

    $source = (string) file_get_contents($root . '/assets/Maps/test-map/test-map.data.php');
    $saved = require $root . '/assets/Maps/test-map/test-map.data.php';

    expect($saved['bgmVariants'][0])->toBe([
        'track' => 'crypt-theme',
        'fadeMs' => 250,
        'conditions' => [['type' => 'event', 'name' => 'revealed']],
    ])->and($source)->toContain('// Hand-tuned crossfade for the reveal.')
        ->and($source)->toContain("'futureLighting' => ['mode' => 'dusk']");
});

it('refuses an opaque variants shape honestly, changing nothing', function () {
    $root = metadataProject();
    writeMetadataMapData($root, <<<'PHP'
    <?php

    return [
      'name' => 'Test Map',
      'bgmVariants' => ['quiet' => ['track' => 'harbour-theme']],
    ];
    PHP);

    backdateProject($root);
    $before = projectFileState($root);
    $editor = metadataEditor($root);

    expect(inspectorValueOf($editor, 'Music Variants'))->toBe('read-only')
        ->and(inspectorValueOf($editor, '! Read-only'))->toBe('the bgmVariants block is a keyed array, not an ordered list');

    // The list keys refuse by name rather than editing what they cannot hold.
    selectInspectorField($editor, 'Music Variants');
    callEditorMethod($editor, 'addInspectorListItem');

    expect(getEditorProperty($editor, 'statusMessage'))->toContain('read-only here')
        ->and(callEditorMethod($editor, 'getSelectedMap')->isDirty())->toBeFalse()
        ->and(projectFileState($root))->toBe($before);

    // A variant's conditions the shared editor cannot carry are refused by
    // name while the track stays editable.
    writeMetadataMapData($root, <<<'PHP'
    <?php

    return [
      'name' => 'Test Map',
      'bgmVariants' => [
        [
          'track' => 'harbour-theme',
          'conditions' => [['type' => 'event', 'name' => 'seen', 'grace' => 3]],
        ],
      ],
    ];
    PHP);

    $editor = metadataEditor($root);
    expect(inspectorValueOf($editor, 'Variant 1 When'))
        ->toBe('! condition 1 carries "grace", which the condition editor would drop');

    $field = selectInspectorField($editor, 'Variant 1 When');
    expect($field['mapConditions'] ?? false)->toBeFalse();

    callEditorMethod($editor, 'activateInspectorField');
    expect(getEditorProperty($editor, 'conditionEditor')->isOpen())->toBeFalse();
});

it('keeps every byte, timestamp, dirty flag and history entry when the write is refused', function () {
    $root = metadataProject();
    $editor = metadataEditor($root);
    addVariantThroughInspector($editor);
    pickInspectorReference($editor, 'Variant 1 Track', 'harbour-theme');
    backdateProject($root);
    $before = projectFileState($root);
    $map = callEditorMethod($editor, 'getSelectedMap');
    chmod($map->directory, 0o555);

    $failed = false;

    try {
        $map->save();
    } catch (Throwable) {
        $failed = true;
    }

    chmod($map->directory, 0o777);

    expect($failed)->toBeTrue('a folder that cannot be written refuses the save')
        ->and(projectFileState($root))->toBe($before, 'no byte and no timestamp moved')
        ->and(glob($map->directory . '/*.tmp*'))->toBe([])
        ->and($map->isDirty())->toBeTrue('the work is still there to save')
        ->and(getEditorProperty($editor, 'history')->canUndo())->toBeTrue('the edits are still undoable');

    // The intact checkpoint saves cleanly once the refusal clears.
    $map->save();
    $saved = require $root . '/assets/Maps/test-map/test-map.data.php';
    expect($saved['bgmVariants'][0]['track'])->toBe('harbour-theme');
});

it('touches only the edited map\'s data file, and removes the key with the last variant', function () {
    $root = metadataProject();
    $editor = metadataEditor($root);
    addVariantThroughInspector($editor);
    pickInspectorReference($editor, 'Variant 1 Track', 'crypt-theme');
    backdateProject($root);
    $before = projectFileState($root);

    callEditorMethod($editor, 'saveSelectedMap');

    $after = projectFileState($root);
    $changed = array_keys(array_filter(
        $after,
        static fn(array $state, string $path): bool => ($before[$path] ?? null) !== $state,
        ARRAY_FILTER_USE_BOTH,
    ));

    expect($changed)->toBe(['assets/Maps/test-map/test-map.data.php']);

    // Removing the only variant takes the key with it.
    selectInspectorField($editor, 'Variant 1 Track');
    callEditorMethod($editor, 'removeInspectorListItem');

    expect(array_key_exists('bgmVariants', mapDataOf($editor)))->toBeFalse()
        ->and(inspectorValueOf($editor, 'Music Variants'))->toBe('None');

    callEditorMethod($editor, 'saveSelectedMap');
    $source = (string) file_get_contents($root . '/assets/Maps/test-map/test-map.data.php');
    expect($source)->not->toContain('bgmVariants');
});

it('reads what the engine will make of a list without changing it', function () {
    $absent = MapBgmVariants::of(null);
    expect($absent->isDeclared())->toBeFalse()
        ->and($absent->summary())->toBe('None')
        ->and($absent->isSupported())->toBeTrue();

    $variants = MapBgmVariants::of([
        ['track' => 'a', 'conditions' => [['type' => 'switch', 'name' => 's']], 'fadeMs' => 100],
        ['track' => '', 'conditions' => []],
    ]);

    expect($variants->count())->toBe(2)
        ->and($variants->trackAt(0))->toBe('a')
        ->and($variants->trackAt(1))->toBe('')
        ->and($variants->conditionsIssueAt(0))->toBeNull()
        ->and($variants->summary())->toBe('2 · first match wins')
        ->and($variants->withVariantMovedTo(0, 1))->toBe([
            ['track' => '', 'conditions' => []],
            ['track' => 'a', 'conditions' => [['type' => 'switch', 'name' => 's']], 'fadeMs' => 100],
        ])
        ->and($variants->withVariantMovedTo(1, 2))->toBeNull()
        ->and($variants->withVariantRemovedAt(0))->toBe([['track' => '', 'conditions' => []]])
        ->and(MapBgmVariants::of([['track' => 'a']])->withVariantRemovedAt(0))->toBeNull();

    expect(MapBgmVariants::of('nonsense')->isSupported())->toBeFalse()
        ->and(MapBgmVariants::of(['x' => []])->unsupportedReason())->toBe('the bgmVariants block is a keyed array, not an ordered list')
        ->and(MapBgmVariants::of([['track' => 'a'], 'plain'])->unsupportedReason())->toBe('variant 2 is string, not an array');
});

// -- Production evidence ------------------------------------------------------

/**
 * The sha256 and mtime of every map file under a disposable Last Legend.
 *
 * @return array<string, array{0: string, 1: int}>
 */
function bgmMapTreeState(string $root): array
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

it('round-trips the Last Legend maps already using bgmVariants byte-exactly', function () {
    $root = disposableLastLegend('last-legend-bgm-variants-');

    if ($root === null) {
        $this->markTestSkipped('No Last Legend checkout is pinned (ICHILOTO_GAME_SRC).');
    }

    $before = bgmMapTreeState($root);
    $workspace = ProjectWorkspace::fromProject($root);
    $carrying = [];

    foreach ($workspace->maps as $map) {
        $variants = MapBgmVariants::fromMap($map);

        if (! $variants->isDeclared()) {
            continue;
        }

        $carrying[] = $map->mapId;

        // Every production variant reads as editable rows with a real track
        // and conditions the shared editor can carry.
        expect($variants->isSupported())->toBeTrue($map->mapId)
            ->and($variants->count())->toBeGreaterThanOrEqual(1);

        foreach (array_keys($variants->rows()) as $index) {
            expect((string) $variants->trackAt($index))->not->toBe('', $map->mapId)
                ->and($variants->conditionsIssueAt($index))->toBeNull($map->mapId);
        }

        // A no-op pass over the map writes nothing.
        $map->save();
    }

    expect(count($carrying))->toBeGreaterThanOrEqual(3);

    // The new validation reports nothing on the production maps.
    $issues = array_filter(
        new ProjectValidator()->validate($workspace),
        static fn(Issue $issue): bool => str_contains($issue->where . ' ' . $issue->message, 'bgmVariants'),
    );

    expect(array_values($issues))->toBe([])
        ->and(bgmMapTreeState($root))->toBe($before, 'no byte and no timestamp moved');
});
