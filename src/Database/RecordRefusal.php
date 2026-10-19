<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use RuntimeException;

/**
 * A record change that cannot be made as asked, with the reason an author
 * reads: a read-only category, a record or frame that is gone, a value its
 * field cannot take, a move the file would not keep. Nothing was changed.
 *
 * @package Ichiloto\Editor\Database
 */
final class RecordRefusal extends RuntimeException
{
}
