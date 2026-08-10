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
