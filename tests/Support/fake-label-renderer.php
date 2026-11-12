<?php

declare(strict_types=1);

/*
 * A synthetic stand-in for the GPUI renderer's windowless label mode
 * (`gpui-renderer --prepare-text`), for tests that cannot assume a native
 * renderer: it answers the same protocol lines, measures each label as
 * 0.6 of its font size per character across and its font size down, and
 * writes each label's raster, guard included, as one solid colour.
 */

const FAKE_LABEL_COLOUR = [200, 40, 120];
const FAKE_LABEL_GUARD = 2;

$arguments = array_slice($argv, 1);
if ($arguments === ['--prepare-text', '--check']) {
    echo '{"mode":"prepare_text","protocol":2}', "\n";
    exit(0);
}
if (count($arguments) !== 2 || $arguments[0] !== '--prepare-text' || ! is_dir($arguments[1])) {
    fwrite(STDERR, "usage: fake-label-renderer --prepare-text <raster directory> | --prepare-text --check\n");
    exit(1);
}
$directory = $arguments[1];
$reply = static function (array $event): void {
    fwrite(STDOUT, json_encode(['protocol' => 2, ...$event], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    fflush(STDOUT);
};
$layouts = 0;
while (($line = fgets(STDIN)) !== false) {
    $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    switch ($message['type']) {
        case 'hello':
            $reply(['type' => 'ready', 'capabilities' => ['text_layout']]);
            break;
        case 'prepare_text':
            $size = (float) $message['spec']['fontSize'];
            $width = 0.6 * $size * mb_strlen($message['spec']['text']);
            $layoutId = 'fake-layout-' . ++$layouts;
            $image = imagecreatetruecolor((int) ceil($width) + 2 * FAKE_LABEL_GUARD, (int) ceil($size) + 2 * FAKE_LABEL_GUARD);
            imagefill($image, 0, 0, (int) imagecolorallocate($image, ...FAKE_LABEL_COLOUR));
            imagepng($image, $directory . '/' . bin2hex($layoutId) . '.png');
            $bounds = ['x' => 0.0, 'y' => 0.0, 'width' => $width, 'height' => $size];
            $reply(['type' => 'text_prepared', 'requestId' => $message['requestId'], 'layoutId' => $layoutId,
                'metrics' => ['advance' => $width, 'baseline' => 0.8 * $size, 'lineBounds' => $bounds, 'inkBounds' => $bounds,
                    'paintBounds' => $bounds]]);
            break;
        case 'release_text':
            $reply(['type' => 'text_released', 'layoutIds' => $message['layoutIds']]);
            break;
        case 'shutdown':
            exit(0);
    }
}
