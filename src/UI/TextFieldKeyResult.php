<?php

declare(strict_types=1);

namespace Ichiloto\Editor\UI;

/**
 * The outcome of feeding one input token to a TextFieldEditor.
 */
enum TextFieldKeyResult
{
    /**
     * The edit was cancelled (Esc); the caller should close and clean up.
     */
    case CANCELLED;
    /**
     * The edit was submitted (Enter); the caller should commit the value.
     */
    case SUBMITTED;
    /**
     * The buffer or caret changed; the caller should repaint its field.
     */
    case CHANGED;
    /**
     * The token was not applicable to the edit buffer.
     */
    case IGNORED;
}
