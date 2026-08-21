<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Closure;

/**
 * Declares one Database category: where its records live, what an entry is
 * called, and which fields the settings pane offers.
 *
 * Everything the editor needs to load, list, edit, create, delete, and save a
 * category is on this object — which is why ten categories cost ten schema
 * declarations instead of ten hand-wired code paths.
 */
final readonly class RecordSchema
{
    /**
     * @param string $key The Database category key.
     * @param string $entryNoun The singular noun used in status messages.
     * @param RecordStorage $storage The on-disk layout.
     * @param string $relativePath The project-relative file or directory.
     * @param RecordField[] $fields The record-level fields.
     * @param string $labelKey The payload key shown in the entry list.
     * @param string|null $identityKey The payload key holding the record id.
     * @param array<string, mixed> $blank A freshly created record's payload.
     * @param RecordSubList|null $subList The nested repeating list, when the record has one.
     * @param string|null $listPayloadKey When set, the file returns the sub-list bare (event scripts).
     * @param string[] $configPath The config.php subtree roots, for CONFIG_SUBTREE storage.
     * @param Closure(mixed): bool|null $recordFilter Selects which of a shared file's entries belong here.
     * @param bool $isAlwaysReadOnly Whether the category never writes, regardless of the file probe.
     * @param string $readOnlyNote An honest explanation shown when the category cannot be edited.
     */
    /**
     * @param RecordProjection|null $projection How this category's records are
     * read out of, and folded back into, a file that is not simply a list of
     * them -- one list inside a catalogue that holds several, or the nested
     * maps of an optimization policy. Whatever the file holds that this
     * category does not own is written back exactly as it was read.
     * @param Closure(array<string, mixed>): RecordField[]|null $fieldsFor The
     * fields one record offers, when they depend on what the record is. An
     * exclusion naming an item and an exclusion naming an availability are
     * both exclusions, but they are not picked from the same list.
     * @param Closure(array<string, mixed>): string|null $labelFor The entry
     * label, when a record's name is made of its parts rather than stored.
     */
    public function __construct(
        public string $key,
        public string $entryNoun,
        public RecordStorage $storage,
        public string $relativePath,
        public array $fields,
        public string $labelKey = 'name',
        public ?string $identityKey = 'id',
        public array $blank = [],
        public ?RecordSubList $subList = null,
        public ?string $listPayloadKey = null,
        public array $configPath = [],
        public ?Closure $recordFilter = null,
        public ?Closure $makeBlank = null,
        public array $commandLists = [],
        public bool $isAlwaysReadOnly = false,
        public string $readOnlyNote = '',
        public ?RecordProjection $projection = null,
        public ?Closure $fieldsFor = null,
        public ?Closure $labelFor = null,
    ) {
    }

    /**
     * Returns the fields one record offers.
     *
     * @param array<string, mixed>|object $payload The record payload.
     * @return RecordField[] The fields.
     */
    public function fieldsFor(array|object $payload): array
    {
        if ($this->fieldsFor === null) {
            return $this->fields;
        }

        /** @var RecordField[] $fields */
        $fields = ($this->fieldsFor)(is_array($payload) ? $payload : (array) $payload);

        return $fields;
    }

    /**
     * Returns the absolute path this category reads from.
     *
     * @param string $projectRoot The project root.
     * @return string
     */
    public function resolvePath(string $projectRoot): string
    {
        return rtrim($projectRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $this->relativePath);
    }
}
