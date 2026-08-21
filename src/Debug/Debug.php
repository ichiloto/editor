<?php

namespace Ichiloto\Editor\Debug;

final class Debug
{
    private function __construct()
    {
    }

    public static function log(mixed $message, array $context = []): void
    {
        self::write("DEBUG", $message, $context);
    }

    public static function error(mixed $message, array $context = []): void
    {
        self::write("ERROR", $message, $context, "error.log");
        self::write("ERROR", $message, $context);
    }

    public static function info(mixed $message, array $context = []): void
    {
        self::write("INFO", $message, $context);
    }

    public static function warn(mixed $message, array $context = []): void
    {
        self::write("WARN", $message, $context);
    }

    private static function write(string $prefix, mixed $message, array $context = [], string $filename = "debug.log"): void
    {
        $logFilePath = self::getLogFilePath($filename);

        if (self::ensureLogDirectoryExists($logFilePath) === false) {
            return;
        }

        if (self::ensureLogFileExists($logFilePath) === false) {
            return;
        }

        $handle = @fopen($logFilePath, "ab");
        if ($handle === false) {
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }

            fwrite($handle, self::formatLine($prefix, $message, $context));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private static function formatLine(string $prefix, mixed $message, array $context): string
    {
        $contextSegment = $context === [] ? "" : " " . self::normalizeContext($context);

        return sprintf("[%s] [%s] %s%s%s", $prefix, date(DATE_ATOM), self::normalizeMessage($message), $contextSegment, PHP_EOL);
    }

    private static function normalizeMessage(mixed $message): string
    {
        if (is_string($message)) {
            return $message;
        }

        if ($message instanceof \Stringable) {
            return (string) $message;
        }

        if (is_scalar($message)) {
            return strval($message);
        }

        if ($message === null) {
            return strval($message);
        }

        $encoded = json_encode($message);

        return $encoded === false ? get_debug_type($message) : $encoded;
    }

    private static function normalizeContext(array $context): string
    {
        $encoded = json_encode($context);

        return $encoded === false ? "{}" : $encoded;
    }

    private static function getLogFilePath(string $filename): string
    {
        return getcwd() . DIRECTORY_SEPARATOR . "logs" . DIRECTORY_SEPARATOR . $filename;
    }

    private static function ensureLogDirectoryExists(string $logFilePath): bool
    {
        $logDirectory = dirname($logFilePath);

        if (is_dir($logDirectory)) {
            return true;
        }

        if (@mkdir($logDirectory, 0775, true)) {
            return true;
        }

        return is_dir($logDirectory);
    }

    private static function ensureLogFileExists(string $logFilePath): bool
    {
        if (is_file($logFilePath)) {
            return true;
        }

        return @touch($logFilePath);
    }
}
