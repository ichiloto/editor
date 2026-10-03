<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Session;

use RuntimeException;

/**
 * A change or read the session will not make, with the reason an author
 * reads: an unknown map, a stale revision, source that cannot hold the edit.
 */
final class SessionRefusal extends RuntimeException
{
}
