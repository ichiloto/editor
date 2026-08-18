<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\ProjectWorkspace;
use RuntimeException;

/**
 * What an inventory reference means, for every tool that reads one.
 *
 * The engine's identity contract has three parts and only the first is
 * identity: a stable definition id, the current display name, and declared
 * compatibility aliases. `ItemStore` resolves any of the three to the id,
 * case-insensitively, and fails closed on anything it cannot resolve. Every
 * editor and console surface that names an item -- a picker, a validator, a
 * preview, a save diagnostic, a shop's stock, a chest's loot, a quest reward
 * -- reads this one catalogue so they all agree about what a reference is.
 *
 * The rules are the engine's, mirrored rather than reimplemented:
 * `InventoryItem` normalises an id with `strtolower(trim())` and requires
 * `^[a-z0-9][a-z0-9._-]*$`, defaulting an absent id to `legacy.<slug>`;
 * `ItemStore` normalises a reference the same way and refuses to let two
 * definitions answer to one reference. The one difference is deliberate: the
 * store throws on a conflicting catalogue, while an editor has to open a
 * broken project and say what is wrong with it, so a conflict is recorded
 * here as a conflict and reported by the validator.
 *
 * @package Ichiloto\Editor\Database
 */
final class InventoryCatalog
{
    /**
     * The record categories whose entries are inventory definitions.
     *
     * The engine reads one `assets/Data/items.php` catalogue; the editor
     * presents it as consumables, weapons and armors, so identity spans all
     * three rather than living in any one of them.
     */
    public const array CATEGORIES = ['items', 'weapons', 'armors'];

    /**
     * @param array<string, array{id: string, name: string, category: string, aliases: string[]}> $definitions By stable id.
     * @param array<string, string> $references Normalised reference => stable id.
     * @param array<string, string[]> $conflicts Normalised reference => the ids that claim it.
     */
    private function __construct(
        private readonly array $definitions,
        private readonly array $references,
        private readonly array $conflicts,
    ) {
    }

    /**
     * Reads a project's inventory catalogue.
     *
     * @param ProjectWorkspace $workspace The project.
     * @return self The catalogue.
     */
    public static function fromWorkspace(ProjectWorkspace $workspace): self
    {
        $definitions = [];
        $references = [];
        $conflicts = [];
        $contested = [];

        foreach (self::CATEGORIES as $category) {
            $database = $workspace->getRecordDatabase($category);

            if (! $database instanceof ProjectRecordDatabase) {
                continue;
            }

            foreach ($database->getRecords() as $record) {
                $name = trim(strval($record->get('name') ?? ''));
                $id = self::definitionId($record->get('id'), $name);

                if ($id === null) {
                    continue;
                }

                if (isset($definitions[$id])) {
                    // Two definitions under one id. The runtime's store
                    // refuses the whole catalogue over this, so nothing
                    // belonging to either claimant may resolve until it is
                    // fixed: not the id, and not the names and aliases each
                    // of them brought with it. Both are recorded so the
                    // validator can name every claimant.
                    $conflicts[$id] = array_values(array_unique([
                        ...($conflicts[$id] ?? [$definitions[$id]['name']]),
                        $name,
                    ]));
                    $contested[$id] = [
                        ...($contested[$id] ?? [$definitions[$id]]),
                        ['id' => $id, 'name' => $name, 'category' => $category, 'aliases' => self::declaredAliases($record)],
                    ];
                    continue;
                }

                $aliases = self::declaredAliases($record);

                $definitions[$id] = [
                    'id' => $id,
                    'name' => $name,
                    'category' => $category,
                    'aliases' => $aliases,
                ];
            }
        }

        // The id itself always resolves; then the display name and declared
        // aliases, either of which may collide with another definition.
        foreach ($definitions as $id => $definition) {
            $references[self::normalize($id)] = $id;
        }

        foreach ($definitions as $id => $definition) {
            foreach ([$definition['name'], ...$definition['aliases']] as $reference) {
                $normalized = self::normalize($reference);

                if ($normalized === '') {
                    continue;
                }

                $existing = $references[$normalized] ?? null;

                if ($existing === $id) {
                    continue;
                }

                if ($existing !== null) {
                    $conflicts[$normalized] = array_values(array_unique([
                        ...($conflicts[$normalized] ?? [$existing]),
                        $id,
                    ]));
                    continue;
                }

                $references[$normalized] = $id;
            }
        }

        // Everything a contested definition brought with it is contested
        // too. The first one retained kept its display name and aliases
        // resolving, which let a reference quietly mean one of two
        // definitions the runtime will not load at all.
        foreach ($contested as $claimants) {
            foreach ($claimants as $claimant) {
                foreach ([$claimant['name'], ...$claimant['aliases']] as $reference) {
                    $normalized = self::normalize($reference);

                    if ($normalized === '') {
                        continue;
                    }

                    $conflicts[$normalized] = array_values(array_unique([
                        ...($conflicts[$normalized] ?? []),
                        ...array_map(static fn(array $each): string => $each['name'], $claimants),
                    ]));
                }
            }
        }

        // A reference two definitions claim resolves to nothing at all: an
        // editor that guessed would author the wrong item.
        foreach (array_keys($conflicts) as $normalized) {
            unset($references[$normalized]);
        }

        return new self($definitions, $references, $conflicts);
    }

