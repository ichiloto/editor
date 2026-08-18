<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Playtest;

use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\Database\PhpValueExporter;
use RuntimeException;
use Throwable;

/**
 * A throwaway project root that plays the author's game from a chosen map and
 * tile *without writing a single byte into the author's project*.
 *
 * Every path the engine resolves is relative to the process working
 * directory, and it offers no starting-map or spawn override (see the
 * deferral note in `docs/roadmap.md`). So the overlay works with that grain
 * instead of against it: a temporary directory is filled with symlinks back
 * to the real project, and exactly two entries are replaced by real ones —
 *
 *  - `assets/Data/system.php`, rewritten with the playtest spawn; and
 *  - `.data/`, a fresh empty directory, so a playtest can never overwrite the
 *    author's save slots.
 *
 * Because maps, graphics, and every other asset are symlinks, the playtest
 * runs against the author's live files — a map saved in the editor is the map
 * the playtest loads.
 */
final class PlaytestOverlay
{
    /**
     * @param string $root The overlay project root.
     * @param string $mapId The map the playtest starts on.
     * @param int $spawnX The spawn column.
     * @param int $spawnY The spawn row.
     */
    private function __construct(
        public readonly string $root,
        public readonly string $mapId,
        public readonly int $spawnX,
        public readonly int $spawnY,
    ) {
    }

    /**
     * Builds an overlay for one playtest run.
     *
     * @param string $projectRoot The real project root.
     * @param string $mapId The map to start on.
     * @param int $spawnX The spawn column.
     * @param int $spawnY The spawn row.
     * @return self
     */
    public static function create(string $projectRoot, string $mapId, int $spawnX, int $spawnY): self
    {
        $projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('ichiloto-playtest-', true);

        if (! mkdir($root, 0777, true) && ! is_dir($root)) {
            throw new RuntimeException("Unable to create the playtest overlay at {$root}.");
        }

        try {
            // Everything at the project root is a symlink, except `assets`
            // (which needs one file replaced) and `.data` (which must be
            // isolated).
            self::mirrorDirectory($projectRoot, $root, ['assets', '.data']);

            $assetsSource = $projectRoot . DIRECTORY_SEPARATOR . 'assets';
            $assetsTarget = $root . DIRECTORY_SEPARATOR . 'assets';

            if (! is_dir($assetsSource)) {
                throw new RuntimeException("The project has no assets directory at {$assetsSource}.");
            }

            mkdir($assetsTarget, 0777, true);
            self::mirrorDirectory($assetsSource, $assetsTarget, ['Data']);

            $dataSource = $assetsSource . DIRECTORY_SEPARATOR . 'Data';
            $dataTarget = $assetsTarget . DIRECTORY_SEPARATOR . 'Data';
            mkdir($dataTarget, 0777, true);
            self::mirrorDirectory($dataSource, $dataTarget, ['system.php']);

            mkdir($root . DIRECTORY_SEPARATOR . '.data', 0777, true);

            self::writeSystemOverride(
                $dataSource . DIRECTORY_SEPARATOR . 'system.php',
                $dataTarget . DIRECTORY_SEPARATOR . 'system.php',
                $mapId,
                $spawnX,
                $spawnY,
            );
        } catch (\Throwable $throwable) {
            // An overlay that could not be finished is not left behind: what
            // was mirrored so far is links and two generated entries, removed
            // without ever following a link into the author's project.
            self::removeTree($root);

            throw $throwable;
        }

        return new self($root, $mapId, $spawnX, $spawnY);
    }

    /**
     * Removes the overlay directory.
     *
     * Only symlinks and the two generated entries live here, so unlinking is
     * safe: `unlink()` on a symlink never follows it into the real project.
     *
     * @return void
     */
    public function destroy(): void
    {
        self::removeTree($this->root);
    }

    /**
     * Symlinks every entry of a directory, skipping the named exceptions.
     *
     * @param string $source The directory to mirror.
     * @param string $target The directory to fill.
     * @param string[] $skip Entry names to leave out.
     * @return void
     */
    private static function mirrorDirectory(string $source, string $target, array $skip = []): void
    {
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, $skip, true)) {
                continue;
            }

            @symlink($source . DIRECTORY_SEPARATOR . $entry, $target . DIRECTORY_SEPARATOR . $entry);
        }
    }

    /**
     * Writes a copy of system.php with the playtest spawn substituted in.
     *
     * The rest of the author's system data — party, battle settings, title
     * screen — is preserved exactly, so the playtest is the real game.
     *
     * @param string $sourcePath The project's system.php.
     * @param string $targetPath The overlay's system.php.
     * @param string $mapId The map to start on.
     * @param int $spawnX The spawn column.
     * @param int $spawnY The spawn row.
     * @return void
     */
    private static function writeSystemOverride(
        string $sourcePath,
        string $targetPath,
        string $mapId,
        int $spawnX,
        int $spawnY,
    ): void {
        if (! is_file($sourcePath)) {
            throw new RuntimeException("The project has no assets/Data/system.php to base a playtest on.");
        }

        try {
            $payload = require $sourcePath;
        } catch (Throwable $throwable) {
            throw new RuntimeException("Unable to read system.php: {$throwable->getMessage()}.");
        }

        if (! is_array($payload)) {
            throw new RuntimeException('system.php did not return an array.');
        }

        if (! PhpValueExporter::isExportable($payload)) {
            throw new RuntimeException(
                'system.php contains PHP objects, so a playtest spawn cannot be written without rewriting them.',
            );
        }

        $player = $payload['startingPositions']['player'] ?? [];
        $player = is_array($player) ? $player : [];
        $player['destinationMap'] = $mapId;
        $player['spawnPoint'] = ['x' => $spawnX, 'y' => $spawnY];
        $player['spawnSprite'] = $player['spawnSprite'] ?? ['^'];
        $payload['startingPositions']['player'] = $player;

        PhpDataFile::writeTransactionally(
            $targetPath,
            "<?php\n\n// Generated by the Ichiloto editor for a playtest run.\n// The author's project was not modified.\nreturn "
            . PhpValueExporter::export($payload)
            . ";\n",
        );
    }

    /**
     * Recursively removes a directory of symlinks and generated files.
     *
     * @param string $directory The directory to remove.
     * @return void
     */
    private static function removeTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            // is_dir() follows symlinks, so guard on is_link() first —
            // otherwise this would descend into the author's project.
            if (is_link($path) || is_file($path)) {
                @unlink($path);
                continue;
            }

            self::removeTree($path);
        }

        @rmdir($directory);
    }
}
