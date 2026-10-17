<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Engine\UI\Presentation\MapAtlasTheme;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;

/**
 * One source owner for the game's menu presentation catalog, the shared theme every menu and HUD draws with
 * (Engine MenuPresentationCatalog). Its settings are edited by path like config.php's. The minimap atlas is the
 * first group it offers; the palette, frames and other groups join it as the Engine exposes their rules. Defaults
 * and the values the Engine accepts are the Engine's own, read from its theme types, never restated here.
 */
final class MenuPresentationSettings extends ProjectSettingsFile
{
    /** Where the catalog lives in a project: under its assets, where the Engine reads it. */
    public const string RELATIVE_PATH = 'assets/' . MenuPresentationCatalog::FILE;
    /** The minimap atlas's sizes and frame. */
    public const string ATLAS = 'atlas';

    public function __construct(string $projectRoot)
    {
        parent::__construct($projectRoot, self::RELATIVE_PATH);
    }

    /** Every atlas setting with the default the Engine's MapAtlasTheme reads when the catalog leaves it out. */
    protected function listEngineSettings(): array
    {
        $settings = [];
        foreach (get_object_vars(new MapAtlasTheme()) as $key => $default) {
            $settings[self::ATLAS . '.' . $key] = ['default' => $default];
        }

        return $settings;
    }

    /**
     * Refuses an atlas value the Engine would not accept beside the atlas settings as they stand: its theme type
     * checks each value and how they relate (the larger corner at least as large as the compact one).
     *
     * @throws \InvalidArgumentException
     */
    public function assertAcceptableValue(string $path, mixed $value, bool $complete = true): void
    {
        if (! str_starts_with($path, self::ATLAS . '.')) { return; }
        $atlas = [];
        foreach (array_keys(get_object_vars(new MapAtlasTheme())) as $key) {
            $current = $this->getCurrentValue(self::ATLAS . '.' . $key);
            if ($current !== null) { $atlas[$key] = $current; }
        }
        $key = substr($path, strlen(self::ATLAS) + 1);
        if ($value === null) { unset($atlas[$key]); } else { $atlas[$key] = $value; }
        MapAtlasTheme::fromArray($atlas);
    }
}
