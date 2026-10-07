<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\IO\Console\TerminalText;

/**
 * An effect is previewed on the game's field as a cinematic's field animation
 * presents it: in an isolated field where a new game starts, at the player,
 * held at the timeline's playhead. Synthetic effect project only.
 */

/** The effect project with a looping image mote, and the player starting on the harbour's open quay. */
function effectFieldProject(): string
{
    $root = effectProject();
    writeTilesetTestPng($root . '/assets/Graphics/Effects/mote.png', 8, 2);
    mkdir($root . '/assets/Animations/mote', 0o777, true);
    file_put_contents($root . '/assets/Animations/mote/mote.timeline.php', '<?php return ' . var_export([
        'fps' => 4, 'lengthFrames' => 4, 'playback' => 'loop',
        'tracks' => [['id' => 'motes', 'type' => 'image', 'asset' => 'Graphics/Effects/mote.png',
            'sheet' => ['columns' => 4, 'rows' => 1], 'cells' => ['width' => 1, 'height' => 1], 'depth' => 'front',
            'keyframes' => array_map(static fn(int $frame): array => ['frame' => $frame, 'sourceFrame' => $frame], range(0, 3))]]], true) . ';');
    file_put_contents($root . '/assets/Data/system.php', "<?php\n\nreturn " . var_export(['startingPositions' => ['player' => [
        'destinationMap' => 'harbour', 'spawnPoint' => ['x' => 4, 'y' => 4], 'spawnSprite' => ['^']]]], true) . ";\n");

    return $root;
}

function effectIndex(EditorSession $session, string $id): int
{
    return array_search($id, $session->listDatabaseRecords('cutscenes/effect')['records'], true);
}

/** @param list<string> $lines */
function plainField(array $lines): string
{
    return implode("\n", array_map(TerminalText::stripAnsi(...), $lines));
}

it('shows an effect at the player where a new game starts, held at the playhead\'s frame', function () {
    $session = EditorSession::open(effectFieldProject());
    $index = effectIndex($session, 'ember-spark');

    try {
        $first = $session->showEffectOnField($index, 0, 40, 12);
        expect($first)->toMatchArray(['frame' => 0, 'totalFrames' => 6, 'fps' => 10, 'mapId' => 'harbour', 'issue' => null])
            ->and(plainField($first['lines']))->toContain('*')->not->toContain('+');
        // Another frame of the same effect is the same field, the effect moved to that frame.
        $later = $session->showEffectOnField($index, 4, 40, 12);
        expect($later['frame'])->toBe(4)
            ->and(plainField($later['lines']))->toContain('+')->not->toContain('*')
            // A frame past the end is the last one.
            ->and($session->showEffectOnField($index, 99, 40, 12)['frame'])->toBe(5);
    } finally {
        $session->closeEffectField();
    }
});

it('gives the editor window the field with the effect\'s own graphical art', function () {
    $session = EditorSession::open(effectFieldProject());
    $session->showEffectOnField(effectIndex($session, 'mote'), 1, 40, 12);

    try {
        $answer = $session->exchangeEffectFieldScene([previewWindowReady()]);
        expect($answer['grid'])->toBe(['columns' => 40, 'rows' => 12, 'cellWidth' => 10, 'cellHeight' => 20])
            ->and(json_encode($answer['messages']))->toContain('Graphics\/Effects\/mote.png');
        expect($session->closeEffectField())->toBe(['closed' => true])
            ->and(fn() => $session->exchangeEffectFieldScene([]))->toThrow(SessionRefusal::class);
    } finally {
        $session->closeEffectField();
    }
});

it('keeps one isolated field open: an effect on the field ends a cinematic preview, and the reverse', function () {
    $session = EditorSession::open(effectFieldProject());
    $session->startCinematicPreview(0, 40, 12);

    try {
        $session->showEffectOnField(effectIndex($session, 'ember-spark'), 0, 40, 12);
        expect(fn() => $session->exchangeCinematicScene([]))->toThrow(SessionRefusal::class);
        $session->startCinematicPreview(0, 40, 12);
        expect($session->closeEffectField())->toBe(['closed' => false]);
    } finally {
        $session->stopCinematicPreview();
        $session->closeEffectField();
    }
});

it('refuses to show an effect on the field when no new game start names a map', function () {
    $session = EditorSession::open(effectProject());

    expect(fn() => $session->showEffectOnField(effectIndex($session, 'ember-spark'), 0, 40, 12))
        ->toThrow(SessionRefusal::class, 'System names no player starting map');
});
