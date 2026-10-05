<?php

declare(strict_types=1);

use Ichiloto\Editor\Animations\AnimationConversionEditor;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;

/** Converting a legacy animation to a timeline from the terminal's Database. Synthetic fixtures only. */

const TERMINAL_LEGACY_ANIMATIONS = <<<'PHP'
<?php

return [
    // A spark in the old cell frames.
    [
        'id' => 1,
        'name' => 'Old Spark',
        'position' => 'center',
        'maxFrames' => 3,
        'frames' => [
            ['index' => 1, 'cells' => [['x' => 0, 'y' => 0, 'symbol' => '*', 'color' => 'yellow']]],
            ['index' => 2, 'cells' => [['x' => 1, 'y' => 0, 'symbol' => '+']]],
        ],
        'cues' => [['frame' => 1, 'soundEffect' => 'spark', 'flashColor' => 'white', 'flashDurationFrames' => 2]],
    ],
];
PHP;

/** The terminal editor on the Animations database, the legacy record selected. */
function openLegacyAnimationEditor(): array
{
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Data/animations.php', TERMINAL_LEGACY_ANIMATIONS);
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 160, 'height' => 48]);
    callEditorMethod($editor, 'openDatabaseAtCategory', 'animations');

    return [$editor, $root];
}

/** The conversion pane as one line of prose, whatever its wrapping. */
function readConversionPane(Editor $editor): string
{
    return preg_replace('/\s+/', ' ', implode(' ', callEditorMethod($editor, 'buildAnimationConversionRows')));
}

/** The conversion form the editor holds. */
function terminalConversion(Editor $editor): AnimationConversionEditor
{
    return getEditorProperty($editor, 'animationConversion');
}

it('converts from the terminal: chosen timing, previewed files, one undo step restoring them', function () {
    [$editor, $root] = openLegacyAnimationEditor();
    $timeline = $root . '/assets/Animations/old-spark/old-spark.timeline.php';

    pressKeys($editor, 'T');
    $conversion = terminalConversion($editor);

    expect($conversion->isOpen())->toBeTrue()
        ->and($conversion->getRows())->toBe(['consumer', 'timeline', 'ticks', 'rest', 'flash', 'preview']);

    // A battle paced by its phases, bound as the target effect; then the ticks and rest frame, typed.
    pressKeys($editor, "\033[C", "\033[B", "\033[B", "\033[B");
    typeText($editor, '1');
    pressKeys($editor, "\033[B");
    typeText($editor, '0');
    pressKeys($editor, "\033[B", "\033[B", "\r");

    expect($conversion->getPlan())->not->toBeNull()
        ->and($conversion->getSelectedRow())->toBe('write')
        ->and(implode("\n", callEditorMethod($editor, 'buildAnimationConversionRows')))->toContain("'targetEffect' => 'old-spark'", 'assets/Animations/old-spark/old-spark.timeline.php')
        ->and(file_exists($timeline))->toBeFalse();

    pressKeys($editor, "\r");

    expect($conversion->isOpen())->toBeFalse()
        ->and(file_get_contents($root . '/assets/Data/animations.php'))->toContain("'targetEffect' => 'old-spark'", "'frames' => [", '// A spark in the old cell frames.')
        ->and(new EffectTimelineLibrary($root . '/assets')->load('old-spark', forBattle: true))->not->toBeNull();

    callEditorMethod($editor, 'performUndo');

    expect(file_get_contents($root . '/assets/Data/animations.php'))->toBe(TERMINAL_LEGACY_ANIMATIONS)
        ->and(file_exists($timeline))->toBeFalse();
});

it('forgets a preview when a choice changes, so Write only follows a preview of what is shown', function () {
    [$editor] = openLegacyAnimationEditor();
    pressKeys($editor, 'T', "\033[C", "\033[B", "\033[B", "\033[B");
    typeText($editor, '1');
    pressKeys($editor, "\033[B");
    typeText($editor, '0');
    pressKeys($editor, "\033[B", "\033[B", "\r");
    $conversion = terminalConversion($editor);

    expect($conversion->getRows())->toContain('write');

    // Back up to the ticks and change them.
    pressKeys($editor, "\033[A", "\033[A", "\033[A", "\033[A");
    typeText($editor, '2');

    expect($conversion->getPlan())->toBeNull()
        ->and($conversion->getRows())->not->toContain('write');
});

it('refuses to write a preview the files have moved on from, writing nothing', function () {
    [$editor, $root] = openLegacyAnimationEditor();
    pressKeys($editor, 'T', "\033[C", "\033[B", "\033[B", "\033[B");
    typeText($editor, '1');
    pressKeys($editor, "\033[B");
    typeText($editor, '0');
    pressKeys($editor, "\033[B", "\033[B", "\r");

    file_put_contents($root . '/assets/Data/animations.php', str_replace('// A spark in the old cell frames.', '// Edited elsewhere.', TERMINAL_LEGACY_ANIMATIONS));
    pressKeys($editor, "\r");

    expect(terminalConversion($editor)->isOpen())->toBeTrue()
        ->and(readConversionPane($editor))->toContain('changed outside the animation conversion')
        ->and(file_exists($root . '/assets/Animations/old-spark'))->toBeFalse()
        ->and(file_get_contents($root . '/assets/Data/animations.php'))->toContain('// Edited elsewhere.');
});

it('says why a record cannot be converted, and asks for timing rather than assuming it', function () {
    [$editor, $root] = openLegacyAnimationEditor();
    // Down to Preview with no consumer chosen.
    pressKeys($editor, 'T', "\033[B", "\033[B", "\033[B", "\033[B", "\033[B", "\r");

    expect(readConversionPane($editor))->toContain('Choose who plays the timeline');

    pressKeys($editor, "\033");
    file_put_contents($root . '/assets/Data/animations.php', "<?php\n\nreturn [['id' => 1, 'name' => 'Already Timed', 'targetEffect' => 'burst']];\n");
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    pressKeys($editor, 'T');

    expect(terminalConversion($editor)->isOpen())->toBeFalse()
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('has no legacy frames or cues to convert');
});
