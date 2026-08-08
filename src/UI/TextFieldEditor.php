<?php

declare(strict_types=1);

namespace Ichiloto\Editor\UI;

use Ichiloto\Editor\Inspector\InputControl;
use Ichiloto\Editor\Inspector\InputControlType;

/**
 * The one inline edit buffer shared by the inspector and the Database
 * settings pane: value, caret, and the key grammar (caret movement,
 * backspace/delete, integer stepping, typed-symbol insertion).
 *
 * Callers own everything context-specific — which field is being edited,
 * how a commit is applied, and which pane repaints on change.
 */
final class TextFieldEditor
{
    /**
     * Whether an edit is currently in progress.
     */
    public bool $isActive = false;
    /**
     * The current edit buffer.
     */
    public string $value = '';
    /**
     * The caret position within the buffer, in grapheme offsets.
     */
    public int $caret = 0;

    /**
     * Begins an edit session over the given value with the caret at the end.
     *
     * @param string $value The initial buffer value.
     * @return void
     */
    public function open(string $value): void
    {
        $this->isActive = true;
        $this->value = $value;
        $this->caret = mb_strlen($value);
    }

    /**
     * Ends the edit session and clears the buffer.
     *
     * @return void
     */
    public function close(): void
    {
        $this->isActive = false;
        $this->value = '';
        $this->caret = 0;
    }

    /**
     * Applies one decoded input token to the edit buffer.
     *
     * @param string $input The decoded input token.
     * @param InputControl|null $control The control describing the field being edited.
     * @return TextFieldKeyResult
     */
    public function handleKey(string $input, ?InputControl $control): TextFieldKeyResult
    {
        if ($input === "\033") {
            return TextFieldKeyResult::CANCELLED;
        }

        if ($input === "\n" || $input === "\r") {
            return TextFieldKeyResult::SUBMITTED;
        }

        if (
            $control instanceof InputControl
            && in_array($control->type, [InputControlType::INTEGER, InputControlType::FLOAT], true)
        ) {
            if (str_contains($input, "\033[A")) {
                $this->value = $control->adjust($this->value, 1);
                $this->caret = mb_strlen($this->value);
                return TextFieldKeyResult::CHANGED;
            }

            if (str_contains($input, "\033[B")) {
                $this->value = $control->adjust($this->value, -1);
                $this->caret = mb_strlen($this->value);
                return TextFieldKeyResult::CHANGED;
            }
        }

        if (str_contains($input, "\033[D")) {
            $this->caret = max(0, $this->caret - 1);
            return TextFieldKeyResult::CHANGED;
        }

        if (str_contains($input, "\033[C")) {
            $this->caret = min(mb_strlen($this->value), $this->caret + 1);
            return TextFieldKeyResult::CHANGED;
        }

        if ($input === "\177" || $input === "\010") {
            if ($this->caret > 0) {
                $left = mb_substr($this->value, 0, $this->caret - 1);
                $right = mb_substr($this->value, $this->caret);
                $this->value = $left . $right;
                $this->caret--;
            }

            return TextFieldKeyResult::CHANGED;
        }

        if (str_contains($input, "\033[3~")) {
            if ($this->caret < mb_strlen($this->value)) {
                $left = mb_substr($this->value, 0, $this->caret);
                $right = mb_substr($this->value, $this->caret + 1);
                $this->value = $left . $right;
            }

            return TextFieldKeyResult::CHANGED;
        }

        if (str_contains($input, "\033")) {
            return TextFieldKeyResult::IGNORED;
        }

        if (preg_match('/^\X/u', $input, $matches) !== 1) {
            return TextFieldKeyResult::IGNORED;
        }

        $symbol = $matches[0];

        if (! $control instanceof InputControl || ! $control->acceptsTypedInput() || ! $control->acceptsSymbol($symbol, $this->value)) {
            return TextFieldKeyResult::IGNORED;
        }

        $left = mb_substr($this->value, 0, $this->caret);
        $right = mb_substr($this->value, $this->caret);
        $this->value = $left . $symbol . $right;
        $this->caret += mb_strlen($symbol);

        return TextFieldKeyResult::CHANGED;
    }
}
