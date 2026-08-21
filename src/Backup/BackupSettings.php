<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Backup;

/**
 * The opt-in backup configuration for a project.
 *
 * Backups are **off by default**: an editor that starts writing extra files
 * into someone's project without being asked is exactly the kind of surprise
 * Phase 2 spent its budget removing. Authors turn them on per project in
 * `ichiloto.json`:
 *
 * ```json
 * {
 *   "editor": {
 *     "backups": { "enabled": true, "retain": 5, "directory": ".ichiloto/backups" }
 *   }
 * }
 * ```
 *
 * The environment always wins over the file, so a session can be run with
 * backups forced on (or off) without editing the project:
 * `ICHILOTO_EDITOR_BACKUPS=1` / `=0`, and `ICHILOTO_EDITOR_BACKUP_RETAIN=10`.
 */
final readonly class BackupSettings
{
    /**
     * The default backup directory, relative to the project root.
     */
    public const string DEFAULT_DIRECTORY = '.ichiloto/backups';
    /**
     * The default number of retained backups per source file.
     */
    public const int DEFAULT_RETAIN = 5;

    /**
     * @param bool $isEnabled Whether a backup is written before an overwriting save.
     * @param string $directory The absolute backup root.
     * @param int $retain The number of backups retained per source file.
     */
    public function __construct(
        public bool $isEnabled = false,
        public string $directory = '',
        public int $retain = self::DEFAULT_RETAIN,
    ) {
    }

    /**
     * Resolves the backup settings for a project root.
     *
     * @param string $projectRoot The project root.
     * @return self
     */
    public static function fromProject(string $projectRoot): self
    {
        $projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $configured = self::readProjectConfig($projectRoot);
        $isEnabled = (bool) ($configured['enabled'] ?? false);
        $directory = (string) ($configured['directory'] ?? self::DEFAULT_DIRECTORY);
        $retain = (int) ($configured['retain'] ?? self::DEFAULT_RETAIN);

        $environmentToggle = self::readEnvironmentFlag('ICHILOTO_EDITOR_BACKUPS');

        if ($environmentToggle !== null) {
            $isEnabled = $environmentToggle;
        }

        $environmentRetain = trim((string) getenv('ICHILOTO_EDITOR_BACKUP_RETAIN'));

        if ($environmentRetain !== '' && ctype_digit($environmentRetain)) {
            $retain = (int) $environmentRetain;
        }

        if ($directory === '' || ! self::isAbsolutePath($directory)) {
            $directory = $projectRoot . DIRECTORY_SEPARATOR . ltrim($directory === '' ? self::DEFAULT_DIRECTORY : $directory, DIRECTORY_SEPARATOR);
        }

        return new self(
            isEnabled: $isEnabled,
            directory: rtrim($directory, DIRECTORY_SEPARATOR),
            retain: max(1, $retain),
        );
    }

    /**
     * Returns the short footer/help description of the current policy.
     *
     * @return string
     */
    public function describe(): string
    {
        return $this->isEnabled
            ? sprintf('Backups on (keep %d) → %s', $this->retain, $this->directory)
            : 'Backups off (enable with editor.backups.enabled in ichiloto.json)';
    }

    /**
     * Reads the optional `editor.backups` block from ichiloto.json.
     *
     * @param string $projectRoot The project root.
     * @return array<string, mixed>
     */
    private static function readProjectConfig(string $projectRoot): array
    {
        $configPath = $projectRoot . DIRECTORY_SEPARATOR . 'ichiloto.json';

        if (! is_file($configPath)) {
            return [];
        }

        $config = json_decode((string) file_get_contents($configPath), true);

        if (! is_array($config)) {
            return [];
        }

        $editor = $config['editor'] ?? null;

        if (! is_array($editor)) {
            return [];
        }

        $backups = $editor['backups'] ?? null;

        return is_array($backups) ? $backups : [];
    }

    /**
     * Reads a tri-state boolean environment flag.
     *
     * @param string $name The environment variable name.
     * @return bool|null True/false when set to a recognized value, null when unset.
     */
    private static function readEnvironmentFlag(string $name): ?bool
    {
        $value = strtolower(trim((string) getenv($name)));

        if ($value === '') {
            return null;
        }

        if (in_array($value, ['1', 'on', 'true', 'yes'], true)) {
            return true;
        }

        if (in_array($value, ['0', 'off', 'false', 'no'], true)) {
            return false;
        }

        return null;
    }

    /**
     * Returns whether a configured path is absolute.
     *
     * @param string $path The configured path.
     * @return bool
     */
    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
