<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Playtest;

use Ichiloto\Engine\Core\GameLaunchContext;
use Ichiloto\Engine\Core\GameStartupMode;
use Ichiloto\Engine\IO\LaunchDataRoot;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * One playtest of the author's real project, *without writing a single byte
 * into it*: the game runs from the project's own root and reads its own
 * assets, and the Engine's launch context says where it starts and where its
 * player data lives.
 *
 * The launch owns a fresh data root outside the project, holding a copy of
 * the author's player settings (so a playtest honours their volume and mute)
 * and a logs directory, and nothing else: settings changed in the playtest,
 * its saves and its logs all stay there, and the author's own saves are never
 * read or written. Where the game starts (the title, a map cell, a cinematic
 * on arrival, or a battle test) is launch intent the Engine validates; no
 * project file is copied or rewritten for it.
 */
final class PlaytestLaunch
{
    /** The player's own settings in the data directory, as the Engine's PlayerSettings names them. */
    private const string PLAYER_SETTINGS = 'player-settings.json';

    /** The directory in the data root that receives the launch's output. */
    private const string LOG_DIRECTORY = 'logs';

    /**
     * @param string $projectRoot The real project root, canonical.
     * @param string $dataRoot The isolated data root this launch owns, canonical.
     * @param array<string, mixed> $context The Engine launch context, data root included.
     * @param string|null $mapId The map the playtest starts on; null from the title or in a battle test.
     * @param int|null $x The starting column.
     * @param int|null $y The starting row.
     */
    private function __construct(
        public readonly string $projectRoot,
        public readonly string $dataRoot,
        private readonly array $context,
        public readonly ?string $mapId = null,
        public readonly ?int $x = null,
        public readonly ?int $y = null,
    ) {
    }

    /** A new game starting on a map cell, past the title. */
    public static function fromCell(string $projectRoot, string $mapId, int $x, int $y): self
    {
        return self::prepare($projectRoot, ['mode' => GameStartupMode::NEW_GAME->value,
            'start' => ['mapId' => $mapId, 'x' => $x, 'y' => $y]], $mapId, $x, $y);
    }

    /** The game from its title with the project's own starting position: the opening as a player first meets it. */
    public static function fromTitle(string $projectRoot): self
    {
        return self::prepare($projectRoot, ['mode' => GameStartupMode::TITLE->value]);
    }

    /** A new game starting on a map cell that plays a cinematic once the player has arrived. */
    public static function fromCinematic(string $projectRoot, string $mapId, int $x, int $y, string $cinematicId): self
    {
        return self::prepare($projectRoot, ['mode' => GameStartupMode::NEW_GAME->value,
            'start' => ['mapId' => $mapId, 'x' => $x, 'y' => $y], 'cinematicId' => $cinematicId], $mapId, $x, $y);
    }

    /**
     * A battle test, as the Engine's ProjectBattleTest writes it: the troop,
     * and the party and arena when it sets them. It is the battle test as the
     * author has it now, unsaved edits included; the project's own is not read.
     *
     * @param array<string, mixed> $battleTest
     */
    public static function fromBattleTest(string $projectRoot, array $battleTest): self
    {
        return self::prepare($projectRoot, ['battleTest' => $battleTest]);
    }

    /** Where the launch's own output goes: the play command's, and the game's standard output. */
    public function getLogPath(): string
    {
        return $this->dataRoot . DIRECTORY_SEPARATOR . self::LOG_DIRECTORY . DIRECTORY_SEPARATOR . 'playtest.log';
    }

    /**
     * The environment the play command runs with: this one's, carrying the
     * launch context, and without an inherited start mode that would compete
     * with it. The Engine consumes the context once and clears it, so it never
     * reaches a process the game starts.
     *
     * @return array<string, string>
     */
    public function buildEnvironment(): array
    {
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        unset($environment[GameStartupMode::ENVIRONMENT_VARIABLE]);
        $environment[GameLaunchContext::ENVIRONMENT_VARIABLE] = self::encodeContext($this->context);

        return $environment;
    }

