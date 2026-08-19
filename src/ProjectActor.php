<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Editor\History\TracksPersistedState;
use Ichiloto\Editor\IO\AtomicFile;

use RuntimeException;

/**
 * Represents one editable actor asset in the project database.
 */
final class ProjectActor
{
    use TracksPersistedState;

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
        bool $isDirty = false,
    ) {
        if (! $isDirty) {
            // Loaded from disk: the current content is the saved content.
            $this->captureBaseline();
        }
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

    /** Returns the actor's authored starting summon assignments verbatim. */
    public function getSummons(): mixed
    {
        return $this->getData()['summons'] ?? [];
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
     * Returns the data with one adjustment written or removed.
     *
     * @param array<string, mixed> $data The actor data.
     * @param string[] $segments The path.
     * @param int $amount The adjustment; zero removes it.
     * @return array<string, mixed> The rewritten data.
     */
    private static function withAdjustment(array $data, array $segments, int $amount): array
    {
        $key = array_shift($segments);

        if ($key === null) {
            return $data;
        }

        if ($segments === []) {
            if ($amount === 0) {
                unset($data[$key]);
            } else {
                $data[$key] = $amount;
            }

            return $data;
        }

        $child = is_array($data[$key] ?? null) ? $data[$key] : [];
        $child = self::withAdjustment($child, $segments, $amount);

        if ($child === []) {
            unset($data[$key]);
        } else {
            $data[$key] = $child;
        }

        return $data;
    }

    /**
     * Returns the durable definition id a save resolves this actor by.
     *
     * The engine falls back to the display name when a project has not
     * declared one, which is why renaming an actor used to strand a save.
     *
     * @return string The id, or the name when none is declared.
     */
    public function getDefinitionId(): string
    {
        $id = trim(strval($this->getData()['id'] ?? ''));

        return $id === '' ? $this->getName() : $id;
    }

    /**
     * Returns whether the project declares a durable id of its own.
     *
     * @return bool True when it does.
     */
    public function hasDefinitionId(): bool
    {
        return trim(strval($this->getData()['id'] ?? '')) !== '';
    }

    /**
     * Returns the fixed adjustments this actor's nature makes to the class
     * baseline, by canonical stat key.
     *
     * @return array<string, int> The adjustments.
     */
    public function getActorNaturalAdjustments(): array
    {
        return self::readAdjustments($this->getData()['actorNaturalAdjustments'] ?? null);
    }

    /**
     * Returns the named natural variants, each with its own adjustments.
     *
     * @return array<string, array<string, int>> Variant id => adjustments.
     */
    public function getNaturalVariants(): array
    {
        $variants = $this->getData()['naturalVariants'] ?? null;

        if (! is_array($variants)) {
            return [];
        }

        $read = [];

        foreach ($variants as $variantId => $variant) {
            if (! is_string($variantId) || trim($variantId) === '') {
                continue;
            }

            $read[trim($variantId)] = self::readAdjustments(
                is_array($variant) && isset($variant['adjustments']) ? $variant['adjustments'] : $variant,
            );
        }

        return $read;
    }

    /**
     * Returns the variant the engine starts this actor on.
     *
     * @return string|null The variant id, or null when there are no variants.
     */
    public function getDefaultNaturalVariantId(): ?string
    {
        $default = trim(strval($this->getData()['defaultNaturalVariantId'] ?? ''));

        return $default === '' ? null : $default;
    }

    /**
     * Returns the actor-natural adjustments in force for a variant.
     *
     * The runtime composes rather than replaces: an actor's fixed
     * adjustments always apply, and the selected variant is added on top of
     * them, summing where both name the same stat. That is
     * `ActorDefinition::naturalAdjustmentsFor()`, and it is asked directly
     * whenever the engine is reachable so this can never become a second
     * opinion about what an actor naturally is. A definition the engine
     * refuses to build -- an unknown stat key, a default variant that is not
     * declared -- is composed here the same way instead, so a broken project
     * still opens and the validator is what reports the fault.
     *
     * @param string|null $variantId The variant, or null for the default.
     * @return array<string, int> The adjustments.
     */
    public function getNaturalAdjustmentsFor(?string $variantId = null): array
    {
        $variantId = $this->resolveNaturalVariantId($variantId);
        $definition = $this->engineDefinition();

        if ($definition !== null) {
            /** @var array<string, int> $adjustments */
            $adjustments = $definition->naturalAdjustmentsFor($variantId);

            return $adjustments;
        }

        $adjustments = $this->getActorNaturalAdjustments();

        foreach ($variantId === null ? [] : ($this->getNaturalVariants()[$variantId] ?? []) as $stat => $amount) {
            $adjustments[$stat] = ($adjustments[$stat] ?? 0) + $amount;
        }

        return $adjustments;
    }

    /**
     * Returns which variant is in force: the one asked for when the actor
     * declares it, otherwise the default, and none at all when the actor
     * declares no variants.
     *
     * @param string|null $variantId The variant asked for.
     * @return string|null The variant in force.
     */
    public function resolveNaturalVariantId(?string $variantId = null): ?string
    {
        $variants = $this->getNaturalVariants();

        if ($variants === []) {
            return null;
        }

        $variantId = $variantId === null ? '' : trim($variantId);

        // A variant asked for by name is answered by name, declared or not.
        // The runtime contributes nothing for one it does not know rather
        // than quietly substituting another, and neither does this.
        if ($variantId !== '') {
            return $variantId;
        }

        return $this->getDefaultNaturalVariantId();
    }

    /**
     * Returns this actor as the engine's own definition, or null when the
     * engine cannot be reached or refuses to build one.
     *
     * @return \Ichiloto\Engine\Entities\Actors\ActorDefinition|null The definition.
     */
    private function engineDefinition(): ?object
    {
        if (! class_exists(\Ichiloto\Engine\Entities\Actors\ActorDefinition::class)) {
            return null;
        }

        try {
            return \Ichiloto\Engine\Entities\Actors\ActorDefinition::fromArray(
                ['data' => $this->getData()],
                'this actor',
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Reads an adjustment map, keeping only whole numbers under canonical
     * stat keys -- what the runtime itself accepts.
     *
     * @param mixed $adjustments The authored value.
     * @return array<string, int> The adjustments.
     */
    private static function readAdjustments(mixed $adjustments): array
    {
        if (! is_array($adjustments)) {
            return [];
        }

        $read = [];

        foreach ($adjustments as $key => $value) {
            if (is_string($key) && is_numeric($value)) {
                $read[$key] = intval($value);
            }
        }

        return $read;
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

            $this->touchState();
            return;
        }

        if ($field === 'summons') {
            // A list of stable summon ids, each at most once, in the order
            // chosen; an empty list removes the key so the actor reads as
            // it did before summons existed.
            $ids = is_array($value) ? $value : explode(',', (string) $value);
            $clean = [];

            foreach ($ids as $id) {
                $id = trim((string) $id);

                if ($id !== '' && ! in_array($id, $clean, true)) {
                    $clean[] = $id;
                }
            }

            if ($clean === []) {
                unset($this->payload['data']['summons']);
            } else {
                $this->payload['data']['summons'] = $clean;
            }

            $this->touchState();
            return;
        }

        if (in_array($field, ['name', 'description', 'level', 'currentExp'], true)) {
            $this->payload['data'][$field] = $value;
            $this->touchState();
            return;
        }

        if ($field === 'id' || $field === 'defaultNaturalVariantId') {
            $identity = trim((string) $value);

            if ($identity === '') {
                unset($this->payload['data'][$field]);
            } else {
                $this->payload['data'][$field] = $identity;
            }

            $this->touchState();
            return;
        }

        // actorNaturalAdjustments.<stat>, naturalVariants.<variant>.<stat>:
        // an adjustment of zero is the absence of an adjustment, so it is
        // removed rather than written, and a variant emptied of every
        // adjustment stops being a variant.
        if (str_starts_with($field, 'actorNaturalAdjustments.') || str_starts_with($field, 'naturalVariants.')) {
            $segments = explode('.', $field);
            $amount = intval($value);
            $this->payload['data'] = self::withAdjustment($this->payload['data'], $segments, $amount);
            $this->touchState();
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
            $this->touchState();
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

        AtomicFile::write($this->path, $this->buildPersistedPayload());
        $this->captureBaseline();
    }

    /**
     * @inheritDoc
     */
    protected function buildPersistedPayload(): string
    {
        return "<?php\n\nuse Ichiloto\\Engine\\Entities\\Character;\n\nreturn [\n"
            . "  'class' => Character::class,\n"
            . "  'data' => " . self::exportPhpValue($this->getData(), 1) . ",\n"
            . "];\n";
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
