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
    // Child processes the tests start (the migration probe) run from other directories, so they
    // inherit the checkout's resolved path, never one relative to where the suite was started.
    putenv('ICHILOTO_ENGINE_SRC=' . dirname($source));
}
