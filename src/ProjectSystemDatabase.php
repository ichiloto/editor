<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use RuntimeException;

/**
 * Manages the project's system database asset.
 */
final class ProjectSystemDatabase
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $path,
        private array $data = [],
        private bool $isDirty = false,
    ) {
    }

    /**
     * Loads the system database from the project.
     *
     * @param string $projectRoot The project root.
     * @return self
     */
    public static function fromProject(string $projectRoot): self
    {
        $path = rtrim($projectRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'assets'
            . DIRECTORY_SEPARATOR
            . 'Data'
            . DIRECTORY_SEPARATOR
            . 'system.php';

        if (! is_file($path)) {
            return new self($path, self::normalize([]));
        }

        $payload = require $path;

        if (! is_array($payload)) {
            throw new RuntimeException("Unable to parse {$path}.");
        }

        return new self($path, self::normalize($payload));
    }

    /**
     * Returns whether the system database has unsaved changes.
     *
     * @return bool
     */
    public function isDirty(): bool
    {
        return $this->isDirty;
    }

    /**
     * Returns the configured battle engine id.
     *
     * @return string
     */
    public function getBattleEngine(): string
    {
        return strval($this->data['battle']['engine'] ?? 'traditional');
    }

    /**
     * Returns the configured ATB mode.
     *
     * @return string
     */
    public function getAtbMode(): string
    {
        return strval($this->data['battle']['activeTime']['mode'] ?? 'wait');
    }

    /**
     * Returns the configured ATB base fill rate.
     *
     * @return int
     */
    public function getAtbBaseFillRate(): int
    {
        return max(1, intval($this->data['battle']['activeTime']['baseFillRate'] ?? 35));
    }

    /**
     * Returns the configured ATB speed factor percent.
     *
     * @return int
     */
    public function getAtbSpeedFactorPercent(): int
    {
        return max(0, intval($this->data['battle']['activeTime']['speedFactorPercent'] ?? 35));
    }

    /**
     * Applies one editable system field.
     *
     * @param string $field The field identifier.
     * @param mixed $value The replacement value.
     * @return void
     */
    public function setField(string $field, mixed $value): void
    {
        $this->data = self::normalize($this->data);

        switch ($field) {
            case 'battleEngine':
                $this->data['battle']['engine'] = strval($value) === 'active_time'
                    ? 'active_time'
                    : 'traditional';
                break;

            case 'atbMode':
                $this->data['battle']['activeTime']['mode'] = 'wait';
                break;

            case 'atbBaseFillRate':
                $this->data['battle']['activeTime']['baseFillRate'] = max(1, intval($value));
                break;

            case 'atbSpeedFactorPercent':
                $this->data['battle']['activeTime']['speedFactorPercent'] = max(0, intval($value));
                break;

            default:
                return;
        }

        $this->isDirty = true;
    }

    /**
     * Writes the system asset back to disk.
     *
     * @return void
     */
    public function save(): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create {$directory}.");
        }

        $payload = "<?php\n\nreturn " . self::exportPhpValue(self::normalize($this->data)) . ";\n";
        self::writeFileTransactionally($this->path, $payload);
        $this->isDirty = false;
    }

    /**
     * Normalizes the loaded system data.
     *
     * @param array<string, mixed> $data The raw system data.
     * @return array<string, mixed>
     */
    private static function normalize(array $data): array
    {
        $data['title'] ??= basename(dirname(dirname(dirname($data['path'] ?? 'project'))));
        $data['currency'] = is_array($data['currency'] ?? null)
            ? $data['currency']
            : ['amount' => 0];
        $data['startingParty'] = is_array($data['startingParty'] ?? null)
            ? $data['startingParty']
            : [];
        $data['startingInventory'] = is_array($data['startingInventory'] ?? null)
            ? $data['startingInventory']
            : [];
        $data['startingPositions'] = is_array($data['startingPositions'] ?? null)
            ? $data['startingPositions']
            : [
                'player' => [
                    'destinationMap' => 'overworld',
                    'spawnPoint' => ['x' => 0, 'y' => 0],
                    'spawnSprite' => ['^'],
                ],
            ];
        $battle = is_array($data['battle'] ?? null) ? $data['battle'] : [];
        $activeTime = is_array($battle['activeTime'] ?? null) ? $battle['activeTime'] : [];
        $data['battle'] = [
            'engine' => strval($battle['engine'] ?? 'traditional') === 'active_time'
                ? 'active_time'
                : 'traditional',
            'activeTime' => [
                'mode' => 'wait',
                'baseFillRate' => max(1, intval($activeTime['baseFillRate'] ?? 35)),
                'speedFactorPercent' => max(0, intval($activeTime['speedFactorPercent'] ?? 35)),
            ],
        ];

        return $data;
    }

    /**
     * Writes a file using a temp-file swap.
     *
     * @param string $path The destination file.
     * @param string $contents The file contents.
     * @return void
     */
    private static function writeFileTransactionally(string $path, string $contents): void
    {
        $temporaryPath = $path . '.tmp';

        if (file_put_contents($temporaryPath, $contents) === false) {
            throw new RuntimeException("Unable to write temporary file for {$path}.");
        }

        if (! rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new RuntimeException("Unable to replace {$path}.");
        }
    }

    /**
     * Exports a PHP value using short-array syntax.
     *
     * @param mixed $value The value to export.
     * @param int $indentLevel The indentation depth.
     * @return string
     */
    private static function exportPhpValue(mixed $value, int $indentLevel = 0): string
    {
        if (! is_array($value)) {
            return var_export($value, true);
        }

        if ($value === []) {
            return '[]';
        }

        $indent = str_repeat('  ', $indentLevel);
        $nextIndent = str_repeat('  ', $indentLevel + 1);
        $isList = array_is_list($value);
        $lines = ['['];

        foreach ($value as $key => $item) {
            $exportedItem = self::exportPhpValue($item, $indentLevel + 1);

            if ($isList) {
                $lines[] = "{$nextIndent}{$exportedItem},";
                continue;
            }

            $exportedKey = var_export($key, true);
            $lines[] = "{$nextIndent}{$exportedKey} => {$exportedItem},";
        }

        $lines[] = "{$indent}]";

        return implode("\n", $lines);
    }
}