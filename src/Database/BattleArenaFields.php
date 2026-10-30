<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Database\Projections\KeyedDictionaryProjection;
use Ichiloto\Editor\Database\Projections\WholeFileProjection;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Battle\Presentation\BattleArenaBindings;
use Ichiloto\Engine\Battle\Presentation\BattleFooting;
use Ichiloto\Engine\Battle\Presentation\BattleGround;
use Ichiloto\Engine\Entities\Enemies\EnemyHabitat;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Battle Arenas: the backgrounds graphical battles are fought on and the
 * ground each picture shows, the Engine's {@see BattleArenaBindings} file.
 * An arena's ground top is the far edge battlers may stand on, just below
 * its horizon or walkable edge; its bottom (the near edge) and depth scale
 * default to the battle theme's. The depth scale is one of two models the
 * Engine reads, never both: the legacy far edge scale (`perspective`), or a
 * measured scale (`scaleReference`): the floor's horizon, the reference row
 * where the shared battler body keeps its size, the upright pixels per real
 * unit there and optionally the floor's vanishing point. Switching models is
 * one explicit step ({@see setDepthModel()}); an edit that would leave both is
 * refused. Its footing marks the picture's land (a
 * road's dirt, not its verge or rocks) and water as polygons of canvas
 * points; the party stands on land and each enemy where its habitat lives.
 * An arena marking neither is land throughout.
 * An arena standing for a region names its terrain variants: for each
 * encounter terrain a random battle there can start on, the arena drawn for
 * it, which may be the arena itself when its picture already shows that
 * terrain. Validation lists the terrains battles meet without one.
 * Formations belong to troops and the theme, so every troop stands on
 * whichever arena a battle chooses. Graphical
 * presentation only: the category is the graphical editor's, and the
 * Terminal battle never reads it.
 */
