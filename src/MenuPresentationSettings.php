<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Closure;
use Ichiloto\Engine\Battle\Presentation\BattlerConditions;
use Ichiloto\Engine\Field\MapCategoryCatalog;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\UI\Presentation\MapAtlasPresentation;
use Ichiloto\Engine\UI\Presentation\MapAtlasTheme;
use Ichiloto\Engine\UI\Presentation\MenuIconRegistry;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;

/**
 * One source owner for the game's menu presentation catalog, the shared theme every menu and HUD draws with
 * (Engine MenuPresentationCatalog). Its settings are edited by path like config.php's. It offers the minimap atlas
 * and the icon bindings: every role the catalogue binds, every atlas marker role the Engine draws (the player,
 * each landmark kind, each place category, the project's own included), and every state badge role (each project
 * state's own and its pending badge's), each an image or none. The palette, frames
 * and other groups join it as the Engine exposes their rules. Defaults and the values the Engine accepts are the
 * Engine's own, read from its theme types, never restated here.
 */
final class MenuPresentationSettings extends ProjectSettingsFile
{
    /** Where the catalog lives in a project: under its assets, where the Engine reads it. */
    public const string RELATIVE_PATH = 'assets/' . MenuPresentationCatalog::FILE;
    /** The minimap atlas's sizes and frame. */
    public const string ATLAS = 'atlas';
    /** Icon bindings by semantic role, whose keys hold dots of their own (map.category.bsa). */
    public const string ICONS = 'icons';

    /** What an atlas marker the theme leaves unbound shows. */
    private const string UNBOUND_MARKER = '(Engine pictogram)';
    /** What a state badge the theme leaves unbound shows ({@see BattlerConditions::listStateIconRoles()}). */
    private const string UNBOUND_STATE = '(state icon or label)';

    /** @var (Closure(): MapCategoryCatalog)|null The project's place categories, unsaved edits included. */
    private ?Closure $categories = null;
    /** @var (Closure(): list<string>)|null The project's state ids, unsaved edits included. */
    private ?Closure $stateIds = null;

    public function __construct(string $projectRoot)
    {
        parent::__construct($projectRoot, self::RELATIVE_PATH);
    }

    /**
     * Reads the project's place categories as its Place Categories page holds them, so their marker roles are offered
     * before they are saved. Without it the saved catalogue is read.
     *
     * @param Closure(): MapCategoryCatalog $categories
     */
    public function useCategoryCatalog(Closure $categories): void
    {
        $this->categories = $categories;
    }

    /**
     * Reads the project's state ids as its States page holds them, so their badge roles are offered before they are
     * saved. The workspace supplies them; the theme file itself names no states.
     *
     * @param Closure(): list<string> $stateIds
     */
    public function useStateIds(Closure $stateIds): void
    {
        $this->stateIds = $stateIds;
    }

    /**
     * Every atlas setting with the default the Engine's MapAtlasTheme reads when the catalog leaves it out, every
     * atlas marker role, which shows the Engine's own pictogram until the theme binds an image, and every state
     * badge role the Engine draws, which shows the state's icon or label until the theme binds one. Each icon role
     * carries what it shows while unbound.
     */
    protected function listEngineSettings(): array
    {
        $settings = [];
        foreach (get_object_vars(new MapAtlasTheme()) as $key => $default) {
            $settings[self::ATLAS . '.' . $key] = ['default' => $default];
        }
        foreach ($this->listMarkerRoles() as $role) {
            $settings[self::ICONS . '.' . $role] = ['default' => null, 'unbound' => self::UNBOUND_MARKER];
        }
        foreach (BattlerConditions::listStateIconRoles($this->stateIds === null ? [] : ($this->stateIds)()) as $role) {
            $settings[self::ICONS . '.' . $role] = ['default' => null, 'unbound' => self::UNBOUND_STATE];
        }

        return $settings;
    }

    /** An icon binding's path is its group and its whole role, dots included. */
    protected function splitPath(string $path): array
    {
        return str_starts_with($path, self::ICONS . '.') ? [self::ICONS, substr($path, strlen(self::ICONS) + 1)] : explode('.', $path);
    }

    /** An icon bound to no image is no binding: its key goes, and the Engine's own treatment shows. */
    protected function isRemovedWhenEmpty(string $path): bool
    {
        return str_starts_with($path, self::ICONS . '.');
    }

    /** Whether a setting is an icon binding. */
    public static function isIcon(string $path): bool
    {
        return str_starts_with($path, self::ICONS . '.');
    }

    /**
     * The semantic icon roles the theme offers, as this file holds them, unsaved edits included: every role it
     * binds, every atlas marker role the Engine draws and every state badge role. A world power or Super Move
     * technique names one of these.
     *
     * @return list<string>
     */
    public function listIconRoles(): array
    {
        $roles = [];
        foreach ($this->getSettingRecords([self::ICONS]) as $record) {
            $path = strval($record->get('path'));
            if (self::isIcon($path)) {
                $roles[] = substr($path, strlen(self::ICONS) + 1);
            }
        }

        return array_values(array_unique($roles));
    }

    /** Whether a setting binds an atlas marker the Engine draws with a pictogram of its own when unbound. */
    public function isMarkerIcon(string $path): bool
    {
        return self::isIcon($path) && in_array(substr($path, strlen(self::ICONS) + 1), $this->listMarkerRoles(), true);
    }

    /** @return list<string> The atlas marker roles, as the Engine names them for the project's place categories. */
    private function listMarkerRoles(): array
    {
        try {
            $categories = $this->categories !== null ? ($this->categories)() : MapCategoryCatalog::load($this->projectRoot . '/assets');
        } catch (\InvalidArgumentException) {
            $categories = new MapCategoryCatalog();
        }
        return MapAtlasPresentation::listIconRoles($categories);
    }

    /**
     * Refuses an atlas value the Engine would not accept beside the atlas settings as they stand: its theme type
     * checks each value and how they relate (the larger corner at least as large as the compact one).
     *
     * @throws \InvalidArgumentException
     */
    public function assertAcceptableValue(string $path, mixed $value, bool $complete = true): void
    {
        if (self::isIcon($path)) {
            if ($value === null) { return; }
            // The registry checks the role and path as the Engine binds them; the image must be one it can draw.
            try {
                new MenuIconRegistry($this->projectRoot . '/assets', [substr($path, strlen(self::ICONS) + 1) => $value]);
                PngAssetPreflight::inspect($this->projectRoot . '/assets', (string) $value);
            } catch (\Throwable $error) {
                throw new \InvalidArgumentException(sprintf('%s cannot be this icon: %s', (string) $value, $error->getMessage()), previous: $error);
            }
            return;
        }
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
