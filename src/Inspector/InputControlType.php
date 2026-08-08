<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Inspector;

/**
 * Enumerates supported inspector input control types.
 */
enum InputControlType: string
{
    case TEXT = 'text';
    case INTEGER = 'integer';
    case FLOAT = 'float';
    case BOOLEAN = 'boolean';
    case FILE_PATH = 'file_path';
}
