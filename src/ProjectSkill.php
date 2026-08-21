<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Editor\History\TracksPersistedState;

use Ichiloto\Engine\Entities\Effects\SkillEffects\SkillEffect;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Magic\MagicEffectType;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;

final class ProjectSkill
{
    use TracksPersistedState;

    public function __construct(
        public readonly int $id,
        private array $payload,
        bool $isDirty = false,
    ) {
        if (! $isDirty) {
            $this->captureBaseline();
        }
    }

    public static function fromSkill(Skill $skill, int $id): self
    {
        return new self($id, [
            "type" => match (true) {
                $skill instanceof MagicSkill => "magic",
                $skill instanceof SpecialSkill => "special",
                default => "basic",
            },
            "name" => $skill->name,
            "description" => $skill->description,
            "icon" => $skill->icon,
            "cost" => $skill->cost,
            "cooldown" => $skill->cooldown,
            "occasion" => $skill->occasion->value,
            "scope" => [
                "side" => $skill->scope->side->value,
                "number" => $skill->scope->number->value,
                "status" => $skill->scope->status->value,
                "targetCount" => $skill->scope->targetCount,
            ],
            "invocation" => [
                "message" => $skill->invocation->message,
                "speed" => $skill->invocation->speed,
                "accuracy" => $skill->invocation->accuracy,
                "repeat" => $skill->invocation->repeat,
                "apGain" => $skill->invocation->apGain,
            ],
            "effects" => array_map(
                static fn(SkillEffect $effect): array => [
                    "class" => $effect::class,
                    "formula" => $effect->formula,
                    "element" => $effect->element,
                    "variance" => $effect->variance,
                    "isCriticalHit" => $effect->isCriticalHit,
                ],
                $skill->effects,
            ),
            "effectType" => $skill instanceof MagicSkill ? $skill->effectType?->value : null,
        ]);
    }

    public static function createBlank(int $id, string $name = "New Skill"): self
    {
        return new self($id, [
            "type" => "special",
            "name" => $name,
            "description" => "",
            "icon" => "",
            "cost" => 0,
            "cooldown" => 0,
            "occasion" => Occasion::BATTLE_SCREEN->value,
            "scope" => [
                "side" => "Enemy",
                "number" => "One",
                "status" => "Alive",
                "targetCount" => null,
            ],
            "invocation" => [
                "message" => "$1 uses $2!",
                "speed" => 0,
                "accuracy" => 0,
                "repeat" => 1,
                "apGain" => 10,
            ],
            "effects" => [],
            "effectType" => MagicEffectType::DESTRUCTIVE->value,
        ], true);
    }

    /**
     * @inheritDoc
     */
    protected function buildPersistedPayload(): string
    {
        return serialize($this->toArray());
    }

    public function getType(): string { return strval($this->payload["type"] ?? "special"); }
    public function getName(): string { return strval($this->payload["name"] ?? "Skill"); }
    public function getDescription(): string { return strval($this->payload["description"] ?? ""); }
    public function getIcon(): string { return strval($this->payload["icon"] ?? ""); }
    public function getCost(): int { return max(0, intval($this->payload["cost"] ?? 0)); }
    public function getCooldown(): int { return max(0, intval($this->payload["cooldown"] ?? 0)); }
    public function getOccasion(): string { return strval($this->payload["occasion"] ?? Occasion::BATTLE_SCREEN->value); }
    public function getScope(): array { return is_array($this->payload["scope"] ?? null) ? $this->payload["scope"] : []; }
    public function getInvocation(): array { return is_array($this->payload["invocation"] ?? null) ? $this->payload["invocation"] : []; }
    public function getEffects(): array { return is_array($this->payload["effects"] ?? null) ? array_values($this->payload["effects"]) : []; }
    public function getEffectType(): ?string { $value = $this->payload["effectType"] ?? null; return is_string($value) && $value !== "" ? $value : null; }

    public function setField(string $field, mixed $value): void
    {
        if (in_array($field, ["name", "description", "icon", "type", "occasion", "effectType"], true)) {
            $this->payload[$field] = is_string($value) ? trim($value) : strval($value);
            $this->touchState();
            return;
        }

        if (in_array($field, ["cost", "cooldown"], true)) {
            $this->payload[$field] = max(0, intval($value));
            $this->touchState();
            return;
        }
        if (str_starts_with($field, "scope")) {
            $key = lcfirst(substr($field, 5));
            $this->payload["scope"][$key] = $field === "scopeTargetCount" ? (trim(strval($value)) === "" ? null : max(0, intval($value))) : strval($value);
            $this->touchState();
            return;
        }

        if (str_starts_with($field, "invocation")) {
            $key = lcfirst(substr($field, 10));
            $this->payload["invocation"][$key] = $key === "message" ? strval($value) : max(0, intval($value));
            $this->touchState();
        }
    }

    public function getEffectSummaryLines(): array
    {
        $lines = [];
        foreach ($this->getEffects() as $effect) {
            $class = basename(str_replace(chr(92), "/", strval($effect["class"] ?? "Effect")));
            $lines[] = sprintf("%s: %s", $class, strval($effect["formula"] ?? ""));
        }
        return $lines === [] ? ["No effects configured."] : $lines;
    }

    public function toArray(): array
    {
        return $this->payload;
    }
}
