<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Theme;

use Atatusoft\Termutil\IO\Enumerations\Color;
use Atatusoft\Termutil\UI\Windows\BorderPack;
use Ichiloto\Editor\Database\PhpDataFile;
use Throwable;
use UnitEnum;

/**
 * The editor's look, taken from the project it has open.
 *
 * A project already declares how its windows and menus look — `ui.menu.border`
 * picks a border pack and `ui.menu.selection_color` picks the highlight the
 * player sees on the selected menu row. The editor reads the same two keys so
 * that authoring a game looks like playing it: the editor's window borders
 * match the game's, and the focused pane is outlined in the game's own
 * selection color.
 *
 * Projects that declare neither keep the editor's defaults.
 */
final readonly class EditorTheme
{
    /**
     * @param BorderPack $borderPack The border characters every editor window draws with.
     * @param Color $selectionColor The color marking the focused pane.
     * @param string $borderPackName A human-readable name for the `?` overlay.
     * @param bool $isFromProject Whether the project actually declared a theme.
     */
    public function __construct(
        public BorderPack $borderPack,
        public Color $selectionColor,
        public string $borderPackName = 'Default',
        public bool $isFromProject = false,
    ) {
    }

    /**
     * Returns the editor's built-in look.
     *
     * @return self
     */
    public static function default(): self
    {
        return new self(new BorderPack(), Color::LIGHT_BLUE);
    }

    /**
     * Reads the theme from a project's config.php.
     *
     * Never throws: a project with an unreadable or exotic config simply keeps
     * the default look.
     *
     * @param string $projectRoot The project root.
     * @return self
     */
    public static function fromProject(string $projectRoot): self
    {
        $path = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.php';

        try {
            $payload = PhpDataFile::load($path)->payload;
        } catch (Throwable) {
            return self::default();
        }

        if (! is_array($payload)) {
            return self::default();
        }

        $menu = $payload['ui']['menu'] ?? null;
        $menu = is_array($menu) ? $menu : [];
        $default = self::default();

        [$borderPack, $borderPackName] = self::resolveBorderPack($menu['border'] ?? null);
        $selectionColor = self::resolveColor($menu['selection_color'] ?? null);

        return new self(
            $borderPack ?? $default->borderPack,
            $selectionColor ?? $default->selectionColor,
            $borderPackName ?? $default->borderPackName,
            $borderPack !== null || $selectionColor !== null,
        );
    }

    /**
     * Describes the active theme for the help overlay.
     *
     * @return string
     */
    public function describe(): string
    {
        return $this->isFromProject
            ? sprintf('Theme: %s borders, %s selection (from config.php)', $this->borderPackName, $this->colorName())
            : sprintf('Theme: %s borders, %s selection (editor default)', $this->borderPackName, $this->colorName());
    }

    /**
     * Returns the selection color's case name in title case.
     *
     * @return string
     */
    public function colorName(): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $this->selectionColor->name)));
    }

    /**
     * Translates the engine's border pack into termutil's border characters.
     *
     * The engine exposes border packs as classes of static getters, and
     * termutil wants a value object, so the characters are copied across.
     *
     * @param mixed $declared The configured `ui.menu.border` value.
     * @return array{0: BorderPack|null, 1: string|null}
     */
    private static function resolveBorderPack(mixed $declared): array
    {
        $className = match (true) {
            is_object($declared) => $declared::class,
            is_string($declared) && $declared !== '' => $declared,
            default => null,
        };

        if ($className === null || ! class_exists($className)) {
            return [null, null];
        }

        if (! method_exists($className, 'getTopLeftCorner') || ! method_exists($className, 'getHorizontalBorder')) {
            return [null, null];
        }

        try {
            $pack = new BorderPack(
                topLeft: $className::getTopLeftCorner(),
                topRight: $className::getTopRightCorner(),
                bottomLeft: $className::getBottomLeftCorner(),
                bottomRight: $className::getBottomRightCorner(),
                horizontal: $className::getHorizontalBorder(),
                vertical: $className::getVerticalBorder(),
                topTee: $className::getTopHorizontalConnector(),
                bottomTee: $className::getBottomHorizontalConnector(),
                leftTee: $className::getLeftVerticalConnector(),
                rightTee: $className::getRightVerticalConnector(),
                cross: $className::getCenterConnector(),
            );
        } catch (Throwable) {
            return [null, null];
        }

        $position = strrpos($className, '\\');

        return [$pack, $position === false ? $className : substr($className, $position + 1)];
    }

    /**
     * Maps the engine's Color enum onto termutil's.
     *
     * The two enums back their cases with the same ANSI escape sequences, so
     * the escape itself is the reliable bridge; a plain string case name is
     * accepted too.
     *
     * @param mixed $declared The configured `ui.menu.selection_color` value.
     * @return Color|null
     */
    private static function resolveColor(mixed $declared): ?Color
    {
        if ($declared instanceof Color) {
            return $declared;
        }

        if ($declared instanceof UnitEnum) {
            $value = $declared->value ?? null;

            if (is_string($value) && ($mapped = Color::tryFrom($value)) instanceof Color) {
                return $mapped;
            }

            return self::colorByName($declared->name);
        }

        if (is_string($declared) && $declared !== '') {
            return Color::tryFrom($declared) ?? self::colorByName($declared);
        }

        return null;
    }

    /**
     * Finds a termutil color by case name, case-insensitively.
     *
     * @param string $name The color name.
     * @return Color|null
     */
    private static function colorByName(string $name): ?Color
    {
        $normalized = strtoupper(str_replace([' ', '-'], '_', trim($name)));

        foreach (Color::cases() as $case) {
            if ($case->name === $normalized) {
                return $case;
            }
        }

        return null;
    }
}
