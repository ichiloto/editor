<?php

declare(strict_types=1);

/**
 * Returns the help line a pane of the given width would show.
 *
 * @param int $width The window width.
 * @param string ...$candidates The forms, longest first.
 * @return string The chosen form.
 */
function fittedHelp(int $width, string ...$candidates): string
{
    return callEditorMethod(createEditorForTesting(makeTemporaryProject()), 'fitHelp', $width, ...$candidates);
}

it('shows the fullest hint a border has room for', function () {
    expect(fittedHelp(46, 'Enter:Edit  Shift+O:Add  Shift+X/Del:Remove', 'Enter:Edit'))
        ->toBe('Enter:Edit  Shift+O:Add  Shift+X/Del:Remove');
});

it('drops to a shorter hint rather than cutting one in half', function () {
    // 'Shift+X/De' is what the long form looked like at this width, which
    // reads as a bug and teaches nothing.
    expect(fittedHelp(34, 'Enter:Edit  Shift+O:Add  Shift+X/Del:Remove', 'Enter:Edit  Shift+O:Add  Del:Remove', 'Enter:Edit'))
        ->toBe('Enter:Edit');
});

it('gives up rather than overflow when nothing fits', function () {
    expect(fittedHelp(6, 'Enter:Edit'))->toBe('');
});

it('measures the width the border actually leaves', function () {
    // A window spends a corner, a border character and the closing corner.
    expect(fittedHelp(13, '1234567890'))->toBe('1234567890')
        ->and(fittedHelp(12, '1234567890', 'short'))->toBe('short');
});

it('tells an author how to remove things, whatever the pane fits', function () {
    $lines = implode("\n", callEditorMethod(createEditorForTesting(makeTemporaryProject()), 'getHelpLines'));

    // The border hint shortens on a narrow terminal, so the help screen is
    // where these have to be findable.
    expect($lines)->toContain('Shift+O / Shift+X')
        ->and($lines)->toContain('add or remove a condition');
});
