<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use RuntimeException;

/**
 * Manages the project's class database asset.
 */
final class ProjectClassDatabase
{
    /**
     * @param ProjectClass[] $classes
     */
    public function __construct(
        public readonly string $path,
        private array $classes = [],
        private bool $isDirty = false,
    ) {
    }

    /**
     * Loads the class database from the project.
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
            . 'classes.php';

        if (! is_file($path)) {
            return new self($path, []);
        }

        $payload = require $path;

        if (! is_array($payload)) {
            throw new RuntimeException("Unable to parse {$path}.");
        }

        $classes = [];

        foreach (array_values(array_filter($payload, 'is_array')) as $index => $classPayload) {
            $classes[] = ProjectClass::fromArray($classPayload, $index + 1);
        }

        return new self($path, $classes);
    }

    /**
     * Returns the stored class records.
     *
     * @return ProjectClass[]
     */
    public function getClasses(): array
    {
        return array_values($this->classes);
    }

    /**
     * Returns whether the database has unsaved changes.
     *
     * @return bool
     */
    public function isDirty(): bool
    {
        if ($this->isDirty) {
            return true;
        }

        foreach ($this->classes as $class) {
            if ($class->isDirty()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the class at the requested index.
     *
     * @param int $index The class index.
     * @return ProjectClass|null
     */
    public function getClassByIndex(int $index): ?ProjectClass
    {
        return $this->classes[$index] ?? null;
    }

    /**
     * Adds a blank class entry and returns its index.
     *
     * @param string $name The default class name.
     * @return int
     */
    public function addClass(string $name = 'New Class'): int
    {
        $nextId = 1;

        foreach ($this->classes as $class) {
            $nextId = max($nextId, $class->id + 1);
        }

        $this->classes[] = ProjectClass::createBlank($nextId, $name);
        $this->isDirty = true;

        return count($this->classes) - 1;
    }

    /**
     * Removes the class at the given index.
     *
     * The database asset is only rewritten on save, so undo (re-insert at
     * the same index) fully restores the entry.
     *
     * @param int $index The class index.
     * @return ProjectClass|null The removed class, or null when the index is unknown.
     */
    public function removeClass(int $index): ?ProjectClass
    {
        $classes = array_values($this->classes);
        $class = $classes[$index] ?? null;

        if (! $class instanceof ProjectClass) {
            return null;
        }

        array_splice($classes, $index, 1);
        $this->classes = $classes;
        $this->isDirty = true;

        return $class;
    }

    /**
     * Re-inserts a previously removed class (the undo of removeClass()).
     *
     * @param int $index The index to restore the class at.
     * @param ProjectClass $class The class to restore.
     * @return void
     */
    public function insertClass(int $index, ProjectClass $class): void
    {
        $classes = array_values($this->classes);
        $index = max(0, min(count($classes), $index));
        array_splice($classes, $index, 0, [$class]);
        $this->classes = $classes;
        $this->isDirty = true;
    }

    /**
     * Updates one class field.
     *
     * @param int $index The class index.
     * @param string $field The field identifier.
     * @param mixed $value The replacement value.
     * @return void
     */
    public function setField(int $index, string $field, mixed $value): void
    {
        $class = $this->getClassByIndex($index);

        if (! $class instanceof ProjectClass) {
            return;
        }

        $class->setField($field, $value);
        $this->isDirty = true;
    }

    /**
     * Writes the database asset back to disk.
     *
     * @return void
     */
    public function save(): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create {$directory}.");
        }

        $payload = "<?php\n\nreturn " . self::exportPhpValue(array_map(
            static fn(ProjectClass $class): array => $class->toArray(),
            $this->getClasses()
        )) . ";\n";

        self::writeFileTransactionally($this->path, $payload);
        $this->isDirty = false;
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
