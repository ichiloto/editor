<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;

/**
 * A preview field lays out a sign's label through the editor window, over the
 * preview's own renderer client, as the game's field does through its own
 * renderer. Synthetic project, board and font only.
 */

/** The effect project with a wooden sign on the harbour quay, its board, and a text role in the project's own font. */
function signPreviewProject(): string
{
    $root = effectProject();
    file_put_contents($root . '/assets/Data/system.php', "<?php\n\nreturn " . var_export(['startingPositions' => ['player' => [
        'destinationMap' => 'harbour', 'spawnPoint' => ['x' => 4, 'y' => 4], 'spawnSprite' => ['^']]]], true) . ";\n");
    writeTilesetTestPng($root . '/assets/Graphics/Boards/Wood.png', 36, 12);
    is_dir($root . '/assets/Fonts') || mkdir($root . '/assets/Fonts', 0o777, true);
    file_put_contents($root . '/assets/Fonts/Open.ttf', 'synthetic font');
    is_dir($root . '/assets/Data/Presentation') || mkdir($root . '/assets/Data/Presentation', 0o777, true);
    file_put_contents($root . '/assets/Data/Presentation/field.php', "<?php\n\nreturn ['resources' => ['wood-sign' => ['name' => 'Wood Sign',"
        . " 'pivot' => ['x' => 0.5, 'y' => 1.0], 'sign' => ['asset' => 'Graphics/Boards/Wood.png', 'fontRole' => 'sign', 'left' => 8,"
        . " 'right' => 8, 'density' => 1, 'padding' => [4, 2, 4, 2], 'minimumWidth' => 24, 'maximumWidth' => 160]]]];\n");
    file_put_contents($root . '/assets/Data/Presentation/text.php', "<?php\n\nreturn ['schema' => 'ichiloto.text/1', 'roles' => ['sign' => ["
        . "'fonts' => [['asset' => 'Fonts/Open.ttf']], 'fontSize' => 12, 'foreground' => ['kind' => 'rgb', 'r' => 230, 'g' => 215, 'b' => 170]]]];\n");
    $data = $root . '/assets/Maps/harbour/harbour.data.php';
    file_put_contents($data, str_replace("'events' => [],", "'events' => [], 'worldObjects' => [['id' => 'inn-sign', 'resource' => 'wood-sign',"
        . " 'anchor' => ['x' => 6, 'y' => 1], 'label' => ['message' => 'signs.inn-sign', 'default' => 'Inn']]],", (string) file_get_contents($data)));

    return $root;
}

it('asks the editor window to lay out a sign\'s label, through the preview\'s own client', function () {
    $session = EditorSession::open(signPreviewProject());
    $index = array_search('ember-spark', $session->listDatabaseRecords('cutscenes/effect')['records'], true);
    $shown = $session->showEffectOnField($index, 0, 40, 12);
    $ready = json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => [...json_decode(previewWindowReady(), true)['capabilities'],
        RendererSessionConfig::TEXT_LAYOUT, RendererSessionConfig::CANVAS_LABELS, RendererSessionConfig::WORLD_CANVAS, RendererSessionConfig::SPRITE_PIVOT]]);

    try {
        $messages = $session->exchangeEffectFieldScene([$ready], $shown['sessionId'])['messages'];
        $requests = array_values(array_filter($messages, static fn(array $message): bool => $message['type'] === 'prepare_text'));
        expect($requests)->not->toBe([])
            ->and($requests[0]['payload']['spec']['text'])->toBe('Inn')
            ->and($requests[0]['payload']['spec']['fonts'][0]['asset'])->toBe('Fonts/Open.ttf');
    } finally {
        $session->closeEffectField();
    }
});
