<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Source;

use RuntimeException;

/**
 * An authored file this editor cannot read as a header, optional variable
 * assignments, and one returned array literal -- and therefore will not
 * rewrite.
 *
 * @package Ichiloto\Editor\Cutscenes\Source
 */
final class SourceUnreadable extends RuntimeException
{
}
