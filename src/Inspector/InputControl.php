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
            InputControlType::INTEGER,
            InputControlType::FLOAT => true,
            InputControlType::BOOLEAN,
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
            InputControlType::FLOAT => $this->acceptsFloatSymbol($symbol, $buffer),
            InputControlType::BOOLEAN,
            InputControlType::FILE_PATH => false,
        };
    }

    /**
     * Applies a step adjustment if supported by the control.
     *
     * Integers and floats step by the control's step size; booleans toggle
     * regardless of direction (the one consistent left/right idiom).
     *
     * @param string $buffer The current raw buffer.
     * @param int $delta The increment delta.
     * @return string
     */
    public function adjust(string $buffer, int $delta): string
    {
        if ($this->type === InputControlType::INTEGER) {
            $currentValue = (int) trim($buffer === '' ? '0' : $buffer);

            return (string) ($currentValue + ($delta * $this->step));
        }

        if ($this->type === InputControlType::FLOAT) {
            $currentValue = (float) trim($buffer === '' ? '0' : $buffer);

            return self::formatFloat($currentValue + ($delta * $this->step));
        }

        if ($this->type === InputControlType::BOOLEAN) {
            return self::parseBoolean($buffer) ? 'false' : 'true';
        }

        return $buffer;
    }

    /**
     * Parses a raw buffer into a boolean value.
     *
     * @param string $buffer The raw buffer.
     * @return bool
     */
    public static function parseBoolean(string $buffer): bool
    {
        return in_array(strtolower(trim($buffer)), ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Formats a float without noise digits (trailing zeros / bare points).
     *
     * @param float $value The value to format.
     * @return string
     */
    public static function formatFloat(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
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

    /**
     * Determines whether a float control accepts the given symbol.
     *
     * @param string $symbol The typed symbol.
     * @param string $buffer The current raw buffer.
     * @return bool
     */
    private function acceptsFloatSymbol(string $symbol, string $buffer): bool
    {
        if (preg_match('/^\d$/', $symbol) === 1) {
            return true;
        }

        if ($symbol === '-') {
            return $buffer === '';
        }

        return $symbol === '.'
            && ! str_contains($buffer, '.');
    }
}
