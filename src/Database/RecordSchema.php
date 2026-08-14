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
        public bool $isAlwaysReadOnly = false,
        public string $readOnlyNote = '',
    ) {
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
