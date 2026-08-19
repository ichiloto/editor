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
     * Builds an overlay whose starting map launches a cinematic on arrival.
     *
     * The map is copied into the overlay (its tiles stay a link) with one
     * extra event: an automatic, single-use `CinematicEventTrigger` on the
     * spawn tile naming the cinematic. The game then plays the real asset
     * through its real trigger the moment the playtest begins, and the
     * author's map files are never written.
     *
     * @param string $projectRoot The real project root.
     * @param string $mapId The map to start on.
     * @param int $spawnX The spawn column.
     * @param int $spawnY The spawn row.
     * @param string $cinematicId The cinematic to launch.
     */
    public static function createForCinematic(string $projectRoot, string $mapId, int $spawnX, int $spawnY, string $cinematicId): self
    {
        $overlay = self::create($projectRoot, $mapId, $spawnX, $spawnY);

        try {
            $overlay->installCinematicLaunch(rtrim($projectRoot, DIRECTORY_SEPARATOR), $cinematicId);
        } catch (\Throwable $throwable) {
            $overlay->destroy();

            throw $throwable;
        }

        return $overlay;
    }

    /**
     * Replaces the overlay's start map with a copy carrying the launch trigger.
     */
    private function installCinematicLaunch(string $projectRoot, string $cinematicId): void
    {
        $mapsSource = $projectRoot . '/assets/Maps';
        $mapsTarget = $this->root . '/assets/Maps';
        $segments = explode('/', trim(str_replace('\\', '/', $this->mapId), '/'));
        $leaf = $segments[array_key_last($segments)];
        $mapSourceDirectory = $mapsSource . '/' . implode('/', $segments);

        foreach (['data', 'map', 'event'] as $part) {
            if (! is_file($mapSourceDirectory . '/' . $leaf . '.' . $part . '.php')) {
                throw new RuntimeException(sprintf('Map %s has no %s file to playtest from.', $this->mapId, $part));
            }
        }

        // assets/Maps was one link; rebuild it as real directories down to
        // the start map, linking every sibling on the way.
        if (is_link($mapsTarget)) {
            unlink($mapsTarget);
        }

        mkdir($mapsTarget, 0777, true);
        $currentSource = $mapsSource;
        $currentTarget = $mapsTarget;

        foreach ($segments as $depth => $segment) {
            $isLast = $depth === count($segments) - 1;
            self::mirrorDirectory($currentSource, $currentTarget, [$segment]);
            $currentSource .= '/' . $segment;
            $currentTarget .= '/' . $segment;
            mkdir($currentTarget, 0777, true);

            if (! $isLast) {
                continue;
            }

            self::mirrorDirectory($currentSource, $currentTarget, [$leaf . '.data.php', $leaf . '.event.php']);
            self::writeCinematicLaunchFiles($currentSource, $currentTarget, $leaf, $cinematicId);
        }
    }

    /**
     * Writes the start map's data and event-layer files with the launch
     * trigger on the spawn tile.
     */
    private function writeCinematicLaunchFiles(string $sourceDirectory, string $targetDirectory, string $leaf, string $cinematicId): void
    {
        $data = require $sourceDirectory . '/' . $leaf . '.data.php';
        $eventText = require $sourceDirectory . '/' . $leaf . '.event.php';

        if (! is_array($data) || ! is_string($eventText)) {
            throw new RuntimeException(sprintf('Map %s could not be read for the playtest.', $this->mapId));
        }

        if (! PhpValueExporter::isExportable($data)) {
            throw new RuntimeException(sprintf('Map %s holds PHP objects, so a launch trigger cannot be written into a copy.', $this->mapId));
        }

        $events = is_array($data['events'] ?? null) ? $data['events'] : [];
        $lines = preg_split('/\r\n|\n|\r/', rtrim($eventText, "\r\n")) ?: [];
        $marker = self::freeEventMarker($events, $lines);

        if ($marker === null) {
            throw new RuntimeException(sprintf('Map %s has no free event marker for the launch trigger.', $this->mapId));
        }

        $row = $lines[$this->spawnY] ?? null;

        if ($row === null) {
            throw new RuntimeException(sprintf('Spawn row %d is outside map %s.', $this->spawnY, $this->mapId));
        }

        $symbols = preg_split('//u', $row, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (! isset($symbols[$this->spawnX])) {
            throw new RuntimeException(sprintf('Spawn column %d is outside map %s.', $this->spawnX, $this->mapId));
        }

        $symbols[$this->spawnX] = $marker;
        $lines[$this->spawnY] = implode('', $symbols);
        $events[$marker] = [
            'class' => 'Ichiloto\\Engine\\Events\\Triggers\\CinematicEventTrigger',
            'data' => ['cinematicId' => $cinematicId, 'mode' => 'auto', 'reusable' => false],
        ];
        $data['events'] = $events;

        PhpDataFile::writeTransactionally(
            $targetDirectory . '/' . $leaf . '.data.php',
            "<?php\n\n// Generated by the Ichiloto editor for a cinematic playtest.\n// The author's map was not modified.\nreturn "
            . PhpValueExporter::export($data)
            . ";\n",
        );
        PhpDataFile::writeTransactionally(
            $targetDirectory . '/' . $leaf . '.event.php',
            "<?php\n\n// Generated by the Ichiloto editor for a cinematic playtest.\nreturn <<<'ICHILOTO_EVENT_MAP'\n" . implode("\n", $lines) . "\nICHILOTO_EVENT_MAP;\n",
        );
    }

    /**
     * Picks a one-column marker the map does not use yet.
     *
     * @param array<string, mixed> $events
     * @param string[] $lines
     */
    private static function freeEventMarker(array $events, array $lines): ?string
    {
        $layer = implode('', $lines);

        foreach (str_split('@!$%&*+=?^{}|~<>ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789') as $candidate) {
            if (! array_key_exists($candidate, $events) && ! str_contains($layer, $candidate)) {
                return $candidate;
            }
        }

        return null;
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
