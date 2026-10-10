<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Playtest;

use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\Database\PhpValueExporter;
use Ichiloto\Editor\Events\EventMarkers;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Scenes\Arena\ProjectBattleTest;
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
 *  - `.data/`, a fresh directory holding only a copy of the player settings,
 *    so a playtest honours the author's volume and mute but can never
 *    overwrite their save slots.
 *
 * Because maps, graphics, and every other asset are symlinks, the playtest
 * runs against the author's live files — a map saved in the editor is the map
 * the playtest loads.
 */
final class PlaytestOverlay
{
    /** The player's own settings in the data directory, as the Engine's PlayerSettings names them. */
    private const string PLAYER_SETTINGS = 'player-settings.json';

    /**
     * @param string $root The overlay project root.
     * @param string|null $mapId The map the playtest starts on; null for a battle test, which starts on none.
     * @param int|null $spawnX The spawn column.
     * @param int|null $spawnY The spawn row.
     */
    private function __construct(
        public readonly string $root,
        public readonly ?string $mapId = null,
        public readonly ?int $spawnX = null,
        public readonly ?int $spawnY = null,
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
        $root = self::build($projectRoot, static function (array $system) use ($mapId, $spawnX, $spawnY): array {
            $player = $system['startingPositions']['player'] ?? [];
            $player = is_array($player) ? $player : [];
            $player['destinationMap'] = $mapId;
            $player['spawnPoint'] = ['x' => $spawnX, 'y' => $spawnY];
            $player['spawnSprite'] = $player['spawnSprite'] ?? ['^'];
            $system['startingPositions']['player'] = $player;

            return $system;
        }, 'a playtest spawn');

        return new self($root, $mapId, $spawnX, $spawnY);
    }

    /**
     * Builds an overlay that plays the game from its title with the project's own starting position: the opening
     * as a player first meets it, still without touching the author's saves.
     */
    public static function createForTitle(string $projectRoot): self
    {
        return new self(self::build($projectRoot, static fn(array $system): array => $system, 'a playtest from the title'));
    }

    /**
     * Builds an overlay for a battle test: the author's project with its
     * system data's battle test replaced by the one given (unsaved edits
     * included), or removed when it is empty, so `ichiloto battle` reads the
     * party and arena the author set without the project being written.
     *
     * @param array<string, mixed> $battleTest The battle test, as the Engine's ProjectBattleTest writes it.
     */
    public static function createForBattle(string $projectRoot, array $battleTest): self
    {
        return new self(self::build($projectRoot, static function (array $system) use ($battleTest): array {
            unset($system[ProjectBattleTest::SYSTEM_KEY]);

            return $battleTest === [] ? $system : [...$system, ProjectBattleTest::SYSTEM_KEY => $battleTest];
        }, 'a battle test'));
    }

    /**
     * Fills a fresh overlay directory: links to the project throughout, a
     * fresh `.data` holding only the player settings, and a system.php the
     * given change has rewritten.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $rewriteSystem
     * @param string $purpose What the rewrite is for, as an error names it.
     * @return string The overlay root.
     */
    private static function build(string $projectRoot, callable $rewriteSystem, string $purpose): string
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
            self::copyPlayerSettings($projectRoot, $root);

            self::writeSystemOverride(
                $dataSource . DIRECTORY_SEPARATOR . 'system.php',
                $dataTarget . DIRECTORY_SEPARATOR . 'system.php',
                $rewriteSystem,
                $purpose,
            );
        } catch (\Throwable $throwable) {
            // An overlay that could not be finished is not left behind: what
            // was mirrored so far is links and two generated entries, removed
            // without ever following a link into the author's project.
            self::removeTree($root);

            throw $throwable;
        }

        return $root;
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
        if ($this->mapId === null || $this->spawnX === null || $this->spawnY === null) {
            throw new RuntimeException('A cinematic playtest starts on a map cell.');
        }

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
        $eventText = MapGridSource::readFile($sourceDirectory . '/' . $leaf . '.event.php');
        $data = require $sourceDirectory . '/' . $leaf . '.data.php';

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
            MapGridSource::buildSource(
                implode("\n", $lines),
                'ICHILOTO_EVENT_MAP',
                "// Generated by the Ichiloto editor for a cinematic playtest.\n",
            ),
        );
    }

    /**
     * Picks a marker the map does not use yet ({@see EventMarkers}): neither
     * defined nor painted anywhere on its event layer.
     *
     * @param array<string, mixed> $events
     * @param string[] $lines
     */
    private static function freeEventMarker(array $events, array $lines): ?string
    {
        preg_match_all('/\X/u', implode('', $lines), $symbols);

        return EventMarkers::findFreeMarker([...array_map(strval(...), array_keys($events)), ...$symbols[0]]);
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
     * Copies the author's player settings into the overlay's isolated data
     * directory: volume and mute, controls and other preferences belong to
     * the player, so a playtest honours them, while the copy keeps any
     * change made during the playtest out of the project. Saves stay behind.
     */
    private static function copyPlayerSettings(string $projectRoot, string $root): void
    {
        $source = $projectRoot . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR . self::PLAYER_SETTINGS;

        if (is_file($source) && ! copy($source, $root . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR . self::PLAYER_SETTINGS)) {
            throw new RuntimeException("Unable to copy the player settings into the playtest from {$source}.");
        }
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
     * Writes a copy of system.php with one change made: a playtest's spawn,
     * or a battle test's setup.
     *
     * The rest of the author's system data — party, battle settings, title
     * screen — is preserved exactly, so the playtest is the real game.
     *
     * @param string $sourcePath The project's system.php.
     * @param string $targetPath The overlay's system.php.
     * @param callable(array<string, mixed>): array<string, mixed> $rewriteSystem The change.
     * @param string $purpose What the change is for, as an error names it.
     * @return void
     */
    private static function writeSystemOverride(
        string $sourcePath,
        string $targetPath,
        callable $rewriteSystem,
        string $purpose,
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
                "system.php contains PHP objects, so {$purpose} cannot be written without rewriting them.",
            );
        }

        $payload = $rewriteSystem($payload);

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
