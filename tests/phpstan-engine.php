<?php

declare(strict_types=1);

$engine = getenv('ICHILOTO_ENGINE_SRC');
$source = is_string($engine) ? realpath($engine . '/src') : false;
if ($source === false || ! is_file($source . '/Field/MapLayerSource.php')) {
    throw new RuntimeException('ICHILOTO_ENGINE_SRC must name the sibling Engine checkout.');
}

return [
    'includes' => [dirname(__DIR__) . '/phpstan.neon'],
    'parameters' => ['scanDirectories' => [$source]],
];
