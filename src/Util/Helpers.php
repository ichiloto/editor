<?php

if (! function_exists('clamp')) {
    function clamp(int|float $value, int|float $min, int|float $max): int|float
    {
        return max($min, min($value, $max));
    }
}