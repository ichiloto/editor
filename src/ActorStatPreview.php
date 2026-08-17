<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

/**
 * What an actor's stats actually come to, layer by layer.
 *
 * An authored number is rarely the number the game uses: the class baseline
 * is adjusted by the actor's own nature, by permanent growth the party has
 * earned, by what it is holding, and by whatever a battle is doing to it
 * right now, and the total is then capped. An author changing an adjustment
 * needs to see where that lands, including when it lands past the cap and
 * the change does nothing.
 *
 * The arithmetic is the engine's own -- `StatResolver` and
 * `EntityStatCapPolicy` -- so this never becomes a second opinion about what
 * a stat is. Nothing here writes: the preview reads project data and the
 * fixture a caller supplies, and leaves both exactly as they were.
 *
 * @package Ichiloto\Editor
 */
final class ActorStatPreview
{
    /**
     * The canonical stat keys, in the order the runtime declares them.
     *
     * Read from the engine when it is loaded, so a stat the runtime adds
     * appears here without another edit. Accuracy and Critical are
     * deliberately absent: they are not resolved layers.
     *
     * @return string[] The stat keys.
     */
    public static function statKeys(): array
    {
        if (enum_exists(\Ichiloto\Engine\Entities\Stats\StatKey::class)) {
            return array_map(
                static fn(\Ichiloto\Engine\Entities\Stats\StatKey $key): string => $key->value,
                \Ichiloto\Engine\Entities\Stats\StatKey::cases(),
            );
        }

        return ['maxHp', 'maxMp', 'attack', 'defence', 'magicAttack', 'magicDefence', 'speed', 'grace', 'evasion'];
    }

    /**
     * Returns whether the engine's resolver is reachable, and so whether a
     * preview can be shown at all.
     *
     * @return bool True when it is.
     */
    public static function isAvailable(): bool
    {
        return class_exists(\Ichiloto\Engine\Entities\Stats\StatResolver::class)
            && class_exists(\Ichiloto\Engine\Entities\Stats\EntityStatCapPolicy::class);
    }

    /**
     * Resolves every stat for an actor.
     *
     * @param ProjectActor $actor The actor.
     * @param string|null $variantId The natural variant to preview, or null for the default.
     * @param array<string, int> $permanent Acquired permanent growth, from a caller's fixture.
     * @param array<string, int> $equipment What equipment contributes.
     * @param array<string, int> $temporary What a battle is contributing right now.
     * @param bool $isEnemy Whether to cap as an enemy rather than a player.
     * @return array<int, array<string, mixed>> One row per stat.
     */
    public static function resolve(
        ProjectActor $actor,
        ?string $variantId = null,
        array $permanent = [],
        array $equipment = [],
        array $temporary = [],
        bool $isEnemy = false,
    ): array {
        if (! self::isAvailable()) {
            return [];
        }

        $natural = $actor->getStats();
        $adjustments = $actor->getNaturalAdjustmentsFor($variantId);
        $caps = $isEnemy
            ? \Ichiloto\Engine\Entities\Stats\EntityStatCapPolicy::enemy()
            : \Ichiloto\Engine\Entities\Stats\EntityStatCapPolicy::player();
        $rows = [];

        foreach (self::statKeys() as $key) {
            $stat = \Ichiloto\Engine\Entities\Stats\StatKey::tryFrom($key);

            if ($stat === null) {
                continue;
            }

            $resolution = \Ichiloto\Engine\Entities\Stats\StatResolver::resolve(
                $stat,
                self::naturalFor($natural, $key),
                $adjustments[$key] ?? 0,
                $permanent[$key] ?? 0,
                $equipment[$key] ?? 0,
                $caps,
                $temporary[$key] ?? 0,
            );

            $rows[] = [
                'stat' => $key,
                'natural' => $resolution->natural,
                'actorNatural' => $resolution->actorNatural,
                'permanent' => $resolution->permanent,
                'equipment' => $resolution->equipment,
                'temporary' => $resolution->temporary,
                'uncapped' => $resolution->uncappedValue,
                'effective' => $resolution->effectiveValue,
                'cap' => $resolution->cap,
                'capLoss' => $resolution->capLoss,
                'headroom' => $resolution->remainingHeadroom,
            ];
        }

        return $rows;
    }

    /**
     * Returns the class-baseline value an actor's stats declare for a key.
     *
     * A project may author vitality as `totalHp` or as `maxHp`; both are the
     * same layer to the runtime, so either is read.
     *
     * @param array<string, int> $stats The authored stats.
     * @param string $key The canonical stat key.
     * @return int The value.
     */
    private static function naturalFor(array $stats, string $key): int
    {
        return match ($key) {
            'maxHp' => $stats['maxHp'] ?? $stats['totalHp'] ?? 0,
            'maxMp' => $stats['maxMp'] ?? $stats['totalMp'] ?? 0,
            default => $stats[$key] ?? 0,
        };
    }

    /**
     * Describes one resolved row for a settings pane.
     *
     * The layers that contribute nothing are left out, so a row reads as
     * what actually made the number rather than a wall of zeroes.
     *
     * @param array<string, mixed> $row One row from resolve().
     * @return string The description.
     */
    public static function describeRow(array $row): string
    {
        $parts = [sprintf('%d natural', $row['natural'])];

        foreach (['actorNatural' => 'nature', 'permanent' => 'growth', 'equipment' => 'gear', 'temporary' => 'battle'] as $key => $noun) {
            $amount = intval($row[$key] ?? 0);

            if ($amount !== 0) {
                $parts[] = sprintf('%+d %s', $amount, $noun);
            }
        }

        $description = sprintf('%d · %s', $row['effective'], implode(', ', $parts));

        if (intval($row['capLoss']) > 0) {
            // The part of the total the cap throws away is the whole reason
            // an author would look at this row.
            return sprintf('%s · %d lost to the %d cap', $description, $row['capLoss'], $row['cap']);
        }

        return sprintf('%s · %d to the cap', $description, $row['headroom']);
    }
}
