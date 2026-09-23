<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Editor\History\TracksPersistedState;
use Ichiloto\Editor\IO\AtomicFile;
use Ichiloto\Editor\Database\PhpSourceDocument;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use ReflectionClass;

use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use RuntimeException;

final class ProjectSkillDatabase
{
    use TracksPersistedState;

    private ?string $source = null;
    /** @var list<int> */
    private array $sourceOrder = [];
    /** @var array<int, array{skill: ProjectSkill, payload: array, source: string|null, block: string|null, class: class-string}> */
    private array $authored = [];

    public function __construct(
        public readonly string $path,
        private array $skills = [],
        bool $isDirty = false,
    ) {
        if (! $isDirty) {
            $this->captureBaseline();
        }
    }

    public static function fromProject(string $projectRoot): self
    {
        $path = rtrim($projectRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . "assets"
            . DIRECTORY_SEPARATOR . "Data"
            . DIRECTORY_SEPARATOR . "skills.php";
        if (is_file($path) === false) {
            return new self($path, []);
        }
        $payload = require $path;
        if (is_array($payload) === false) {
            throw new RuntimeException(sprintf("Unable to parse %s.", $path));
        }
        $skills = [];
        $classes = [];
        foreach (array_values($payload) as $index => $skill) {
            if ($skill instanceof \Ichiloto\Engine\Entities\Skills\Skill) {
                $skills[] = ProjectSkill::fromSkill($skill, $index + 1);
                $classes[] = $skill::class;
            }
        }
        $database = new self($path, $skills);
        $database->source = (string) file_get_contents($path);
        $document = PhpSourceDocument::parse($database->source);
        foreach ($skills as $index => $skill) {
            $key = spl_object_id($skill);
            $database->sourceOrder[] = $key;
            $database->authored[$key] = [
                'skill' => $skill,
                'payload' => $skill->toArray(),
                'source' => count($payload) === count($skills) ? $document->entrySource($index) : null,
                'block' => count($payload) === count($skills) ? $document->getEntryBlockSource($index) : null,
                'class' => $classes[$index],
            ];
        }
        return $database;
    }

    public function getSkills(): array { return array_values($this->skills); }

    public function getSkillByIndex(int $index): ?ProjectSkill
    {
        return $this->skills[$index] ?? null;
    }

    public function addSkill(string $name = "New Skill"): int
    {
        $nextId = 1;
        foreach ($this->skills as $skill) { $nextId = max($nextId, $skill->id + 1); }
        $this->skills[] = ProjectSkill::createBlank($nextId, $name);
        $this->touchState();
        return count($this->skills) - 1;
    }

    public function setField(int $index, string $field, mixed $value): void
    {
        $skill = $this->getSkillByIndex($index);
        if (! $skill instanceof ProjectSkill) { return; }
        $authored = $this->authored[spl_object_id($skill)] ?? null;
        if ($authored !== null && $authored['source'] === null) {
            throw $this->getSourceRefusal($skill);
        }
        if (! $this->canEditField($index, $field)) {
            throw new RuntimeException(sprintf('Skill "%s" does not support editing %s.', $skill->getName(), $field));
        }
        $skill->setField($field, $value);
        $this->touchState();
    }

    public function canEditField(int $index, string $field): bool
    {
        return $this->getReadOnlyReason($index) === null && $this->supportsField($index, $field);
    }

    public function getReadOnlyReason(int $index): ?string
    {
        $skill = $this->getSkillByIndex($index);
        if ($skill === null) { return null; }
        $authored = $this->authored[spl_object_id($skill)] ?? null;
        return $authored !== null && $authored['source'] === null
            ? $this->getSourceRefusal($skill)->getMessage()
            : null;
    }

    public function supportsField(int $index, string $field): bool
    {
        $skill = $this->getSkillByIndex($index);
        if ($skill === null || in_array($field, ['type', 'effects'], true)) { return false; }
        $parameter = str_starts_with($field, 'scope') ? 'scope'
            : (str_starts_with($field, 'invocation') ? 'invocation' : $field);
        return in_array($parameter, $this->getConstructorParameters($skill), true);
    }

    /** @return list<string> */
    private function getConstructorParameters(ProjectSkill $skill): array
    {
        $class = $this->authored[spl_object_id($skill)]['class'] ?? $this->getSkillClass($skill);
        return array_map(static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionClass($class))->getConstructor()?->getParameters() ?? []);
    }

