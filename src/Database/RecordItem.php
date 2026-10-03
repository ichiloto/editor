<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * The item a record row belongs to: something a list holds, which can be
 * added after or removed as a whole. A sub-list entry or a frame's command
 * is an entry; a route step, dialogue line or lane under one is nested; a
 * choice command's option is an option.
 *
 * Where the row's field id leads is {@see ProjectRecordDatabase::locateItem()}'s
 * to say: it owns the field-id grammar the rows were made with.
 *
 * @package Ichiloto\Editor\Database
 */
final readonly class RecordItem
{
    /** A sub-list entry, or a command of a frame. */
    public const string ENTRY = 'entry';

    /** An entry of the nested list an entry owns. */
    public const string NESTED = 'nested';

    /** An option of a choice command. */
    public const string OPTION = 'option';

    /**
     * @param string $kind One of {@see ENTRY}, {@see NESTED} or {@see OPTION}.
     * @param array<int, int|string> $framePath The frame whose list holds the entry.
     * @param int $entryIndex The entry, or the entry a nested item or option belongs to.
     * @param int|null $childIndex The nested item or option; null for an entry.
     * @param string $noun What the item is called, singular.
     * @param string|null $childKind What an entry holds beneath it, when it can hold more: {@see NESTED} for its nested list's entries, {@see OPTION} for a choice's options.
     * @param string|null $childNoun What those are called, singular.
     */
    public function __construct(
        public string $kind,
        public array $framePath,
        public int $entryIndex,
        public ?int $childIndex,
        public string $noun,
        public ?string $childKind = null,
        public ?string $childNoun = null,
    ) {
    }
}
