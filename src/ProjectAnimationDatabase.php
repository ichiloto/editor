<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Editor\History\TracksPersistedState;
use Ichiloto\Editor\IO\AtomicFile;

use Ichiloto\Engine\Animations\ActionAnimationResolver;
use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\AnimationCue;
use Ichiloto\Engine\Animations\AnimationTargetPosition;
use RuntimeException;

/**
 * Manages the project's animation database asset.
 */
final class ProjectAnimationDatabase
{
    use TracksPersistedState;

    /**
     * The fields the editor edits. Every other field of an authored entry
     * (effect bindings, roles, anything the Engine adds) is kept as written.
     */
    private const array EDITED_FIELDS = ['id', 'name', 'position', 'maxFrames', 'frames', 'cues'];

    /**
     * @var array<int, array<string, mixed>> Each loaded animation's authored entry, keyed by object id.
     */
    private array $authoredEntries = [];

    /**
     * @var array<int, list<string>> Roles changed since loading, keyed by object id.
     * The Engine's Animation keeps its roles fixed, so edits live here and are
     * written over the authored entry's `roles` on save.
     */
    private array $editedRoles = [];

    /** The file as read, so its header is kept and an unsafe file is refused. */
    private ?PhpDataFile $file = null;

    /**
     * @param Animation[] $animations
     */
    public function __construct(
        public readonly string $path,
        private array $animations = [],
        bool $isDirty = false,
    ) {
        if (! $isDirty) {
            $this->captureBaseline();
        }
    }

    /**
     * Loads the animation database from the project.
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
            . 'animations.php';

        if (! is_file($path)) {
            return new self($path, []);
        }

        $file = PhpDataFile::load($path, $projectRoot);
        $payload = $file->payload;

        if (! is_array($payload)) {
            throw new RuntimeException("Unable to parse {$path}.");
        }

        $entries = array_values(array_filter($payload, 'is_array'));
        // Roles are the database's to read and edit (getRoles, setRole); the
        // Engine object is built without them, so a role the Engine refuses is
        // reported by validation instead of keeping the database from loading.
        $animations = array_map(static fn(array $entry): Animation => Animation::fromArray(array_diff_key($entry, ['roles' => true])), $entries);
        $database = new self($path, $animations, isDirty: true);
        $database->file = $file;

        foreach ($animations as $index => $animation) {
            $database->authoredEntries[spl_object_id($animation)] = $entries[$index];
        }

        $database->captureBaseline();

        return $database;
    }

    /**
     * Returns the stored animations.
     *
     * @return Animation[]
     */
    public function getAnimations(): array
    {
        return array_values($this->animations);
    }

    /**
     * Returns whether the database has unsaved changes.
     *
     * @return bool
     */
    /**
     * @inheritDoc
     */
    protected function buildPersistedPayload(): string
    {
        return "<?php\n\nreturn " . self::exportPhpValue($this->getPersistedEntries()) . ";\n";
    }

    /**
     * Returns an animation's entry as authored, with the fields the editor
     * edits as they now stand, or null for one the database does not hold.
     *
     * @return array<string, mixed>|null
     */
    public function getAuthoredEntry(Animation $animation): ?array
    {
        $index = array_search($animation, $this->getAnimations(), true);

        return $index === false ? null : $this->getPersistedEntries()[$index];
    }

    /**
     * Returns the entries to write: each authored entry with the fields the
     * editor edits replaced, so nothing else it holds is lost.
     *
     * @return list<array<string, mixed>>
     */
    private function getPersistedEntries(): array
    {
        return array_map(function (Animation $animation): array {
            $edited = $animation->toArray();
            $entry = $this->authoredEntries[spl_object_id($animation)] ?? $edited;

            foreach (self::EDITED_FIELDS as $field) {
                if (array_key_exists($field, $edited)) {
                    $entry[$field] = $edited[$field];
                }
            }

            // Roles the editor changed replace the authored ones; untouched
            // roles stay as written.
            $roles = $this->editedRoles[spl_object_id($animation)] ?? null;

            if ($roles !== null) {
                unset($entry['roles']);

                if ($roles !== []) {
                    $entry['roles'] = $roles;
                }
            }

            return $entry;
        }, $this->getAnimations());
    }

    /**
     * Returns the roles an animation is bound to: which battle actions play
     * it when they name no animation of their own.
     *
     * @param int $index The animation index.
     * @return list<string>
     */
    public function getRoles(int $index): array
    {
        $animation = $this->getAnimationByIndex($index);

        if (! $animation instanceof Animation) {
            return [];
        }

        $id = spl_object_id($animation);
        $authored = $this->authoredEntries[$id]['roles'] ?? [];

        return $this->editedRoles[$id] ?? array_values(array_filter((array) $authored, is_string(...)));
    }

