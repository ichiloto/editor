<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use RuntimeException;

/**
 * A map edit or save the authored source cannot take reversibly.
 *
 * The message names the map, the file, the path and the shape that stands
 * in the way, and — where one exists — the safe author action, because a
 * refusal an author cannot act on is just an error. Nothing was changed:
 * the record, the source bytes, the history and the dirty state are exactly
 * what they were before the attempt.
 *
 * @package Ichiloto\Editor
 */
final class MapSourceRefusal extends RuntimeException
{
}
