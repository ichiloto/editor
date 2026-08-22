<?php

declare(strict_types=1);

use Ichiloto\Editor\Editor;

/**
 * Returns the game project used by optional production-verification tests.
 */
function gameSourceRoot(): ?string
{
    $pinned = getenv('ICHILOTO_GAME_SRC');

    return is_string($pinned) && $pinned !== '' && is_file($pinned . '/assets/Data/items.php')
        ? rtrim($pinned, '/')
        : null;
}

/**
 * Removes every throwaway project a test made, whether it passed or not.
 *
 * A test that builds a project and then fails would otherwise leave it in
 * the temporary directory for good; over a full run those add up to
 * hundreds of copies. Cleaning here, after every test, is what keeps the
 * suite's footprint the size of one fixture rather than the size of the run.
 */
if (new ReflectionProperty(Pest\TestSuite::class, 'instance')->getValue() instanceof Pest\TestSuite) {
    // Only under the Pest runner. A script that loads this file for its
    // autoloader alone -- a reproduction, a probe -- has no suite to hook.
    uses()->afterEach(function (): void {
        cleanUpTemporaryProjects();
    })->in(__DIR__ . '/Unit');
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
 * Hashes every file under a directory, keyed by relative path.
 *
 * Proving that an operation wrote nothing -- or wrote exactly one file --
 * is something several suites need, so the matrix lives here.
 *
 * @param string $directory The directory.
 * @return array<string, string> The SHA-256 matrix.
 */
function sourceHashTree(string $directory): array
{
    $hashes = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $hashes[substr($file->getPathname(), strlen($directory) + 1)] = hash_file('sha256', $file->getPathname());
        }
    }

    ksort($hashes);

    return $hashes;
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
    rememberTemporaryProject($root);

    return $root;
}

/**
 * Registers a throwaway directory for removal when the running test ends.
 *
 * Every project `makeTemporaryProject()` builds is registered; a test that
 * lays out its own directory registers it the same way, so its cleanup does
 * not depend on the test reaching its last line.
 *
 * @param string $root The directory to remove after the test.
 * @return string The same directory, for chaining.
 */
function rememberTemporaryProject(string $root): string
{
    $GLOBALS['ichilotoTemporaryProjects'][$root] = $root;

    return $root;
}

/**
 * Removes every registered throwaway directory.
 */
function cleanUpTemporaryProjects(): void
{
    foreach ($GLOBALS['ichilotoTemporaryProjects'] ?? [] as $root) {
        removeDirectoryRecursively($root);
    }

    $GLOBALS['ichilotoTemporaryProjects'] = [];
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
    if (! is_dir($directory) || is_link($directory)) {
        return;
    }

    // Only ever a directory made under the temporary directory: a path
    // outside it is a mistake, not a fixture, whatever asked.
    $temporary = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $resolved = realpath($directory) ?: $directory;

    if (! str_starts_with($resolved, rtrim($temporary, '/') . '/')) {
        throw new RuntimeException(sprintf('Refusing to remove %s: it is not a temporary fixture.', $directory));
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . '/' . $entry;

        if (is_link($path) || ! is_dir($path)) {
            // A link is removed as a link: what it points at -- a shared
            // vendor tree, an engine -- is not the fixture's to remove.
            @unlink($path);

            continue;
        }

        removeDirectoryRecursively($path);
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
    return plainTextOfFrame(renderGoldenFrame($width, $height), $width, $height);
}

/**
 * Renders one editor's full screen the way a terminal would draw it.
 *
 * @param Editor $editor The editor, in whatever state a test put it.
 * @param int $width The terminal width.
 * @param int $height The terminal height.
 * @return string The frame as it appears on screen.
 */
function renderEditorPlainFrame(Editor $editor, int $width, int $height): string
{
    setEditorProperty($editor, 'lastTerminalSize', ['width' => $width, 'height' => $height]);
    ob_start();

    try {
        callEditorMethod($editor, 'renderFullScreen');
    } finally {
        $frame = (string) ob_get_clean();
    }

    return plainTextOfFrame($frame, $width, $height);
}

/**
 * Replays a frame's cursor moves and text into a plain grid.
 *
 * @param string $frame The escape-coded frame.
 * @param int $width The terminal width.
 * @param int $height The terminal height.
 * @return string The frame as it appears on screen.
 */
function plainTextOfFrame(string $frame, int $width, int $height): string
{
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

require_once __DIR__ . '/Support/CutsceneFixtures.php';

require_once __DIR__ . '/Support/ValidationFixtures.php';

require_once __DIR__ . '/Support/FileSetFixtures.php';
