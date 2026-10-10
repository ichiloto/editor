<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

/**
 * A class record as its curves read: the terminal editor's experience and
 * stat curve panes chart it. The record itself is edited through the shared
 * record database (the `classes` schema); this only reads it, filling what
 * the record leaves out the way the engine's ClassStore does.
 */
final readonly class ProjectClass
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public int $id,
        private array $payload,
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
     * Returns whether the class has unsaved changes.
     *
     * @return bool
     */
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

}
