<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use RuntimeException;

/**
 * The project's quest database (assets/Data/quests.php).
 *
 * The data file may be absent — the database then starts empty and the file
 * is created on the first save.
 */
final class ProjectQuestDatabase
{
    /**
     * @param string $path The quests.php path.
     * @param ProjectQuest[] $quests The loaded quests.
     * @param bool $isDirty Whether structural changes are unsaved.
     */
    public function __construct(
        public readonly string $path,
        private array $quests = [],
        private bool $isDirty = false,
    ) {
    }

    /**
     * Loads the quest database from a project root.
     *
     * @param string $projectRoot The project root.
     * @return self
     */
    public static function fromProject(string $projectRoot): self
    {
        $path = rtrim($projectRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'assets'
            . DIRECTORY_SEPARATOR . 'Data'
            . DIRECTORY_SEPARATOR . 'quests.php';

        if (! is_file($path)) {
            return new self($path, []);
        }

        $payload = require $path;

        if (! is_array($payload)) {
            throw new RuntimeException("Unable to parse {$path}.");
        }

        $quests = [];

        foreach (array_values($payload) as $entry) {
            if (is_array($entry)) {
                $quests[] = new ProjectQuest($entry);
            }
        }

        return new self($path, $quests);
    }

    /**
     * @return ProjectQuest[]
     */
    public function getQuests(): array
    {
        return array_values($this->quests);
    }

    public function getQuestByIndex(int $index): ?ProjectQuest
    {
        return $this->quests[$index] ?? null;
    }

    public function isDirty(): bool
    {
        if ($this->isDirty) {
            return true;
        }

        foreach ($this->quests as $quest) {
            if ($quest->isDirty()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Appends a blank quest with a unique id.
     *
     * @param string $name The quest name.
     * @return int The new quest index.
     */
    public function addQuest(string $name = 'New Quest'): int
    {
        $existingIds = array_map(static fn(ProjectQuest $quest): string => $quest->getId(), $this->quests);
        $id = 'new-quest';
        $suffix = 2;

        while (in_array($id, $existingIds, true)) {
            $id = 'new-quest-' . $suffix;
            $suffix++;
        }

        $this->quests[] = ProjectQuest::createBlank($id, $name);
        $this->isDirty = true;

        return count($this->quests) - 1;
    }

    /**
     * Applies one flat settings-field edit onto a quest.
     *
     * @param int $index The quest index.
     * @param string $field The field identifier.
     * @param mixed $value The edited value.
     * @return void
     */
    public function setField(int $index, string $field, mixed $value): void
    {
        $quest = $this->getQuestByIndex($index);

        if ($quest instanceof ProjectQuest) {
            $quest->setField($field, $value);
            $this->isDirty = true;
        }
    }

    /**
     * Appends an objective to a quest.
     *
     * @param int $index The quest index.
     * @param array<string, mixed>|null $objective The objective payload; a placeholder when null.
     * @return int|null The new objective index.
     */
    public function addObjective(int $index, ?array $objective = null): ?int
    {
        $quest = $this->getQuestByIndex($index);

        if (! $quest instanceof ProjectQuest) {
            return null;
        }

        $this->isDirty = true;

        return $quest->addObjective($objective);
    }

    /**
     * Removes an objective from a quest.
     *
     * @param int $index The quest index.
     * @param int $objectiveIndex The objective index.
     * @return array<string, mixed>|null The removed objective payload.
     */
    public function removeObjective(int $index, int $objectiveIndex): ?array
    {
        $quest = $this->getQuestByIndex($index);

        if (! $quest instanceof ProjectQuest) {
            return null;
        }

        $removed = $quest->removeObjective($objectiveIndex);

        if ($removed !== null) {
            $this->isDirty = true;
        }

        return $removed;
    }

    /**
     * Re-inserts an objective at a specific index (undo support).
     *
     * @param int $index The quest index.
     * @param int $objectiveIndex The target objective index.
     * @param array<string, mixed> $objective The objective payload.
     * @return void
     */
    public function insertObjective(int $index, int $objectiveIndex, array $objective): void
    {
        $quest = $this->getQuestByIndex($index);

        if ($quest instanceof ProjectQuest) {
            $quest->insertObjective($objectiveIndex, $objective);
            $this->isDirty = true;
        }
    }

    /**
     * Writes the quest asset back to disk.
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
            static fn(ProjectQuest $quest): array => $quest->toArray(),
            $this->getQuests()
        )) . ";\n";

        self::writeFileTransactionally($this->path, $payload);

        foreach ($this->quests as $quest) {
            $quest->markClean();
        }

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
