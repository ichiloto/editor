<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Database\Projections\KeyedDictionaryProjection;
use Ichiloto\Editor\Database\Projections\WholeFileProjection;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Battle\Presentation\BattleArenaBindings;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Throwable;

/**
 * Battle Arenas: the backgrounds graphical battles are fought on and the
 * ground each picture shows, the Engine's {@see BattleArenaBindings} file.
 * An arena's ground top is the far edge battlers may stand on, just below
 * its horizon or walkable edge; its bottom (the near edge) and perspective
 * default to the battle theme's. Formations belong to troops and the theme,
 * so every troop stands on whichever arena a battle chooses. Graphical
 * presentation only: the category is the graphical editor's, and the
 * Terminal battle never reads it.
 */
final class BattleArenaFields
{
    public const string CATEGORY = 'battle_arenas';
    public const string DEFAULT_CATEGORY = 'battle_arena_default';
    public const string PATH = 'assets/' . BattleArenaBindings::FILE;

    private function __construct() {}

    public static function getSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::CATEGORY,
            entryNoun: 'battle arena',
            storage: RecordStorage::LIST_FILE,
            relativePath: self::PATH,
            fields: [
                // Maps, encounters and battle tests name an arena by it, so it is set once, from the name.
                new RecordField('id', 'Stable Id', isReadOnly: true),
                new RecordField('name', 'Name'),
                RecordField::reference('background.image', 'Background', 'png_assets'),
                new RecordField('background.source', 'Background Crop (x, y, width, height)', codec: RecordFieldCodec::SOURCE_RECT,
                    removeWhenEmpty: true, displayDefault: '(the whole image)'),
                new RecordField('ground.top', 'Ground Far Edge (canvas y)', InputControlType::FLOAT, removeWhenEmpty: true,
                    displayDefault: '(the battle theme\'s)'),
                new RecordField('ground.bottom', 'Ground Near Edge (canvas y)', InputControlType::FLOAT, removeWhenEmpty: true,
                    displayDefault: '(the theme\'s gap above the battle UI)'),
                new RecordField('ground.perspective', 'Far Edge Scale', InputControlType::FLOAT, removeWhenEmpty: true,
                    displayDefault: '1 (no perspective)'),
            ],
            labelKey: 'name',
            identityKey: 'id',
            makeBlank: self::makeBlank(...),
            projection: new KeyedDictionaryProjection('arenas'),
            saveCheck: self::checkArenas(...),
        );
    }

    /** The arena a battle with none chosen is fought on. */
    public static function getDefaultSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::DEFAULT_CATEGORY,
            entryNoun: 'default battle arena',
            storage: RecordStorage::LIST_FILE,
            relativePath: self::PATH,
            fields: [RecordField::reference('default', 'Default Arena', self::CATEGORY, allowsNone: true)],
            labelKey: 'default',
            identityKey: null,
            projection: new WholeFileProjection(['default']),
            saveCheck: self::checkArenas(...),
        );
    }

    /**
     * A new arena, which saves at once as the Engine admits it: it starts on
     * the project's first image, for the author to replace, with the theme's
     * ground until the author marks where its picture's ground begins.
     */
    public static function makeBlank(string $name, string $projectRoot): array
    {
        $images = ReferenceCatalog::getPngAssets($projectRoot);
        sort($images);
        if ($images === []) {
            throw new RecordRefusal('A new battle arena starts on a background image. Add a PNG under assets first.');
        }
        $id = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

        return ['id' => 'arena.' . ($id === '' ? 'new' : $id), 'name' => $name, 'background' => ['image' => $images[0]]];
    }

    /**
     * Why the battle could not read the arenas as they would be saved, or
     * null when it can: the Engine reads them, and binds them beside any the
     * presentation code declares, refusing an arena or default both own.
     *
     * @param array<array-key, mixed> $whole The arenas file as it would be written.
     */
    public static function checkArenas(array $whole, string $projectRoot): ?string
    {
        $assets = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'assets';
        try {
            $arenas = BattleArenaBindings::getFromArray($whole);
            $catalog = BattlePresentationCatalog::loadCode($assets)?->bindArenas($arenas);
            $layout = $catalog?->ui;
            foreach ($catalog?->arenas ?? [] as $arena) {
                $layout?->getGround($arena);
            }
        } catch (Throwable $error) {
            return sprintf('The battle could not read %s as it would be saved: %s', BattleArenaBindings::FILE, $error->getMessage());
        }

        return null;
    }
}
