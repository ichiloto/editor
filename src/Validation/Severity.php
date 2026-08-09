<?php

namespace Ichiloto\Editor\Validation;

/**
 * How much a validation issue matters.
 *
 * @package Ichiloto\Editor\Validation
 */
enum Severity: string
{
  /**
   * The game will crash, or the content will not work at all.
   */
  case ERROR = 'error';
  /**
   * The game runs, but something an author wrote is being ignored.
   */
  case WARNING = 'warning';
}
