<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Editor\History\TracksPersistedState;
use Ichiloto\Editor\IO\AtomicFile;

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

        $payload = require $path;

        if (! is_array($payload)) {
            throw new RuntimeException("Unable to parse {$path}.");
        }

        return new self(
            $path,
            array_map(
                static fn(array $animation): Animation => Animation::fromArray($animation),
                array_values(array_filter($payload, 'is_array'))
            ),
        );
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
        return "<?php\n\nreturn " . self::exportPhpValue(array_map(
            static fn(Animation $animation): array => $animation->toArray(),
            $this->getAnimations()
        )) . ";\n";
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

        AtomicFile::write($this->path, $this->buildPersistedPayload());
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
}
