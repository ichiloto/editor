<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Engine\Animations\Field\FieldCueStyle;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Animations\Field\FieldWaymarkStyle;

/**
 * The settings of the game's field presentation catalog (Engine
 * FieldPresentationCatalog) that are edited by path: the cue treatment
 * (`cueStyle`), each cue colour's binding (its mark effect and the eight
 * pointer images an edge marker turns) and the objective waymark style.
 * Resources keep their own owner. Defaults and the values accepted are the
 * Engine's own, read from its types, never restated here.
 */
final class FieldPresentationSettings extends ProjectSettingsFile
{
    /** Where the catalog lives in a project: under its assets, where the Engine reads it. */
    public const string RELATIVE_PATH = 'assets/' . FieldPresentationCatalog::FILE;
    /** The objective waymark style. */
    public const string WAYMARKS = 'waymarks';
    /** How every cue mark stays readable. */
    public const string CUE_STYLE = 'cueStyle';
    /** Each cue colour's mark and pointer images. */
    public const string CUES = 'cues';

    public function __construct(string $projectRoot)
    {
        parent::__construct($projectRoot, self::RELATIVE_PATH);
    }

    /**
     * Every waymark and cue-style setting with the default the Engine reads
     * when the catalog leaves it out, and each bound colour's eight pointers,
     * offered even where the file has none yet.
     */
    protected function listEngineSettings(): array
    {
        $settings = [];
        foreach (get_object_vars(new FieldWaymarkStyle()) as $key => $default) {
            $settings[self::WAYMARKS . '.' . $key] = ['default' => $default];
        }
        $style = new FieldCueStyle();
        foreach (['plate' => $style->plate, 'bob.height' => $style->bobHeight, 'bob.seconds' => $style->bobSeconds,
            'pointerDistance' => $style->pointerDistance, 'terminal.background' => $style->terminalBackground,
            'terminal.bold' => $style->terminalBold] as $key => $default) {
            $settings[self::CUE_STYLE . '.' . $key] = ['default' => $default];
        }
        foreach (array_keys(is_array($this->payload[self::CUES] ?? null) ? $this->payload[self::CUES] : []) as $color) {
            foreach (FieldPresentationCatalog::DIRECTIONS as $direction) {
                $settings[self::CUES . ".{$color}.pointer.{$direction}.asset"] = ['default' => null];
                $settings[self::CUES . ".{$color}.pointer.{$direction}.quarterTurns"] = ['default' => 0];
            }
        }

        return $settings;
    }

    /** A plate, a background or a pointer chosen away is no key at all, so the Engine's own treatment applies. */
    protected function isRemovedWhenEmpty(string $path): bool
    {
        return in_array($path, [self::CUE_STYLE . '.plate'], true)
            || (str_starts_with($path, self::CUES . '.') && str_ends_with($path, '.asset'));
    }

    /**
     * Refuses a value the Engine would not accept beside the catalog as it
     * stands: the whole would-be catalog is read by the Engine's own reader,
     * images included.
     *
     * @throws \InvalidArgumentException
     */
    public function assertAcceptableValue(string $path, mixed $value, bool $complete = true): void
    {
        if (! in_array(explode('.', $path)[0], [self::WAYMARKS, self::CUE_STYLE, self::CUES], true)) { return; }
        $payload = $this->readPendingPayload([$path => $value]);
        foreach ($payload[self::CUES] ?? [] as $color => $binding) {
            // A pointer is authored a part at a time; one still without an image is not yet part of the catalog.
            foreach (is_array($binding['pointer'] ?? null) ? $binding['pointer'] : [] as $direction => $image) {
                if (! is_array($image) || ($image['asset'] ?? null) === null) { unset($payload[self::CUES][$color]['pointer'][$direction]); }
            }
            if (($payload[self::CUES][$color]['pointer'] ?? null) === []) { unset($payload[self::CUES][$color]['pointer']); }
        }
        FieldPresentationCatalog::fromArray($payload, $this->projectRoot . '/assets');
    }

    /**
     * Splits each cue colour's old baked edge images into pointer images: the
     * same directions and turns, each image replaced through `$assets` (old
     * edge image => its pointer-only image). The source keeps its comments
     * and every other entry. Returns whether anything changed.
     *
     * @param array<string, string> $assets
     */
    public function replaceEdgesWithPointers(array $assets): bool
    {
        return $this->rewritePayload(function (array $payload) use ($assets): array {
            foreach (is_array($payload[self::CUES] ?? null) ? $payload[self::CUES] : [] as $color => $binding) {
                if (! is_array($binding) || ! is_array($binding['edges'] ?? null)) { continue; }
                $pointer = [];
                foreach ($binding['edges'] as $direction => $image) {
                    $asset = (string) ($image['asset'] ?? '');
                    $pointer[$direction] = ['asset' => $assets[$asset] ?? throw new \InvalidArgumentException(
                        "No pointer image was given for {$asset}."), 'quarterTurns' => (int) ($image['quarterTurns'] ?? 0)];
                }
                unset($binding['edges']);
                $payload[self::CUES][$color] = [...$binding, 'pointer' => $pointer];
            }
            FieldPresentationCatalog::fromArray($payload, $this->projectRoot . '/assets');
            return $payload;
        });
    }
}
