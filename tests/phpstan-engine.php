<?php

declare(strict_types=1);

/*
 * Which Engine source static analysis reads, chosen the way the test
 * bootstrap chooses it: ICHILOTO_ENGINE_SRC names a sibling Engine checkout,
 * whose classes then take the place of the installed Engine's; without it
 * the installed Engine is read. phpstan.neon includes this file, so every
 * run of `composer analyse` or `vendor/bin/phpstan analyse` makes the same
 * choice as `vendor/bin/pest`.
 */
$engine = getenv('ICHILOTO_ENGINE_SRC');
if (! is_string($engine) || $engine === '') {
    return [];
}

$source = realpath(rtrim($engine, '/') . '/src');
if ($source === false || ! is_file($source . '/Field/MapLayerSource.php')) {
    throw new RuntimeException('ICHILOTO_ENGINE_SRC must name an Engine checkout with layered map support.');
}

return ['parameters' => ['scanDirectories' => [$source]]];
