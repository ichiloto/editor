<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Editor\History\TracksPersistedState;

use Ichiloto\Editor\Database\ConditionCodec;
use Ichiloto\Engine\Quests\QuestObjectiveType;

/**
 * One editable quest entry backed by its raw quests.php payload.
 *
 * The payload array is kept verbatim so untouched keys (optional rewards,
 * prerequisites, omitted objective quantities) round-trip losslessly.
 */
final class ProjectQuest
{
    use TracksPersistedState;

    /**
     * @param array<string, mixed> $payload The raw quest entry.
     * @param bool $isDirty Whether the quest holds unsaved edits.
     */
    public function __construct(
        private array $payload,
        bool $isDirty = false,
    ) {
        if (! $isDirty) {
            $this->captureBaseline();
        }
    }

    /**
     * Creates a blank quest with one placeholder objective.
     *
     * @param string $id The quest id.
     * @param string $name The quest name.
     * @return self
     */
    public static function createBlank(string $id, string $name = 'New Quest'): self
    {
        return new self([
            'id' => $id,
            'name' => $name,
            'description' => '',
            'giver' => '',
            'objectives' => [
                ['type' => QuestObjectiveType::TALK_TO->value, 'target' => 'New Target'],
            ],
        ], true);
    }


    /**
     * @inheritDoc
     */
    protected function buildPersistedPayload(): string
    {
        return serialize($this->payload);
    }

