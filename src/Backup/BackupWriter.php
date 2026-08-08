<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Backup;

/**
 * Writes timestamped copies of project files immediately before a save
 * overwrites them.
 *
 * The design choice, stated plainly: the editor never silently writes an
 * author's source files, so "autosave" here means *a backup taken at the
 * moment of an overwriting save*, not a background write into the original.
 * Nothing happens at all unless {@see BackupSettings} is enabled, and every
 * byte written lands under the configured backup directory — never next to
 * the source file, and never inside a map folder the editor also scans.
 */
final class BackupWriter
{
    /**
     * @var string[] The backup paths written this session, newest last.
     */
    public private(set) array $writtenPaths = [];

    /**
     * @param BackupSettings $settings The resolved backup policy.
     * @param string $projectRoot The project root, used to mirror relative paths.
     */
    public function __construct(
        public readonly BackupSettings $settings,
        private readonly string $projectRoot,
    ) {
    }

    /**
     * Returns whether backups are enabled for this session.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->settings->isEnabled;
    }

    /**
     * Backs up every existing path, pruning older copies afterwards.
     *
     * Missing paths are skipped silently: a first save has nothing to
     * preserve. Failures are reported rather than thrown — a backup problem
     * must never block the save the author asked for.
     *
     * @param string ...$paths The source paths about to be overwritten.
     * @return array{written: int, skipped: int, failed: string[]}
     */
    public function backup(string ...$paths): array
    {
        $result = ['written' => 0, 'skipped' => 0, 'failed' => []];

        if (! $this->isEnabled()) {
            $result['skipped'] = count($paths);

            return $result;
        }

        $timestamp = date('Ymd-His');

        foreach ($paths as $path) {
            if ($path === '' || ! is_file($path)) {
                $result['skipped']++;
                continue;
            }

            $destination = $this->resolveDestination($path, $timestamp);
            $directory = dirname($destination);

            if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
                $result['failed'][] = $path;
                continue;
            }

            if (! @copy($path, $destination)) {
                $result['failed'][] = $path;
                continue;
            }

            $this->writtenPaths[] = $destination;
            $result['written']++;
            $this->prune($destination);
        }

        return $result;
    }

    /**
     * Resolves the backup path for one source file.
     *
     * @param string $path The source path.
     * @param string $timestamp The shared backup timestamp.
     * @return string
     */
    public function resolveDestination(string $path, string $timestamp): string
    {
        return $this->settings->directory
            . DIRECTORY_SEPARATOR
            . $this->relativize($path)
            . '.' . $timestamp . '.bak';
    }

    /**
     * Returns the source path relative to the project root, falling back to
     * the file name for anything outside it.
     *
     * @param string $path The source path.
     * @return string
     */
    private function relativize(string $path): string
    {
        $root = rtrim($this->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if ($this->projectRoot !== '' && str_starts_with($path, $root)) {
            return substr($path, strlen($root));
        }

        return basename($path);
    }

    /**
     * Removes all but the newest retained backups of one source file.
     *
     * @param string $destination A backup path just written.
     * @return void
     */
    private function prune(string $destination): void
    {
        $stem = preg_replace('/\.\d{8}-\d{6}\.bak$/', '', $destination);

        if (! is_string($stem) || $stem === '') {
            return;
        }

        $existing = glob($stem . '.*.bak') ?: [];

        if (count($existing) <= $this->settings->retain) {
            return;
        }

        sort($existing);
        $stale = array_slice($existing, 0, count($existing) - $this->settings->retain);

        foreach ($stale as $path) {
            @unlink($path);
        }
    }
}