    /**
     * Removes the skill at the given index (rewritten to disk on save, so
     * re-inserting at the same index is a complete undo).
     *
     * @param int $index The skill index.
     * @return ProjectSkill|null The removed skill, or null when the index is unknown.
     */
    public function removeSkill(int $index): ?ProjectSkill
    {
        $skills = array_values($this->skills);
        $skill = $skills[$index] ?? null;

        if (! $skill instanceof ProjectSkill) {
            return null;
        }

        array_splice($skills, $index, 1);
        $this->skills = $skills;
        $this->touchState();

        return $skill;
    }

    /**
     * Re-inserts a previously removed skill (the undo of removeSkill()).
     *
     * @param int $index The index to restore the skill at.
     * @param ProjectSkill $skill The skill to restore.
     * @return void
     */
    public function insertSkill(int $index, ProjectSkill $skill): void
    {
        $skills = array_values($this->skills);
        $index = max(0, min(count($skills), $index));
        array_splice($skills, $index, 0, [$skill]);
        $this->skills = $skills;
        $this->touchState();
    }

    public function save(): void
    {
        $directory = dirname($this->path);
        if (is_dir($directory) === false && mkdir($directory, 0777, true) === false && is_dir($directory) === false) {
            throw new RuntimeException(sprintf("Unable to create %s.", $directory));
        }
        if (! $this->isDirty() && is_file($this->path)) {
            return;
        }

        if ($this->source !== null && file_get_contents($this->path) !== $this->source) {
            throw new RuntimeException('Refusing to overwrite skills.php changed outside the editor. Reload before saving.');
        }
        $source = $this->getUpdatedSource();
        try {
            \PhpToken::tokenize($source, TOKEN_PARSE);
        } catch (\ParseError $error) {
            throw new RuntimeException('Refusing to write invalid skill source: ' . $error->getMessage(), previous: $error);
        }
        AtomicFile::write($this->path, $source);
        $this->source = $source;
        $document = PhpSourceDocument::parse($source);
        $this->sourceOrder = [];
        foreach ($this->getSkills() as $index => $skill) {
            $key = spl_object_id($skill);
            $this->sourceOrder[] = $key;
            $this->authored[$key] = [
                'skill' => $skill,
                'payload' => $skill->toArray(),
                'source' => $document->entrySource($index),
                'block' => $document->getEntryBlockSource($index),
                'class' => $this->authored[$key]['class'] ?? $this->getSkillClass($skill),
            ];
        }
        $this->captureBaseline();
    }

    /**
     * @inheritDoc
     */
    protected function dependencyVersion(): string
    {
        $versions = [];

        foreach ($this->skills as $skill) {
            $versions[] = $skill->stateVersion();
        }

        return count($this->skills) . ':' . implode(',', $versions);
    }

    /**
     * @inheritDoc
     */
    protected function buildPersistedPayload(): string
    {
        return serialize(array_map(static fn(ProjectSkill $skill): array => $skill->toArray(), $this->getSkills()));
    }

