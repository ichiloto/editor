<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Util\Interfaces\ConfigInterface;

/**
 * The configuration a preview host hands the Engine for the duration of a run.
 *
 * A preview is deterministic on purpose: no autosave, no reduced-motion
 * shortcut (so authored seconds are the seconds observed), and a screen the
 * size of the pane the frame is drawn into. Nothing here is read from or
 * written to the author's own play settings.
 */
final class PreviewConfig implements ConfigInterface
{
    /**
     * @param array<string, mixed> $values The nested configuration values.
     */
    public function __construct(private array $values = [])
    {
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $value = $this->values;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $path, mixed $value): void
    {
        $target = &$this->values;

        foreach (explode('.', $path) as $segment) {
            if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }

        $target = $value;
    }

    public function has(string $path): bool
    {
        $sentinel = new \stdClass();

        return $this->get($path, $sentinel) !== $sentinel;
    }

    public function persist(): void
    {
        // A preview configuration is never written anywhere.
    }
}
