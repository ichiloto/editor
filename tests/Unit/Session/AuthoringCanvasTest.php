<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;

/**
 * The editing canvas draws a map as it stands in memory, unsaved edits
 * included, through the Engine's frame composer and its own renderer client,
 * as the field draws it: a sign there asks the window to lay out its label,
 * and once it is measured its board joins the frame. Synthetic project only.
 */

/** A graphics project with a wooden sign placed on the test map, its board and a text role. */
function createCanvasSignProject(): array
{
    $root = mapGraphicsProject();
    // Tall enough for a 10 px label inside its 2 px top and bottom padding.
    writeTilesetTestPng($root . '/assets/Graphics/Boards/Wood.png', 36, 24);
    is_dir($root . '/assets/Fonts') || mkdir($root . '/assets/Fonts', 0o777, true);
    file_put_contents($root . '/assets/Fonts/Open.ttf', 'synthetic font');
    is_dir($root . '/assets/Data/Presentation') || mkdir($root . '/assets/Data/Presentation', 0o777, true);
    file_put_contents($root . '/assets/Data/Presentation/field.php', "<?php\n\nreturn ['resources' => ['wood-sign' => ['name' => 'Wood Sign',"
        . " 'pivot' => ['x' => 0.5, 'y' => 1.0], 'sign' => ['asset' => 'Graphics/Boards/Wood.png', 'fontRole' => 'sign', 'left' => 8,"
        . " 'right' => 8, 'density' => 1, 'padding' => [4, 2, 4, 2], 'minimumWidth' => 24, 'maximumWidth' => 160]]]];\n");
    file_put_contents($root . '/assets/Data/Presentation/text.php', "<?php\n\nreturn ['schema' => 'ichiloto.text/1', 'roles' => ['sign' => ["
        . "'fonts' => [['asset' => 'Fonts/Open.ttf']], 'fontSize' => 12, 'foreground' => ['kind' => 'rgb', 'r' => 230, 'g' => 215, 'b' => 170]]]];\n");
    $session = EditorSession::open($root);
    $session->createWorldObject('test-map', $session->readMap('test-map')['revision'], 'inn-sign', 1, 1, 'wood-sign', 'Inn');

    return [$root, $session];
}

/** The window's measurement of a label it was asked to lay out. */
function canvasTextPrepared(string $requestId): string
{
    return json_encode(['protocol' => 2, 'type' => 'text_prepared', 'requestId' => $requestId, 'layoutId' => 'layout-inn',
        'metrics' => ['advance' => 18, 'baseline' => 9, 'lineBounds' => ['x' => 0, 'y' => 0, 'width' => 18, 'height' => 12],
            'inkBounds' => ['x' => 0, 'y' => 1, 'width' => 18, 'height' => 10], 'paintBounds' => ['x' => 0, 'y' => 1, 'width' => 18, 'height' => 10]]],
        JSON_THROW_ON_ERROR);
}

it('draws the map only once the window is ready, under one session for the open map', function () {
    [, $session] = createCanvasSignProject();
    $first = $session->exchangeAuthoringCanvas('test-map', []);

    expect(findCanvasMessages($first, 'frame'))->toBe([])
        ->and($first)->toMatchArray(['map' => 'test-map', 'grid' => ['columns' => 4, 'rows' => 2, 'cellWidth' => 48, 'cellHeight' => 48]]);
    $ready = $session->exchangeAuthoringCanvas('test-map', [canvasWindowReady()], sessionId: $first['sessionId']);

    expect($ready['sessionId'])->toBe($first['sessionId'])
        ->and(findCanvasMessages($ready, 'frame'))->not->toBe([])
        ->and($ready['assetPaths'])->toContain('Graphics/Boards/Wood.png');
    $session->closeAuthoringCanvas('test-map');
});

it('asks the window to lay out a sign\'s label, and draws its board once the label is measured', function () {
    [, $session] = createCanvasSignProject();
    $first = $session->exchangeAuthoringCanvas('test-map', []);
    $ready = $session->exchangeAuthoringCanvas('test-map', [canvasWindowReady()], sessionId: $first['sessionId']);
    $request = findCanvasMessages($ready, 'prepare_text')[0] ?? null;

    expect($request['payload']['spec']['text'] ?? null)->toBe('Inn')
        ->and(json_encode($ready['messages']))->not->toContain('world_canvas');
    $measured = $session->exchangeAuthoringCanvas('test-map', [canvasTextPrepared($request['payload']['requestId'])], sessionId: $first['sessionId']);

    expect(json_encode($measured['messages']))->toContain('world-object:test-map:inn-sign')->toContain('layout-inn');
    $session->closeAuthoringCanvas('test-map');
});

it('sends nothing while the map is unchanged, and the edit once it changes', function () {
    [, $session] = createCanvasSignProject();
    $first = $session->exchangeAuthoringCanvas('test-map', []);
    $ready = $session->exchangeAuthoringCanvas('test-map', [canvasWindowReady()], sessionId: $first['sessionId']);
    $request = findCanvasMessages($ready, 'prepare_text')[0];
    $measured = $session->exchangeAuthoringCanvas('test-map', [...acknowledgeCanvasFrames($ready),
        canvasTextPrepared($request['payload']['requestId'])], sessionId: $first['sessionId']);
    $settled = $session->exchangeAuthoringCanvas('test-map', acknowledgeCanvasFrames($measured), sessionId: $first['sessionId']);

    expect(findCanvasMessages($settled, 'frame'))->toBe([]);
    $map = $session->readMap('test-map');
    $session->paint('test-map', $map['revision'], $map['baseLayer'], [[0, 0]], '#');
    $edited = $session->exchangeAuthoringCanvas('test-map', [], sessionId: $first['sessionId']);

    expect(findCanvasMessages($edited, 'frame'))->toHaveCount(1)
        ->and($edited['revision'])->toBeGreaterThan($map['revision']);
    $session->closeAuthoringCanvas('test-map');
});

it('ignores answers meant for an earlier canvas, and releases a closed one', function () {
    [, $session] = createCanvasSignProject();
    $first = $session->exchangeAuthoringCanvas('test-map', []);

    // A READY for a session this canvas is not is not applied: nothing is drawn.
    expect(findCanvasMessages($session->exchangeAuthoringCanvas('test-map', [canvasWindowReady()], sessionId: 'stale'), 'frame'))->toBe([])
        ->and($session->closeAuthoringCanvas('test-map'))->toBe(['closed' => true])
        ->and($session->closeAuthoringCanvas('test-map'))->toBe(['closed' => false])
        ->and($session->exchangeAuthoringCanvas('test-map', [])['sessionId'])->not->toBe($first['sessionId']);
    $session->closeAuthoringCanvas('test-map');
});
