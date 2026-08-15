<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * How a Database category's records are laid out on disk.
 */
enum RecordStorage: string
{
    /** One file returning a list of records (states.php, troops.php, items.php). */
    case LIST_FILE = 'list_file';

    /** One file per record under a directory (Data/Skits/*.php, Events/*.php). */
    case DIRECTORY = 'directory';

    /** A flattened subtree of the project's config.php (vocab, messages). */
    case CONFIG_SUBTREE = 'config_subtree';

    /**
     * A read-only inventory of the files in a directory, listed without being
     * evaluated. Used where the "data" is really PHP source (the project's
     * `Data/Types/*.php` enum declarations), which the editor must never
     * `require` — doing so would declare classes into the editor's own
     * process and can fatal on redeclaration.
     */
    case FILE_LISTING = 'file_listing';

    /**
     * Records that live inside another asset's data (a map's `npcs`), read
     * from and written back through that asset rather than a file of their
     * own. The owner persists them; this category only edits them.
     */
    case MAP_OWNED = 'map_owned';
}
