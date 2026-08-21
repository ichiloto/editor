<?php

declare(strict_types=1);

/**
 * Returns the Database layout for a terminal of the given size.
 *
 * @param int $columns The terminal width.
 * @param int $rows The terminal height.
 * @return array<string, int> The layout.
 */
function databaseLayoutFor(int $columns, int $rows = 39): array
{
    return callEditorMethod(
        createEditorForTesting(makeTemporaryProject()),
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
