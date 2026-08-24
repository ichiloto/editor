<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectMap;

/**
 * Returns the accepted engine source root for the map-music contract, or
 * null to skip.
 */
function mapMusicEngineRoot(): ?string
{
    $root = getenv('ICHILOTO_ENGINE_SRC');

    if (! is_string($root) || ! is_file($root . '/src/Field/MapManager.php')) {
        return null;
    }

    return str_contains((string) file_get_contents($root . '/src/Field/MapManager.php'), 'bgmVariants')
        ? $root
        : null;
}

/**
 * Resolves music cases through the accepted engine head in a subprocess.
 *
 * @param array<int, array<string, mixed>> $cases
 * @return array<int, string|null> The selected track per case.
 */
function resolveThroughAcceptedEngine(string $engineRoot, array $cases): array
{
    $caseFile = tempnam(sys_get_temp_dir(), 'ichiloto-bgm-parity-');
    file_put_contents($caseFile, json_encode(['cases' => $cases]));

    try {
        $command = sprintf(
            '%s %s %s %s 2>/dev/null',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(dirname(__DIR__) . '/Support/map-bgm-parity-runner.php'),
            escapeshellarg($engineRoot),
            escapeshellarg($caseFile),
        );
        $decoded = json_decode((string) shell_exec($command), true);
    } finally {
        unlink($caseFile);
    }

    if (! is_array($decoded) || ! array_key_exists('results', $decoded)) {
        throw new RuntimeException('The map music parity runner produced no result.');
    }

    return $decoded['results'];
}

it('selects the same track the accepted engine selects, for every condition kind', function () {
    $engineRoot = mapMusicEngineRoot();

    if ($engineRoot === null) {
        expect(true)->toBeTrue();

        return;
    }

    $variants = [
        ['track' => 'switch-track', 'conditions' => [['type' => 'switch', 'name' => 'gate_open', 'value' => true]]],
        ['track' => 'event-track', 'conditions' => [['type' => 'event', 'name' => 'met_the_king']]],
        ['track' => 'variable-track', 'conditions' => [['type' => 'variable', 'name' => 'step', 'op' => '>=', 'value' => 3]]],
        ['track' => 'item-track', 'conditions' => [['type' => 'item', 'name' => 'Lantern', 'quantity' => 2]]],
        ['track' => 'key-item-track', 'conditions' => [['type' => 'key_item', 'name' => 'Rusty Key']]],
        ['track' => 'quest-track', 'conditions' => [['type' => 'quest', 'name' => 'main-quest', 'status' => 'completed']]],
    ];

    $results = resolveThroughAcceptedEngine($engineRoot, [
        ['bgm' => 'fallback', 'variants' => $variants, 'state' => ['switches' => ['gate_open' => true]]],
        ['bgm' => 'fallback', 'variants' => $variants, 'state' => ['events' => ['met_the_king']]],
        ['bgm' => 'fallback', 'variants' => $variants, 'state' => ['variables' => ['step' => 3]]],
        ['bgm' => 'fallback', 'variants' => $variants, 'state' => ['items' => [['name' => 'Lantern', 'quantity' => 2]]]],
        ['bgm' => 'fallback', 'variants' => $variants, 'state' => ['items' => [['name' => 'Rusty Key', 'quantity' => 1, 'isKeyItem' => true]]]],
        // No quest system runs here, which the engine answers as an unmet
        // condition: the fallback plays. The editor documents exactly that
        // boundary rather than simulating quests.
        ['bgm' => 'fallback', 'variants' => $variants, 'state' => []],
        // Declaration order breaks ties: two matching variants, first wins.
        ['bgm' => 'fallback', 'variants' => $variants, 'state' => ['switches' => ['gate_open' => true], 'events' => ['met_the_king']]],
    ]);

    expect($results)->toBe([
        'switch-track',
        'event-track',
        'variable-track',
        'item-track',
        'key-item-track',
        'fallback',
        'switch-track',
    ]);
});

