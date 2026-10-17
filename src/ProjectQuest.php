<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Editor\Database\ConditionCodec;
use Ichiloto\Engine\Quests\QuestObjectiveType;

/**
 * A quest record as validation and the terminal's journal panes read it. The
 * record itself is edited through the shared record database (the `quests`
 * schema); this only reads it, the way the engine's `Quest::fromArray` does.
 */
final readonly class ProjectQuest
{
    /**
     * @param array<string, mixed> $payload The quest entry as quests.php holds it.
     */
    public function __construct(private array $payload)
    {
    }

    public function getId(): string
    {
        return strval($this->payload['id'] ?? '');
    }

    public function getName(): string
    {
        return strval($this->payload['name'] ?? $this->getId());
    }

    public function getDescription(): string
    {
        return strval($this->payload['description'] ?? '');
    }

    public function getGiver(): string
    {
        return strval($this->payload['giver'] ?? '');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getObjectives(): array
    {
        $objectives = $this->payload['objectives'] ?? null;

        return is_array($objectives) ? array_values(array_filter($objectives, is_array(...))) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getRewards(): array
    {
        $rewards = $this->payload['rewards'] ?? null;

        return is_array($rewards) ? $rewards : [];
    }

    public function getRewardGold(): int
    {
        return max(0, intval($this->getRewards()['gold'] ?? 0));
    }

    public function getRewardExperience(): int
    {
        return max(0, intval($this->getRewards()['experience'] ?? 0));
    }

    /**
     * Returns the reward items as a comma-separated string.
     */
    public function getRewardItemsString(): string
    {
        return implode(', ', $this->getRewardItems());
    }

    /**
     * The reward items as the journal lists them: each item's name, with
     * its quantity when it gives more than one. The engine reads an item
     * either as a bare name or as `['item' => ..., 'quantity' => ...]`.
     *
     * @return string[]
     */
    public function getRewardItems(): array
    {
        $items = [];

        foreach ((array) ($this->getRewards()['items'] ?? []) as $item) {
            $name = trim(strval(is_array($item) ? ($item['item'] ?? '') : $item));
            $quantity = is_array($item) ? max(1, intval($item['quantity'] ?? 1)) : 1;

            if ($name !== '') {
                $items[] = $quantity > 1 ? sprintf('%s x%d', $name, $quantity) : $name;
            }
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPrerequisites(): array
    {
        $prerequisites = $this->payload['prerequisites'] ?? null;

        return is_array($prerequisites) ? array_values(array_filter($prerequisites, is_array(...))) : [];
    }

    /**
     * Returns journal-style objective summary lines.
     *
     * @return string[]
     */
    public function getObjectiveSummaryLines(): array
    {
        $lines = [];

        foreach ($this->getObjectives() as $index => $objective) {
            $type = QuestObjectiveType::tryFrom(strval($objective['type'] ?? ''));
            $target = strval($objective['target'] ?? '');
            $quantity = max(1, intval($objective['quantity'] ?? 1));
            $description = trim(strval($objective['description'] ?? ''));

            if ($description === '') {
                $description = $type instanceof QuestObjectiveType
                    ? $type->describe($target, $quantity)
                    : sprintf('%s %s', strval($objective['type'] ?? '?'), $target);
            }

            $lines[] = sprintf('%d. %s', $index + 1, $description);
        }

        return $lines === [] ? ['No objectives yet.'] : $lines;
    }

    /**
     * Returns prerequisite summary lines.
     *
     * @return string[]
     */
    public function getPrerequisiteSummaryLines(): array
    {
        $prerequisites = $this->getPrerequisites();

        return $prerequisites === [] ? ['None'] : array_map(ConditionCodec::encode(...), $prerequisites);
    }

    /**
     * Encodes the prerequisites into the one-line condition form.
     */
    public function getPrerequisitesString(): string
    {
        return ConditionCodec::encodeAll($this->getPrerequisites());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }
}