    private function getUpdatedSource(): string
    {
        if ($this->source === null) {
            return $this->getNewSource();
        }
        $document = PhpSourceDocument::parse($this->source);
        $order = $this->sourceOrder;
        if ($document->entryCount() !== count($order)) {
            throw new RuntimeException('Refusing to flatten skills.php: its entries cannot be matched to the loaded skills.');
        }
        $retained = array_map(spl_object_id(...), $this->getSkills());
        foreach (array_reverse(array_keys($order)) as $index) {
            if (in_array($order[$index], $retained, true)) { continue; }
            $removed = $this->authored[$order[$index]];
            if ($removed['source'] === null) { throw $this->getSourceRefusal($removed['skill']); }
            $document = $document->withoutEntry($index);
            array_splice($order, $index, 1);
        }
        foreach ($this->getSkills() as $index => $skill) {
            $key = spl_object_id($skill);
            $position = array_search($key, $order, true);
            $authored = $this->authored[$key] ?? null;
            if ($authored !== null && $authored['source'] === null) {
                if ($skill->toArray() !== $authored['payload']) { throw $this->getSourceRefusal($skill); }
                // Opaque expressions stay untouched. Move editable neighbours past them.
                while ($position !== $index) {
                    $neighbour = $this->authored[$order[$index]]['skill'];
                    $destination = array_search(spl_object_id($neighbour), $retained, true);
                    if ($destination === false || $destination <= $index) { throw $this->getSourceRefusal($skill); }
                    $document = $this->moveSkillEntry($document, $order, $neighbour, $destination);
                    $position = array_search($key, $order, true);
                }
                continue;
            }
            if ($position === $index && $skill->toArray() === ($authored['payload'] ?? null)) {
                continue;
            }
            $entrySource = $this->getUpdatedEntry($skill);
            if ($position === $index) {
                if ($document->entrySource($index) !== $entrySource) {
                    $old = $document->entrySource($index);
                    if ($old === null) { throw new RuntimeException('Refusing to replace an unsupported skill expression.'); }
                    // Patch the entry's values through the same constructor-span writer.
                    $document = $this->applySkillChanges($document, $index, $skill);
                }
                continue;
            }
            $document = $this->moveSkillEntry($document, $order, $skill, $index);
        }
        for ($index = count($order) - 1; $index >= count($this->skills); $index--) {
            $document = $document->withoutEntry($index);
        }
        return $document->source;
    }

    /** @param list<int> $order */
    private function moveSkillEntry(PhpSourceDocument $document, array &$order, ProjectSkill $skill, int $index): PhpSourceDocument
    {
        $key = spl_object_id($skill);
        $entrySource = $this->getUpdatedEntry($skill);
        $position = array_search($key, $order, true);
        if ($position !== false) {
            $document = $document->withoutEntry($position);
            array_splice($order, $position, 1);
        }
        $index = min($index, count($order));
        $before = $index < count($order) ? $index : null;
        $block = $this->authored[$key]['block'] ?? null;
        if ($block !== null) {
            $preserved = PhpSourceDocument::parse("<?php return [\n" . $block . "\n];");
            $block = $this->applySkillChanges($preserved, 0, $skill)->getEntryBlockSource(0);
            $document = $document->getWithEntryBlockSource($block, $before);
        } else {
            $document = $document->getWithEntrySource($entrySource, $before);
        }
        array_splice($order, $index, 0, [$key]);
        return $document;
    }

    private function getUpdatedEntry(ProjectSkill $skill): string
    {
        $authored = $this->authored[spl_object_id($skill)] ?? null;
        if ($authored === null) { return ltrim($this->exportSkill($skill)); }
        if ($authored['source'] === null) {
            throw $this->getSourceRefusal($skill);
        }
        $document = PhpSourceDocument::parse('<?php return [' . $authored['source'] . '];');
        return $this->applySkillChanges($document, 0, $skill)->entrySource(0)
            ?? throw new RuntimeException('Cannot preserve the edited skill constructor.');
    }

    private function getSourceRefusal(ProjectSkill $skill): RuntimeException
    {
        return new RuntimeException(sprintf('Refusing to flatten skill "%s" (entry %d): its authored expression is read-only. Edit its source directly.', $skill->getName(), $skill->id));
    }

    private function applySkillChanges(PhpSourceDocument $document, int $index, ProjectSkill $skill): PhpSourceDocument
    {
        $authored = $this->authored[spl_object_id($skill)];
        $parameters = $this->getConstructorParameters($skill);
        foreach ($skill->toArray() as $field => $value) {
            if ($value === ($authored['payload'][$field] ?? null)) { continue; }
            if (in_array($field, ['type', 'effects'], true)) {
                throw new RuntimeException('Refusing to regenerate skill type or effects; edit those expressions in source.');
            }
            if ($field === 'animationId' && $value === null) {
                $document = $document->getWithoutConstructorArgument($index, $field, $parameters);
                continue;
            }
            $literal = match ($field) {
                'scope' => $this->exportScope($skill),
                'invocation' => $this->exportInvocation($skill),
                'occasion' => $this->exportOccasion($skill->getOccasion()),
                'effectType' => $this->exportMagicEffectType($skill->getEffectType()),
                default => var_export($value, true),
            };
            $document = $document->getWithConstructorArgument($index, $field, $literal, $parameters);
        }
        return $document;
    }

