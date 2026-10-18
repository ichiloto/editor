<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use RuntimeException;

/**
 * A disposable asset root holding the project's maps as saving the
 * workspace would leave them, for checks that read map folders from disk
 * (the Engine's reachability analysis) while maps have unsaved changes.
 *
 * Its `Maps` folder is a copy of the project's, with every unsaved map
 * written by that map's own writer ({@see ProjectMap::exportSources()});
 * every other asset folder is a link to the project's own, read in place.
 * Nothing of the project is written. {@see dispose()} removes the stage.
 */
final class UnsavedMapStage
{
    private function __construct(public readonly string $assetRoot, private readonly string $root)
    {
    }

    /**
     * Stages the workspace's unsaved maps; null when no map has unsaved
     * changes, so the project's own asset root is already what saving leaves.
     *
     * @throws RuntimeException When the stage cannot be made; nothing is left behind.
     */
    public static function prepare(ProjectWorkspace $workspace): ?self
    {
        $unsaved = array_filter($workspace->maps, static fn(ProjectMap $map): bool => $map->isDirty());
        if ($unsaved === []) {
            return null;
        }

        $assets = $workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets';
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ichiloto-unsaved-maps-' . bin2hex(random_bytes(6));
        $stage = new self($root . DIRECTORY_SEPARATOR . 'assets', $root);

        try {
            if (! @mkdir($stage->assetRoot, 0o700, true)) {
                throw new RuntimeException('Its folder could not be made.');
            }
            foreach (array_diff(scandir($assets) ?: [], ['.', '..', 'Maps']) as $entry) {
                if (! @symlink($assets . DIRECTORY_SEPARATOR . $entry, $stage->assetRoot . DIRECTORY_SEPARATOR . $entry)) {
                    throw new RuntimeException(sprintf('assets/%s could not be linked.', $entry));
                }
            }
            self::copyFolder($workspace->getMapsRoot(), $stage->assetRoot . DIRECTORY_SEPARATOR . 'Maps');
            foreach ($unsaved as $map) {
                $map->exportSources($stage->assetRoot . DIRECTORY_SEPARATOR . 'Maps');
            }
        } catch (\Throwable $failure) {
            $stage->dispose();

            throw new RuntimeException('The unsaved maps could not be staged: ' . $failure->getMessage(), previous: $failure);
        }

        return $stage;
    }

    /** Removes the stage: the copied maps, and the links without what they point at. */
    public function dispose(): void
    {
        self::removeFolder($this->root);
    }

    private static function copyFolder(string $from, string $to): void
    {
        if (! is_dir($to) && ! @mkdir($to, 0o700, true)) {
            throw new RuntimeException(sprintf('%s could not be made.', $to));
        }
        foreach (array_diff(scandir($from) ?: [], ['.', '..']) as $entry) {
            $source = $from . DIRECTORY_SEPARATOR . $entry;
            $target = $to . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($source) && ! is_link($source)) {
                self::copyFolder($source, $target);
            } elseif (! @copy($source, $target)) {
                throw new RuntimeException(sprintf('%s could not be copied.', $source));
            }
        }
    }

    private static function removeFolder(string $folder): void
    {
        if (! is_dir($folder) || is_link($folder)) {
            return;
        }
        foreach (array_diff(scandir($folder) ?: [], ['.', '..']) as $entry) {
            $path = $folder . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path) || ! is_dir($path)) {
                @unlink($path);
            } else {
                self::removeFolder($path);
            }
        }
        @rmdir($folder);
    }
}
