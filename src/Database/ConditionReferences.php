<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/** Identifies typed world-state references in authored PHP data, including nested conditions. */
final class ConditionReferences
{
    private function __construct()
    {
    }

    /** @param list<string> $types The condition types that name this kind of resource. */
    public static function containsReference(mixed $source, array $types, string $id): bool
    {
        foreach (self::findConditions($source, $types) as $condition) {
            if (is_string($condition['name'] ?? null) && trim($condition['name']) === $id) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $types The declared condition types to look for.
     * @return iterable<array<array-key, mixed>>
     */
    public static function findConditions(mixed $source, array $types): iterable
    {
        if (! is_array($source) && ! is_object($source)) {
            return;
        }
        $source = (array) $source;
        if (is_string($source['type'] ?? null) && in_array(trim($source['type']), $types, true)) {
            yield $source;
        }

        foreach ($source as $child) {
            yield from self::findConditions($child, $types);
        }
    }
}
