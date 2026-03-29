<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Inspector;

/**
 * Defines how an inspector field accepts and adjusts input.
 */
final class InputControl
{
    /**
     * @param string[] $acceptedExtensions
     */
    public function __construct(
        public readonly InputControlType $type,
        public readonly string $rawValue = '',
        public readonly int $step = 1,
        public readonly array $acceptedExtensions = [],
    ) {
    }

    /**
     * Returns whether the control accepts direct text entry.
     *
     * @return bool
     */
    public function acceptsTypedInput(): bool
    {
        return match ($this->type) {
            InputControlType::TEXT,
            InputControlType::INTEGER => true,
            InputControlType::FILE_PATH => false,
        };
    }

    /**
     * Returns whether the control accepts the given symbol.
     *
     * @param string $symbol The typed symbol.
     * @param string $buffer The current raw buffer.
     * @return bool
     */
    public function acceptsSymbol(string $symbol, string $buffer): bool
    {
        return match ($this->type) {
            InputControlType::TEXT => $symbol !== "\t",
            InputControlType::INTEGER => $this->acceptsIntegerSymbol($symbol, $buffer),
            InputControlType::FILE_PATH => false,
        };
    }

    /**
     * Applies a numeric increment if supported by the control.
     *
     * @param string $buffer The current raw buffer.
     * @param int $delta The increment delta.
     * @return string
     */
    public function adjust(string $buffer, int $delta): string
    {
        if ($this->type !== InputControlType::INTEGER) {
            return $buffer;
        }

        $currentValue = (int) trim($buffer === '' ? '0' : $buffer);

        return (string) ($currentValue + ($delta * $this->step));
    }

    /**
     * Determines whether an integer control accepts the given symbol.
     *
     * @param string $symbol The typed symbol.
     * @param string $buffer The current raw buffer.
     * @return bool
     */
    private function acceptsIntegerSymbol(string $symbol, string $buffer): bool
    {
        if (preg_match('/^\d$/', $symbol) === 1) {
            return true;
        }

        return $symbol === '-'
            && $buffer === '';
    }
}
