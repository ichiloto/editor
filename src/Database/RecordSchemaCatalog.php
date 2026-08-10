<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Inventory\Accessory;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;

/**
 * The schemas behind the Database categories added in Phase 6.
 *
 * Whether a category can be *written* is not declared here — it is detected
 * when the file loads (see `PhpDataFile`). What these schemas declare is
 * where records live, what an entry is called, and which fields the settings
 * pane shows. Categories whose authored files are PHP constructor calls
 * (`items.php`, `enemies.php`) still get real field lists, because browsing
 * an enemy's stats is useful even when the editor refuses to rewrite them.
 */
final class RecordSchemaCatalog
{
    /**
     * The event-script command types the engine's interpreter understands.
     *
     * Mirrors `EventInterpreter::execute()`; an unknown type is skipped by
     * the engine with a warning, so keeping this list honest matters.
     */
    public const array EVENT_COMMAND_TYPES = [
        'text',
        'choice',
        'wait',
        'set_switch',
        'set_variable',
        'record_event',
        'give_item',
        'give_gold',
        'play_sound',
        'play_music',
        'accept_quest',
        'move_player',
        'transfer',
        'start_battle',
        'branch',
    ];

    /**
     * Returns every schema-driven category, keyed by Database category key.
     *
     * @return array<string, RecordSchema>
     */
    public static function all(): array
    {
        $schemas = [
            self::states(),
            self::troops(),
            self::items(),
            self::weapons(),
            self::armors(),
            self::enemies(),
            self::skits(),
            self::commonEvents(),
            self::terms(),
            self::types(),
            self::tilesets(),
        ];

        $keyed = [];

        foreach ($schemas as $schema) {
            $keyed[$schema->key] = $schema;
        }

        return $keyed;
    }

    /**
     * Returns the schema for a category key, when one exists.
     *
     * @param string $key The Database category key.
     * @return RecordSchema|null
     */
    public static function forKey(string $key): ?RecordSchema
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Status effects — `assets/Data/states.php`, a plain array the engine's
     * `StateRegistry` reads. Fully editable.
     *
     * @return RecordSchema
     */
    private static function states(): RecordSchema
    {
        return new RecordSchema(
            key: 'states',
            entryNoun: 'state',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/Data/states.php',
            fields: [
                new RecordField('id', 'Id'),
                new RecordField('name', 'Name'),
                new RecordField('icon', 'Icon'),
                new RecordField('description', 'Description'),
                new RecordField('durationTurns', 'Duration Turns', InputControlType::INTEGER, removeWhenEmpty: true),
                new RecordField('tickFormula', 'Tick Formula', removeWhenEmpty: true),
                RecordField::boolean('preventsAction', 'Prevents Action'),
                RecordField::boolean('persistsAfterBattle', 'Persists After Battle'),
            ],
            labelKey: 'name',
            identityKey: 'id',
            blank: [
                'id' => 'new-state',
                'name' => 'New State',
                'icon' => '',
                'description' => '',
            ],
        );
    }

    /**
     * Encounter groups — `assets/Data/troops.php`, a plain array read through
     * the engine's `get_troop()` helper. Fully editable, members included.
     *
     * @return RecordSchema
     */
    private static function troops(): RecordSchema
    {
        return new RecordSchema(
            key: 'troops',
            entryNoun: 'troop',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/Data/troops.php',
            fields: [
                new RecordField('name', 'Name'),
            ],
            labelKey: 'name',
            identityKey: 'name',
            blank: [
                'name' => 'New Troop',
                'enemies' => [
                    ['enemy' => 'Regular Bat', 'position' => [15, 7]],
                ],
            ],
            subList: new RecordSubList(
                key: 'enemies',
                prefix: 'member',
                singular: 'member',
                fields: [
                    RecordField::reference('enemy', 'Enemy', 'enemies'),
                    new RecordField('position.0', 'X', InputControlType::INTEGER),
                    new RecordField('position.1', 'Y', InputControlType::INTEGER),
                ],
                blank: ['enemy' => 'Regular Bat', 'position' => [15, 7]],
            ),
        );
    }

