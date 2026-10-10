<?php

declare(strict_types=1);

use Ichiloto\Editor\Animations\AnimationConversionEditor;
use Ichiloto\Engine\Animations\ActionAnimationResolver;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;

/**
 * Converting a legacy animation to a timeline from the terminal's Database,
 * read from the pane as the window fits it at real terminal sizes.
 * Synthetic fixtures only.
 */

/** A legacy record long enough to scroll: thirty frames, and roles enough to wrap its facts. */
function longLegacyAnimations(): string
{
    $frames = [];
    foreach (range(1, 30) as $frame) {
        $frames[] = sprintf("            ['index' => %d, 'cells' => [['x' => %d, 'y' => 0, 'symbol' => '*']]],", $frame, $frame % 5);
    }
    // Every role the Engine supports: a long list of battle consumers to wrap.
    $roles = implode(', ', array_map(static fn(string $role): string => var_export($role, true), ActionAnimationResolver::getSupportedRoles()));

    return "<?php\n\nreturn [\n    // A spark in the old cell frames.\n    [\n                        // preserved nested source\n        'id' => 1,\n        'name' => 'Old Spark',\n"
        . "        'position' => 'center',\n        'maxFrames' => 30,\n        'roles' => [{$roles}],\n        'frames' => [\n"
        . implode("\n", $frames) . "\n        ],\n"
        . "        'cues' => [['frame' => 1, 'soundEffect' => 'spark', 'flashColor' => 'white', 'flashDurationFrames' => 2]],\n    ],\n];\n";
}

/** The terminal editor at a size, on the Animations database with the legacy record selected. */
function openLegacyAnimationEditor(int $width = 160, int $height = 48): array
{
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Data/animations.php', longLegacyAnimations());
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => $width, 'height' => $height]);
    callEditorMethod($editor, 'openDatabaseAtCategory', 'animations');

    return [$editor, $root];
}

function terminalConversion(Editor $editor): AnimationConversionEditor
{
    return getEditorProperty($editor, 'animationConversion');
}

/** @return list<string> The settings pane as the window draws it: fitted to its width and height. */
function visibleConversionPane(Editor $editor): array
{
    $metrics = callEditorMethod($editor, 'recordPaneMetrics');

    return callEditorMethod($editor, 'fitLines', callEditorMethod($editor, 'getDatabaseSettingsLines'), $metrics['width'], $metrics['rows']);
}

/** The line the cursor is on, as drawn; it must be on screen. */
function visibleCursorLine(Editor $editor): string
{
    return array_values(array_filter(visibleConversionPane($editor), static fn(string $line): bool => str_starts_with($line, '> ')))[0]
        ?? throw new RuntimeException("The cursor is off screen:\n" . implode("\n", visibleConversionPane($editor)));
}

/** The visible pane as one line of prose, cursor markers dropped, whatever its wrapping. */
function readVisibleConversionPane(Editor $editor): string
{
    return preg_replace('/\s+/', ' ', implode(' ', array_map(static fn(string $line): string => substr($line, 2), visibleConversionPane($editor))));
}

/** Battle phase cadence, target binding, ticks 1 and rest 0, typed through the pane. */
function chooseBattleTiming(Editor $editor): void
{
    pressKeys($editor, 'T', "\033[C", "\033[B", "\033[B", "\033[B");
    typeText($editor, '1');
    pressKeys($editor, "\033[B");
    typeText($editor, '0');
    pressKeys($editor, "\033[B", "\033[B");
}

it('keeps the selected control on screen and every fact reachable at real terminal sizes', function (int $width, int $height) {
    [$editor] = openLegacyAnimationEditor($width, $height);
    pressKeys($editor, 'T');
    $conversion = terminalConversion($editor);
    $seen = [];

    expect(visibleCursorLine($editor))->toContain('Played by');

    // Walk every step, controls then the facts after them, reading what the pane shows at each.
    $lines = count($conversion->getLines(callEditorMethod($editor, 'recordPaneMetrics')['width'], getEditorProperty($editor, 'projectRoot')));
    foreach (range(1, $lines) as $step) {
        visibleCursorLine($editor);
        $seen[] = readVisibleConversionPane($editor);
        pressKeys($editor, "\033[B");
    }
    $read = implode(' ', $seen);

    expect($read)->toContain('Preview the files', 'role ' . array_last(ActionAnimationResolver::getSupportedRoles()), 'until their command names the timeline');
})->with([[80, 24], [120, 40], [160, 48]]);

