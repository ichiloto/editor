<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * The part of a condition the Terminal's condition editor takes as typed
 * text: a name the author invents (a switch, story event or variable), or
 * the value a comparison is made with (a variable's value, a play
 * statistic's count). Names known to the project are picked, never typed.
 */
enum ConditionTypedPart
{
    case NAME;
    case VALUE;
}
