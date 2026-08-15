<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * How a field's stored value is projected onto one editable line.
 *
 * Most fields are stored exactly as shown. The exceptions are list-shaped
 * values that would otherwise need their own sub-editor, and which the
 * Quests category already proved read well as a single encoded row.
 */
enum RecordFieldCodec: string
{
    /** Stored and shown identically. */
    case NONE = 'none';

    /** A list of engine world-conditions, shown as `type:name:extras; …`. */
    case CONDITIONS = 'conditions';

    /** A list of plain strings, shown comma-separated. */
    case CSV_LIST = 'csv_list';

    /** An element => multiplier map, edited a row at a time. */
    case AFFINITIES = 'affinities';

    /** A list of world-state writes, in the engine's `sets` vocabulary. */
    case WORLD_WRITES = 'world_writes';
}
