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
