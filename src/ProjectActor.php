<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use RuntimeException;

/**
 * Represents one editable actor asset in the project database.
 */
final class ProjectActor
{
    /**
     * The sentinel option meaning "no class reference" in the editor picker.
     */
    public const string CLASS_NONE = 'none';

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly string $path,
        private array $payload,
        private bool $isDirty = false,
    ) {
    }

    /**
     * Loads an actor asset from disk.
     *
     * @param string $path The actor asset path.
     * @return self
     */
    public static function fromFile(string $path): self
    {
        $payload = require $path;

        if (! is_array($payload)) {
            throw new RuntimeException("Unable to parse {$path}.");
        }

        return new self(
            id: pathinfo($path, PATHINFO_FILENAME),
            path: $path,
            payload: $payload,
        );
    }

    /**
     * Creates a new blank actor record in memory.
     *
     * @param string $path The destination asset path.
     * @param string $id The actor file id.
     * @param string $name The default actor display name.
     * @return self
     */
    public static function createBlank(string $path, string $id, string $name): self
    {
        return new self(
            id: $id,
            path: $path,
            payload: [
                'class' => 'Ichiloto\\Engine\\Entities\\Character',
                'data' => [
                    'name' => $name,
                    'description' => '',
                    'level' => 1,
                    'currentExp' => 0,
                    'stats' => [
                        'currentHp' => 100,
                        'currentMp' => 20,
                        'currentAp' => 10,
                        'totalHp' => 100,
                        'totalMp' => 20,
                        'totalAp' => 10,
                        'attack' => 10,
                        'defence' => 10,
                        'magicAttack' => 10,
                        'magicDefence' => 10,
                        'grace' => 10,
                        'speed' => 10,
                        'evasion' => 5,
                        'accuracy' => 5,
                        'critical' => 5,
                    ],
                    'images' => [
                        'dialog' => [],
                        'field' => [],
                        'battle' => [],
                    ],
                    'abilities' => [
                        'learned' => [],
                        'learnables' => [],
                        'sortOrder' => 'A-Z',
                    ],
                    'magic' => [
                        'learned' => [],
                        'learnables' => [],
                        'sortOrder' => 'A-Z',
                    ],
                ],
            ],
            isDirty: true,
        );
    }

    /**
     * Returns whether the actor has unsaved changes.
     *
     * @return bool
     */
    public function isDirty(): bool
    {
        return $this->isDirty;
    }

    /**
     * Returns the actor display name.
     *
     * @return string
     */
    public function getName(): string
    {
        return (string) ($this->getData()['name'] ?? $this->id);
    }

    /**
     * Returns the actor description/profile text.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return (string) ($this->getData()['description'] ?? '');
    }

    /**
     * Returns the character class this actor references by name.
     *
     * The reference lives at `data.class` — a plain class *name* matching a
     * `name` in the project's `assets/Data/classes.php`, which the engine's
     * ClassStore hydrates into a CharacterRole. It is deliberately NOT the
     * payload's top-level `class` key: that one is the entity's PHP FQCN
     * (`Ichiloto\Engine\Entities\Character`) and must survive every save
     * untouched.
     *
     * @return string The class name, or an empty string when unassigned.
     */
    public function getClassName(): string
    {
        $className = $this->getData()['class'] ?? $this->getData()['role'] ?? '';

        return is_string($className) ? $className : '';
    }

    /**
     * Returns the actor level.
     *
     * @return int
     */
    public function getLevel(): int
    {
        return (int) ($this->getData()['level'] ?? 1);
    }

    /**
     * Returns the actor current experience value.
     *
     * @return int
     */
    public function getCurrentExp(): int
    {
        return (int) ($this->getData()['currentExp'] ?? 0);
    }

    /**
     * Returns the actor stats payload.
     *
     * @return array<string, int>
     */
    public function getStats(): array
    {
        $stats = $this->getData()['stats'] ?? [];

        if (! is_array($stats)) {
            return [];
        }

        return array_map(
            static fn(mixed $value): int => (int) $value,
            $stats,
        );
    }

    /**
     * Returns one actor stat.
     *
     * @param string $field The stat field.
     * @return int
     */
    public function getStat(string $field): int
    {
        return $this->getStats()[$field] ?? 0;
    }

    /**
     * Returns the actor images payload.
     *
     * @return array<string, mixed>
     */
    public function getImages(): array
    {
        $images = $this->getData()['images'] ?? [];

        return is_array($images) ? $images : [];
    }

    /**
     * Returns the actor battle sprite preview lines.
     *
     * @return string[]
     */
    public function getBattleSpriteLines(): array
    {
        $battle = $this->getImages()['battle'] ?? [];

        if (! is_array($battle)) {
            return [];
        }

        return array_values(array_map(static fn(mixed $line): string => (string) $line, $battle));
    }

    /**
     * Returns the actor abilities payload.
     *
     * @return array<string, mixed>
     */
    public function getAbilities(): array
    {
        $abilities = $this->getData()['abilities'] ?? [];

        return is_array($abilities) ? $abilities : [];
    }

    /**
     * Returns the actor magic payload.
     *
     * @return array<string, mixed>
     */
    public function getMagic(): array
    {
        $magic = $this->getData()['magic'] ?? [];

        return is_array($magic) ? $magic : [];
    }

    /**
     * Updates one editable actor field.
     *
     * @param string $field The field identifier.
     * @param mixed $value The replacement value.
     * @return void
     */
    public function setField(string $field, mixed $value): void
    {
        if (! isset($this->payload['data']) || ! is_array($this->payload['data'])) {
            $this->payload['data'] = [];
        }

        if ($field === 'class') {
            $className = trim((string) $value);

            // "None" clears the reference outright rather than persisting an
            // empty string the engine would have to special-case.
            if ($className === '' || strtolower($className) === self::CLASS_NONE) {
                unset($this->payload['data']['class']);
            } else {
                $this->payload['data']['class'] = $className;
            }

            $this->isDirty = true;
            return;
        }

        if (in_array($field, ['name', 'description', 'level', 'currentExp'], true)) {
            $this->payload['data'][$field] = $value;
            $this->isDirty = true;
            return;
        }

        $statFields = [
            'currentHp',
            'currentMp',
            'currentAp',
            'totalHp',
            'totalMp',
            'totalAp',
            'attack',
            'defence',
            'magicAttack',
            'magicDefence',
            'grace',
            'speed',
            'evasion',
            'accuracy',
            'critical',
        ];

        if (in_array($field, $statFields, true)) {
            if (! isset($this->payload['data']['stats']) || ! is_array($this->payload['data']['stats'])) {
                $this->payload['data']['stats'] = [];
            }

            $this->payload['data']['stats'][$field] = (int) $value;
            $this->isDirty = true;
        }
    }

    /**
     * Writes the actor asset back to disk.
     *
     * @return void
     */
    public function save(): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create {$directory}.");
        }

        $payload = "<?php\n\nuse Ichiloto\\Engine\\Entities\\Character;\n\nreturn [\n"
            . "  'class' => Character::class,\n"
            . "  'data' => " . self::exportPhpValue($this->getData(), 1) . ",\n"
            . "];\n";

        self::writeFileTransactionally($this->path, $payload);
        $this->isDirty = false;
    }

    /**
     * Returns the normalized actor data payload.
     *
     * @return array<string, mixed>
     */
    private function getData(): array
    {
        $data = $this->payload['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * Writes a file using a temp-file swap.
     *
     * @param string $path The destination file path.
     * @param string $contents The new file contents.
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
