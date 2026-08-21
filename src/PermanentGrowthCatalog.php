<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Throwable;

/**
 * The permanent growth a project defines, and what it would come to.
 *
 * Permanent growth is the layer between what an actor naturally is and what
 * it is holding: a stat increase the party earns and keeps. The runtime keeps
 * the *earned* entries in a save's `PermanentGrowthLedger`, which is acquired
 * state and none of an editor's business. What a project owns is the other
 * half -- the reusable definitions a grant is made from, each with a stable
 * identity, the stat it moves, by how much, and where it came from -- and
 * that is what this reads.
 *
 * Every rule here is the engine's, run rather than restated: each definition
 * is built through `PermanentStatModifier::fromArray()` and granted into a
 * real `PermanentGrowthLedger`, so an id two definitions disagree over fails
 * exactly where the runtime would fail, in the runtime's own words.
 *
 * Nothing here writes, and nothing here reaches a save file.
 *
 * @package Ichiloto\Editor
 */
final class PermanentGrowthCatalog
{
    /**
     * Where a project defines its permanent growth.
     */
    public const string RELATIVE_PATH = 'assets/Data/permanent-growth.php';

    /**
     * @param array<string, array<string, mixed>> $definitions By stable id, as authored.
     * @param array<int, array{index: int, id: string, message: string}> $faults What the engine refused, and why.
     * @param array<int, string> $repeats Ids granted more than once with identical content.
     */
    private function __construct(
        private readonly array $definitions,
        private readonly array $faults,
        private readonly array $repeats,
    ) {
    }

    /**
     * Returns whether the engine's permanent-growth contract is reachable.
     *
     * @return bool True when it is.
     */
    public static function isAvailable(): bool
    {
        return class_exists(\Ichiloto\Engine\Entities\Stats\PermanentStatModifier::class)
            && class_exists(\Ichiloto\Engine\Entities\Stats\PermanentGrowthLedger::class);
    }

    /**
     * Reads a project's permanent-growth definitions.
     *
     * @param string $projectRoot The project root.
     * @return self The catalogue.
     */
    public static function fromProject(string $projectRoot): self
    {
        $path = rtrim($projectRoot, '/') . '/' . self::RELATIVE_PATH;

        if (! is_file($path)) {
            return new self([], [], []);
        }

        try {
            $payload = (static fn(): mixed => require $path)();
        } catch (Throwable $failure) {
            return new self([], [[
                'index' => 0,
                'id' => '',
                'message' => $failure->getMessage(),
            ]], []);
        }

        return self::fromPayload(is_array($payload) ? $payload : []);
    }

    /**
     * Reads definitions already loaded, so a caller with the payload in hand
     * does not read the file twice.
     *
     * @param array<int, mixed> $payload The authored definitions.
     * @return self The catalogue.
     */
    public static function fromPayload(array $payload): self
    {
        $definitions = [];
        $faults = [];
        $repeats = [];
        $ledger = self::isAvailable()
            ? new \Ichiloto\Engine\Entities\Stats\PermanentGrowthLedger()
            : null;

        foreach (array_values($payload) as $index => $entry) {
            if (! is_array($entry)) {
                $faults[] = ['index' => $index, 'id' => '', 'message' => 'A definition must be an array.'];

                continue;
            }

            $id = trim(strval($entry['id'] ?? ''));

            if ($ledger === null) {
                // Without the engine there is nothing to check against, so
                // the definitions are reported as authored and no rule is
                // invented in its absence.
                $definitions[$id] = $entry;

                continue;
            }

            try {
                $granted = $ledger->grant(
                    \Ichiloto\Engine\Entities\Stats\PermanentStatModifier::fromArray($entry),
                );
            } catch (Throwable $failure) {
                $faults[] = ['index' => $index, 'id' => $id, 'message' => $failure->getMessage()];

                continue;
            }

            if (! $granted) {
                // The runtime treats an identical repeat as already done, so
                // the second one is redundant rather than wrong.
                $repeats[] = $id;

                continue;
            }

            $definitions[$id] = $entry;
        }

        return new self($definitions, $faults, array_values(array_unique($repeats)));
    }

    /**
     * Returns every definition the project declares, keyed by stable id.
     *
     * @return array<string, array<string, mixed>> The definitions.
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * Returns the stable ids, in authored order.
     *
     * @return string[] The ids.
     */
    public function ids(): array
    {
        return array_values(array_filter(
            array_keys($this->definitions),
            static fn(string $id): bool => $id !== '',
        ));
    }

    /**
     * Returns what the engine refused, and why, in its own words.
     *
     * @return array<int, array{index: int, id: string, message: string}> The faults.
     */
    public function faults(): array
    {
        return $this->faults;
    }

    /**
     * Returns the ids defined more than once with identical content.
     *
     * @return string[] The ids.
     */
    public function repeats(): array
    {
        return $this->repeats;
    }

    /**
     * Describes one definition the way a picker or a diagnostic should: what
     * it does, and the identity a grant would carry.
     *
     * @param string $id The stable id.
     * @return string The description.
     */
    public function describe(string $id): string
    {
        $definition = $this->definitions[$id] ?? null;

        if ($definition === null) {
            return $id;
        }

        $metadata = is_array($definition['metadata'] ?? null) ? $definition['metadata'] : [];
        $label = trim(strval($metadata['label'] ?? ''));

        return sprintf(
            '%s%+d %s (%s)',
            $label === '' ? '' : $label . ' · ',
            intval($definition['amount'] ?? 0),
            trim(strval($definition['stat'] ?? '')),
            $id,
        );
    }

    /**
     * Returns what a set of definitions would add to each stat.
     *
     * This is what a preview assumes the party has earned. It is a fixture
     * for looking at, never a save payload: the editor does not grant growth,
     * because at this engine head nothing but runtime API can.
     *
     * @param string[]|null $ids The definitions to assume, or null for all of them.
     * @return array<string, int> The totals by stat key.
     */
    public function totalsFor(?array $ids = null): array
    {
        $totals = [];

        foreach ($this->definitions as $id => $definition) {
            if ($ids !== null && ! in_array($id, $ids, true)) {
                continue;
            }

            $stat = trim(strval($definition['stat'] ?? ''));

            if ($stat === '') {
                continue;
            }

            $totals[$stat] = ($totals[$stat] ?? 0) + intval($definition['amount'] ?? 0);
        }

        return $totals;
    }
}
