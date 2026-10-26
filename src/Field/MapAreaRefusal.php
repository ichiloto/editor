<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use RuntimeException;

/**
 * A named map area edit the map cannot take, with the reason an author reads.
 * Nothing was changed.
 *
 * @package Ichiloto\Editor\Field
 */
final class MapAreaRefusal extends RuntimeException
{
}
