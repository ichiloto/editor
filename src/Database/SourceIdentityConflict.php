<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use RuntimeException;

/**
 * A write refused because a record could not be addressed in its source
 * with certainty.
 *
 * A category over a file of constructor calls patches, removes and places
 * entries by the durable identity each entry declares -- an item's stable
 * id, a troop's name. When that identity is missing, or two entries claim
 * it, or a write would leave two entries claiming it, there is no address
 * to write to that could be proved right, and the only safe write is none.
 * The message names the identity and the file so the author can make the
 * file addressable again.
 *
 * @package Ichiloto\Editor\Database
 */
final class SourceIdentityConflict extends RuntimeException
{
}
