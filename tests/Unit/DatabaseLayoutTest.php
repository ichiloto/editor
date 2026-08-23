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

it('gives the System Notes pane room for its sentences on a wide terminal', function () {
    // The Notes pane holds prose up to 40 columns wide. At 230 columns it
    // used to inherit the 10-column width meant for animation frame numbers,
    // clipping every sentence to six characters beside a mostly empty
    // preview.
    $layout = databaseLayoutFor(230, category: 'system');

    expect($layout['framesWidth'])->toBeGreaterThanOrEqual(44)
        ->and($layout['previewWidth'])->toBeGreaterThanOrEqual(24);
});

it('fills the bottom row exactly, for any category at any width', function () {
    foreach ([null, 'system', 'skills', 'quests', 'classes', 'actors'] as $category) {
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
