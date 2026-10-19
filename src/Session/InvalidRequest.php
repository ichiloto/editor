<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Session;

use InvalidArgumentException;

/** A protocol request that is malformed, unknown, or sent before the session opened. */
final class InvalidRequest extends InvalidArgumentException
{
}