    /**
     * Consumables and key items — the `Item` entries of `assets/Data/items.php`.
     *
     * The file is authored as `new Item(...)` constructor calls, so the editor
     * browses it and never rewrites it.
     *
     * @return RecordSchema
     */
    private static function items(): RecordSchema
    {
        return new RecordSchema(
            key: 'items',
            entryNoun: 'item',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/Data/items.php',
            fields: self::inventoryFields(),
            labelKey: 'name',
            identityKey: 'name',
            recordFilter: static fn(mixed $entry): bool => $entry instanceof Item,
        );
    }

    /**
     * Weapons — the `Weapon` entries of `assets/Data/items.php`. Edited by rebuilding the entry.
     *
     * @return RecordSchema
     */
    private static function weapons(): RecordSchema
    {
        return new RecordSchema(
            key: 'weapons',
            entryNoun: 'weapon',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/Data/items.php',
            fields: [
                ...self::inventoryFields(),
                new RecordField('equipmentType', 'Equipment Type', isReadOnly: true),
                new RecordField('parameterChanges.attack', 'Attack', InputControlType::INTEGER),
                new RecordField('parameterChanges.magicAttack', 'Magic Attack', InputControlType::INTEGER),
                new RecordField('parameterChanges.speed', 'Speed', InputControlType::INTEGER),
            ],
            labelKey: 'name',
            identityKey: 'name',
            recordFilter: static fn(mixed $entry): bool => $entry instanceof Weapon,
        );
    }

    /**
     * Armors and accessories — the `Armor`/`Accessory` entries of
     * `assets/Data/items.php`. Edited by rebuilding the entry.
     *
     * @return RecordSchema
     */
    private static function armors(): RecordSchema
    {
        return new RecordSchema(
            key: 'armors',
            entryNoun: 'armor',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/Data/items.php',
            fields: [
                ...self::inventoryFields(),
                new RecordField('equipmentType', 'Equipment Type', isReadOnly: true),
                new RecordField('parameterChanges.defence', 'Defence', InputControlType::INTEGER),
                new RecordField('parameterChanges.magicDefence', 'Magic Defence', InputControlType::INTEGER),
                new RecordField('parameterChanges.evasion', 'Evasion', InputControlType::INTEGER),
            ],
            labelKey: 'name',
            identityKey: 'name',
            recordFilter: static fn(mixed $entry): bool => $entry instanceof Armor || $entry instanceof Accessory,
        );
    }

    /**
     * Enemies — `assets/Data/enemies.php`.
     *
     * The file builds `new Enemy(...)` objects and shares skill instances
     * between them through local variables, so it cannot be regenerated from
     * the loaded values. Edited by rebuilding the entry.
     *
     * @return RecordSchema
     */
    private static function enemies(): RecordSchema
    {
        return new RecordSchema(
            key: 'enemies',
            entryNoun: 'enemy',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/Data/enemies.php',
            fields: [
                new RecordField('name', 'Name'),
                new RecordField('level', 'Level', InputControlType::INTEGER),
                new RecordField('imagePath', 'Sprite'),
                new RecordField('stats.totalHp', 'HP', InputControlType::INTEGER),
                new RecordField('stats.totalMp', 'MP', InputControlType::INTEGER),
                new RecordField('stats.attack', 'Attack', InputControlType::INTEGER),
                new RecordField('stats.defence', 'Defence', InputControlType::INTEGER),
                new RecordField('stats.magicAttack', 'Magic Attack', InputControlType::INTEGER),
                new RecordField('stats.magicDefence', 'Magic Defence', InputControlType::INTEGER),
                new RecordField('stats.grace', 'Grace', InputControlType::INTEGER),
                new RecordField('stats.evasion', 'Evasion', InputControlType::INTEGER),
                new RecordField('rewards.experience', 'Reward EXP', InputControlType::INTEGER),
                new RecordField('rewards.gold', 'Reward Gold', InputControlType::INTEGER),
                new RecordField('elementAffinities', 'Element Affinities', isReadOnly: true),
                new RecordField('actionPatterns', 'Action Patterns', isReadOnly: true),
            ],
            labelKey: 'name',
            identityKey: 'name',
            recordFilter: static fn(mixed $entry): bool => $entry instanceof Enemy,
        );
    }

