<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Animations\Field\FieldWaymarkStyle;

/**
 * The settings of the game's field presentation catalog (Engine
 * FieldPresentationCatalog) that are edited by path: the objective waymark
 * style. Its cue bindings and resources keep their own owners. Defaults and the
 * values accepted are the Engine's FieldWaymarkStyle, never restated here.
 */
final class FieldPresentationSettings extends ProjectSettingsFile
{
    /** Where the catalog lives in a project: under its assets, where the Engine reads it. */
    public const string RELATIVE_PATH = 'assets/' . FieldPresentationCatalog::FILE;
    /** The objective waymark style. */
    public const string WAYMARKS = 'waymarks';

    public function __construct(string $projectRoot)
    {
        parent::__construct($projectRoot, self::RELATIVE_PATH);
    }

    /** Every waymark setting with the default the Engine reads when the catalog leaves it out. */
    protected function listEngineSettings(): array
    {
        $settings = [];
        foreach (get_object_vars(new FieldWaymarkStyle()) as $key => $default) {
            $settings[self::WAYMARKS . '.' . $key] = ['default' => $default];
        }

        return $settings;
    }

    /**
     * Refuses a waymark value the Engine would not accept beside the waymark settings as they stand.
     *
     * @throws \InvalidArgumentException
     */
    public function assertAcceptableValue(string $path, mixed $value, bool $complete = true): void
    {
        if (! str_starts_with($path, self::WAYMARKS . '.')) { return; }
        $style = [];
        foreach (FieldWaymarkStyle::FIELDS as $key) {
            $current = $this->getCurrentValue(self::WAYMARKS . '.' . $key);
            if ($current !== null) { $style[$key] = $current; }
        }
        $key = substr($path, strlen(self::WAYMARKS) + 1);
        if ($value === null) { unset($style[$key]); } else { $style[$key] = $value; }
        FieldWaymarkStyle::fromArray($style);
    }
}