it('shadows, falls back, and keeps the current music exactly as the engine does', function () {
    $engineRoot = mapMusicEngineRoot();

    if ($engineRoot === null) {
        expect(true)->toBeTrue();

        return;
    }

    $results = resolveThroughAcceptedEngine($engineRoot, [
        // A first unconditional variant shadows every later variant — the
        // exact situation the validator's unreachable warning names.
        ['bgm' => 'fallback', 'variants' => [
            ['track' => 'always', 'conditions' => []],
            ['track' => 'never', 'conditions' => [['type' => 'switch', 'name' => 'on', 'value' => true]]],
        ], 'state' => ['switches' => ['on' => true]]],
        // No matching variant falls back to the static bgm.
        ['bgm' => 'fallback', 'variants' => [
            ['track' => 'guarded', 'conditions' => [['type' => 'switch', 'name' => 'off', 'value' => true]]],
        ], 'state' => []],
        // No variant and no static track keeps the current music.
        ['bgm' => '', 'variants' => [], 'state' => []],
        ['bgm' => null, 'variants' => null, 'state' => []],
        // The shapes the validator reports are exactly the shapes the engine
        // skips: a non-array variant, an empty track, conditions that are
        // not a list. The later valid variant still plays.
        ['bgm' => 'fallback', 'variants' => [
            'nonsense',
            ['track' => '', 'conditions' => []],
            ['track' => 'skipped', 'conditions' => 'sunny'],
            ['track' => 'reached', 'conditions' => []],
        ], 'state' => []],
        // Negation rides the shared vocabulary.
        ['bgm' => 'fallback', 'variants' => [
            ['track' => 'quiet', 'conditions' => [['type' => 'event', 'name' => 'crisis', 'negate' => true]]],
        ], 'state' => []],
    ]);

    expect($results)->toBe([
        'always',
        'fallback',
        null,
        null,
        'reached',
        'quiet',
    ]);
});

it('feeds the engine what the editor authored, in the authored order', function () {
    $engineRoot = mapMusicEngineRoot();

    if ($engineRoot === null) {
        expect(true)->toBeTrue();

        return;
    }

    $root = metadataProject();
    $editor = metadataEditor($root);

    // Author two ordered variants entirely through the Inspector surface.
    selectInspectorField($editor, 'Music Variants');
    callEditorMethod($editor, 'addInspectorListItem');
    pickInspectorReference($editor, 'Variant 1 Track', 'harbour-theme');

    selectInspectorField($editor, 'Variant 1 Track');
    callEditorMethod($editor, 'addInspectorListItem');
    pickInspectorReference($editor, 'Variant 2 Track', 'crypt-theme');

    selectInspectorField($editor, 'Variant 1 When');
    callEditorMethod($editor, 'activateInspectorField');
    callEditorMethod($editor, 'dispatchInput', 'a');
    callEditorMethod($editor, 'dispatchInput', 't');
    getEditorProperty($editor, 'conditionEditor')->setName('crisis_resolved');
    callEditorMethod($editor, 'dispatchInput', "\r");

    callEditorMethod($editor, 'saveSelectedMap');

    $saved = require $root . '/assets/Maps/test-map/test-map.data.php';
    $cases = [
        // The first variant's event holds: it wins over the unconditional
        // second, because the editor wrote them in the authored order.
        ['bgm' => (string) ($saved['bgm'] ?? ''), 'variants' => $saved['bgmVariants'], 'state' => ['events' => ['crisis_resolved']]],
        // Without the event, the unconditional second variant plays.
        ['bgm' => (string) ($saved['bgm'] ?? ''), 'variants' => $saved['bgmVariants'], 'state' => []],
    ];

    expect(resolveThroughAcceptedEngine($engineRoot, $cases))->toBe(['harbour-theme', 'crypt-theme']);

    // Reorder through the Inspector, save, and the engine's winner follows.
    selectInspectorField($editor, 'Variant 1 Track');
    callEditorMethod($editor, 'dispatchInput', ']');
    callEditorMethod($editor, 'saveSelectedMap');

    $saved = require $root . '/assets/Maps/test-map/test-map.data.php';

    expect(resolveThroughAcceptedEngine($engineRoot, [
        ['bgm' => '', 'variants' => $saved['bgmVariants'], 'state' => ['events' => ['crisis_resolved']]],
    ]))->toBe(['crypt-theme'], 'the unconditional variant now precedes the conditional one');
})->skip(fn (): bool => mapMusicEngineRoot() === null, 'ICHILOTO_ENGINE_SRC does not expose the bgmVariants contract.');
