<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Events;

/**
 * Where the values of a script command reference come from, which decides
 * how the Editor picks and checks it.
 *
 * @package Ichiloto\Editor\Events
 */
enum ScriptReferenceScope: string
{
    /** A project resource, the same wherever the script runs. */
    case PROJECT = 'project';
    /** A member of the troop whose battle event page holds the command. */
    case OWNING_TROOP = 'owning_troop';
    /** An NPC on the map the script is on when the command runs. */
    case CURRENT_MAP = 'current_map';
    /** Something the cinematic running the command declares. */
    case CURRENT_CINEMATIC = 'current_cinematic';
    /** An Engine reference kind this Editor has no picker for: kept as typed text and reported. */
    case UNSUPPORTED = 'unsupported';
}