    /**
     * Returns the index of the animation a role is bound to, if any.
     *
     * @param string $role The role.
     * @return int|null
     */
    public function findRoleOwner(string $role): ?int
    {
        foreach (array_keys($this->getAnimations()) as $index) {
            if (in_array($role, $this->getRoles($index), true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Binds or unbinds a role on an animation.
     *
     * Refused, changing nothing, for a role the Engine does not support or
     * one already bound to another animation: the runtime plays a role only
     * when exactly one animation holds it.
     *
     * @param int $index The animation index.
     * @param string $role The role.
     * @param bool $isBound Whether the animation should hold the role.
     * @return string|null Why the change was refused, or null when it was made.
     */
    public function setRole(int $index, string $role, bool $isBound): ?string
    {
        $animation = $this->getAnimationByIndex($index);

        if (! $animation instanceof Animation) {
            return 'No animation is selected.';
        }

        if (! in_array($role, ActionAnimationResolver::getSupportedRoles(), true)) {
            return sprintf('The Engine has no animation role "%s".', $role);
        }

        $roles = $this->getRoles($index);
        $owner = $this->findRoleOwner($role);

        if ($isBound && $owner !== null && $owner !== $index) {
            return sprintf('Role %s is already bound to %s; unbind it there first.', $role, $this->getAnimationByIndex($owner)?->name ?? 'another animation');
        }

        $next = $isBound
            ? array_values(array_unique([...$roles, $role]))
            : array_values(array_diff($roles, [$role]));

        if ($next !== $roles) {
            $this->editedRoles[spl_object_id($animation)] = $next;
            $this->touchState();
        }

        return null;
    }

    /**
     * Returns the animation at the given index.
     *
     * @param int $index The animation index.
     * @return Animation|null
     */
    public function getAnimationByIndex(int $index): ?Animation
    {
        return $this->animations[$index] ?? null;
    }

    /**
     * Adds a blank animation and returns its index.
     *
     * @param string $name The new animation name.
     * @return int
     */
    public function addAnimation(string $name = 'New Animation'): int
    {
        $nextId = 1;

        foreach ($this->animations as $animation) {
            $nextId = max($nextId, $animation->id + 1);
        }

        $animation = new Animation(
            id: $nextId,
            name: $name,
            position: AnimationTargetPosition::CENTER,
            maxFrames: 5,
        );
        $this->animations[] = $animation;
        $this->touchState();

        return count($this->animations) - 1;
    }

    /**
     * Removes the animation at the given index (rewritten to disk on save,
     * so re-inserting at the same index is a complete undo).
     *
     * @param int $index The animation index.
     * @return Animation|null The removed animation, or null when the index is unknown.
     */
    public function removeAnimation(int $index): ?Animation
    {
        $animations = array_values($this->animations);
        $animation = $animations[$index] ?? null;

        if (! $animation instanceof Animation) {
            return null;
        }

        array_splice($animations, $index, 1);
        $this->animations = $animations;
        $this->touchState();

        return $animation;
    }

    /**
     * Re-inserts a previously removed animation (the undo of removeAnimation()).
     *
     * @param int $index The index to restore the animation at.
     * @param Animation $animation The animation to restore.
     * @return void
     */
    public function insertAnimation(int $index, Animation $animation): void
    {
        $animations = array_values($this->animations);
        $index = max(0, min(count($animations), $index));
        array_splice($animations, $index, 0, [$animation]);
        $this->animations = $animations;
        $this->touchState();
    }

    /**
     * Updates a basic animation field.
     *
     * @param int $index The animation index.
     * @param string $field The field name.
     * @param mixed $value The replacement value.
     * @return void
     */
    public function setField(int $index, string $field, mixed $value): void
    {
        $animation = $this->getAnimationByIndex($index);

        if (! $animation instanceof Animation) {
            return;
        }

        match ($field) {
            'name' => $animation->name = strval($value),
            'position' => $animation->position = AnimationTargetPosition::fromValue(strval($value)),
            'maxFrames' => $animation->ensureFrameCount(max(1, intval($value))),
            default => null,
        };

        $this->touchState();
    }

    /**
     * Updates one cell in the selected animation.
     *
     * @param int $index The animation index.
     * @param int $frameIndex The frame number.
     * @param int $x The cell x offset.
     * @param int $y The cell y offset.
     * @param string $symbol The symbol to store.
     * @param string|null $color The optional color name.
     * @return void
     */
    public function setFrameCell(
        int $index,
        int $frameIndex,
        int $x,
        int $y,
        string $symbol,
        ?string $color = null,
    ): void {
        $animation = $this->getAnimationByIndex($index);

        if (! $animation instanceof Animation) {
            return;
        }

        $animation->setCell($frameIndex, $x, $y, $symbol, $color);
        $this->touchState();
    }

    /**
     * Replaces the cue attached to one frame.
     *
     * @param int $index The animation index.
     * @param int $frameIndex The frame number.
     * @param string $soundEffect The sound-effect cue.
     * @param string|null $flashColor The flash color.
     * @param int $flashDurationFrames The flash duration.
     * @return void
     */
    public function setFrameCue(
        int $index,
        int $frameIndex,
        string $soundEffect,
        ?string $flashColor,
        int $flashDurationFrames,
    ): void {
        $animation = $this->getAnimationByIndex($index);

        if (! $animation instanceof Animation) {
            return;
        }

        $animation->setCue(
            $frameIndex,
            new AnimationCue(
                soundEffect: $soundEffect,
                flashColor: $flashColor,
                flashDurationFrames: $flashDurationFrames,
            ),
        );
        $this->touchState();
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

        if (! $this->isDirty() && is_file($this->path)) {
            return;
        }

        $entries = $this->getPersistedEntries();

        if ($this->file !== null) {
            $this->file->save($entries);
        } else {
            AtomicFile::write($this->path, $this->buildPersistedPayload());
        }

        foreach ($this->getAnimations() as $index => $animation) {
            $this->authoredEntries[spl_object_id($animation)] = $entries[$index];
        }

        // The written entries now hold every edited role.
        $this->editedRoles = [];
        $this->captureBaseline();
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

    /**
     * Returns the files a save of this database would overwrite.
     *
     * @return string[]
     */
    public function getBackupPaths(): array
    {
        return [$this->path];
    }
}