    /**
     * The launch context as the Engine reads it.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Removes the data root and everything the playtest wrote there. Nothing
     * in it links anywhere (the Engine refuses data that follows a link), and
     * a link is still only unlinked, never followed into what it names.
     */
    public function destroy(): void
    {
        self::removeTree($this->dataRoot);
    }

    /**
     * Checks the intent the Engine's way, then fills a fresh data root.
     *
     * @param array<string, mixed> $intent The launch context without its data root.
     * @throws RuntimeException When the intent is not one the Engine reads, or the data root cannot be made.
     */
    private static function prepare(string $projectRoot, array $intent, ?string $mapId = null, ?int $x = null, ?int $y = null): self
    {
        $project = realpath($projectRoot);
        if ($project === false || ! is_dir($project)) {
            throw new RuntimeException("The project to playtest is not at {$projectRoot}.");
        }
        try {
            GameLaunchContext::fromArray($intent);
        } catch (InvalidArgumentException $invalid) {
            throw new RuntimeException('The game cannot start that way: ' . $invalid->getMessage(), previous: $invalid);
        }

        $created = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('ichiloto-playtest-', true);
        if (! @mkdir($created, 0o700, true) && ! is_dir($created)) {
            throw new RuntimeException("Unable to create the playtest's data directory at {$created}.");
        }
        // The Engine takes the data root only by its canonical path: the temporary directory may sit behind a link.
        $root = realpath($created) ?: $created;

        try {
            if (! @mkdir($root . DIRECTORY_SEPARATOR . self::LOG_DIRECTORY, 0o700)) {
                throw new RuntimeException("Unable to create the playtest's log directory in {$root}.");
            }
            self::copyPlayerSettings($project, $root);
            touch($root . DIRECTORY_SEPARATOR . self::LOG_DIRECTORY . DIRECTORY_SEPARATOR . 'playtest.log');
            LaunchDataRoot::validate($root, $project);
            $context = [...$intent, 'dataRoot' => $root];
            self::encodeContext($context);
        } catch (InvalidArgumentException $invalid) {
            self::removeTree($root);

            throw new RuntimeException('The playtest cannot keep its data apart from the project: ' . $invalid->getMessage(), previous: $invalid);
        } catch (\Throwable $throwable) {
            self::removeTree($root);

            throw $throwable;
        }

        return new self($project, $root, $context, $mapId, $x, $y);
    }

    /**
     * Copies the author's player settings into the data root: volume and
     * mute, controls and other preferences belong to the player, so a
     * playtest honours them, while the copy keeps any change made during the
     * playtest out of the project. Saves stay behind.
     */
    private static function copyPlayerSettings(string $projectRoot, string $root): void
    {
        $source = $projectRoot . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR . self::PLAYER_SETTINGS;

        if (is_file($source) && ! copy($source, $root . DIRECTORY_SEPARATOR . self::PLAYER_SETTINGS)) {
            throw new RuntimeException("Unable to copy the player settings into the playtest from {$source}.");
        }
    }

    /**
     * @param array<string, mixed> $context
     * @throws RuntimeException When the context is too large for the Engine to take.
     */
    private static function encodeContext(array $context): string
    {
        try {
            $json = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new RuntimeException('The playtest launch could not be written: ' . $error->getMessage(), previous: $error);
        }
        if (strlen($json) > GameLaunchContext::MAX_TRANSPORT_BYTES) {
            throw new RuntimeException(sprintf('The playtest launch is larger than the %d bytes the game takes.', GameLaunchContext::MAX_TRANSPORT_BYTES));
        }

        return $json;
    }

    private static function removeTree(string $directory): void
    {
        if (is_link($directory) || is_file($directory)) {
            @unlink($directory);

            return;
        }
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($directory . DIRECTORY_SEPARATOR . $entry);
            }
        }

        @rmdir($directory);
    }
}
