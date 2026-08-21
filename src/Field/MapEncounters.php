<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\ProjectMap;
use InvalidArgumentException;

/**
 * A map's random-encounter block, as the engine's `EncounterManager` reads it.
 *
 * The authored shape is one keyed block on the map:
 *
 * ```php
 * 'encounters' => [
 *     'troops' => ['Rat + Bat' => 5, 'Bat x 2' => 4],
 *     'rate' => 22,
 *     'tiles' => 'encounter',
 * ],
 * ```
 *
 * The engine reads it loosely -- a missing `rate` means fifteen steps, a
 * missing `tiles` means danger tiles only, a troop with a non-positive or
 * unreadable weight is dropped, and a block that names no readable troop
 * produces no encounters at all. This model holds exactly what was authored
 * so the editor can show it and say what the engine will make of it, rather
 * than writing the engine's defaults into a file merely because someone
 * opened the map.
 *
 * Troops are edited as ordered rows even though the file stores them as a
 * keyed map, because that is how an author thinks about a weighted table and
 * because renaming a row must keep its place. Two rows may never carry the
 * same troop: PHP would keep the last and discard the other without a word,
 * so the editor refuses the edit instead.
 *
 * Anything else inside the block -- a key a later engine will read -- is kept
 * exactly as it was found. A shape this model cannot hold is refused by name
 * rather than rewritten.
 *
 * @package Ichiloto\Editor\Field
 */
final class MapEncounters
{
    /** The map key holding the block. */
    public const string KEY = 'encounters';

    /** What the engine uses when `rate` is absent. */
    public const int DEFAULT_RATE = 15;

    /** What the engine uses when `tiles` is absent. */
    public const string DEFAULT_TILES = 'encounter';

    /** The tile modes the engine understands. */
    public const array TILE_MODES = ['encounter', 'any'];

    /** The keys this model owns; everything else in the block is the author's. */
    private const array OWNED_KEYS = ['troops', 'rate', 'tiles'];

    /**
     * @param array<string, mixed>|null $block The authored block, or null when the map declares none.
     * @param array<int, array{name: string, weight: mixed}> $rows The troop rows, in authored order.
     * @param string|null $unsupported Why this block cannot be edited, or null.
     */
    private function __construct(
        private readonly ?array $block,
        private readonly array $rows,
        private readonly ?string $unsupported,
    ) {
    }

    /**
     * Reads the block a map currently holds, unsaved edits included.
     */
    public static function fromMap(ProjectMap $map): self
    {
        return self::of($map->getMapDataField([self::KEY]));
    }

    /**
     * Reads one authored value as an encounter block.
     */
    public static function of(mixed $block): self
    {
        if ($block === null) {
            return new self(null, [], null);
        }

        if (! is_array($block)) {
            return new self(null, [], sprintf('the encounters block is %s, not an array', get_debug_type($block)));
        }

        $troops = $block['troops'] ?? [];

        if (! is_array($troops)) {
            return new self($block, [], sprintf('the encounters troops are %s, not troop weights', get_debug_type($troops)));
        }

        $rows = [];

        foreach ($troops as $name => $weight) {
            if (! is_string($name)) {
                // The engine skips a troop it cannot name, and the editor
                // cannot show a numeric key as a troop reference.
                return new self($block, [], sprintf('a troop is keyed by %s (%s) rather than by name', get_debug_type($name), var_export($name, true)));
            }

            if (! is_scalar($weight) && $weight !== null) {
                return new self($block, [], sprintf('the troop "%s" has a weight that is %s', $name, get_debug_type($weight)));
            }

            $rows[] = ['name' => $name, 'weight' => $weight];
        }

        return new self($block, $rows, null);
    }

    /**
     * Whether the map declares an encounters block at all.
     */
    public function isDeclared(): bool
    {
        return $this->block !== null;
    }

    /**
     * Whether the editor can hold and rewrite this block exactly.
     */
    public function isSupported(): bool
    {
        return $this->unsupported === null;
    }

    /**
     * Why the block cannot be edited, or null when it can.
     */
    public function unsupportedReason(): ?string
    {
        return $this->unsupported;
    }

    /**
     * The troop rows, in authored order.
     *
     * @return array<int, array{name: string, weight: mixed}>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * The authored rate, or null when the block leaves it to the engine.
     */
    public function authoredRate(): ?int
    {
        $rate = $this->block['rate'] ?? null;

        return is_numeric($rate) ? (int) $rate : null;
    }

    /**
     * The raw authored rate, whatever it is.
     */
    public function rawRate(): mixed
    {
        return $this->block['rate'] ?? null;
    }

    /**
     * The number of steps the engine will actually average between fights.
     */
    public function rate(): int
    {
        $rate = $this->block['rate'] ?? null;

        return is_numeric($rate) ? max(1, (int) $rate) : self::DEFAULT_RATE;
    }

    /**
     * The authored tile mode, or null when the block leaves it to the engine.
     */
    public function authoredTiles(): ?string
    {
        $tiles = $this->block['tiles'] ?? null;

        return is_string($tiles) ? $tiles : null;
    }