final class BattleArenaFields
{
    public const string CATEGORY = 'battle_arenas';
    public const string DEFAULT_CATEGORY = 'battle_arena_default';
    public const string PATH = 'assets/' . BattleArenaBindings::FILE;
    /** The habitats an arena marks footing for; air hovers over either. */
    public const array FOOTED_HABITATS = [EnemyHabitat::LAND, EnemyHabitat::WATER];

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
                // A measured scale is set whole by setDepthModel(); each value can then be typed on its own.
                new RecordField('ground.scaleReference.horizonY', 'Measured Horizon (canvas y)', InputControlType::FLOAT,
                    removeWhenEmpty: true, displayDefault: '(no measured scale)'),
                new RecordField('ground.scaleReference.referenceY', 'Measured Reference Row (canvas y)', InputControlType::FLOAT,
                    removeWhenEmpty: true, displayDefault: '(no measured scale)'),
                new RecordField('ground.scaleReference.pixelsPerUnit', 'Measured Pixels Per Unit', InputControlType::FLOAT,
                    removeWhenEmpty: true, displayDefault: '(no measured scale)'),
                new RecordField('ground.scaleReference.vanishingX', 'Measured Vanishing X (canvas x)', InputControlType::FLOAT,
                    removeWhenEmpty: true, displayDefault: '(none)'),
                // The arena's light on battlers' contact shadows: darker in a mine, softer on bright grass.
                new RecordField('shadow.strength', 'Shadow Strength', InputControlType::FLOAT, removeWhenEmpty: true,
                    displayDefault: '1 (the theme\'s)'),
                new RecordField('shadow.color', 'Shadow Colour (#rrggbb)', removeWhenEmpty: true, displayDefault: '(the theme\'s)'),
            ],
            labelKey: 'name',
            identityKey: 'id',
            makeBlank: self::makeBlank(...),
            subLists: [...array_map(self::getFootingList(...), self::FOOTED_HABITATS), self::getVariantList()],
            projection: new KeyedDictionaryProjection('arenas'),
            saveCheck: self::checkArenas(...),
            editCheck: self::checkGroundEdit(...),
        );
    }

    /**
     * Why the Engine could not read an arena's ground as an edit of its
     * depth scale model leaves it, or null when it can or the edit leaves
     * the model alone. The Engine's
     * own ground reading decides; the reason adds how to make the change the
     * author meant: a measured scale replaces the far edge scale only through
     * an explicit switch, and its values start together.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public static function checkGroundEdit(array $before, array $after): ?string
    {
        $ground = $after['ground'] ?? [];
        $was = is_array($before['ground'] ?? null) ? $before['ground'] : [];
        // Band edges alone are read at save, beside the theme they inherit from.
        $model = static fn(mixed $ground): array => is_array($ground) ? array_intersect_key($ground, ['perspective' => 0, 'scaleReference' => 0]) : [];
        if ($model($ground) === $model($was)) {
            return null;
        }
        try {
            BattleGround::fromArray($ground);

            return null;
        } catch (InvalidArgumentException $refused) {
            $hint = match (true) {
                is_array($ground) && array_key_exists('perspective', $ground) && array_key_exists('scaleReference', $ground)
                    => array_key_exists('scaleReference', $was)
                        ? ' This arena uses a measured scale; switch it to a far edge scale explicitly (Use Far Edge Scale, arenas.setDepthModel), which removes the measured scale in the same step.'
                        : ' This arena uses a far edge scale; switch it to a measured scale explicitly (Use Measured Scale, arenas.setDepthModel), which removes the far edge scale in the same step.',
                is_array($ground) && array_key_exists('scaleReference', $ground) && ! array_key_exists('scaleReference', $was)
                    => ' Start a measured scale with its horizon, reference row and pixels per unit together (Use Measured Scale, arenas.setDepthModel).',
                default => '',
            };

            return $refused->getMessage() . $hint;
        }
    }

    /**
     * Sets the depth scale model an arena's ground declares, as one step:
     * a measured scale, the legacy far edge scale, or neither, so the arena
     * keeps the battle theme's. The model named replaces the one the arena
     * had, which is the explicit switch between them. The Engine's ground
     * reading decides what is accepted; whole numbers stay integers, as an
     * author writes them.
     *
     * @param array<string, mixed>|null $scaleReference horizonY, referenceY, pixelsPerUnit and optional vanishingX.
     * @throws RecordRefusal When the arenas cannot be written, there is no such arena, both models are given, or the ground is refused.
     */
    public static function setDepthModel(ProjectRecordDatabase $arenas, int $index, int|float|null $perspective, ?array $scaleReference,
        string $label): RecordChange
    {
        if (! $arenas->isEditable()) {
            throw new RecordRefusal(sprintf('Read-only: %s.', $arenas->getReadOnlyReason() ?? 'the arenas cannot be written'));
        }
        $record = $arenas->getRecordByIndex($index) ?? throw new RecordRefusal(sprintf('%s has no record %d.', self::CATEGORY, $index));
        $before = $record->toArray();
        $ground = is_array($before['ground'] ?? null) ? $before['ground'] : [];
        unset($ground['perspective'], $ground['scaleReference']);
        if ($perspective !== null) {
            $ground['perspective'] = self::keepWhole($perspective);
        }
        if ($scaleReference !== null) {
            // The Engine's own order and keys; an unknown or missing key is refused below with its reason.
            $order = array_flip(['horizonY', 'referenceY', 'pixelsPerUnit', 'vanishingX']);
            uksort($scaleReference, static fn(int|string $a, int|string $b): int => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));
            $ground['scaleReference'] = array_map(static fn(mixed $value): mixed => is_int($value) || is_float($value) ? self::keepWhole($value) : $value,
                $scaleReference);
        }
        try {
            BattleGround::fromArray($ground);
        } catch (InvalidArgumentException $refused) {
            throw new RecordRefusal($refused->getMessage(), previous: $refused);
        }
        $record->set('ground', $ground === [] ? null : $ground);
        $after = $record->toArray();
        if ($after === $before) {
            return new RecordChange(null, $index);
        }
        try {
            $arenas->assertSourceAccepts($index);
        } catch (SourcePreservationRefusal|SourceIdentityConflict|RuntimeException $refused) {
            $record->restorePayload($before);

            throw new RecordRefusal($refused->getMessage(), previous: $refused);
        }

        return new RecordChange(new GenericCommand(
            $label,
            static fn() => $record->restorePayload($after),
            static fn() => $record->restorePayload($before),
        ), $index);
    }

    /** A whole number as an integer, as an author writes it; any other value as it is. */
    private static function keepWhole(int|float $value): int|float
    {
        return is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < PHP_INT_MAX ? (int) $value : $value;
    }

    /**
     * The picture's land or water footing as rows of canvas points, in order
     * around the polygon. The Engine reads three or more, and an arena
     * marking neither is land throughout; the graphical editor draws and
     * drags it.
     */
    public static function getFootingList(EnemyHabitat $habitat): RecordSubList
    {
        $name = ucfirst($habitat->value);

        return new RecordSubList(
            key: 'footing.' . $habitat->value, prefix: $habitat->value . 'Footing', singular: $habitat->value . ' footing point',
            fields: [new RecordField('x', 'x', InputControlType::FLOAT), new RecordField('y', 'y', InputControlType::FLOAT)],
            blank: ['x' => 0, 'y' => 0], heading: $name . ' Footing', removeWhenEmpty: true,
        );
    }

    /**
     * The arena drawn for each encounter terrain a random battle on this
     * arena starts on, stored as the Engine reads it: terrain => arena.
     */
    public static function getVariantList(): RecordSubList
    {
        return new RecordSubList(
            key: 'variants', prefix: 'terrainVariant', singular: 'terrain variant',
            fields: [
                RecordField::reference('terrain', 'Encounter Terrain', ReferenceCatalog::ENCOUNTER_TERRAINS),
                RecordField::reference('arena', 'Drawn On', self::CATEGORY),
            ],
            blank: ['terrain' => '', 'arena' => ''], heading: 'Terrain Variants', removeWhenEmpty: true,
            keyField: 'terrain', valueField: 'arena',
        );
    }

    /**
     * Replaces the land or water footing at once, as one step: what a
     * graphical editor's drag of a corner, or a new outline, writes. No
     * points removes it. The Engine's footing rules decide what is accepted.
     *
     * @param list<array{x: int|float, y: int|float}> $points
     * @throws RecordRefusal When the arenas cannot be written, there is no such arena, or the polygon is refused.
     */
    public static function setFooting(ProjectRecordDatabase $arenas, int $index, EnemyHabitat $habitat, array $points, string $label): RecordChange
    {
        if (! $arenas->isEditable()) {
            throw new RecordRefusal(sprintf('Read-only: %s.', $arenas->getReadOnlyReason() ?? 'the arenas cannot be written'));
        }
        $record = $arenas->getRecordByIndex($index) ?? throw new RecordRefusal(sprintf('%s has no record %d.', self::CATEGORY, $index));
        if (! in_array($habitat, self::FOOTED_HABITATS, true)) {
            throw new RecordRefusal(sprintf('An arena marks land and water footing; %s battlers stand over either.', $habitat->value));
        }
        $list = self::getFootingList($habitat);
        try {
            $read = BattleFooting::readPolygon($points, $list->heading);
            $polygon = $habitat === EnemyHabitat::WATER ? new BattleFooting(water: $read) : new BattleFooting($read);
        } catch (InvalidArgumentException $refused) {
            throw new RecordRefusal($refused->getMessage(), previous: $refused);
        }
        // Whole canvas units stay integers, as an author writes them; the rest keep two decimals.
        $entries = array_map(static fn(array $point): array => array_map(static fn(float $value): int|float
            => round($value, 2) === floor($value) ? (int) $value : round($value, 2), ['x' => $point[0], 'y' => $point[1]]),
            $habitat === EnemyHabitat::WATER ? $polygon->water : $polygon->land);
        if ($entries === $record->getSubList($list->key)) {
            return new RecordChange(null, $index);
        }
        $before = $record->toArray();
        $record->setEntries($list, $entries);
        $after = $record->toArray();
        try {
            $arenas->assertSourceAccepts($index);
        } catch (SourcePreservationRefusal|SourceIdentityConflict|RuntimeException $refused) {
            $record->restorePayload($before);

            throw new RecordRefusal($refused->getMessage(), previous: $refused);
        }

        return new RecordChange(new GenericCommand(
            $label,
            static fn() => $record->restorePayload($after),
            static fn() => $record->restorePayload($before),
        ), $index);
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