    public function markClean(): void
    {
        $this->captureBaseline();
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
     * Returns the reward item names as a comma-separated string.
     *
     * @return string
     */
    public function getRewardItemsString(): string
    {
        return implode(', ', $this->getRewardItems());
    }

    /**
     * @return string[] The reward item names, as the engine's store resolves them.
     */
    public function getRewardItems(): array
    {
        return array_values(array_filter(
            array_map(strval(...), (array) ($this->getRewards()['items'] ?? [])),
            static fn(string $item): bool => trim($item) !== ''
        ));
    }

    /**
     * Stores the reward item list, dropping the key when it empties.
     *
     * @param string[] $items The item names.
     * @return void
     */
    public function setRewardItems(array $items): void
    {
        $items = array_values(array_filter(
            array_map(static fn(mixed $item): string => trim(strval($item)), $items),
            static fn(string $item): bool => $item !== ''
        ));
        $rewards = $this->getRewards();

        if ($items === []) {
            unset($rewards['items']);
        } else {
            $rewards['items'] = $items;
        }

        $this->setRewards($rewards);
    }

    /**
     * Adds a reward item slot after the given one, or at the end.
     *
     * @param int|null $afterIndex The slot to insert after.
     * @return int The new slot's index.
     */
    public function addRewardItem(?int $afterIndex = null): int
    {
        $items = $this->getRewardItems();
        $position = $afterIndex === null ? count($items) : min(count($items), $afterIndex + 1);

        // A placeholder name, so the slot exists to be picked into; the
        // picker replaces it before anything resolves it.
        array_splice($items, $position, 0, ['S-Potion']);
        $rewards = $this->getRewards();
        $rewards['items'] = $items;
        $this->setRewards($rewards);

        return $position;
    }

    /**
     * Removes a reward item slot.
     *
     * @param int $index The slot.
     * @return string|null The removed name, or null when the slot is unknown.
     */
    public function removeRewardItem(int $index): ?string
    {
        $items = $this->getRewardItems();

        if (! array_key_exists($index, $items)) {
            return null;
        }

        [$removed] = array_splice($items, $index, 1);
        $this->setRewardItems($items);

        return $removed;
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
     * Applies one flat settings-field edit.
     *
     * Objective fields arrive as
     * `objective{index}{Type|Target|Quantity|Description|RevealedDescription|RevealConditions}`
     * with a zero-based objective index.
     *
     * @param string $field The field identifier.
     * @param mixed $value The edited value.
     * @return void
     */
    public function setField(string $field, mixed $value): void
    {
        if (preg_match('/^objective(\d+)(Type|Target|Quantity|Description|RevealedDescription|RevealConditions)$/', $field, $matches) === 1) {
            $this->setObjectiveField(intval($matches[1]), $matches[2], $value);
            return;
        }

        if (preg_match('/^rewardItem(\d+)$/', $field, $matches) === 1) {
            $items = $this->getRewardItems();
            $items[intval($matches[1])] = trim(strval($value));
            $this->setRewardItems($items);
            return;
        }

        switch ($field) {
            case 'id':
                $id = trim(strval($value));

                if ($id !== '') {
                    $this->payload['id'] = $id;
                    $this->touchState();
                }

                return;
            case 'name':
            case 'giver':
            case 'description':
                $this->payload[$field] = trim(strval($value));
                $this->touchState();
                return;
            case 'rewardGold':
                $this->setRewardAmount('gold', max(0, intval($value)));
                return;
            case 'rewardExperience':
                $this->setRewardAmount('experience', max(0, intval($value)));
                return;
            case 'rewardItems':
                $items = array_values(array_filter(array_map(trim(...), explode(',', strval($value))), static fn(string $item): bool => $item !== ''));
                $rewards = $this->getRewards();

                if ($items === []) {
                    unset($rewards['items']);
                } else {
                    $rewards['items'] = $items;
                }

                $this->setRewards($rewards);
                return;
            case 'prerequisites':
                $prerequisites = self::decodePrerequisites(strval($value));

                if ($prerequisites === []) {
                    unset($this->payload['prerequisites']);
                } else {
                    $this->payload['prerequisites'] = $prerequisites;
                }

                $this->touchState();
                return;
        }
    }

    /**
     * Appends a placeholder objective.
     *
     * @param array<string, mixed>|null $objective The objective payload; a placeholder when null.
     * @return int The new objective index.
     */
    public function addObjective(?array $objective = null): int
    {
        $objectives = $this->getObjectives();
        $objectives[] = $objective ?? ['type' => QuestObjectiveType::TALK_TO->value, 'target' => 'New Target'];
        $this->payload['objectives'] = $objectives;
        $this->touchState();

        return count($objectives) - 1;
    }

    /**
     * Removes one objective by index.
     *
     * @param int $index The objective index.
     * @return array<string, mixed>|null The removed objective payload.
     */
    public function removeObjective(int $index): ?array
    {
        $objectives = $this->getObjectives();

        if (! array_key_exists($index, $objectives)) {
            return null;
        }

        [$removed] = array_splice($objectives, $index, 1);
        $this->payload['objectives'] = $objectives;
        $this->touchState();

        return $removed;
    }

    /**
     * Re-inserts an objective at a specific index (undo support).
     *
     * @param int $index The target objective index.
     * @param array<string, mixed> $objective The objective payload.
     * @return void
     */
    public function insertObjective(int $index, array $objective): void
    {
        $objectives = $this->getObjectives();
        $index = max(0, min(count($objectives), $index));
        array_splice($objectives, $index, 0, [$objective]);
        $this->payload['objectives'] = $objectives;
        $this->touchState();
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

        if ($prerequisites === []) {
            return ['None'];
        }

        return array_map(static fn(array $prerequisite): string => self::encodePrerequisite($prerequisite), $prerequisites);
    }

    /**
     * Encodes the prerequisites into the editable one-line form.
     *
     * @return string
     */
    public function getPrerequisitesString(): string
    {
        return implode('; ', array_map(
            static fn(array $prerequisite): string => self::encodePrerequisite($prerequisite),
            $this->getPrerequisites()
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    /**
     * Sets or removes a numeric reward amount.
     *
     * @param string $key The reward key.
     * @param int $amount The amount; zero removes the key.
     * @return void
     */
    private function setRewardAmount(string $key, int $amount): void
    {
        $rewards = $this->getRewards();

        if ($amount <= 0) {
            unset($rewards[$key]);
        } else {
            $rewards[$key] = $amount;
        }

        $this->setRewards($rewards);
    }

    /**
     * Stores the rewards array, dropping it entirely when empty.
     *
     * @param array<string, mixed> $rewards The rewards payload.
     * @return void
     */
    private function setRewards(array $rewards): void
    {
        if ($rewards === []) {
            unset($this->payload['rewards']);
        } else {
            $this->payload['rewards'] = $rewards;
        }

        $this->touchState();
    }

    /**
     * Applies one objective sub-field edit.
     *
     * @param int $index The objective index.
     * @param string $key The objective key.
     * @param mixed $value The edited value.
     * @return void
     */
    private function setObjectiveField(int $index, string $key, mixed $value): void
    {
        $objectives = $this->getObjectives();

        if (! array_key_exists($index, $objectives)) {
            return;
        }

        $objective = $objectives[$index];

        switch ($key) {
            case 'Type':
                $type = QuestObjectiveType::tryFrom(strval($value));

                if (! $type instanceof QuestObjectiveType) {
                    return;
                }

                $objective['type'] = $type->value;
                break;
            case 'Target':
                $target = trim(strval($value));

                if ($target === '') {
                    return;
                }

                $objective['target'] = $target;
                break;
            case 'Quantity':
                $quantity = max(1, intval($value));

                if ($quantity > 1) {
                    $objective['quantity'] = $quantity;
                } else {
                    unset($objective['quantity']);
                }

                break;
            case 'Description':
                $description = trim(strval($value));

                if ($description === '') {
                    unset($objective['description']);
                } else {
                    $objective['description'] = $description;
                }

                break;
            case 'RevealedDescription':
                $description = trim(strval($value));

                if ($description === '') {
                    unset($objective['revealedDescription']);
                } else {
                    $objective['revealedDescription'] = $description;
                }

                break;
            case 'RevealConditions':
                $conditions = ConditionCodec::decodeAll(strval($value));

                if ($conditions === []) {
                    unset($objective['revealConditions']);
                } else {
                    $objective['revealConditions'] = $conditions;
                }

                break;
        }

        $objectives[$index] = $objective;
        $this->payload['objectives'] = $objectives;
        $this->touchState();
    }

    /**
     * Encodes one prerequisite as `[!]type:name[:extras]`.
     *
     * @param array<string, mixed> $prerequisite The prerequisite payload.
     * @return string
     */
    private static function encodePrerequisite(array $prerequisite): string
    {
        $type = strval($prerequisite['type'] ?? '');
        $name = strval($prerequisite['name'] ?? '');
        $parts = [$type, $name];

        switch ($type) {
            case 'quest':
                $parts[] = strval($prerequisite['status'] ?? 'completed');
                break;
            case 'switch':
                if (($prerequisite['value'] ?? true) === false) {
                    $parts[] = 'false';
                }

                break;
            case 'item':
                if (intval($prerequisite['quantity'] ?? 1) > 1) {
                    $parts[] = strval(intval($prerequisite['quantity']));
                }

                break;
            case 'variable':
                $parts[] = strval($prerequisite['op'] ?? '==');
                $parts[] = strval($prerequisite['value'] ?? 0);
                break;
        }

        $negate = ($prerequisite['negate'] ?? false) === true ? '!' : '';

        return $negate . implode(':', $parts);
    }

    /**
     * Decodes the one-line prerequisite form back into condition arrays.
     *
     * Entries are separated by `;`. Each entry is `[!]type:name[:extras]`
     * where extras depend on the type (quest status, switch `false`, item
     * quantity, or variable `op:value`).
     *
     * @param string $value The encoded prerequisites.
     * @return array<int, array<string, mixed>>
     */
    private static function decodePrerequisites(string $value): array
    {
        $prerequisites = [];

        foreach (explode(';', $value) as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            $negate = str_starts_with($segment, '!');
            $segment = ltrim($segment, '!');
            $parts = array_map(trim(...), explode(':', $segment));
            $type = strtolower($parts[0] ?? '');
            $name = $parts[1] ?? '';

            if ($name === '' || ! in_array($type, ['quest', 'switch', 'event', 'variable', 'item'], true)) {
                continue;
            }

            $prerequisite = ['type' => $type, 'name' => $name];

            switch ($type) {
                case 'quest':
                    $status = strtolower($parts[2] ?? 'completed');
                    $prerequisite['status'] = in_array($status, ['completed', 'active'], true) ? $status : 'completed';
                    break;
                case 'switch':
                    if (strtolower($parts[2] ?? 'true') === 'false') {
                        $prerequisite['value'] = false;
                    }

                    break;
                case 'item':
                    if (intval($parts[2] ?? 1) > 1) {
                        $prerequisite['quantity'] = intval($parts[2]);
                    }

                    break;
                case 'variable':
                    $prerequisite['op'] = $parts[2] ?? '==';
                    $rawValue = $parts[3] ?? '0';
                    $prerequisite['value'] = is_numeric($rawValue) ? intval($rawValue) : $rawValue;
                    break;
            }

            if ($negate) {
                $prerequisite['negate'] = true;
            }

            $prerequisites[] = $prerequisite;
        }

        return $prerequisites;
    }
}
