<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Source;

use RuntimeException;

/**
 * A change this editor will not write, because writing it would mean
 * rewriting authored source it cannot express the change inside of -- an
 * expression, a computed key, a shape it did not read.
 *
 * The message names the value's path so an author can make that part of the
 * file plain data, or make the change by hand.
 *
 * @package Ichiloto\Editor\Cutscenes\Source
 */
final class SourcePreservationRefusal extends RuntimeException
{
}
