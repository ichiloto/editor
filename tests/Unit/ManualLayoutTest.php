<?php

declare(strict_types=1);

/**
 * Returns the fenced block that follows a manual heading.
 *
 * @param string $heading The heading to read under.
 * @return string The block's contents.
 */
function manualBlockUnder(string $heading): string
{
    $manual = (string) file_get_contents(__DIR__ . '/../../docs/manual.md');
    $section = substr($manual, strpos($manual, $heading) ?: 0);

    preg_match('/```text\n(.*?)\n```/s', $section, $matches);

    return $matches[1] ?? '';
}

it('shows the shell the editor actually draws', function () {
    // A picture drawn by hand is wrong the first time a pane moves, and
    // nobody notices for months. This one comes from the editor.
    expect(manualBlockUnder('## Layout Overview'))->toBe(renderPlainFrame(100, 24));
});

it('draws that picture from a project nobody has to own', function () {
    $block = manualBlockUnder('## Layout Overview');

    // The manual is read by people whose machines look nothing like the one
    // it was written on, so its example is the test fixture rather than a
    // game that lives in this repository.
    expect($block)->toContain('Sample Project')
        ->and($block)->not->toContain('Last Legend')
        ->and($block)->not->toContain('happyville');
});
