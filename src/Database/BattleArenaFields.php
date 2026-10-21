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
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Battle Arenas: the backgrounds graphical battles are fought on and the
 * ground each picture shows, the Engine's {@see BattleArenaBindings} file.
 * An arena's ground top is the far edge battlers may stand on, just below
 * its horizon or walkable edge; its bottom (the near edge) and perspective
 * default to the battle theme's. Each side's footing is the polygon of
 * canvas points its battlers may plant their feet in (a road's dirt, not its
 * verge or rocks); a side without one stands anywhere on its ground.
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
    /** Each side's footing list, keyed by whether it is the party's. */
    private const array FOOTING = [false => 'enemyFooting', true => 'partyFooting'];

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
            subLists: [self::getFootingList(false), self::getFootingList(true)],
            projection: new KeyedDictionaryProjection('arenas'),
            saveCheck: self::checkArenas(...),
        );
    }

    /**
     * One side's footing as rows of canvas points, in order around the
     * polygon. The Engine reads three or more, and none means anywhere on
     * the side's ground; the graphical editor draws and drags it.
     */
    public static function getFootingList(bool $party): RecordSubList
    {
        $side = $party ? 'Party' : 'Enemy';

        return new RecordSubList(
            key: self::FOOTING[$party], prefix: lcfirst($side) . 'Footing', singular: strtolower($side) . ' footing point',
            fields: [new RecordField('x', 'x', InputControlType::FLOAT), new RecordField('y', 'y', InputControlType::FLOAT)],
            blank: ['x' => 0, 'y' => 0], heading: $side . ' Footing', removeWhenEmpty: true,
        );
    }

    /**
     * Replaces one side's footing polygon at once, as one step: what a
     * graphical editor's drag of a corner, or a new outline, writes. No
     * points removes the footing, so the side stands anywhere on its ground.
     * The Engine's footing rules decide what is accepted.
     *
     * @param list<array{x: int|float, y: int|float}> $points
     * @throws RecordRefusal When the arenas cannot be written, there is no such arena, or the polygon is refused.
     */
    public static function setFooting(ProjectRecordDatabase $arenas, int $index, bool $party, array $points, string $label): RecordChange
    {
        if (! $arenas->isEditable()) {
            throw new RecordRefusal(sprintf('Read-only: %s.', $arenas->getReadOnlyReason() ?? 'the arenas cannot be written'));
        }
        $record = $arenas->getRecordByIndex($index) ?? throw new RecordRefusal(sprintf('%s has no record %d.', self::CATEGORY, $index));
        $list = self::getFootingList($party);
        try {
            $read = BattleFooting::readPolygon($points, $list->heading);
            $polygon = $party ? new BattleFooting(party: $read) : new BattleFooting($read);
        } catch (InvalidArgumentException $refused) {
            throw new RecordRefusal($refused->getMessage(), previous: $refused);
        }
        // Whole canvas units stay integers, as an author writes them; the rest keep two decimals.
        $entries = array_map(static fn(array $point): array => array_map(static fn(float $value): int|float
            => round($value, 2) === floor($value) ? (int) $value : round($value, 2), ['x' => $point[0], 'y' => $point[1]]),
            $polygon->getPolygon($party));
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
