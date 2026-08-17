<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Field\ProjectNpc;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Enumerations\ItemUserType;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeProgressService;
use Ichiloto\Engine\Entities\Inventory\Accessory;
use Ichiloto\Engine\Entities\Inventory\EquipmentSlotType;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\Enumerations\ArmorType;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\ParameterChanges;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Battle\BattleRewards;

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
     * Imported from the runtime so the editor cannot drift into a duplicate
     * command registry.
     */
    public const array EVENT_COMMAND_TYPES = EventInterpreter::COMMAND_TYPES;

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
            self::knowledgeSubjects(),
            self::knowledgeReports(),
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
                new RecordField(
                    'escapePolicy',
                    'Escape Policy',
                    options: ['allowed', 'forbidden'],
                    removeWhenEmpty: true,
                ),
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
            fields: [
                ...self::inventoryFields(),
                // Only a plain item carries a stack limit: the engine's
                // Equipment constructor does not take one.
                new RecordField('maxQuantity', 'Max Quantity', InputControlType::INTEGER),
            ],
            labelKey: 'name',
            identityKey: 'id',
            recordFilter: static fn(mixed $entry): bool => $entry instanceof Item,
            makeBlank: static fn(string $name): object => new Item(
                $name,
                'What it does.',
                '✨',
                0,
                id: self::inventoryDefinitionId('item', $name),
            ),
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
            fields: self::equipmentFields([
                new RecordField(
                    'equipmentType',
                    'Equipment Type',
                    options: array_map(static fn(WeaponType $type): string => $type->value, WeaponType::cases()),
                    enumClass: WeaponType::class,
                ),
                ...self::parameterChangeFields(),
            ]),
            labelKey: 'name',
            identityKey: 'id',
            recordFilter: static fn(mixed $entry): bool => $entry instanceof Weapon,
            makeBlank: static fn(string $name): object => new Weapon(
                $name,
                'What it does.',
                '🗡',
                0,
                equipmentType: WeaponType::SWORD,
                parameterChanges: new ParameterChanges(attack: 1),
                id: self::inventoryDefinitionId('equipment', $name),
            ),
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
            fields: self::equipmentFields([
                new RecordField(
                    'equipmentType',
                    'Equipment Type',
                    options: array_map(static fn(ArmorType $type): string => $type->value, ArmorType::cases()),
                    enumClass: ArmorType::class,
                ),
                ...self::parameterChangeFields(),
            ]),
            labelKey: 'name',
            identityKey: 'id',
            recordFilter: static fn(mixed $entry): bool => $entry instanceof Armor || $entry instanceof Accessory,
            makeBlank: static fn(string $name): object => new Armor(
                $name,
                'What it protects against.',
                '🛡',
                0,
                equipmentType: ArmorType::GENERAL_ARMOR,
                parameterChanges: new ParameterChanges(defence: 1),
                id: self::inventoryDefinitionId('equipment', $name),
            ),
        );
    }

    /**
     * Knowledge subjects — the `subjects` list of
     * `assets/Data/knowledge.php`.
     *
     * The file holds several lists and the vocabularies they share, so this
     * category edits one of them and writes the rest back exactly as it
     * read them.
     *
     * A subject is anything the party can come to know: a creature, a
     * person, a place, a practice. Nothing here requires an enemy -- a
     * subject the party never fights is an ordinary record, and defeat is
     * not the only outcome a record can have.
     *
     * @return RecordSchema
     */
    private static function knowledgeSubjects(): RecordSchema
    {
        return new RecordSchema(
            key: 'knowledge_subjects',
            entryNoun: 'knowledge subject',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/Data/knowledge.php',
            fields: [
                new RecordField('id', 'Id'),
                // The project names its own kinds of record; the picker
                // offers what it has declared.
                RecordField::reference('recordType', 'Record Type', 'knowledge_record_types'),
                new RecordField('displayName', 'Display Name'),
                new RecordField('quickCard', 'Quick Card'),
                new RecordField('deepCard', 'Deep Card', removeWhenEmpty: true),
                new RecordField('family', 'Family', removeWhenEmpty: true),
                new RecordField('species', 'Species', removeWhenEmpty: true),
                new RecordField('tags', 'Tags', codec: RecordFieldCodec::CSV_LIST, removeWhenEmpty: true),
                new RecordField('habitats', 'Habitats', codec: RecordFieldCodec::CSV_LIST, removeWhenEmpty: true),
                new RecordField('observations', 'Observations', codec: RecordFieldCodec::CSV_LIST, removeWhenEmpty: true),
                new RecordField('displayOrder', 'Display Order', InputControlType::INTEGER, step: 10),
                // A subject the party has not met yet is authored, but not
                // listed, until something discovers it.
                RecordField::boolean('hidden', 'Hidden Until Discovered'),
            ],
            labelKey: 'displayName',
            identityKey: 'id',
            blank: [
                'id' => 'subject.new-subject',
                'recordType' => '',
                'displayName' => 'New Subject',
                'quickCard' => 'What is known about it at a glance.',
                'displayOrder' => 0,
            ],
            subList: new RecordSubList(
                key: 'relationships',
                prefix: 'relationship',
                singular: 'relationship',
                fields: [
                    new RecordField('type', 'Type'),
                    RecordField::reference('subject', 'Related Subject', 'knowledge_subjects'),
                ],
                blank: ['type' => 'related', 'subject' => ''],
            ),
            fileListKey: 'subjects',
        );
    }

    /**
     * Knowledge reports — the `reports` list of `assets/Data/knowledge.php`.
     *
     * A report is a claim about a subject that the party can unlock, amend,
     * withdraw or supersede, and one report may disagree with others.
     *
     * @return RecordSchema
     */
    private static function knowledgeReports(): RecordSchema
    {
        return new RecordSchema(
            key: 'knowledge_reports',
            entryNoun: 'knowledge report',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/Data/knowledge.php',
            fields: [
                new RecordField('id', 'Id'),
                RecordField::reference('subject', 'Subject', 'knowledge_subjects'),
                new RecordField('title', 'Title'),
                new RecordField('summary', 'Summary'),
                new RecordField('details', 'Details', removeWhenEmpty: true),
                new RecordField('disagreesWith', 'Disagrees With', codec: RecordFieldCodec::CSV_LIST, removeWhenEmpty: true),
                new RecordField('displayOrder', 'Display Order', InputControlType::INTEGER, step: 10),
            ],
            labelKey: 'title',
            identityKey: 'id',
            blank: [
                'id' => 'report.new-report',
                'subject' => '',
                'title' => 'New Report',
                'summary' => 'What it claims.',
                'displayOrder' => 0,
            ],
            fileListKey: 'reports',
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
                RecordField::reference('imagePath', 'Sprite', 'enemy_sprites'),
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
                new RecordField('elementAffinities', 'Element Affinities', codec: RecordFieldCodec::AFFINITIES),
                new RecordField('actionPatterns', 'Action Patterns', isReadOnly: true),
            ],
            labelKey: 'name',
            identityKey: 'name',
            recordFilter: static fn(mixed $entry): bool => $entry instanceof Enemy,
            // An Enemy loads its sprite in its constructor, so a blank needs a
            // real file to point at; a project with no enemy sprites cannot
            // author an enemy yet, and creation refuses rather than crashing.
            makeBlank: static function (string $name, string $projectRoot): ?object {
                $spriteDirectory = rtrim($projectRoot, DIRECTORY_SEPARATOR) . '/assets/Graphics/Enemies';
                $sprites = is_dir($spriteDirectory)
                    ? array_values(array_filter(
                        (array) scandir($spriteDirectory),
                        static fn(mixed $entry): bool => is_string($entry)
                            && ! str_starts_with($entry, '.')
                            && is_file($spriteDirectory . '/' . $entry),
                    ))
                    : [];

                if ($sprites === []) {
                    return null;
                }

                return new Enemy(
                    $name,
                    1,
                    new Stats(currentHp: 10, attack: 5, defence: 5, speed: 5),
                    // graphics() appends the extension itself, so the stem is
                    // what an imagePath stores.
                    pathinfo($sprites[0], PATHINFO_FILENAME),
                    new BattleRewards(1, 1, []),
                    [],
                );
            },
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
            subList: self::eventCommandList('commands'),
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
            // Identity. The id is what a save, an alias and every reference
            // resolve to, so it is shown and never edited after creation:
            // nothing that names it would follow a rename.
            new RecordField('id', 'Id', isReadOnly: true),
            new RecordField('name', 'Name'),
            new RecordField('description', 'Description'),
            new RecordField('icon', 'Icon'),
            // Compatibility references an older save may still name.
            new RecordField('aliases', 'Aliases', codec: RecordFieldCodec::CSV_LIST, removeWhenEmpty: true),
            // Policy
            new RecordField(
                'userType',
                'Who May Use It',
                options: array_map(static fn(ItemUserType $type): string => $type->value, ItemUserType::cases()),
                enumClass: ItemUserType::class,
            ),
            RecordField::boolean('isKeyItem', 'Key Item'),
            RecordField::boolean('consumable', 'Consumable'),
            // Trade
            new RecordField('price', 'Price', InputControlType::INTEGER),
            RecordField::boolean('sellable', 'Sellable', removeWhenEmpty: false),
            new RecordField('sellRateBasisPoints', 'Sell Rate (basis points)', InputControlType::INTEGER, step: 500),
            // Stock
            new RecordField('quantity', 'Quantity', InputControlType::INTEGER),
            // Project-owned vocabularies: the engine reads these as plain
            // strings a project gives meaning to, so they are typed rather
            // than chosen from a list this code would have to invent.
            new RecordField('availability', 'Availability', displayDefault: 'ordinary'),
            new RecordField('acquisitionPolicy', 'Acquisition Policy', removeWhenEmpty: true),
        ];
    }

    /**
     * Returns the stable id a new inventory definition is created under.
     *
     * A definition's id is its identity for saves, aliases and every
     * reference, so a new one gets an explicit id derived from its name
     * rather than falling back to the engine's legacy slug, which two
     * records created in the same session would share.
     *
     * @param string $prefix The namespace the kind of definition uses.
     * @param string $name The display name.
     * @return string The id.
     */
    private static function inventoryDefinitionId(string $prefix, string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($name))), '-');

        return sprintf('%s.%s', $prefix, $slug === '' ? 'definition' : $slug);
    }

    /**
     * Every canonical parameter a piece of equipment may change.
     *
     * The engine's `ParameterChanges` is one shape for weapons and armor
     * alike, so both author all of it: a shield that grants attack and a
     * sword that grants defence are both things a project may want.
     *
     * @return RecordField[]
     */
    private static function parameterChangeFields(): array
    {
        return [
            new RecordField('parameterChanges.attack', 'Attack', InputControlType::INTEGER),
            new RecordField('parameterChanges.defence', 'Defence', InputControlType::INTEGER),
            new RecordField('parameterChanges.magicAttack', 'Magic Attack', InputControlType::INTEGER),
            new RecordField('parameterChanges.magicDefence', 'Magic Defence', InputControlType::INTEGER),
            new RecordField('parameterChanges.speed', 'Speed', InputControlType::INTEGER),
            new RecordField('parameterChanges.grace', 'Grace', InputControlType::INTEGER),
            new RecordField('parameterChanges.evasion', 'Evasion', InputControlType::INTEGER),
            new RecordField('parameterChanges.totalHp', 'Max HP', InputControlType::INTEGER),
            new RecordField('parameterChanges.totalMp', 'Max MP', InputControlType::INTEGER),
        ];
    }

    /**
     * The fields every piece of equipment adds to the base inventory ones.
     *
     * `semanticSlot` is what the runtime equips by; the PHP class alone is not
     * enough, which is why a shield has to say so. Form, size and material
     * are project-owned words the engine only stores.
     *
     * @param RecordField[] $typeAndStats The type row and the stat rows this kind of equipment has.
     * @return RecordField[]
     */
    private static function equipmentFields(array $typeAndStats): array
    {
        return [
            ...self::inventoryFields(),
            // Slot and kind
            new RecordField(
                'semanticSlot',
                'Slot',
                options: array_map(static fn(EquipmentSlotType $slot): string => $slot->value, EquipmentSlotType::cases()),
                enumClass: EquipmentSlotType::class,
            ),
            ...$typeAndStats,
            // Shape
            new RecordField('form', 'Form', removeWhenEmpty: true),
            new RecordField('size', 'Size', removeWhenEmpty: true),
            new RecordField('material', 'Material', removeWhenEmpty: true),
            // Elements
            new RecordField('element', 'Attack Element', reference: 'elements', allowsNone: true),
            new RecordField('elementAffinities', 'Elemental Wards', codec: RecordFieldCodec::AFFINITIES),
            // Bounded modifiers: the runtime accepts -100 through 100.
            new RecordField('accuracyModifier', 'Accuracy Modifier', InputControlType::INTEGER, step: 5),
            new RecordField('criticalModifier', 'Critical Modifier', InputControlType::INTEGER, step: 5),
            // A typed property a project defines and the runtime passes on.
            new RecordField('specialProperty.type', 'Special Property', removeWhenEmpty: true),
        ];
    }

    /**
     * The schema for a map's NPCs, edited in the map's Inspector.
     *
     * Every field the engine's `NpcManager::configure()` reads, in the groups
     * an author thinks in. The id is shown but not editable: it is what
     * routes and diagnostics name, and nothing that names it would follow a
     * rename. Dialogue is edited as conditional variants -- the runtime's
     * own model, of which plain pages are the one-variant case with no
     * conditions -- so the settings pane and the game agree about what a
     * given entry means. Scripts are the shared event-command list.
     *
     * @return RecordSchema The schema.
     */
    public static function mapNpcs(): RecordSchema
    {
        return new RecordSchema(
            key: 'map_npcs',
            entryNoun: 'NPC',
            storage: RecordStorage::MAP_OWNED,
            relativePath: '',
            fields: [
                // Identity
                new RecordField('id', 'Id', isReadOnly: true),
                new RecordField('name', 'Name'),
                // Placement
                new RecordField('x', 'X', InputControlType::INTEGER),
                new RecordField('y', 'Y', InputControlType::INTEGER),
                // Appearance
                new RecordField('sprite', 'Sprite', displayDefault: '@'),
                new RecordField('sprites.north', 'Facing North', removeWhenEmpty: true),
                new RecordField('sprites.south', 'Facing South', removeWhenEmpty: true),
                new RecordField('sprites.east', 'Facing East', removeWhenEmpty: true),
                new RecordField('sprites.west', 'Facing West', removeWhenEmpty: true),
                // Movement
                new RecordField('movement', 'Movement', options: ProjectNpc::MOVEMENTS, displayDefault: 'fixed'),
                new RecordField('wanderArea.x', 'Wander X', InputControlType::INTEGER, removeWhenEmpty: true),
                new RecordField('wanderArea.y', 'Wander Y', InputControlType::INTEGER, removeWhenEmpty: true),
                new RecordField('wanderArea.width', 'Wander Width', InputControlType::INTEGER, removeWhenEmpty: true),
                new RecordField('wanderArea.height', 'Wander Height', InputControlType::INTEGER, removeWhenEmpty: true),
                // Visibility
                new RecordField('conditions', 'Visible When', removeWhenEmpty: true, codec: RecordFieldCodec::CONDITIONS),
                // Completion writes
                new RecordField('sets', 'After Talking', removeWhenEmpty: true, codec: RecordFieldCodec::WORLD_WRITES),
            ],
            labelKey: 'name',
            identityKey: 'id',
            blank: [
                'name' => 'New NPC',
                'sprite' => '@',
                'x' => 0,
                'y' => 0,
                'movement' => 'fixed',
                'dialogue' => [['text' => 'Hello.']],
            ],
            subList: new RecordSubList(
                key: 'dialogue',
                prefix: 'variant',
                singular: 'dialogue variant',
                fields: [
                    new RecordField('conditions', 'When', removeWhenEmpty: true, codec: RecordFieldCodec::CONDITIONS),
                    new RecordField('sets', 'Then Set', removeWhenEmpty: true, codec: RecordFieldCodec::WORLD_WRITES),
                ],
                blank: ['lines' => [['text' => 'Something to say.']]],
                nestedLists: [
                    '*' => new RecordSubList(
                        key: 'lines',
                        prefix: 'line',
                        singular: 'line',
                        fields: [
                            // The runtime titles a page with its `name`, the
                            // NPC's own when the key is absent, and no title
                            // at all for ''. Both are choices, by meaning,
                            // never a name retyped that goes stale on rename.
                            RecordField::reference(
                                'name',
                                'Speaker',
                                'actors',
                                allowsNone: true,
                                noneLabel: "(the NPC's name)",
                                blankLabel: '(No speaker)',
                            ),
                            new RecordField('text', 'Text'),
                        ],
                        blank: ['text' => 'Something to say.'],
                    ),
                ],
                // A variant's own script, edited as a frame like a branch arm.
                commandArms: ['script' => 'Script'],
            ),
            // The NPC's inline script: the shared command vocabulary, in a
            // frame. The runtime runs it INSTEAD of dialogue when non-empty.
            commandLists: ['script' => self::eventCommandList('script')],
        );
    }

    /**
     * Returns the event-command list under a payload key.
     *
     * One command vocabulary, one editor: event scripts hold it under
     * `commands`, an NPC's inline script under `script`, and a dialogue
     * variant's under `script` too. Every surface that runs the interpreter
     * edits the same list the same way.
     *
     * @param string $key The payload key holding the list.
     * @return RecordSubList The command list.
     */
    public static function eventCommandList(string $key): RecordSubList
    {
        return new RecordSubList(
            key: $key,
            prefix: 'command',
            singular: 'command',
            fields: [
                new RecordField('type', 'Type', options: self::EVENT_COMMAND_TYPES),
            ],
            blank: ['type' => 'text', 'name' => '', 'text' => 'Something happens.'],
            variants: self::eventCommandVariants(),
            variantKey: 'type',
            nestedLists: [
                'move_route' => new RecordSubList(
                    key: 'steps',
                    prefix: 'step',
                    singular: 'route step',
                    fields: [
                        new RecordField('direction', 'Direction', options: ['up', 'down', 'left', 'right']),
                        new RecordField('count', 'Count', InputControlType::INTEGER),
                        RecordField::boolean('faceOnly', 'Face Only'),
                        new RecordField('seconds', 'Seconds', InputControlType::FLOAT, removeWhenEmpty: true),
                    ],
                    blank: ['direction' => 'down', 'count' => 1, 'faceOnly' => false],
                ),
            ],
        );
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
                // Options become their own rows and frames; see
                // ProjectRecordDatabase::describeSubEntryFields.
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
            'recover_party' => [],
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
            // The runtime owns which knowledge operations exist, and each one
            // reads its own fields. Everything optional drops out when empty,
            // so a discover command does not carry an unused observation.
            'knowledge' => [
                new RecordField(
                    'operation',
                    'Operation',
                    options: KnowledgeProgressService::OPERATIONS,
                ),
                RecordField::reference('subject', 'Subject', 'knowledge_subjects'),
                RecordField::reference('report', 'Report', 'knowledge_reports', allowsNone: true),
                RecordField::reference('replacement', 'Replacement Report', 'knowledge_reports', allowsNone: true),
                new RecordField('observation', 'Observation', removeWhenEmpty: true),
                new RecordField('outcome', 'Outcome', removeWhenEmpty: true),
                new RecordField('source', 'Source', removeWhenEmpty: true, displayDefault: 'story.event'),
                new RecordField('confidence', 'Confidence', InputControlType::FLOAT, removeWhenEmpty: true, displayDefault: '1.0'),
            ],
            'move_route' => [
                new RecordField('subject', 'Subject', options: ['player', 'npc']),
                // The stable ids of the map an author is working in; the
                // picker reads the live collection, so a just-created NPC
                // is offered at once.
                new RecordField('npcId', 'NPC Id', reference: 'map_npcs', removeWhenEmpty: true, allowsNone: true),
                new RecordField('secondsPerStep', 'Seconds Per Step', InputControlType::FLOAT, removeWhenEmpty: true),
                new RecordField('speed', 'Steps Per Second', InputControlType::FLOAT, removeWhenEmpty: true),
                new RecordField(
                    'wait',
                    'Wait For Completion',
                    InputControlType::BOOLEAN,
                    ['true'],
                    removeWhenEmpty: false,
                ),
            ],
            'transfer' => [
                RecordField::reference('map', 'Map', 'maps'),
                new RecordField('x', 'X', InputControlType::INTEGER),
                new RecordField('y', 'Y', InputControlType::INTEGER),
            ],
            'start_battle' => [
                RecordField::reference('troop', 'Troop', 'troops'),
                new RecordField('resultVariable', 'Result Variable', removeWhenEmpty: true),
                new RecordField('defeatPolicy', 'Defeat Policy', options: ['game_over', 'continue'], removeWhenEmpty: true),
                new RecordField('escapePolicy', 'Escape Policy', options: ['allowed', 'forbidden'], removeWhenEmpty: true),
            ],
            'branch' => [
                new RecordField('conditions', 'Conditions', codec: RecordFieldCodec::CONDITIONS),
                // The arms become frames; see describeSubEntryFields.
            ],
        ];
    }
}
