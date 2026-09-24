<?php

declare(strict_types=1);

$loader = require dirname(__DIR__) . '/vendor/autoload.php';
$engine = getenv('ICHILOTO_ENGINE_SRC');
if (is_string($engine) && $engine !== '') {
    $source = realpath(rtrim($engine, '/') . '/src');
    if (! is_file($source . '/Field/MapLayerSource.php')) {
        throw new RuntimeException('ICHILOTO_ENGINE_SRC must name an Engine checkout with layered map support.');
    }
    $loader->addPsr4('Ichiloto\\Engine\\', $source, prepend: true);
}
