<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use RuntimeException;

/**
 * A project-owned parameter line the editor will not guess at.
 *
 * Silently repairing malformed input is how an author loses a value without
 * being told. The line is rejected, the record is left exactly as it was,
 * and the reason is shown.
 *
 * @package Ichiloto\Editor\Database
 */
final class ParameterMapSyntaxError extends RuntimeException
{
}