    /**
     * The tile mode the engine will use: `any` counts every step, anything
     * else counts only danger tiles.
     */
    public function tiles(): string
    {
        return $this->authoredTiles() === 'any' ? 'any' : self::DEFAULT_TILES;
    }

    /**
     * A one-line summary for the collapsed row.
     */
    public function summary(): string
    {
        if ($this->unsupported !== null) {
            return 'unsupported shape';
        }

        if (! $this->isDeclared() || $this->rows === []) {
            return 'off';
        }

        return sprintf(
            '%d troop%s, every ~%d step%s, %s',
            count($this->rows),
            count($this->rows) === 1 ? '' : 's',
            $this->rate(),
            $this->rate() === 1 ? '' : 's',
            $this->tiles() === 'any' ? 'any tile' : 'danger tiles',
        );
    }

    /**
     * The troop names already spoken for, so a new row can pick a free one.
     *
     * @return string[]
     */
    public function troopNames(): array
    {
        return array_map(static fn(array $row): string => $row['name'], $this->rows);
    }

    // -- Edits -------------------------------------------------------------

    /**
     * The block with a different average rate.
     *
     * @return array<string, mixed>|null
     */
    public function withRate(int $rate): ?array
    {
        return $this->rebuilt($this->rows, ['rate' => max(1, $rate)]);
    }

    /**
     * The block with a different tile mode.
     *
     * @return array<string, mixed>|null
     */
    public function withTiles(string $tiles): ?array
    {
        $tiles = in_array($tiles, self::TILE_MODES, true) ? $tiles : self::DEFAULT_TILES;

        return $this->rebuilt($this->rows, ['tiles' => $tiles]);
    }

    /**
     * The block with one row pointed at a different troop.
     *
     * @return array<string, mixed>|null
     * @throws InvalidArgumentException When another row already names that troop.
     */
    public function withTroopAt(int $index, string $name): ?array
    {
        $rows = $this->rows;

        if (! array_key_exists($index, $rows)) {
            return $this->block;
        }

        $this->assertFree($name, $index);
        $rows[$index]['name'] = $name;

        return $this->rebuilt($rows);
    }

    /**
     * The block with one row's weight changed.
     *
     * @return array<string, mixed>|null
     */
    public function withWeightAt(int $index, int $weight): ?array
    {
        $rows = $this->rows;

        if (! array_key_exists($index, $rows)) {
            return $this->block;
        }

        $rows[$index]['weight'] = max(1, $weight);

        return $this->rebuilt($rows);
    }

    /**
     * The block with one more troop row, after the given position.
     *
     * @return array<string, mixed>|null
     * @throws InvalidArgumentException When that troop is already listed.
     */
    public function withTroopAdded(string $name, ?int $after = null, int $weight = 1): ?array
    {
        $this->assertFree($name, null);
        $rows = $this->rows;
        $position = $after === null ? count($rows) : min(count($rows), max(0, $after + 1));
        array_splice($rows, $position, 0, [['name' => $name, 'weight' => max(1, $weight)]]);

        return $this->rebuilt($rows);
    }

    /**
     * The block without one troop row. Removing the last row removes the
     * block, unless it carries a field this model does not own.
     *
     * @return array<string, mixed>|null
     */
    public function withTroopRemovedAt(int $index): ?array
    {
        $rows = $this->rows;

        if (! array_key_exists($index, $rows)) {
            return $this->block;
        }

        array_splice($rows, $index, 1);

        return $this->rebuilt($rows);
    }

    /**
     * Rebuilds the authored block around new rows, keeping every key this
     * model does not own, and the order they were authored in.
     *
     * @param array<int, array{name: string, weight: mixed}> $rows
     * @param array<string, mixed> $changes Owned values to set.
     * @return array<string, mixed>|null The block to write, or null to remove it.
     */
    private function rebuilt(array $rows, array $changes = []): ?array
    {
        $block = $this->block ?? [];

        if ($rows === []) {
            // No troop can be picked, so the block asks for encounters the
            // engine cannot give. Everything this model owns goes with it;
            // a field it does not own keeps the block alive.
            $preserved = array_diff_key($block, array_flip(self::OWNED_KEYS));

            return $preserved === [] ? null : $preserved;
        }

        $troops = [];

        foreach ($rows as $row) {
            $troops[$row['name']] = $row['weight'] ?? 1;
        }

        $block['troops'] = $troops;

        foreach ($changes as $key => $value) {
            $block[$key] = $value;
        }

        return $block;
    }

    /**
     * Refuses a troop another row already names.
     *
     * @throws InvalidArgumentException
     */
    private function assertFree(string $name, ?int $exceptIndex): void
    {
        foreach ($this->rows as $index => $row) {
            if ($index !== $exceptIndex && $row['name'] === $name) {
                throw new InvalidArgumentException(sprintf(
                    'The troop "%s" is already row %d; two rows would collapse into one and lose a weight.',
                    $name,
                    $index + 1,
                ));
            }
        }
    }
}