    /**
     * Skits — one file per skit under `assets/Data/Skits`, scanned by the
     * engine's `SkitManager`. Fully editable, beats included.
     *
     * The engine keys skits by their payload `id`, never by filename, so
     * editing an id does not require renaming the file.
     *
     * @return RecordSchema
     */
    private static function skits(): RecordSchema
    {
        return new RecordSchema(
            key: 'skits',
            entryNoun: 'skit',
            storage: RecordStorage::DIRECTORY,
            relativePath: 'assets/Data/Skits',
            fields: [
                new RecordField('id', 'Id'),
                new RecordField('title', 'Title'),
                RecordField::reference('where', 'Where', 'maps'),
                new RecordField('conditions', 'Conditions', removeWhenEmpty: true, codec: RecordFieldCodec::CONDITIONS),
                new RecordField('speed', 'Speed (chars/sec)', InputControlType::INTEGER, removeWhenEmpty: true),
            ],
            labelKey: 'title',
            identityKey: 'id',
            blank: [
                'id' => 'new-skit',
                'title' => 'New Skit',
                'beats' => [
                    ['speaker' => 'Speaker', 'text' => 'Say something.'],
                ],
            ],
            subList: new RecordSubList(
                key: 'beats',
                prefix: 'beat',
                singular: 'beat',
                fields: [
                    RecordField::reference('speaker', 'Speaker', 'actors'),
                    new RecordField('text', 'Text'),
                ],
                blank: ['speaker' => 'Speaker', 'text' => 'Say something.'],
            ),
        );
    }

    /**
     * Event scripts — one command list per file under `assets/Events`, run by
     * the engine's `EventInterpreter` when a `ScriptEventTrigger` fires.
     *
     * Each file returns a bare list, so `listPayloadKey` wraps it into the
     * record model. Command fields vary by `type`, which is what
     * `RecordSubList::$variants` exists for.
     *
     * @return RecordSchema
     */
    private static function commonEvents(): RecordSchema
    {
        return new RecordSchema(
            key: 'common_events',
            entryNoun: 'event script',
            storage: RecordStorage::DIRECTORY,
            relativePath: 'assets/Events',
            fields: [
                new RecordField('__scriptId', 'Script Id', isReadOnly: true),
            ],
            labelKey: '__scriptId',
            identityKey: null,
            blank: [
                'commands' => [
                    ['type' => 'text', 'name' => '', 'text' => 'Something happens.'],
                ],
            ],
            subList: new RecordSubList(
                key: 'commands',
                prefix: 'command',
                singular: 'command',
                fields: [
                    new RecordField('type', 'Type', options: self::EVENT_COMMAND_TYPES),
                ],
                blank: ['type' => 'text', 'name' => '', 'text' => 'Something happens.'],
                variants: self::eventCommandVariants(),
                variantKey: 'type',
            ),
            listPayloadKey: 'commands',
        );
    }

    /**
     * UI vocabulary — the `vocab` and `messages` trees of the project's
     * `config.php`, flattened to one editable row per term.
     *
     * Editable only when the whole config file round-trips (it holds enum
     * cases, which export fine; a `new Something()` in there would make the
     * category read-only, and say so).
     *
     * @return RecordSchema
     */
    private static function terms(): RecordSchema
    {
        return new RecordSchema(
            key: 'terms',
            entryNoun: 'term',
            storage: RecordStorage::CONFIG_SUBTREE,
            relativePath: 'config.php',
            fields: [
                new RecordField('path', 'Term', isReadOnly: true),
                new RecordField('value', 'Value'),
            ],
            labelKey: 'path',
            identityKey: 'path',
            configPath: ['vocab', 'messages'],
        );
    }

    /**
     * Element/equipment type tables — `assets/Data/Types`.
     *
     * These are PHP enum *declarations*, not data: the engine reads its own
     * `Entities\Enumerations` enums and never loads this directory. The
     * editor lists the files so they are discoverable, and does not evaluate
     * them (requiring a class declaration into the editor's process would
     * risk a redeclaration fatal).
     *
     * @return RecordSchema
     */
    private static function types(): RecordSchema
    {
        return new RecordSchema(
            key: 'types',
            entryNoun: 'type table',
            storage: RecordStorage::FILE_LISTING,
            relativePath: 'assets/Data/Types',
            fields: [
                new RecordField('file', 'File', isReadOnly: true),
                new RecordField('kind', 'Kind', isReadOnly: true),
                new RecordField('lines', 'Lines', InputControlType::INTEGER, isReadOnly: true),
            ],
            labelKey: 'file',
            identityKey: null,
            isAlwaysReadOnly: true,
            readOnlyNote: 'element and equipment types are PHP enum declarations, not data the engine loads — edit them in your IDE',
        );
    }