    /**
     * Returns the aliases a definition declares.
     *
     * @param ProjectRecord $record The definition.
     * @return string[] The aliases.
     */
    private static function declaredAliases(ProjectRecord $record): array
    {
        return array_values(array_filter(
            array_map(
                static fn(mixed $alias): string => trim(strval(is_scalar($alias) ? $alias : '')),
                is_array($record->get('aliases')) ? (array) $record->get('aliases') : [],
            ),
            static fn(string $alias): bool => $alias !== '',
        ));
    }

    /**
     * Returns the stable id a reference names, or null when the project has
     * no definition for it or more than one claims it.
     *
     * @param string $reference An id, a display name, or a declared alias.
     * @return string|null The stable id.
     */
    public function definitionIdFor(string $reference): ?string
    {
        return $this->references[self::normalize($reference)] ?? null;
    }

    /**
     * Returns the stable id a reference names, or throws with the context
     * that asked -- the engine's fail-closed boundary, in the editor.
     *
     * @param string $reference An id, a display name, or a declared alias.
     * @param string $context What was being done, for the diagnostic.
     * @return string The stable id.
     */
    public function requireDefinitionId(string $reference, string $context): string
    {
        $id = $this->definitionIdFor($reference);

        if ($id !== null) {
            return $id;
        }

        throw new RuntimeException(sprintf(
            $this->isAmbiguous($reference)
                ? 'Ambiguous inventory reference "%s" while %s'
                : 'Inventory reference "%s" while %s',
            trim($reference),
            $context,
        ));
    }

    /**
     * Determines whether a reference names more than one definition.
     *
     * @param string $reference The reference.
     * @return bool True when it is ambiguous.
     */
    public function isAmbiguous(string $reference): bool
    {
        return isset($this->conflicts[self::normalize($reference)]);
    }

    /**
     * Returns the current display name of whatever a reference names.
     *
     * @param string $reference The reference.
     * @return string|null The display name, or null when unresolved.
     */
    public function displayNameFor(string $reference): ?string
    {
        $id = $this->definitionIdFor($reference);

        return $id === null ? null : $this->definitions[$id]['name'];
    }

    /**
     * Determines whether a reference resolves.
     *
     * @param string $reference The reference.
     * @return bool True when it does.
     */
    public function has(string $reference): bool
    {
        return $this->definitionIdFor($reference) !== null;
    }

    /**
     * Returns every stable definition id, in catalogue order.
     *
     * @return string[] The ids.
     */
    public function ids(): array
    {
        return array_keys($this->definitions);
    }

    /**
     * Returns every stable id of one record category.
     *
     * @param string ...$categories The categories.
     * @return string[] The ids.
     */
    public function idsIn(string ...$categories): array
    {
        return array_values(array_map(
            static fn(array $definition): string => $definition['id'],
            array_filter(
                $this->definitions,
                static fn(array $definition): bool => in_array($definition['category'], $categories, true),
            ),
        ));
    }

    /**
     * Returns every definition, keyed by stable id.
     *
     * @return array<string, array{id: string, name: string, category: string, aliases: string[]}> The definitions.
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * Returns the references more than one definition claims, each with the
     * ids that claim it, for diagnostics.
     *
     * @return array<string, string[]> The conflicts.
     */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * Describes a definition the way a diagnostic or a picker should show it:
     * the display name with the identity that will be stored.
     *
     * @param string $reference The reference.
     * @return string The description.
     */
    public function describe(string $reference): string
    {
        $id = $this->definitionIdFor($reference);

        if ($id === null) {
            return trim($reference);
        }

        $name = $this->definitions[$id]['name'];

        return $name === '' || self::normalize($name) === self::normalize($id)
            ? $id
            : sprintf('%s (%s)', $name, $id);
    }

    /**
     * Returns the stable id of a definition as the engine derives it: the
     * authored id when there is one, otherwise the legacy slug of the display
     * name. Returns null for an entry that has neither.
     *
     * @param mixed $authoredId The authored id, if any.
     * @param string $name The display name.
     * @return string|null The stable id.
     */
    public static function definitionId(mixed $authoredId, string $name): ?string
    {
        $id = is_scalar($authoredId) ? self::normalize(strval($authoredId)) : '';

        if ($id !== '') {
            return preg_match('/^[a-z0-9][a-z0-9._-]*$/', $id) === 1 ? $id : null;
        }

        if (trim($name) === '') {
            return null;
        }

        return 'legacy.' . self::slug($name);
    }

    /**
     * Determines whether an authored id is one the engine will accept.
     *
     * @param string $id The id.
     * @return bool True when it is well formed.
     */
    public static function isWellFormedId(string $id): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]*$/', self::normalize($id)) === 1;
    }

    /**
     * Normalises a reference the way the engine's store does.
     *
     * @param string $reference The reference.
     * @return string The normalised reference.
     */
    public static function normalize(string $reference): string
    {
        return strtolower(trim($reference));
    }

    /**
     * Returns the slug the engine builds a legacy id from.
     *
     * @param string $name The display name.
     * @return string The slug.
     */
    private static function slug(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($name))), '-');

        return $slug !== '' ? $slug : hash('sha256', $name);
    }
}
