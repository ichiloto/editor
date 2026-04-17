<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

/**
 * Represents one editable class entry inside the project database.
 */
final class ProjectClass
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $id,
        private array $payload,
        private bool $isDirty = false,
    ) {
    }

    /**
     * Creates a class entry from persisted data.
     *
     * @param array<string, mixed> $payload The raw payload.
     * @param int $fallbackId The fallback id.
     * @return self
     */
    public static function fromArray(array $payload, int $fallbackId): self
    {
        return new self(
            id: max(1, (int) ($payload['id'] ?? $fallbackId)),
            payload: $payload,
        );
    }

    /**
     * Creates a blank class definition.
     *
     * @param int $id The class id.
     * @param string $name The display name.
     * @return self
     */
    public static function createBlank(int $id, string $name = 'New Class'): self
    {
        return new self(
            id: $id,
            payload: [
                'id' => $id,
                'name' => $name,
                'description' => '',
                'initialLevel' => 1,
                'maxLevel' => 99,
                'note' => '',
                'traits' => [],
                'experienceCurve' => [
                    'baseValue' => 30,
                    'extraValue' => 20,
                    'accelerationA' => 30,
                    'accelerationB' => 30,
                ],
                'parameterCurves' => [
                    'totalHp' => ['baseValue' => 120, 'extraGrowth' => 500, 'flatIncrement' => 40],
                    'totalMp' => ['baseValue' => 12, 'extraGrowth' => 100, 'flatIncrement' => 10],
                    'attack' => ['baseValue' => 10, 'extraGrowth' => 50, 'flatIncrement' => 1],
                    'defence' => ['baseValue' => 10, 'extraGrowth' => 30, 'flatIncrement' => 1],
                    'magicAttack' => ['baseValue' => 10, 'extraGrowth' => 50, 'flatIncrement' => 1],
                    'magicDefence' => ['baseValue' => 10, 'extraGrowth' => 30, 'flatIncrement' => 1],
                    'speed' => ['baseValue' => 10, 'extraGrowth' => 20, 'flatIncrement' => 1],
                    'grace' => ['baseValue' => 10, 'extraGrowth' => 15, 'flatIncrement' => 1],
                    'evasion' => ['baseValue' => 5, 'extraGrowth' => 10, 'flatIncrement' => 1],
                ],
            ],
            isDirty: true,
        );
    }

    /**
     * Returns whether the class has unsaved changes.
     *
     * @return bool
     */
    public function isDirty(): bool
    {
        return $this->isDirty;
    }

    /**
     * Returns the class name.
     *
     * @return string
     */
    public function getName(): string
    {
        return (string) ($this->payload['name'] ?? "Class {$this->id}");
    }

    /**
     * Returns the class description.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return (string) ($this->payload['description'] ?? '');
    }

    /**
     * Returns the class initial level.
     *
     * @return int
     */
    public function getInitialLevel(): int
    {
        return max(1, (int) ($this->payload['initialLevel'] ?? 1));
    }

    /**
     * Returns the class max level.
     *
     * @return int
     */
    public function getMaxLevel(): int
    {
        return max($this->getInitialLevel(), (int) ($this->payload['maxLevel'] ?? 99));
    }

    /**
     * Returns the class note.
     *
     * @return string
     */
    public function getNote(): string
    {
        return (string) ($this->payload['note'] ?? '');
    }

    /**
     * Returns the class trait payload.
     *
     * @return array<int, mixed>
     */
    public function getTraits(): array
    {
        $traits = $this->payload['traits'] ?? [];

        return is_array($traits) ? array_values($traits) : [];
    }

    /**
     * Returns the experience curve payload.
     *
     * @return array<string, int>
     */
    public function getExperienceCurve(): array
    {
        $curve = $this->payload['experienceCurve'] ?? [];

        if (! is_array($curve)) {
            return [
                'baseValue' => 30,
                'extraValue' => 20,
                'accelerationA' => 30,
                'accelerationB' => 30,
            ];
        }

        return [
            'baseValue' => (int) ($curve['baseValue'] ?? 30),
            'extraValue' => (int) ($curve['extraValue'] ?? 20),
            'accelerationA' => (int) ($curve['accelerationA'] ?? 30),
            'accelerationB' => (int) ($curve['accelerationB'] ?? 30),
        ];
    }

    /**
     * Returns one parameter curve payload.
     *
     * @param string $key The parameter key.
     * @return array<string, int>
     */
    public function getParameterCurve(string $key): array
    {
        $curves = $this->payload['parameterCurves'] ?? [];
        $curve = is_array($curves) ? ($curves[$key] ?? []) : [];

        if (! is_array($curve)) {
            $curve = [];
        }

        return [
            'baseValue' => (int) ($curve['baseValue'] ?? 0),
            'extraGrowth' => (int) ($curve['extraGrowth'] ?? 0),
            'flatIncrement' => (int) ($curve['flatIncrement'] ?? 0),
        ];
    }

    /**
     * Returns all parameter curves.
     *
     * @return array<string, array<string, int>>
     */
    public function getParameterCurves(): array
    {
        return [
            'totalHp' => $this->getParameterCurve('totalHp'),
            'totalMp' => $this->getParameterCurve('totalMp'),
            'attack' => $this->getParameterCurve('attack'),
            'defence' => $this->getParameterCurve('defence'),
            'magicAttack' => $this->getParameterCurve('magicAttack'),
            'magicDefence' => $this->getParameterCurve('magicDefence'),
            'speed' => $this->getParameterCurve('speed'),
            'grace' => $this->getParameterCurve('grace'),
            'evasion' => $this->getParameterCurve('evasion'),
        ];
    }

    /**
     * Updates one editable class field.
     *
     * @param string $field The field identifier.
     * @param mixed $value The replacement value.
     * @return void
     */
    public function setField(string $field, mixed $value): void
    {
        if (in_array($field, ['name', 'description', 'note'], true)) {
            $this->payload[$field] = (string) $value;
            $this->isDirty = true;
            return;
        }

        if ($field === 'initialLevel') {
            $this->payload['initialLevel'] = max(1, (int) $value);
            $this->payload['maxLevel'] = max(
                (int) ($this->payload['maxLevel'] ?? 99),
                (int) $this->payload['initialLevel'],
            );
            $this->isDirty = true;
            return;
        }

        if ($field === 'maxLevel') {
            $this->payload['maxLevel'] = max($this->getInitialLevel(), (int) $value);
            $this->isDirty = true;
            return;
        }

        $experienceMap = [
            'expBaseValue' => 'baseValue',
            'expExtraValue' => 'extraValue',
            'expAccelerationA' => 'accelerationA',
            'expAccelerationB' => 'accelerationB',
        ];

        if (isset($experienceMap[$field])) {
            if (! isset($this->payload['experienceCurve']) || ! is_array($this->payload['experienceCurve'])) {
                $this->payload['experienceCurve'] = [];
            }

            $this->payload['experienceCurve'][$experienceMap[$field]] = max(0, (int) $value);
            $this->isDirty = true;
            return;
        }

        $parameterMap = [
            'totalHpBaseValue' => 'totalHp',
            'totalMpBaseValue' => 'totalMp',
            'attackBaseValue' => 'attack',
            'defenceBaseValue' => 'defence',
            'magicAttackBaseValue' => 'magicAttack',
            'magicDefenceBaseValue' => 'magicDefence',
            'speedBaseValue' => 'speed',
            'graceBaseValue' => 'grace',
            'evasionBaseValue' => 'evasion',
        ];

        if (isset($parameterMap[$field])) {
            if (! isset($this->payload['parameterCurves']) || ! is_array($this->payload['parameterCurves'])) {
                $this->payload['parameterCurves'] = [];
            }

            $curveKey = $parameterMap[$field];

            if (! isset($this->payload['parameterCurves'][$curveKey]) || ! is_array($this->payload['parameterCurves'][$curveKey])) {
                $this->payload['parameterCurves'][$curveKey] = [];
            }

            $this->payload['parameterCurves'][$curveKey]['baseValue'] = max(0, (int) $value);
            $this->isDirty = true;
        }
    }

    /**
     * Returns the normalized payload for persistence.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = $this->payload;
        $payload['id'] = $this->id;

        return $payload;
    }
}
