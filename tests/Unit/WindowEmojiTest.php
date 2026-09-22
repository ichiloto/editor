<?php

declare(strict_types=1);

use Ichiloto\Editor\EditorWindow;

it('writes an emoji through without re-encoding it', function () {
    $window = new EditorWindow(
        title: 'Probe',
        position: ['x' => 0, 'y' => 0],
        width: 30,
        height: 4,
        content: ['  Icon: 🧪'],
    );

    ob_start();
    $window->render();
    $output = (string) ob_get_clean();

    // A test tube is F0 9F A7 AA. Re-encoding it a second time gives
    // C3 B0 C2 9F ..., which is what reached the terminal.
    expect(str_contains($output, "\u{1F9EA}"))->toBeTrue()
        ->and(str_contains($output, "\xc3\xb0\xc2\x9f"))->toBeFalse();
});

it('pads ANSI-styled content to the full interior width', function () {
    $window = new EditorWindow(
        title: 'Swatches',
        position: ['x' => 0, 'y' => 0],
        width: 30,
        height: 4,
        content: ["  \033[96m■\033[0m bright-cyan"],
    );

    ob_start();
    $window->render();
    $output = (string) ob_get_clean();

    // Escape bytes occupy no columns: the row must still be padded to the
    // interior width so nothing beneath the window bleeds through, and the
    // styled swatch must survive untruncated.
    $lines = explode("\n", str_replace("\r", '', $output));
    $contentLine = null;

    foreach ($lines as $line) {
        if (str_contains($line, 'bright-cyan')) {
            $contentLine = $line;
        }
    }

    expect($contentLine)->not->toBeNull();
    $visible = (string) preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', (string) $contentLine);

    expect(str_contains((string) $contentLine, "\033[96m■\033[0m"))->toBeTrue()
        ->and(mb_strwidth(trim($visible, "\n")))->toBeGreaterThanOrEqual(30);
});

it('keeps escape sequences while truncating overlong styled content', function () {
    $window = new EditorWindow(
        title: 'Probe',
        position: ['x' => 0, 'y' => 0],
        width: 12,
        height: 3,
        content: ["\033[31mAAAAAAAAAAAAAAAAAAAA\033[0m"],
    );

    ob_start();
    $window->render();
    $output = (string) ob_get_clean();

    // The colour is applied, the text is cut to the interior, and the
    // reset survives the cut so styling never leaks past the window.
    expect(str_contains($output, "\033[31m"))->toBeTrue()
        ->and(str_contains($output, "\033[0m"))->toBeTrue()
        ->and(substr_count($output, 'A'))->toBeLessThan(20);
});
