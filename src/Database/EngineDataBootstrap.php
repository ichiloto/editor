<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\ProjectDirectoryContext;
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
    private static ?string $loadedProjectRoot = null;

    /**
     * Registers the engine's item store for the current project.
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
        if (! class_exists(ConfigStore::class) || ! class_exists(ItemStore::class)) {
            return;
        }

        $canonicalRoot = realpath($projectRoot);

        if ($canonicalRoot === false) {
            return;
        }

        if (self::$loadedProjectRoot === $canonicalRoot && ConfigStore::has(ItemStore::class)) {
            return;
        }

        try {
            ProjectDirectoryContext::run($canonicalRoot, static function (string $root): void {
                ConfigStore::put(ItemStore::class, new ItemStore());
                self::$loadedProjectRoot = $root;
            });
        } catch (Throwable) {
            // The project's items.php is unreadable or malformed; the
            // categories that depend on it will say so themselves.
        }
    }

    /**
     * Allows tests to re-run the bootstrap against a different project.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$loadedProjectRoot = null;
    }
}
