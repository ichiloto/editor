<?php

declare(strict_types=1);

namespace Ichiloto\Editor\UI;

/**
 * Every modal surface the editor can open.
 *
 * Declaration order IS dispatch priority: when several modals are open at
 * once, input routes to the earliest-declared open modal, and overlays render
 * accordingly. This replaces the two hand-ordered boolean `if` cascades that
 * previously had to be kept in sync manually — there is now exactly one
 * ordering, defined here.
 */
enum Modal: string
{
    /**
     * The Ctrl+E status detail overlay. Sits above every other surface.
     */
    case STATUS_DETAIL = 'status_detail';
    /**
     * The unsaved-changes confirmation raised by Ctrl+Q / Ctrl+R.
     */
    case UNSAVED_CHANGES_GUARD = 'unsaved_changes_guard';
    /**
     * The database entry deletion confirmation. Sits in the safety group so
     * it consumes everything while open — a destructive prompt must never
     * compete with the Database screen beneath it for keystrokes.
     */
    case DATABASE_ENTRY_DELETE_CONFIRMATION = 'database_entry_delete_confirmation';
    /**
     * The folder-move confirmation raised by a renaming save.
     */
    case RENAME_CONFIRMATION = 'rename_confirmation';
    /**
     * The Ctrl+P command palette. Opens above the Database screen and the
     * main shell, below the safety modals.
     */
    case COMMAND_PALETTE = 'command_palette';
    /**
     * The `?` help overlay generated from the input binding tables.
     */
    case HELP = 'help';
    /**
     * The Database screen. Consumes all input while open (below the safety
     * modals and the global Ctrl+E shortcut).
     */
    case DATABASE = 'database';
    /**
     * The spawn-point confirmation at the end of the destination round trip.
     */
    case DESTINATION_SPAWN_CONFIRMATION = 'destination_spawn_confirmation';
    /**
     * The in-context spawn-point picker on the destination map.
     */
    case DESTINATION_SPAWN_SELECTION = 'destination_spawn_selection';
    /**
     * The destination-map picker dialog.
     */
    case DESTINATION_DIALOG = 'destination_dialog';
    /**
     * The loot picker dialog.
     */
    case LOOT_DIALOG = 'loot_dialog';
    /**
     * The generic event-option picker dialog.
     */
    case EVENT_OPTION_DIALOG = 'event_option_dialog';
    /**
     * The event-type picker dialog.
     */
    case EVENT_TYPE_DIALOG = 'event_type_dialog';
    /**
     * The character-map (glyph palette) picker. Mouse input still routes to
     * the shared mouse handler while this is open.
     */
    case CHARACTER_MAP = 'character_map';
    /**
     * The map delete confirmation. Mouse input still routes while open.
     */
    case DELETE_CONFIRMATION = 'delete_confirmation';

    /**
     * Returns the dispatch priority of this modal (lower routes first).
     *
     * @return int
     */
    public function priority(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
