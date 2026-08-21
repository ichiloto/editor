<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * One definition's claim on a stable inventory id, as a diagnostic needs it.
 *
 * A catalogue that resolves references keeps one definition per id. A
 * catalogue that has to explain a broken project keeps every definition
 * that claimed an id, with what tells them apart: the display name each
 * one currently carries, the category it was authored in, the aliases it
 * brought, and where in the source it sits. None of this makes a claimant
 * resolvable; it makes the conflict nameable.
 *
 * @package Ichiloto\Editor\Database
 */
final readonly class InventoryClaimant
{
    /**
     * @param string $id The stable id claimed.
     * @param string $name The current display name.
     * @param string $category The record category the claimant was authored in.
     * @param string[] $aliases The compatibility aliases the claimant declares.
     * @param string|null $source Where the claimant sits in the source, when known.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $category,
        public array $aliases,
        public ?string $source,
    ) {
    }

    /**
     * Returns the claimant as a definition, the shape resolvable ids are
     * described in.
     *
     * @return array{id: string, name: string, category: string, aliases: string[]} The definition.
     */
    public function toDefinition(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'category' => $this->category, 'aliases' => $this->aliases];
    }

    /**
     * Describes the claimant for a diagnostic: name, category, aliases and
     * source, so an author can tell it from the others.
     *
     * @return string The description.
     */
    public function describe(): string
    {
        $noun = match ($this->category) {
            'items' => 'item',
            'weapons' => 'weapon',
            'armors' => 'armor',
            default => $this->category,
        };
        $parts = [$noun];

        if ($this->aliases !== []) {
            $parts[] = 'aliases: ' . implode(', ', $this->aliases);
        }

        if ($this->source !== null) {
            $parts[] = $this->source;
        }

        return sprintf('%s (%s)', $this->name === '' ? $this->id : $this->name, implode('; ', $parts));
    }
}
