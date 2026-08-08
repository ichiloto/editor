<?php

declare(strict_types=1);

use Ichiloto\Editor\Editor;

// The editor package does not vendor the engine, so map Ichiloto\Engine\ to
// the workspace's live dev engine directly. Only the namespace is mapped —
// requiring the engine's full vendor autoloader here would shadow this
// suite's phpunit with the engine's own copy.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Ichiloto\\Engine\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $path = dirname(__DIR__, 2)
        . '/engine/src/'
        . str_replace('\\', '/', substr($class, strlen($prefix)))
        . '.php';

    if (is_file($path)) {
        require $path;
    }
});

/**
 * Returns the absolute path of a test fixture.
 */
function fixturePath(string $relativePath = ''): string
{
    $root = __DIR__ . '/Fixtures';

    return $relativePath === '' ? $root : $root . '/' . ltrim($relativePath, '/');
}

/**
 * Copies the sample fixture project into a throwaway directory so a test may
 * exercise real save paths without ever touching the checked-in fixture.
 */
function makeTemporaryProject(string $prefix = 'ichiloto-editor-'): string
{
    $root = sys_get_temp_dir() . '/' . uniqid($prefix, true);
    mkdir($root, 0777, true);
    copyDirectoryRecursively(fixturePath('sample-project'), $root);

    return $root;
}

/**
 * Recursively copies a directory tree.
 */
function copyDirectoryRecursively(string $source, string $destination): void
{
    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $sourcePath = $source . '/' . $entry;
        $destinationPath = $destination . '/' . $entry;

        if (is_dir($sourcePath)) {
            @mkdir($destinationPath, 0777, true);
            copyDirectoryRecursively($sourcePath, $destinationPath);
            continue;
        }

        copy($sourcePath, $destinationPath);
    }
}

/**
 * Recursively removes a directory tree created by makeTemporaryProject().
 */
function removeDirectoryRecursively(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . '/' . $entry;
        is_dir($path) ? removeDirectoryRecursively($path) : @unlink($path);
    }

    @rmdir($directory);
}

/**
 * Creates an Editor instance for white-box tests without booting a terminal
 * session, and immediately restores the global handlers its constructor
 * installs so test failures surface normally.
 */
function createEditorForTesting(string $projectRoot): Editor
{
    $editor = new Editor($projectRoot);
    restore_error_handler();
    restore_exception_handler();

    return $editor;
}

/**
 * Sets a private Editor property by reflection.
 */
function setEditorProperty(Editor $editor, string $property, mixed $value): void
{
    new ReflectionProperty(Editor::class, $property)->setValue($editor, $value);
}

/**
 * Reads a private Editor property by reflection.
 */
function getEditorProperty(Editor $editor, string $property): mixed
{
    return new ReflectionProperty(Editor::class, $property)->getValue($editor);
}

/**
 * Invokes a private Editor method by reflection.
 */
function callEditorMethod(Editor $editor, string $method, mixed ...$arguments): mixed
{
    return new ReflectionMethod(Editor::class, $method)->invoke($editor, ...$arguments);
}
