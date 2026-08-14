<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Editor\Database\PhpDataSource;
use Ichiloto\Editor\Database\Slug;
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
     * @param string $sourceHeader Verbatim source before the top-level return.
     */
    public function __construct(
        public readonly string $path,
        private array $quests = [],
        private bool $isDirty = false,
        private string $sourceHeader = "<?php\n\n",
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

        $source = (string) file_get_contents($path);
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

        return new self(
            $path,
            $quests,
            sourceHeader: PhpDataSource::inspect($source)['header'],
        );
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
        $this->quests[] = ProjectQuest::createBlank(Slug::unique($name, $this->getQuestIds(), 'quest'), $name);
        $this->isDirty = true;

        return count($this->quests) - 1;
    }

    /**
     * Returns every quest id in the project.
     *
     * @return string[] The ids.
     */
    public function getQuestIds(): array
    {
        return array_map(static fn(ProjectQuest $quest): string => $quest->getId(), $this->quests);
    }

    /**
     * Renames a quest, taking the id with it while that is still safe.
     *
     * An id is what triggers grant and conditions wait on, and renaming the
     * quest does not rewrite those. So the id keeps up with the name until
     * something points at it, and holds still afterwards -- which is the
     * point at which changing it would break the game rather than tidy it.
     *
     * @param int $index The quest index.
     * @param string $name The new name.
     * @param bool $mayChangeId Whether nothing points at the current id.
     * @return string|null The new id when it changed, or null when it did not.
     */
    public function renameQuest(int $index, string $name, bool $mayChangeId): ?string
    {
        $quest = $this->getQuestByIndex($index);

        if (! $quest instanceof ProjectQuest) {
            return null;
        }

        $quest->setField('name', $name);
        $this->isDirty = true;

        if (! $mayChangeId) {
            return null;
        }

        $taken = array_values(array_filter(
            $this->getQuestIds(),
            static fn(string $id): bool => $id !== $quest->getId()
        ));
        $id = Slug::unique($name, $taken, 'quest');

        if ($id === $quest->getId()) {
            return null;
        }

        $quest->setField('id', $id);

        return $id;
    }

    /**
     * Removes the quest at the given index (rewritten to disk on save, so
     * re-inserting at the same index is a complete undo).
     *
     * @param int $index The quest index.
     * @return ProjectQuest|null The removed quest, or null when the index is unknown.
     */
    public function removeQuest(int $index): ?ProjectQuest
    {
        $quests = array_values($this->quests);
        $quest = $quests[$index] ?? null;

        if (! $quest instanceof ProjectQuest) {
            return null;
        }

        array_splice($quests, $index, 1);
        $this->quests = $quests;
        $this->isDirty = true;

        return $quest;
    }

    /**
     * Re-inserts a previously removed quest (the undo of removeQuest()).
     *
     * @param int $index The index to restore the quest at.
     * @param ProjectQuest $quest The quest to restore.
     * @return void
     */
    public function insertQuest(int $index, ProjectQuest $quest): void
    {
        $quests = array_values($this->quests);
        $index = max(0, min(count($quests), $index));
        array_splice($quests, $index, 0, [$quest]);
        $this->quests = $quests;
        $this->isDirty = true;
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
     * Adds a reward item slot to a quest.
     *
     * @param int $index The quest index.
     * @param int|null $afterSlot The slot to insert after, or null for the end.
     * @return int|null The new slot, or null when the quest is unknown.
     */
    public function addRewardItem(int $index, ?int $afterSlot = null): ?int
    {
        $quest = $this->getQuestByIndex($index);

        if (! $quest instanceof ProjectQuest) {
            return null;
        }

        $this->isDirty = true;

        return $quest->addRewardItem($afterSlot);
    }

    /**
     * Removes a reward item slot from a quest.
     *
     * @param int $index The quest index.
     * @param int $slot The slot.
     * @return string|null The removed item name.
     */
    public function removeRewardItem(int $index, int $slot): ?string
    {
        $quest = $this->getQuestByIndex($index);

        if (! $quest instanceof ProjectQuest) {
            return null;
        }

        $removed = $quest->removeRewardItem($slot);

        if ($removed !== null) {
            $this->isDirty = true;
        }

        return $removed;
    }

    /**
     * Puts a removed reward item back where it was.
     *
     * @param int $index The quest index.
     * @param int $slot The slot it held.
     * @param string $item The item name.
     * @return void
     */
    public function insertRewardItem(int $index, int $slot, string $item): void
    {
        $quest = $this->getQuestByIndex($index);

        if (! $quest instanceof ProjectQuest) {
            return;
        }

        $items = $quest->getRewardItems();
        array_splice($items, min($slot, count($items)), 0, [$item]);
        $quest->setRewardItems($items);
        $this->isDirty = true;
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

        $payload = $this->sourceHeader . 'return ' . self::exportPhpValue(array_map(
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