it('reviews every proposed line before writing exactly that plan, as one undo step', function (int $width, int $height) {
    [$editor, $root] = openLegacyAnimationEditor($width, $height);
    $timeline = $root . '/assets/Animations/old-spark/old-spark.timeline.php';
    chooseBattleTiming($editor);
    $conversion = terminalConversion($editor);

    expect($conversion->getSelectedControl())->toBe('preview')
        ->and(visibleCursorLine($editor))->toContain('[ Preview')
        ->and(readVisibleConversionPane($editor))->toContain('[ Preview the files ]');

    pressKeys($editor, "\r");

    expect($conversion->getMode())->toBe(AnimationConversionEditor::MODE_REVIEW)
        ->and(file_exists($timeline))->toBeFalse();

    // Read the whole review, a line at a time, as the pane shows it.
    $seen = [];
    $lines = count($conversion->getLines(callEditorMethod($editor, 'recordPaneMetrics')['width'], $root));
    foreach (range(1, $lines) as $step) {
        $seen[] = substr(visibleCursorLine($editor), 2);
        pressKeys($editor, "\033[B");
    }
    $read = preg_replace('/\s+/', '', implode('', $seen));

    // Deeply indented source stays readable however narrow the pane.
    expect($read)->toContain('//preservednestedsource');

    // Every proposed file was on screen in full, in order: nothing is written unread.
    foreach ($conversion->getPlan()->getProposedSources() as $source) {
        expect(str_contains($read, preg_replace('/\s+/', '', $source)))->toBeTrue();
    }

    pressKeys($editor, "\r");

    expect($conversion->isOpen())->toBeFalse()
        ->and(file_get_contents($root . '/assets/Data/animations.php'))->toContain("'targetEffect' => 'old-spark'", '// A spark in the old cell frames.')
        ->and(new EffectTimelineLibrary($root . '/assets')->load('old-spark', forBattle: true))->not->toBeNull();

    callEditorMethod($editor, 'performUndo');

    expect(file_get_contents($root . '/assets/Data/animations.php'))->toBe(longLegacyAnimations())
        ->and(file_exists($timeline))->toBeFalse();
})->with([[80, 24], [120, 40], [160, 48]]);

it('goes back from review to the choices on Escape, and forgets the plan when a choice changes', function () {
    [$editor] = openLegacyAnimationEditor(80, 24);
    chooseBattleTiming($editor);
    pressKeys($editor, "\r", "\033");
    $conversion = terminalConversion($editor);

    expect($conversion->getMode())->toBe(AnimationConversionEditor::MODE_CHOICES)
        ->and(visibleCursorLine($editor))->toContain('[ Review')
        ->and($conversion->getSelectedControl())->toBe('review')
        ->and(readVisibleConversionPane($editor))->toContain('[ Review and write 2 files ]');

    // Up to the ticks, and change them.
    pressKeys($editor, "\033[A", "\033[A", "\033[A", "\033[A");
    typeText($editor, '2');

    expect($conversion->getPlan())->toBeNull()
        ->and($conversion->getControls())->not->toContain('review');
});

it('refuses to write a review the files have moved on from, writing nothing and saying why', function () {
    [$editor, $root] = openLegacyAnimationEditor(80, 24);
    chooseBattleTiming($editor);
    pressKeys($editor, "\r");

    file_put_contents($root . '/assets/Data/animations.php', str_replace('// A spark in the old cell frames.', '// Edited elsewhere.', longLegacyAnimations()));
    pressKeys($editor, "\r");

    expect(terminalConversion($editor)->getMode())->toBe(AnimationConversionEditor::MODE_CHOICES)
        ->and(terminalConversion($editor)->getError())->toContain('changed outside the animation conversion')
        ->and(file_exists($root . '/assets/Animations/old-spark'))->toBeFalse()
        ->and(file_get_contents($root . '/assets/Data/animations.php'))->toContain('// Edited elsewhere.');
});

it('asks for who plays the timeline rather than assuming it', function () {
    [$editor] = openLegacyAnimationEditor(160, 48);
    // Down to Preview with no consumer chosen.
    pressKeys($editor, 'T', "\033[B", "\033[B", "\033[B", "\033[B", "\033[B");

    expect(visibleCursorLine($editor))->toContain('Preview the files');

    pressKeys($editor, "\r");

    expect(terminalConversion($editor)->getError())->toContain('Choose who plays the timeline')
        ->and(terminalConversion($editor)->getMode())->toBe(AnimationConversionEditor::MODE_CHOICES);
});

it('says why a record cannot be converted', function () {
    [$editor, $root] = openLegacyAnimationEditor(80, 24);
    file_put_contents($root . '/assets/Data/animations.php', "<?php\n\nreturn [['id' => 1, 'name' => 'Already Timed', 'targetEffect' => 'burst']];\n");
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    pressKeys($editor, 'T');

    expect(terminalConversion($editor)->isOpen())->toBeFalse()
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('has no legacy frames or cues to convert');
});
