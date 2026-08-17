<?php

declare(strict_types=1);

use Ichiloto\Editor\Editor;

/**
 * Returns the engine source tree this suite resolves engine classes from.
 *
 * The workspace's live engine by default. `ICHILOTO_ENGINE_SRC` pins it to
 * another checkout instead, which is how a gate runs against one accepted
 * engine head while that same worktree is being changed by someone else.
 */
function engineSourceRoot(): string
{
    $pinned = getenv('ICHILOTO_ENGINE_SRC');

    if (is_string($pinned) && $pinned !== '' && is_dir($pinned . '/src')) {
        return rtrim($pinned, '/');
    }

    return dirname(__DIR__, 2) . '/engine';
}

// The editor package does not vendor the engine, so map Ichiloto\Engine\ to
// the engine source tree directly. Only the namespace is mapped --
// requiring the engine's full vendor autoloader here would shadow this
// suite's phpunit with the engine's own copy.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Ichiloto\\Engine\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $path = engineSourceRoot()
        . '/src/'
        . str_replace('\\', '/', substr($class, strlen($prefix)))
        . '.php';

    if (is_file($path)) {
        require $path;
    }
});

// The engine also autoloads its function helpers via composer "files";
// authored data constructs engine objects whose constructors call them
// (an Enemy loads its sprite through graphics()), so the mapping is
// incomplete without them.
foreach (['Constants.php', 'Helpers.php'] as $engineHelperFile) {
    $engineHelperPath = engineSourceRoot() . '/src/Util/' . $engineHelperFile;

    if (is_file($engineHelperPath)) {
        require_once $engineHelperPath;
    }
}

// The helpers lean on the engine's own vendor packages (graphics() resolves
// paths through Assegai\Util\Path). Registered after this suite's autoloader,
// so the engine's map only fields what nothing here provides -- its phpunit
// never shadows ours.
$enginePsr4Path = engineSourceRoot() . '/vendor/composer/autoload_psr4.php';

if (is_file($enginePsr4Path)) {
    /** @var array<string, string[]> $enginePsr4 */
    $enginePsr4 = require $enginePsr4Path;

    spl_autoload_register(static function (string $class) use ($enginePsr4): void {
        foreach ($enginePsr4 as $prefix => $directories) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }

            foreach ($directories as $directory) {
                $path = $directory . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

                if (is_file($path)) {
                    require $path;

                    return;
                }
            }
        }
    });
}

/**
 * Builds an unbooted editor over a throwaway copy of the fixture project, so
 * a test may exercise the real save paths.
 */
function deletionEditor(string $root): Editor
{
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', Ichiloto\Editor\ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
    setEditorProperty($editor, 'isRunning', true);

    return $editor;
}

/**
 * Opens the Database screen on a category with the entry list focused.
 */
function openDatabaseCategory(Editor $editor, string $category): void
{
    callEditorMethod($editor, 'dispatchInput', "\x04");
    setEditorProperty($editor, 'databaseCategoryIndex', Ichiloto\Editor\Database\DatabaseCatalog::indexOf($category));
    setEditorProperty($editor, 'databaseFocus', 'database_list');
}

/**
 * Loads one schema-driven Database category from a project root.
 */
function loadRecordDatabase(string $projectRoot, string $categoryKey): Ichiloto\Editor\Database\ProjectRecordDatabase
{
    $schema = Ichiloto\Editor\Database\RecordSchemaCatalog::forKey($categoryKey);

    if ($schema === null) {
        throw new RuntimeException("No record schema for category {$categoryKey}.");
    }

    return Ichiloto\Editor\Database\ProjectRecordDatabase::fromProject($projectRoot, $schema);
}

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

/**
 * Renders one full editor frame into a string using the same output
 * buffering the live loop uses, with the terminal size pinned.
 *
 * The project theme is applied exactly as boot() applies it, so the snapshot
 * shows what an author actually sees rather than the unthemed defaults.
 */
function renderGoldenFrame(int $width, int $height): string
{
  $editor = createEditorForTesting(fixturePath('sample-project'));
  setEditorProperty($editor, 'workspace', \Ichiloto\Editor\ProjectWorkspace::fromProject(fixturePath('sample-project')));
  callEditorMethod($editor, 'applyProjectTheme');
  setEditorProperty($editor, 'lastTerminalSize', ['width' => $width, 'height' => $height]);

  ob_start();

  try {
    callEditorMethod($editor, 'renderFullScreen');
  } finally {
    $frame = (string) ob_get_clean();
  }

  return $frame;
}

/**
 * Renders a frame the way a terminal would draw it: plain text, no escapes.
 *
 * The manual shows a picture of the shell, and a picture drawn by hand goes
 * stale the first time a pane changes. This renders the real one.
 *
 * @param int $width The terminal width.
 * @param int $height The terminal height.
 * @return string The frame as it appears on screen.
 */
function renderPlainFrame(int $width, int $height): string
{
    $frame = renderGoldenFrame($width, $height);
    $screen = array_fill(0, $height, array_fill(0, $width, ' '));
    $row = 0;
    $column = 0;

    foreach (preg_split('/(\x1b\[[0-9;?]*[A-Za-z])/', $frame, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $chunk) {
        if ($chunk === '') {
            continue;
        }

        if (preg_match('/^\x1b\[(\d+);(\d+)H$/', $chunk, $matches) === 1) {
            $row = (int) $matches[1] - 1;
            $column = (int) $matches[2] - 1;

            continue;
        }

        if (str_starts_with($chunk, "\x1b")) {
            continue;
        }

        foreach (preg_split('//u', $chunk, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if ($row >= 0 && $row < $height && $column >= 0 && $column < $width) {
                $screen[$row][$column] = $character;
            }

            $column++;
        }
    }

    $lines = array_filter(
        array_map(static fn(array $cells): string => rtrim(implode('', $cells)), $screen),
        static fn(string $line): bool => $line !== '',
    );

    return implode("\n", $lines);
}