    /**
     * Tilesets — no engine system exists yet.
     *
     * Ichiloto maps store their glyphs directly in the map files and the
     * canvas paints them; there is no tileset/terrain table to edit. The
     * category stays visible and says so rather than pretending.
     *
     * @return RecordSchema
     */
    private static function tilesets(): RecordSchema
    {
        return new RecordSchema(
            key: 'tilesets',
            entryNoun: 'tileset',
            storage: RecordStorage::FILE_LISTING,
            relativePath: 'assets/Data/Tilesets',
            fields: [
                new RecordField('file', 'File', isReadOnly: true),
                new RecordField('kind', 'Kind', isReadOnly: true),
            ],
            labelKey: 'file',
            identityKey: null,
            isAlwaysReadOnly: true,
            readOnlyNote: 'the engine has no tileset system yet — map tiles are authored directly on the canvas',
        );
    }

    /**
     * The shared read-only fields every inventory entry displays.
     *
     * @return RecordField[]
     */
    private static function inventoryFields(): array
    {
        return [
            new RecordField('name', 'Name'),
            new RecordField('description', 'Description'),
            new RecordField('icon', 'Icon'),
            new RecordField('price', 'Price', InputControlType::INTEGER),
            new RecordField('quantity', 'Quantity', InputControlType::INTEGER),
        ];
    }

    /**
     * Returns the per-type field sets for event-script commands.
     *
     * Nested arms (`choice.options`, `branch.then`/`else`) are shown as
     * read-only counts: flattening a tree into one settings pane would be
     * unreadable, and silently dropping it on save would be worse.
     *
     * @return array<string, RecordField[]>
     */
    private static function eventCommandVariants(): array
    {
        return [
            'text' => [
                new RecordField('name', 'Speaker', removeWhenEmpty: false),
                new RecordField('text', 'Text'),
            ],
            'choice' => [
                new RecordField('prompt', 'Prompt', removeWhenEmpty: true),
                new RecordField('title', 'Title', removeWhenEmpty: true),
                new RecordField('options', 'Options', isReadOnly: true),
            ],
            'wait' => [
                new RecordField('seconds', 'Seconds', InputControlType::FLOAT),
            ],
            'set_switch' => [
                new RecordField('name', 'Switch'),
                RecordField::boolean('value', 'Value', removeWhenEmpty: false),
            ],
            'set_variable' => [
                new RecordField('name', 'Variable'),
                new RecordField('op', 'Operation', options: ['set', 'add']),
                new RecordField('value', 'Value'),
            ],
            'record_event' => [
                new RecordField('name', 'Story Event'),
            ],
            'give_item' => [
                // The interpreter hands this to the item store, which holds
                // everything in items.php.
                RecordField::reference('item', 'Item', 'inventory'),
                new RecordField('quantity', 'Quantity', InputControlType::INTEGER),
            ],
            'give_gold' => [
                new RecordField('amount', 'Amount', InputControlType::INTEGER),
            ],
            'play_sound' => [
                RecordField::reference('sound', 'Sound', 'sfx'),
            ],
            'play_music' => [
                RecordField::reference('music', 'Music', 'bgm'),
            ],
            'accept_quest' => [
                RecordField::reference('id', 'Quest', 'quests'),
            ],
            'move_player' => [
                new RecordField('x', 'X', InputControlType::INTEGER),
                new RecordField('y', 'Y', InputControlType::INTEGER),
            ],
            'transfer' => [
                RecordField::reference('map', 'Map', 'maps'),
                new RecordField('x', 'X', InputControlType::INTEGER),
                new RecordField('y', 'Y', InputControlType::INTEGER),
            ],
            'start_battle' => [
                RecordField::reference('troop', 'Troop', 'troops'),
            ],
            'branch' => [
                new RecordField('conditions', 'Conditions', codec: RecordFieldCodec::CONDITIONS),
                new RecordField('then', 'Then', isReadOnly: true),
                new RecordField('else', 'Else', isReadOnly: true),
            ],
        ];
    }
}
