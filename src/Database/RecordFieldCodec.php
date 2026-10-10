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

    /** A list of whole numbers, shown comma-separated: a tileset's tile identities. */
    case CSV_INTEGERS = 'csv_integers';

    /**
     * A list shown comma-separated whose whole numbers are stored as numbers
     * and anything else as text: a tileset's shadow casters, sheet names and
     * tile identities alike.
     */
    case CSV_TOKENS = 'csv_tokens';

    /**
     * A connected piece's tile on one layer: one tile entry for every shape
     * (`5888`), or one per shape, shown as `horizontal: 5888, vertical: 5890,
     * corner: 5892`.
     */
    case SHAPE_TILES = 'shape_tiles';

    /** An element => multiplier map, edited a row at a time. */
    case AFFINITIES = 'affinities';

    /** A list of world-state writes, in the engine's `sets` vocabulary. */
    case WORLD_WRITES = 'world_writes';

    /**
     * A list of battle-entry actor predicates, shown as `actor:presence; …`
     * and built in a dedicated editor with the actor picker.
     */
    case ACTOR_PREDICATES = 'actor_predicates';

    /**
     * A map of project-owned parameters, shown as `name=value` pairs. What
     * the line cannot carry is preserved rather than shown.
     */
    case KEY_VALUES = 'key_values';

    /**
     * A list of strings that are the rows of one block -- a staged actor's
     * sprite -- stored as the list, shown and edited as lines.
     */
    case LINES = 'lines';

    /**
     * Two whole numbers stored as `[first, second]`, shown as `first, second`:
     * a staged position's `[x, y]`, or a condition's `[minimum, maximum]`.
     */
    case POINT = 'point';

    /**
     * A point within a whole, stored as `['x' => x, 'y' => y]` with each
     * from 0 to 1, shown as `x, y`: an image's pivot. Both or neither.
     */
    case NORMALIZED_POINT = 'normalized_point';

    /**
     * A point in authored units, stored as `['x' => x, 'y' => y]`, shown as
     * `x, y`: where a cinematic stage subject stands, a camera key's focus,
     * an image's offset on the stage. Whole numbers stay integers; both or
     * neither.
     */
    case COORDINATES = 'coordinates';

    /**
     * A size in authored units, stored as `['width' => w, 'height' => h]`,
     * shown as `width, height`: a cinematic stage's canvas, a subject's
     * registered box. Whole numbers stay integers; both or neither.
     */
    case SIZE = 'size';

    /** One optional pixel crop, stored atomically as integer x, y, width and height. */
    case SOURCE_RECT = 'source_rect';

    /**
     * A battler slot, the Engine's BattlerSlot, stored as `['x' => x,
     * 'y' => y, 'width' => w, 'height' => h]` with an optional
     * `'displayScale' => s`, shown as `x, y, width, height` or
     * `x, y, width, height, s`: a battler's graphical placement. Whole or
     * not at all, so one edit moves it as one step, and its display scale
     * is kept.
     */
    case BATTLER_SLOT = 'battler_slot';

    /**
     * A project's battle test, the Engine's ProjectBattleTest: the troop to
     * preselect, the party (each member's actor, level, equipment and
     * loadout) and the arena. Stored as the Engine writes it and shown as
     * one line of JSON; a value the Engine would not read is refused.
     */
    case BATTLE_TEST = 'battle_test';
}
