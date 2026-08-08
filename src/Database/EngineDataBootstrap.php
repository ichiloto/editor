<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use Throwable;

/**
 * Registers the minimum engine state an authored data file needs in order to
 * be evaluated outside a running game.
 *
 * `assets/Data/enemies.php` builds `new Enemy(...)` values, and
 * `BattleRewards` reaches into `ConfigStore::get(ItemStore::class)` while it
 * constructs. Without a registered item store the file throws, and the editor
 * would report "enemies could not be evaluated" for a reason that has nothing
 * to do with the author's data.
 *
 * Everything here is best-effort and failure is silent: a project that cannot
 * satisfy the bootstrap simply keeps the honest read-only reason its data file
 * produced.
 */
final class EngineDataBootstrap
{
    private static bool $hasAttempted = false;

    /**
     * Registers the engine's item store once per process.
     *
     * The store constructor reads `assets/Data/items.php` relative to the
     * process working directory, so the project root is borrowed for the call
     * and restored immediately afterwards.
     *
     * @param string $projectRoot The project root.
     * @return void
     */
    public static function ensure(string $projectRoot): void
    {
        if (self::$hasAttempted) {
            return;
        }

        self::$hasAttempted = true;

        if (! class_exists(ConfigStore::class) || ! class_exists(ItemStore::class)) {
            return;
        }

        if (ConfigStore::has(ItemStore::class)) {
            return;
        }

        $previousDirectory = getcwd();

        try {
            if (! @chdir($projectRoot)) {
                return;
            }

            ConfigStore::put(ItemStore::class, new ItemStore());
        } catch (Throwable) {
            // The project's items.php is unreadable or malformed; the
            // categories that depend on it will say so themselves.
        } finally {
            if (is_string($previousDirectory)) {
                @chdir($previousDirectory);
            }
        }
    }

    /**
     * Allows tests to re-run the bootstrap against a different project.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$hasAttempted = false;
    }
}
