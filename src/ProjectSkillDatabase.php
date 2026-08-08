<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use RuntimeException;

final class ProjectSkillDatabase
{
    public function __construct(
        public readonly string $path,
        private array $skills = [],
        private bool $isDirty = false,
    ) {
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
        foreach (array_values($payload) as $index => $skill) {
            if ($skill instanceof \Ichiloto\Engine\Entities\Skills\Skill) {
                $skills[] = ProjectSkill::fromSkill($skill, $index + 1);
            }
        }
        return new self($path, $skills);
    }

    public function getSkills(): array { return array_values($this->skills); }
    public function isDirty(): bool
    {
        if ($this->isDirty) { return true; }
        foreach ($this->skills as $skill) { if ($skill->isDirty()) { return true; } }
        return false;
    }

    public function getSkillByIndex(int $index): ?ProjectSkill
    {
        return $this->skills[$index] ?? null;
    }

    public function addSkill(string $name = "New Skill"): int
    {
        $nextId = 1;
        foreach ($this->skills as $skill) { $nextId = max($nextId, $skill->id + 1); }
        $this->skills[] = ProjectSkill::createBlank($nextId, $name);
        $this->isDirty = true;
        return count($this->skills) - 1;
    }

    public function setField(int $index, string $field, mixed $value): void
    {
        $skill = $this->getSkillByIndex($index);
        if ($skill instanceof ProjectSkill) { $skill->setField($field, $value); $this->isDirty = true; }
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
        $this->isDirty = true;

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
        $this->isDirty = true;
    }

    public function save(): void
    {
        $directory = dirname($this->path);
        if (is_dir($directory) === false && mkdir($directory, 0777, true) === false && is_dir($directory) === false) {
            throw new RuntimeException(sprintf("Unable to create %s.", $directory));
        }
        $imports = $this->getImportLines();
        $definitions = array_map(fn(ProjectSkill $skill): string => $this->exportSkill($skill), $this->getSkills());
        $payload = [self::openTag(), self::emptyString()];
        foreach ($imports as $import) { $payload[] = $import; }
        $payload[] = self::emptyString();
        $payload[] = self::returnArray();
        foreach ($definitions as $definition) { $payload[] = $definition . self::comma(); }
        $payload[] = self::closingArray();
        $payload[] = self::emptyString();
        self::writeFileTransactionally($this->path, implode(PHP_EOL, $payload));
        $this->isDirty = false;
    }

    private function getImportLines(): array
    {
        $imports = [
            "use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;",
            "use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;",
            "use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;",
            "use Ichiloto\Engine\Entities\Enumerations\Occasion;",
            "use Ichiloto\Engine\Entities\ItemScope;",
            "use Ichiloto\Engine\Entities\Skills\BasicSkill;",
            "use Ichiloto\Engine\Entities\Skills\MagicSkill;",
            "use Ichiloto\Engine\Entities\Skills\SkillInvocation;",
            "use Ichiloto\Engine\Entities\Skills\SpecialSkill;",
        ];
        foreach ($this->getSkills() as $skill) {
            if ($skill->getType() === "magic") { $imports[] = "use Ichiloto\Engine\Entities\Magic\MagicEffectType;"; }
            foreach ($skill->getEffects() as $effect) { $imports[] = "use " . strval($effect["class"] ?? "") . ";"; }
        }
        sort($imports);
        return array_values(array_unique($imports));
    }

    private function exportSkill(ProjectSkill $skill): string
    {
        $lines = [
            sprintf("  new %s(", match ($skill->getType()) {
                "basic" => "BasicSkill",
                "magic" => "MagicSkill",
                default => "SpecialSkill",
            }),
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
        foreach ($skill->getEffects() as $effect) { $lines[] = "      " . $this->exportEffect($effect) . ","; }
        $lines[] = "    ],";
        if ($skill->getType() === "magic") {
            $lines[] = "    [],";
            $lines[] = "    " . $this->exportMagicEffectType($skill->getEffectType()) . ",";
        }
        $lines[] = "  )";
        return implode(PHP_EOL, $lines);
    }

    private function exportScope(ProjectSkill $skill): string
    {
        $scope = $skill->getScope();
        $parts = [
            "ItemScopeSide::" . $this->enumCaseFromValue(ItemScopeSide::class, strval($scope["side"] ?? "Enemy"), "ENEMY"),
            "ItemScopeNumber::" . $this->enumCaseFromValue(ItemScopeNumber::class, strval($scope["number"] ?? "One"), "ONE"),
            "ItemScopeStatus::" . $this->enumCaseFromValue(ItemScopeStatus::class, strval($scope["status"] ?? "Alive"), "ALIVE"),
        ];
        if (($scope["targetCount"] ?? null) !== null) { $parts[] = strval(max(0, intval($scope["targetCount"]))); }
        return "new ItemScope(" . implode(", ", $parts) . ")";
    }

    private function exportOccasion(string $occasion): string
    {
        return "Occasion::" . $this->enumCaseFromValue(Occasion::class, $occasion, "BATTLE_SCREEN");
    }

    private function exportInvocation(ProjectSkill $skill): string
    {
        $invocation = $skill->getInvocation();
        return sprintf(
            "new SkillInvocation(%s, %d, %d, %d, %d)",
            var_export(strval($invocation["message"] ?? ""), true),
            max(0, intval($invocation["speed"] ?? 0)),
            max(0, intval($invocation["accuracy"] ?? 0)),
            max(1, intval($invocation["repeat"] ?? 1)),
            max(0, intval($invocation["apGain"] ?? 10)),
        );
    }

    private function exportEffect(array $effect): string
    {
        $class = basename(str_replace(chr(92), "/", strval($effect["class"] ?? "SkillEffect")));
        return sprintf(
            "new %s(%s, %s, %s, %s)",
            $class,
            var_export(strval($effect["formula"] ?? "0"), true),
            var_export($effect["element"] ?? null, true),
            var_export(floatval($effect["variance"] ?? 0.2), true),
            var_export(boolval($effect["isCriticalHit"] ?? false), true),
        );
    }

    private function exportMagicEffectType(?string $effectType): string
    {
        return $effectType === null
            ? "null"
            : "MagicEffectType::" . $this->enumCaseFromValue(\Ichiloto\Engine\Entities\Magic\MagicEffectType::class, $effectType, "DESTRUCTIVE");
    }

    private function enumCaseFromValue(string $enumClass, string $value, string $fallback): string
    {
        foreach ($enumClass::cases() as $case) {
            if ($case->value === $value) { return $case->name; }
        }
        return $fallback;
    }


    private static function openTag(): string { return chr(60) . chr(63) . chr(112) . chr(104) . chr(112); }
    private static function emptyString(): string { return str_repeat(chr(32), 0); }
    private static function returnArray(): string { return chr(114) . chr(101) . chr(116) . chr(117) . chr(114) . chr(110) . chr(32) . chr(91); }
    private static function comma(): string { return chr(44); }
    private static function closingArray(): string { return chr(93) . chr(59); }
    private static function writeFileTransactionally(string $path, string $contents): void
    {
        $temporaryPath = $path . ".tmp";
        if (file_put_contents($temporaryPath, $contents) === false) {
            throw new RuntimeException(sprintf("Unable to write temporary file for %s.", $path));
        }
        if (rename($temporaryPath, $path) === false) {
            @unlink($temporaryPath);
            throw new RuntimeException(sprintf("Unable to replace %s.", $path));
        }
    }
}
