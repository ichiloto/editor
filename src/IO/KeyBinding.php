<?php

declare(strict_types=1);

namespace Ichiloto\Editor\IO;

use Closure;

/**
 * One entry in a binding table: a key matcher paired with an action.
 *
 * Matching semantics mirror the editor's historic dispatch exactly — exact
 * byte equality for control keys, substring containment for escape
 * sequences (tokens may carry modifier prefixes), and predicates for
 * anything richer.
 *
 * Bindings may carry a human-readable key label and description; the help
 * overlay is generated from these, so documented shortcuts can never drift
 * from the dispatch table.
 */
final class KeyBinding
{
    /**
     * @param Closure(string, string): bool $dispatcher Attempts the binding; returns whether the input was consumed.
     * @param string|null $keyLabel The human-readable key name (e.g. "Ctrl+S").
     * @param string|null $description What the binding does, for the help overlay.
     */
    private function __construct(
        private readonly Closure $dispatcher,
        public readonly ?string $keyLabel = null,
        public readonly ?string $description = null,
    ) {
    }

    /**
     * Binds an action to an exact input token.
     *
     * @param string $key The exact token (e.g. "\x11" for Ctrl+Q).
     * @param callable(): void $action The action to run.
     * @param string|null $keyLabel The human-readable key name.
     * @param string|null $description What the binding does.
     * @return self
     */
    public static function exact(string $key, callable $action, ?string $keyLabel = null, ?string $description = null): self
    {
        return new self(static function (string $input, string $normalized) use ($key, $action): bool {
            if ($input !== $key) {
                return false;
            }

            $action();

            return true;
        }, $keyLabel, $description);
    }

    /**
     * Binds an action to any token containing the given escape sequence.
     *
     * @param string $needle The escape sequence to look for (e.g. "\033[Z").
     * @param callable(): void $action The action to run.
     * @param string|null $keyLabel The human-readable key name.
     * @param string|null $description What the binding does.
     * @return self
     */
    public static function contains(string $needle, callable $action, ?string $keyLabel = null, ?string $description = null): self
    {
        return new self(static function (string $input, string $normalized) use ($needle, $action): bool {
            if (! str_contains($input, $needle)) {
                return false;
            }

            $action();

            return true;
        }, $keyLabel, $description);
    }

    /**
     * Binds an action to a custom match predicate.
     *
     * @param callable(string, string): bool $predicate Receives the raw and normalized input.
     * @param callable(): void $action The action to run on a match.
     * @param string|null $keyLabel The human-readable key name.
     * @param string|null $description What the binding does.
     * @return self
     */
    public static function when(callable $predicate, callable $action, ?string $keyLabel = null, ?string $description = null): self
    {
        return new self(static function (string $input, string $normalized) use ($predicate, $action): bool {
            if (! $predicate($input, $normalized)) {
                return false;
            }

            $action();

            return true;
        }, $keyLabel, $description);
    }

    /**
     * Binds a handler that decides for itself whether it consumed the input.
     *
     * @param callable(string): bool $attempt Returns whether the input was consumed.
     * @param string|null $keyLabel The human-readable key name.
     * @param string|null $description What the binding does.
     * @return self
     */
    public static function attempt(callable $attempt, ?string $keyLabel = null, ?string $description = null): self
    {
        return new self(
            static fn(string $input, string $normalized): bool => $attempt($input),
            $keyLabel,
            $description,
        );
    }

    /**
     * Returns the help-overlay descriptor for this binding, when labeled.
     *
     * @return array{key: string, description: string}|null
     */
    public function describe(): ?array
    {
        if ($this->keyLabel === null || $this->description === null) {
            return null;
        }

        return ['key' => $this->keyLabel, 'description' => $this->description];
    }

    /**
     * Attempts this binding against one input token.
     *
     * @param string $input The raw input token.
     * @param string $normalized The lowercased input token.
     * @return bool Whether the input was consumed.
     */
    public function dispatch(string $input, string $normalized): bool
    {
        return ($this->dispatcher)($input, $normalized);
    }
}
