<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Events;

use RuntimeException;

/**
 * An event edit that cannot be made as asked: a marker already in use,
 * cells another event holds, a move off the map. Nothing was changed.
 */
final class EventRefusal extends RuntimeException
{
}
