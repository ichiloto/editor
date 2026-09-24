<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Canvas;

use Ichiloto\Editor\Maps\EditableGrid;
use RuntimeException;

/** Blank-line-separated authored facades; no duplicated shape definitions. */
final class FacadeCatalogue
{
    public static function load(string $path): array
    {
        $text = @file_get_contents($path);
        if ($text === false || ! mb_check_encoding($text, 'UTF-8')) {
            throw new RuntimeException('The facade catalogue cannot be read as UTF-8: ' . $path);
        }
        $blocks = preg_split('/(?:\r\n|\n|\r)[ \t]*(?:\r\n|\n|\r)+/', rtrim($text, "\r\n")) ?: [];
        return array_values(array_map(static fn(string $block): array => new EditableGrid($block)->cells,
            array_filter($blocks, static fn(string $block): bool => trim($block) !== ''),
        ));
    }

    public static function discover(string $root): array
    {
        $directory = $root . '/assets/Graphics/Tilesets';
        $files = is_dir($directory) ? (glob($directory . '/*.txt') ?: []) : [];
        sort($files);
        return $files;
    }
}
