<?php

declare(strict_types=1);

/**
 * Returns the Database layout for a terminal of the given size.
 *
 * @param int $columns The terminal width.
 * @param int $rows The terminal height.
 * @param string|null $category The category key to select, or null for the default.
 * @return array<string, int> The layout.
 */
function databaseLayoutFor(int $columns, int $rows = 39, ?string $category = null): array
{
    $editor = createEditorForTesting(makeTemporaryProject());

    if ($category !== null) {
        setEditorProperty($editor, 'databaseCategoryIndex', Ichiloto\Editor\Database\DatabaseCatalog::indexOf($category));
    }

    return callEditorMethod(
        $editor,
        'resolveDatabaseLayout',
        ['width' => $columns, 'height' => $rows],
    );
}

it('gives a wide terminal to the pane that has reading to do', function () {
    $layout = databaseLayoutFor(230);

    // The settings pane holds label-and-value lines that used to truncate at
    // 38 columns while the summary beside it had 142 to itself.
    expect($layout['settingsWidth'])->toBeGreaterThan($layout['cueWidth'])
        ->and($layout['settingsWidth'])->toBeGreaterThanOrEqual(90);
});

it('fills the right side exactly, at any width', function () {
    foreach ([230, 180, 140, 120, 100, 90, 80, 70] as $columns) {
        $layout = databaseLayoutFor($columns);
        $used = $layout['settingsWidth'] + $layout['cueWidth'] + $layout['gutter'];

        // Panes that overrun their side draw through the frame; panes that
        // fall short leave a hole in it.
        expect($used)->toBeLessThanOrEqual($layout['rightWidth'], "{$columns} columns overflows")
            ->and($layout['settingsWidth'])->toBeGreaterThan(0)
            ->and($layout['cueWidth'])->toBeGreaterThan(0);
    }
});

it('keeps the summary readable before widening the settings pane', function () {
    $layout = databaseLayoutFor(120);

    expect($layout['cueWidth'])->toBeGreaterThanOrEqual(22)
        ->and($layout['settingsWidth'])->toBeGreaterThanOrEqual(34);
});

it('shares out what there is when neither pane can have its minimum', function () {
    $layout = databaseLayoutFor(80);

    // Better a cramped pair than one pane drawn over the other.
    expect($layout['settingsWidth'] + $layout['cueWidth'] + $layout['gutter'])
        ->toBeLessThanOrEqual($layout['rightWidth'])
        ->and($layout['cueWidth'])->toBeGreaterThan(0);
});

it('stops widening the settings pane once it is wide enough', function () {
    // A 400-column terminal should not put 350 of them into one column of
    // label-and-value lines.
    expect(databaseLayoutFor(400)['settingsWidth'])->toBeLessThanOrEqual(96);
});

it('sizes the System Notes pane to its own sentences on a wide terminal', function () {
    // The Notes pane holds prose. At 230 columns it used to inherit the
    // 10-column width meant for animation frame numbers, clipping every
    // sentence to six characters beside a mostly empty preview. The width
    // is derived from the lines themselves, so this holds however the
    // sentences are reworded.
    $root = makeTemporaryProject();
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', Ichiloto\Editor\ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'databaseCategoryIndex', Ichiloto\Editor\Database\DatabaseCatalog::indexOf('system'));

    $widestSentence = max(array_map(mb_strwidth(...), callEditorMethod($editor, 'getDatabaseFrameLines')));
    $layout = callEditorMethod($editor, 'resolveDatabaseLayout', ['width' => 230, 'height' => 39]);

    expect($widestSentence)->toBeGreaterThanOrEqual(30)
        ->and($layout['framesWidth'])->toBe($widestSentence + 4)
        ->and($layout['previewWidth'])->toBeGreaterThanOrEqual(24);
});

it('keeps a content-fitted pane compact when its lines are short', function () {
    // A record category's frame list is a placeholder dash: fitting content
    // means hugging it, not ballooning to the maximum.
    expect(databaseLayoutFor(230, category: 'items')['framesWidth'])->toBe(10);
});

it('keeps the tuned entry-list panes steady on a wide terminal', function () {
    // These frame lists scroll a per-entry selection, so their widths stay
    // tuned instead of reflowing with whichever entry is selected.
    expect(databaseLayoutFor(230, category: 'skills')['framesWidth'])->toBe(34)
        ->and(databaseLayoutFor(230, category: 'classes')['framesWidth'])->toBe(24)
        ->and(databaseLayoutFor(230, category: 'actors')['framesWidth'])->toBe(18);
});

it('fills the bottom row exactly, for any category at any width', function () {
    foreach ([null, 'system', 'skills', 'quests', 'classes', 'actors', 'items', 'animations'] as $category) {
        foreach ([230, 180, 140, 120, 100, 90, 80, 70] as $columns) {
            $layout = databaseLayoutFor($columns, category: $category);
            $used = $layout['framesWidth'] + $layout['previewWidth'] + $layout['gutter'];
            $label = ($category ?? 'default') . " at {$columns} columns";

            // The Skills row used to claim 55 of a 46-column right side,
            // drawing its preview through the Database frame.
            expect($used)->toBeLessThanOrEqual($layout['rightWidth'], "{$label} overflows")
                ->and($layout['framesWidth'])->toBeGreaterThan(0)
                ->and($layout['previewWidth'])->toBeGreaterThan(0);
        }
    }
});

it('wraps prose that a narrow pane cannot fit, losing no words', function () {
    $editor = createEditorForTesting(makeTemporaryProject());
    $wrapped = callEditorMethod(
        $editor,
        'wrapLines',
        ['Switch Battle Engine to active_time', '', 'ok'],
        17,
    );

    // Every word survives, in order; the blank line and the line that
    // already fits pass through untouched.
    expect(implode(' ', array_filter(array_slice($wrapped, 0, count($wrapped) - 2), static fn(string $line): bool => $line !== '')))
        ->toBe('Switch Battle Engine to active_time')
        ->and(array_slice($wrapped, -2))->toBe(['', 'ok']);

    foreach ($wrapped as $line) {
        expect(mb_strwidth($line))->toBeLessThanOrEqual(17);
    }
});

it('splits a word wider than the pane instead of dropping it', function () {
    $editor = createEditorForTesting(makeTemporaryProject());
    $wrapped = callEditorMethod($editor, 'wrapLines', ['incontrovertibly so'], 8);

    expect(implode('', array_map(static fn(string $line): string => str_replace(' ', '', $line), $wrapped)))
        ->toBe('incontrovertiblyso');

    foreach ($wrapped as $line) {
        expect(mb_strwidth($line))->toBeLessThanOrEqual(8);
    }
});

it('shows whole wrapped words in the System Notes pane at a small terminal', function () {
    $root = makeTemporaryProject();
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', Ichiloto\Editor\ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'isRunning', true);
    openDatabaseCategory($editor, 'system');
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 100, 'height' => 30]);

    $frame = renderEditorPlainFrame($editor, 100, 30);

    // The pane is too narrow for its sentences at 100 columns, so they wrap:
    // they used to render as clipped shards ("Tradit"), and a clipped pane
    // would never show the sentence's tail words whole.
    expect($frame)->toContain('turn-based')
        ->and($frame)->toContain('battles.');
});