    /** @return class-string */
    private function getSkillClass(ProjectSkill $skill): string
    {
        return match ($skill->getType()) {
            'basic' => BasicSkill::class,
            'magic' => MagicSkill::class,
            default => SpecialSkill::class,
        };
    }

    private function getNewSource(): string
    {
        $definitions = array_map(fn(ProjectSkill $skill): string => $this->exportSkill($skill), $this->getSkills());
        return "<?php\n\nreturn [\n" . implode("\n", array_map(static fn(string $definition): string => $definition . ',', $definitions)) . "\n];\n";
    }

    private function exportSkill(ProjectSkill $skill): string
    {
        if ($skill->getEffects() !== []) {
            throw new RuntimeException('Refusing to regenerate skill effects without their authored source.');
        }
        $lines = [
            sprintf("  new \\%s(", $this->getSkillClass($skill)),
            "    " . var_export($skill->getName(), true) . ",",
            "    " . var_export($skill->getDescription(), true) . ",",
            "    " . var_export($skill->getIcon(), true) . ",",
            "    " . $skill->getCost() . ",",
            "    " . $skill->getCooldown() . ",",
            "    " . $this->exportScope($skill) . ",",
            "    " . $this->exportOccasion($skill->getOccasion()) . ",",
            "    " . $this->exportInvocation($skill) . ",",
            "    [",
        ];
        $lines[] = "    ],";
        if ($skill->getType() === "magic") {
            $lines[] = "    [],";
            $lines[] = "    " . $this->exportMagicEffectType($skill->getEffectType()) . ",";
        }
        if ($skill->animationId !== null) {
            $lines[] = '    animationId: ' . $skill->animationId . ',';
        }
        $lines[] = "  )";
        return implode(PHP_EOL, $lines);
    }

    private function exportScope(ProjectSkill $skill): string
    {
        $scope = $skill->getScope();
        $parts = [
            "\\" . ItemScopeSide::class . "::" . $this->enumCaseFromValue(ItemScopeSide::class, strval($scope["side"] ?? "Enemy"), "ENEMY"),
            "\\" . ItemScopeNumber::class . "::" . $this->enumCaseFromValue(ItemScopeNumber::class, strval($scope["number"] ?? "One"), "ONE"),
            "\\" . ItemScopeStatus::class . "::" . $this->enumCaseFromValue(ItemScopeStatus::class, strval($scope["status"] ?? "Alive"), "ALIVE"),
        ];
        if (($scope["targetCount"] ?? null) !== null) { $parts[] = strval(max(0, intval($scope["targetCount"]))); }
        return "new \\Ichiloto\\Engine\\Entities\\ItemScope(" . implode(", ", $parts) . ")";
    }

    private function exportOccasion(string $occasion): string
    {
        return "\\" . Occasion::class . "::" . $this->enumCaseFromValue(Occasion::class, $occasion, "BATTLE_SCREEN");
    }

    private function exportInvocation(ProjectSkill $skill): string
    {
        $invocation = $skill->getInvocation();
        return sprintf(
            "new \\Ichiloto\\Engine\\Entities\\Skills\\SkillInvocation(%s, %d, %d, %d, %d)",
            var_export(strval($invocation["message"] ?? ""), true),
            max(0, intval($invocation["speed"] ?? 0)),
            max(0, intval($invocation["accuracy"] ?? 0)),
            max(1, intval($invocation["repeat"] ?? 1)),
            max(0, intval($invocation["apGain"] ?? 10)),
        );
    }

    private function exportMagicEffectType(?string $effectType): string
    {
        return $effectType === null
            ? "null"
            : "\\Ichiloto\\Engine\\Entities\\Magic\\MagicEffectType::" . $this->enumCaseFromValue(\Ichiloto\Engine\Entities\Magic\MagicEffectType::class, $effectType, "DESTRUCTIVE");
    }

    private function enumCaseFromValue(string $enumClass, string $value, string $fallback): string
    {
        foreach ($enumClass::cases() as $case) {
            if ($case->value === $value) { return $case->name; }
        }
        return $fallback;
    }
}
