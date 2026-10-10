<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Field\PlayerPresentationFields;

use Closure;
use LogicException;

use Ichiloto\Editor\ActorStatPreview;
use Ichiloto\Editor\Database\Projections\BattlerBindingProjection;
use Ichiloto\Editor\Database\Projections\KeyedListProjection;
use Ichiloto\Editor\Database\Projections\KnowledgeEnemyMappingProjection;
use Ichiloto\Editor\Database\Projections\KnowledgeRecordTypeProjection;
use Ichiloto\Editor\Database\Projections\KnowledgeReportProjection;
use Ichiloto\Editor\Database\Projections\OptimizationExclusionProjection;
use Ichiloto\Editor\Database\Projections\OptimizationOutcomeProjection;
use Ichiloto\Editor\Database\Projections\OptimizationWeightProjection;
use Ichiloto\Editor\EquipmentOptimizationPolicy;
use Ichiloto\Editor\ProjectConfig;
use Ichiloto\Editor\Field\ProjectNpc;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Editor\PermanentGrowthCatalog;
use Ichiloto\Engine\Entities\States\StateDisposition;
use Ichiloto\Engine\Scenes\Arena\ProjectBattleTest;
use Ichiloto\Engine\Animations\AnimationTargetPosition;
use Ichiloto\Editor\Events\ProjectScriptCommands;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandDefinition;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandField;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandFieldKind;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandReference;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandRegistry;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Enemies\EnemyCatalog;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SkillRecord;
use Ichiloto\Engine\Entities\Skills\SkillResolutionScope;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Rendering\Tilesets\TilesetPiece;
use Ichiloto\Engine\Rendering\Tilesets\TilesetSheet;
use Ichiloto\Engine\Quests\QuestObjectiveType;
use Ichiloto\Engine\Battle\Enumerations\BattleEngineType;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlerBindings;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Editor\Database\Projections\WholeFileProjection;
use Ichiloto\Engine\Entities\Enumerations\ActionConditionType;
use Ichiloto\Engine\Entities\Enumerations\ItemUserType;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeProgressService;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\Inventory\ItemCatalog;
use Ichiloto\Engine\Entities\Inventory\ItemRecord;
use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Inventory\EquipmentSlotType;
use Ichiloto\Engine\Entities\Enumerations\ArmorType;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Character;

/**
 * The schemas behind the Database categories added in Phase 6.
 *
 * Whether a category can be *written* is not declared here — it is detected
 * when the file loads (see `PhpDataFile`). What these schemas declare is
 * where records live, what an entry is called, and which fields the settings
 * pane shows. Categories whose authored files are PHP constructor calls
 * (`items.php`) still get real field lists, because browsing an item is
 * useful even when the editor refuses to rewrite it.
 */
final class RecordSchemaCatalog
{
    /**
     * The event-script command types built into the engine's interpreter.
     *
     * Imported from the runtime so the editor cannot drift into a duplicate
     * command vocabulary. Registered commands join them through
     * getEventCommandTypes().
     */
    public const array EVENT_COMMAND_TYPES = EventInterpreter::COMMAND_TYPES;

    /**
     * The stats a class grows by a curve, in `ClassStore`'s order, with the
     * abbreviations their rows are labelled by.
     */
    private const array CLASS_CURVE_STATS = [
        'totalHp' => 'HP', 'totalMp' => 'MP', 'attack' => 'ATK', 'defence' => 'DEF', 'magicAttack' => 'MAT',
        'magicDefence' => 'MDF', 'speed' => 'SPD', 'grace' => 'GRC', 'evasion' => 'EVA',
    ];

    /**
     * Returns every schema-driven category, keyed by Database category key.
     *
     * @return array<string, RecordSchema>
     */
    public static function all(bool $graphical = false): array
    {
        $schemas = [
            self::classes(),
            self::quests(),
            self::system(),
            self::states(),
            self::troops(),
            self::animations(),
            self::battleEntryRules(),
            self::items(),
            self::weapons(),
            self::armors(),
            self::enemies(),
            self::skills(),
            self::skits(),
            self::knowledgeSubjects(),
            self::knowledgeReports(),
            self::knowledgeRecordTypes(),
            self::knowledgeEnemyMappings(),
            self::permanentGrowth(),
            self::optimizeWeights(),
            self::optimizeOutcomes(),
            self::optimizeExclusions(),
            self::commonEvents($graphical),
            self::terms(),
            self::configuration($graphical),
            self::types(),
            self::tilesets($graphical),
            self::battlerArt('actors'),
            self::battlerArt('enemies'),
            self::battleScaleReference(),
            ...($graphical ? [PlayerPresentationFields::getSchema(), \Ichiloto\Editor\Field\FieldResourceFields::getSchema()] : []),
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
                // Whether it harms, enhances or is neutral to its bearer; battle poses follow it.
                new RecordField('disposition', 'Disposition',
                    options: array_map(static fn(StateDisposition $disposition): string => $disposition->value, StateDisposition::cases()),
                    removeWhenEmpty: true, displayDefault: StateDisposition::HARMFUL->value),
                // A bearer of this state counters while it is active.
                self::counterAttackField(),
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
    /**
     * Animations -- `assets/Data/animations.php`, the list the Engine's
     * AnimationLibrary reads. A record names the effect timelines it plays on
     * the caster and the target, and the battle roles it plays for; a role
     * plays one animation, so taking one another record holds is refused.
     * An older record's own frames and cues are kept exactly as written and
     * shown read-only beside the position they play at: the Engine still
     * plays them through its importer, and new animation is authored as
     * effect timelines.
     */
    private static function animations(): RecordSchema
    {
        $fields = [
            new RecordField('id', 'Id', InputControlType::INTEGER, isReadOnly: true),
            new RecordField('name', 'Name'),
            RecordField::reference('sourceEffect', 'Caster Effect', 'effects', allowsNone: true, noneLabel: '(none)'),
            RecordField::reference('targetEffect', 'Target Effect', 'effects', allowsNone: true, noneLabel: '(none)'),
            new RecordField('roles', 'Roles', codec: RecordFieldCodec::CSV_LIST, removeWhenEmpty: true,
                reference: 'animation_roles', uniqueAcrossRecords: true),
        ];
        $legacy = [
            new RecordField('position', 'Legacy Position',
                options: array_map(static fn(AnimationTargetPosition $position): string => $position->value, AnimationTargetPosition::cases()),
                removeWhenEmpty: true),
            new RecordField('maxFrames', 'Legacy Frame Count', InputControlType::INTEGER, isReadOnly: true),
            new RecordField('frames', 'Legacy Frames', isReadOnly: true),
            new RecordField('cues', 'Legacy Cues', isReadOnly: true),
        ];

        return new RecordSchema(
            key: 'animations',
            entryNoun: 'animation',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/Data/animations.php',
            fields: $fields,
            labelKey: 'name',
            identityKey: 'id',
            // A numeric identity: a new animation takes the next free number.
            blank: ['id' => 1, 'name' => 'New Animation'],
            fieldsFor: static fn(array $row): array => array_key_exists('frames', $row) || array_key_exists('cues', $row)
                ? [...$fields, ...$legacy] : $fields,
        );
    }

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
                // The engine's battle-entry rules match a troop's explicit
                // classification; omission remains ordinary, so browsing
                // never forces the key into older data.
                new RecordField(
                    'classification',
                    'Classification',
                    options: ['ordinary', 'boss'],
                    removeWhenEmpty: true,
                    displayDefault: 'ordinary',
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
                    // The graphical battle's own placement: the point its feet stand on
                    // and its contain limits, never the terminal position above.
                    new RecordField('graphicalPlacement', 'Battle Placement', codec: RecordFieldCodec::BATTLER_SLOT, removeWhenEmpty: true),
                ],
                blank: ['enemy' => 'Regular Bat', 'position' => [15, 7]],
            ),
        );
    }

    /**
     * Battle-entry rules — `assets/Data/battle-entry-rules.php`, the file the
     * engine's `BattleEntryRuleCatalog` hydrates. Fully editable.
     *
     * The file returns a `rules` list. Each ordered rule carries a required
     * unique stable id, an optional integer priority (the runtime defaults
     * to 0), an optional `ordinary`/`boss` classification (the runtime
     * defaults to ordinary), required non-empty actor predicates, optional
     * shared world conditions, required non-empty typed effects, and
     * optional durable writes the runtime restricts to its reversible
     * transactional vocabulary. Rules run in priority then declaration
     * order; this surface authors the schema and never applies it.
     *
     * @return RecordSchema
     */
    private static function battleEntryRules(): RecordSchema
    {
        return new RecordSchema(
            key: 'battle_entry_rules',
            entryNoun: 'battle entry rule',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/Data/battle-entry-rules.php',
            fields: [
                new RecordField('id', 'Id'),
                new RecordField(
                    'priority',
                    'Priority',
                    InputControlType::INTEGER,
                    removeWhenEmpty: true,
                    displayDefault: '0',
                ),
                new RecordField(
                    'classification',
                    'Classification',
                    options: ['ordinary', 'boss'],
                    removeWhenEmpty: true,
                    displayDefault: 'ordinary',
                ),
                // Required and non-empty at runtime: the key never drops, so
                // an emptied list stays visible and fails validation instead
                // of vanishing.
                new RecordField('actors', 'Actor Predicates', codec: RecordFieldCodec::ACTOR_PREDICATES),
                new RecordField('conditions', 'Conditions', removeWhenEmpty: true, codec: RecordFieldCodec::CONDITIONS),
                new RecordField(
                    'writes',
                    'World Writes',
                    removeWhenEmpty: true,
                    codec: RecordFieldCodec::WORLD_WRITES,
                    writeTypes: ['switch', 'event', 'variable'],
                ),
            ],
            labelKey: 'id',
            identityKey: 'id',
            blank: [
                'id' => 'new-rule',
                // Both required lists start visibly incomplete: validation
                // names them until the author fills them in, which beats a
                // half-authored placeholder that reads as finished.
                'actors' => [],
                'effects' => [
                    ['type' => 'stat_stage', 'actor' => '', 'stat' => 'attack', 'delta' => 1],
                ],
            ],
            projection: new KeyedListProjection('rules'),
            subList: new RecordSubList(
                key: 'effects',
                prefix: 'effect',
                singular: 'effect',
                fields: [
                    new RecordField('type', 'Type', options: ['stat_stage']),
                    RecordField::reference('actor', 'Actor', 'actor_ids'),
                    new RecordField('stat', 'Stat', options: Character::buffableStats()),
                    new RecordField('delta', 'Delta', InputControlType::INTEGER),
                ],
                blank: ['type' => 'stat_stage', 'actor' => '', 'stat' => 'attack', 'delta' => 1],
            ),
        );
    }

    /**
     * Consumables and key items: one record per numbered file under
     * `assets/Data/Items`, in the form the Engine's ItemRecord reads, with
     * whom an item reaches, when, its effects and its animation.
     *
     * @return RecordSchema
     */
    private static function items(): RecordSchema
    {
        $enumValues = static fn(array $cases): array => array_map(static fn(\BackedEnum $case): string => strval($case->value), $cases);

        return new RecordSchema(
            key: 'items',
            entryNoun: 'item',
            storage: RecordStorage::DIRECTORY,
            relativePath: 'assets/Data/' . ItemCatalog::DIRECTORIES[0],
            fields: [
                ...self::inventoryFields(),
                new RecordField('scope.side', 'Scope Side', options: $enumValues(ItemScopeSide::cases()), removeWhenEmpty: true, displayDefault: ItemScopeSide::NONE->value),
                new RecordField('scope.number', 'Scope Number', options: $enumValues(ItemScopeNumber::cases()), removeWhenEmpty: true, displayDefault: ItemScopeNumber::ONE->value),
                new RecordField('scope.status', 'Scope Status', options: $enumValues(ItemScopeStatus::cases()), removeWhenEmpty: true, displayDefault: ItemScopeStatus::ALIVE->value),
                new RecordField('scope.randomNumber', 'Random Targets', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '1'),
                new RecordField('occasion', 'Occasion', options: $enumValues(Occasion::cases()), removeWhenEmpty: true, displayDefault: Occasion::ALWAYS->value),
                new RecordField(
                    'animationId',
                    'Animation',
                    InputControlType::INTEGER,
                    reference: 'animation_ids',
                    removeWhenEmpty: true,
                    allowsNone: true,
                    displayDefault: '(None)',
                ),
            ],
            labelKey: 'name',
            identityKey: 'id',
            subList: new RecordSubList(
                key: 'effects',
                prefix: 'effect',
                singular: 'effect',
                fields: [
                    new RecordField('type', 'Type', options: array_keys(ItemRecord::EFFECTS)),
                    new RecordField('name', 'Name'),
                    new RecordField('description', 'Description'),
                    new RecordField('value', 'Value', InputControlType::INTEGER),
                    new RecordField('successRate', 'Success Rate', InputControlType::FLOAT),
                    new RecordField('valueBasis', 'Value Basis', options: $enumValues(ValueBasis::cases()), removeWhenEmpty: true, displayDefault: ValueBasis::ACTUAL->value),
                ],
                blank: ['type' => 'hp_recovery', 'name' => 'Recover HP', 'description' => 'Recovers HP', 'value' => 50, 'successRate' => 1.0],
                removeWhenEmpty: true,
            ),
            makeBlank: static fn(string $name): array => [
                'kind' => 'item',
                'id' => self::inventoryDefinitionId('item', $name),
                'name' => $name,
                'description' => 'What it does.',
                'icon' => '✨',
                'price' => 0,
            ],
            recordClass: InventoryItem::class,
            numberedFiles: true,
        );
    }

    /**
     * Weapons: one record per numbered file under `assets/Data/Weapons`.
     *
     * @return RecordSchema
     */
    private static function weapons(): RecordSchema
    {
        return new RecordSchema(
            key: 'weapons',
            entryNoun: 'weapon',
            storage: RecordStorage::DIRECTORY,
            relativePath: 'assets/Data/' . ItemCatalog::DIRECTORIES[1],
            fields: self::equipmentFields(EquipmentSlotType::WEAPON, [
                new RecordField(
                    'equipmentType',
                    'Equipment Type',
                    options: array_map(static fn(WeaponType $type): string => $type->value, WeaponType::cases()),
                    removeWhenEmpty: true,
                ),
                ...self::parameterChangeFields(),
            ]),
            labelKey: 'name',
            identityKey: 'id',
            makeBlank: static fn(string $name): array => [
                'kind' => 'weapon',
                'id' => self::inventoryDefinitionId('equipment', $name),
                'name' => $name,
                'description' => 'What it does.',
                'icon' => '🗡',
                'price' => 0,
                'equipmentType' => WeaponType::SWORD->value,
                'parameterChanges' => ['attack' => 1],
            ],
            recordClass: InventoryItem::class,
            numberedFiles: true,
        );
    }

    /**
     * Armors and accessories: one record per numbered file under
     * `assets/Data/Armors`. An accessory has no equipment type.
     *
     * @return RecordSchema
     */
    private static function armors(): RecordSchema
    {
        $kind = new RecordField('kind', 'Kind', options: ['armor', 'accessory']);
        $type = new RecordField(
            'equipmentType',
            'Equipment Type',
            options: array_map(static fn(ArmorType $type): string => $type->value, ArmorType::cases()),
            removeWhenEmpty: true,
        );
        $fields = static fn(bool $isAccessory): array => [
            $kind,
            ...self::equipmentFields($isAccessory ? EquipmentSlotType::ACCESSORY : EquipmentSlotType::BODY, [
                ...($isAccessory ? [] : [$type]),
                ...self::parameterChangeFields(),
            ]),
        ];

        return new RecordSchema(
            key: 'armors',
            entryNoun: 'armor',
            storage: RecordStorage::DIRECTORY,
            relativePath: 'assets/Data/' . ItemCatalog::DIRECTORIES[2],
            fields: $fields(false),
            labelKey: 'name',
            identityKey: 'id',
            makeBlank: static fn(string $name): array => [
                'kind' => 'armor',
                'id' => self::inventoryDefinitionId('equipment', $name),
                'name' => $name,
                'description' => 'What it protects against.',
                'icon' => '🛡',
                'price' => 0,
                'equipmentType' => ArmorType::GENERAL_ARMOR->value,
                'parameterChanges' => ['defence' => 1],
            ],
            fieldsFor: static fn(array $row): array => $fields(strval($row['kind'] ?? 'armor') === 'accessory'),
            recordClass: InventoryItem::class,
            numberedFiles: true,
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
            projection: new KeyedListProjection('subjects'),
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
            // A disagreement names another report, so it is picked from the
            // ones the project has rather than spelled into a list.
            subList: new RecordSubList(
                key: 'disagreesWith',
                prefix: 'disagreement',
                singular: 'disagreement',
                fields: [
                    RecordField::reference('report', 'Disagrees With', 'knowledge_reports'),
                ],
                blank: ['report' => ''],
            ),
            projection: new KnowledgeReportProjection(),
        );
    }

    /**
     * Knowledge record types — the `recordTypes` list of the catalogue.
     *
     * The kinds of record a project declares, and the only kinds a subject
     * can be.
     *
     * @return RecordSchema
     */
    private static function knowledgeRecordTypes(): RecordSchema
    {
        return new RecordSchema(
            key: 'knowledge_record_types',
            entryNoun: 'record type',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/Data/knowledge.php',
            fields: [new RecordField('type', 'Record Type')],
            labelKey: 'type',
            identityKey: 'type',
            blank: ['type' => 'new-record-type'],
            projection: new KnowledgeRecordTypeProjection(),
        );
    }

    /**
     * Knowledge enemy mappings — the `enemyMappings` map of the catalogue.
     *
     * Which subject an enemy is a record of, for the subjects that are
     * fought. Both sides are picked.
     *
     * @return RecordSchema
     */
    private static function knowledgeEnemyMappings(): RecordSchema
    {
        return new RecordSchema(
            key: 'knowledge_enemy_mappings',
            entryNoun: 'enemy mapping',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/Data/knowledge.php',
            fields: [
                RecordField::reference('enemy', 'Enemy', 'enemies'),
                RecordField::reference('subject', 'Knowledge Subject', 'knowledge_subjects'),
            ],
            identityKey: null,
            // The runtime refuses a mapping whose subject is not a stable
            // id, so a new one starts as a placeholder pair the catalogue
            // still loads and validation immediately asks to be filled in,
            // rather than as blanks that could not be written at all.
            blank: ['enemy' => 'New Enemy', 'subject' => 'subject.unassigned'],
            projection: new KnowledgeEnemyMappingProjection(),
            labelFor: static fn(array $row): string => sprintf(
                '%s · %s',
                trim(strval($row['enemy'] ?? '')) ?: '(no enemy)',
                trim(strval($row['subject'] ?? '')) ?: '(no subject)',
            ),
        );
    }

    /**
     * Permanent growth — `assets/Data/permanent-growth.php`.
     *
     * The reusable definitions a permanent stat increase is granted from. The
     * increases a party has actually *earned* live in a save's
     * `PermanentGrowthLedger`, and are not authored here: at this engine head
     * only runtime API grants growth, so a project defines what a grant would
     * be and the game decides when one happens.
     *
     * @return RecordSchema
     */
    private static function permanentGrowth(): RecordSchema
    {
        return new RecordSchema(
            key: 'permanent_growth',
            entryNoun: 'permanent growth definition',
            storage: RecordStorage::LIST_FILE,
            relativePath: PermanentGrowthCatalog::RELATIVE_PATH,
            fields: [
                new RecordField('id', 'Id'),
                // A project's own name for it, kept in the metadata the
                // engine reserves for exactly that: the runtime compares
                // definitions by their contract, so a label is never part of
                // what makes two grants the same.
                new RecordField('metadata.label', 'Label', removeWhenEmpty: true),
                new RecordField(
                    'stat',
                    'Stat',
                    options: ActorStatPreview::statKeys(),
                ),
                // Growth can be a loss. The runtime stores a signed integer
                // and adds it, so a curse is the same contract as a blessing.
                new RecordField('amount', 'Amount', InputControlType::INTEGER),
                // Provenance is deliberately generic in the runtime: it does
                // not know what kinds of thing a project grants growth from,
                // and neither does the editor.
                new RecordField('sourceType', 'Source Type', displayDefault: 'what kind of thing granted it'),
                new RecordField('sourceId', 'Source Id', displayDefault: 'which one of them'),
                new RecordField('metadata.note', 'Note', removeWhenEmpty: true),
                // The runtime carries whatever metadata a project puts on a
                // grant, so label and note are conveniences over a surface
                // that can hold the rest of it.
                new RecordField(
                    'metadata',
                    'Metadata',
                    codec: RecordFieldCodec::KEY_VALUES,
                    removeWhenEmpty: true,
                    displayDefault: 'name=value, name=value',
                ),
            ],
            labelKey: 'metadata.label',
            identityKey: 'id',
            blank: [
                'id' => 'growth.new-growth',
                'stat' => 'maxHp',
                'amount' => 1,
                'sourceType' => 'event',
                'sourceId' => '',
                'metadata' => ['label' => 'New Permanent Growth'],
            ],
        );
    }

    /**
     * Optimize weights — the four weight scopes of the policy file.
     *
     * @return RecordSchema
     */
    private static function optimizeWeights(): RecordSchema
    {
        $weights = array_map(
            static fn(string $key): RecordField => new RecordField(
                'weights.' . $key,
                '  ' . $key,
                InputControlType::INTEGER,
                removeWhenEmpty: true,
                displayDefault: 'not weighed',
            ),
            EquipmentOptimizationPolicy::weightKeys(),
        );
        $scope = new RecordField('scope', 'Scope', options: OptimizationWeightProjection::SCOPES);
        $role = RecordField::reference('role', 'Role', 'classes');
        $slot = new RecordField('slot', 'Slot', options: EquipmentOptimizationPolicy::slotKeys());

        return new RecordSchema(
            key: 'optimize_weights',
            entryNoun: 'weight vector',
            storage: RecordStorage::LIST_FILE,
            relativePath: EquipmentOptimizationPolicy::RELATIVE_PATH,
            fields: [$scope, $role, $slot, ...$weights],
            identityKey: null,
            blank: ['scope' => 'base', 'weights' => []],
            projection: new OptimizationWeightProjection(),
            // A vector narrowed to a role does not ask which slot, and the
            // base vector asks neither.
            fieldsFor: static fn(array $row): array => match (strval($row['scope'] ?? 'base')) {
                'role' => [$scope, $role, ...$weights],
                'slot' => [$scope, $slot, ...$weights],
                'role+slot' => [$scope, $role, $slot, ...$weights],
                default => [$scope, ...$weights],
            },
            labelFor: static function (array $row): string {
                $narrowed = array_values(array_filter([
                    trim(strval($row['role'] ?? '')),
                    trim(strval($row['slot'] ?? '')),
                ], static fn(string $part): bool => $part !== ''));

                return match (strval($row['scope'] ?? 'base')) {
                    'role', 'slot', 'role+slot' => implode(' · ', $narrowed) ?: 'Unnarrowed',
                    default => 'Every role and slot',
                };
            },
        );
    }

    /**
     * Optimize outcomes — what an element or a special property is worth.
     *
     * @return RecordSchema
     */
    private static function optimizeOutcomes(): RecordSchema
    {
        $kind = new RecordField('kind', 'Kind', options: OptimizationOutcomeProjection::KINDS);
        $element = RecordField::reference('element', 'Element', 'elements_or_any');
        $outcome = new RecordField('outcome', 'Outcome', options: EquipmentOptimizationPolicy::OUTCOMES);
        $property = RecordField::reference('property', 'Special Property', 'equipment_special_properties');
        $weight = new RecordField('weight', 'Weight', InputControlType::INTEGER);
        // A name the runtime's own lookups can never compose is shown rather
        // than dropped: the file belongs to the project.
        $name = new RecordField('name', 'Authored Name', isReadOnly: true);

        return new RecordSchema(
            key: 'optimize_outcomes',
            entryNoun: 'outcome weight',
            storage: RecordStorage::LIST_FILE,
            relativePath: EquipmentOptimizationPolicy::RELATIVE_PATH,
            fields: [$kind, $element, $outcome, $property, $weight],
            identityKey: null,
            // A new weight starts at the wildcard, which composes a name the
            // runtime really looks up whatever elements the project has, so
            // an author who has not chosen yet still has a record to keep.
            blank: [
                'kind' => 'defence',
                'element' => EquipmentOptimizationPolicy::ANY_ELEMENT,
                'outcome' => 'resist',
                'weight' => 0,
            ],
            projection: new OptimizationOutcomeProjection(),
            fieldsFor: static fn(array $row): array => match (strval($row['kind'] ?? 'other')) {
                'offence' => [$kind, $element, $weight],
                'defence' => [$kind, $element, $outcome, $weight],
                'special' => [$kind, $property, $weight],
                default => [$kind, $name, $weight],
            },
            labelFor: static function (array $row): string {
                $element = trim(strval($row['element'] ?? ''));
                $element = $element === EquipmentOptimizationPolicy::ANY_ELEMENT ? 'any element' : $element;

                return match (strval($row['kind'] ?? 'other')) {
                    'offence' => sprintf('Dealing %s', $element ?: '?'),
                    'defence' => sprintf('%s to %s', ucfirst(trim(strval($row['outcome'] ?? '?'))), $element ?: '?'),
                    'special' => trim(strval($row['property'] ?? '')) ?: '(no property)',
                    default => trim(strval($row['name'] ?? '')) ?: '(unnamed)',
                };
            },
        );
    }

    /**
     * Optimize exclusions — what automatic selection may not take.
     *
     * @return RecordSchema
     */
    private static function optimizeExclusions(): RecordSchema
    {
        $kind = new RecordField('kind', 'Kind', options: array_keys(OptimizationExclusionProjection::KEYS));
        $references = [
            'definition' => RecordField::reference('value', 'Item', 'inventory'),
            'availability' => RecordField::reference('value', 'Availability', 'equipment_availabilities'),
            'acquisition' => RecordField::reference('value', 'Acquisition Policy', 'equipment_acquisition_policies'),
        ];

        return new RecordSchema(
            key: 'optimize_exclusions',
            entryNoun: 'exclusion',
            storage: RecordStorage::LIST_FILE,
            relativePath: EquipmentOptimizationPolicy::RELATIVE_PATH,
            fields: [$kind, ...array_values($references)],
            identityKey: null,
            blank: ['kind' => 'definition', 'value' => ''],
            projection: new OptimizationExclusionProjection(),
            // One exclusion excludes one thing, and what it is picked from
            // depends on which kind of thing that is.
            fieldsFor: static fn(array $row): array => [
                $kind,
                $references[strval($row['kind'] ?? '')] ?? $references['definition'],
            ],
            labelFor: static fn(array $row): string => sprintf(
                '%s · %s',
                trim(strval($row['value'] ?? '')) ?: '(nothing)',
                strval($row['kind'] ?? ''),
            ),
        );
    }

    /**
     * System — `assets/Data/system.php`, one map the engine reads at start:
     * the title, starting gold, party, inventory and position, and battle
     * settings. Its elements are the Types category's. The file is the category's one record,
     * only ever edited. The starting party and inventory are lists of their
     * own; a party member is a bare actor id, as the file authors it.
     *
     * @return RecordSchema
     */
    private static function system(): RecordSchema
    {
        return new RecordSchema(
            key: 'system',
            entryNoun: 'system settings',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/Data/system.php',
            fields: [
                new RecordField('title', 'Title'),
                new RecordField('currency.amount', 'Starting Gold', InputControlType::INTEGER),
                RecordField::reference('startingPositions.player.destinationMap', 'Start Map', 'maps'),
                new RecordField('startingPositions.player.spawnPoint.x', 'Start X', InputControlType::INTEGER),
                new RecordField('startingPositions.player.spawnPoint.y', 'Start Y', InputControlType::INTEGER),
                new RecordField(
                    'startingPositions.player.spawnSprite.0',
                    'Start Facing',
                    options: array_map(static fn(MovementHeading $heading): string => $heading->value, MovementHeading::cases()),
                ),
                new RecordField(
                    'battle.engine',
                    'Battle Engine',
                    options: array_map(static fn(BattleEngineType $engine): string => $engine->value, BattleEngineType::cases()),
                ),
                new RecordField('battle.opening.preemptiveChancePercent', 'Preemptive Chance %', InputControlType::INTEGER, removeWhenEmpty: true),
                new RecordField('battle.opening.ambushChancePercent', 'Ambush Chance %', InputControlType::INTEGER, removeWhenEmpty: true),
                new RecordField('battle.activeTime.mode', 'Time Gauge Mode', options: ['wait']),
                new RecordField('battle.activeTime.baseFillRate', 'Time Gauge Fill Rate', InputControlType::INTEGER),
                new RecordField('battle.activeTime.speedFactorPercent', 'Time Gauge Speed Factor %', InputControlType::INTEGER),
                new RecordField('battle.activeTime.openingVariance', 'Time Gauge Opening Variance', InputControlType::INTEGER, removeWhenEmpty: true),
                new RecordField('battle.activeTime.openingSpeedFactorPercent', 'Time Gauge Opening Speed %', InputControlType::INTEGER, removeWhenEmpty: true),
                // Test settings, as RPG Maker keeps its Battle Test: normal play never reads them.
                new RecordField(ProjectBattleTest::SYSTEM_KEY, 'Battle Test', removeWhenEmpty: true, codec: RecordFieldCodec::BATTLE_TEST),
            ],
            labelKey: 'title',
            identityKey: null,
            // The keys System owns; its elements are the Types category's.
            projection: new WholeFileProjection(['title', 'currency', 'startingPositions', 'startingParty', 'startingInventory', 'battle', ProjectBattleTest::SYSTEM_KEY]),
            subLists: [
                new RecordSubList(
                    key: 'startingParty',
                    prefix: 'member',
                    singular: 'party member',
                    fields: [RecordField::reference('actor', 'Actor', 'actor_ids')],
                    blank: ['actor' => ''],
                    heading: 'Starting Party',
                    scalarKey: 'actor',
                ),
                new RecordSubList(
                    key: 'startingInventory',
                    prefix: 'stock',
                    singular: 'starting item',
                    fields: [
                        RecordField::reference('item', 'Item', 'inventory'),
                        new RecordField('quantity', 'Quantity', InputControlType::INTEGER),
                    ],
                    blank: ['item' => '', 'quantity' => 1],
                    heading: 'Starting Inventory',
                ),
            ],
        );
    }

    /**
     * Quests — `assets/Data/quests.php`, as the engine's `Quest::fromArray`
     * reads it. A quest's id is its name's slug and follows a rename while
     * nothing refers to it (the workspace knows what does). Objectives are
     * its own list; the target an objective is picked from follows its type.
     * Reward items are a second list, each a bare item name until it is given
     * a quantity, the two forms the engine reads.
     *
     * @return RecordSchema
     */
    private static function quests(): RecordSchema
    {
        $objectiveFields = static fn(?string $reference): array => [
            $reference === null
                ? new RecordField('target', 'Target')
                : RecordField::reference('target', 'Target', $reference),
            new RecordField('quantity', 'Quantity', InputControlType::INTEGER, removeWhenEmpty: true),
            new RecordField('description', 'Text', removeWhenEmpty: true),
            new RecordField('revealedDescription', 'Revealed', removeWhenEmpty: true),
            new RecordField('revealConditions', 'Reveal When', removeWhenEmpty: true, codec: RecordFieldCodec::CONDITIONS),
        ];
        $variants = [];

        foreach (QuestObjectiveType::cases() as $type) {
            $variants[$type->value] = $objectiveFields(match ($type) {
                // Collecting is not limited to consumables: a quest may ask
                // for a weapon or a piece of armor, and the runtime resolves
                // all three from one catalogue.
                QuestObjectiveType::COLLECT => 'inventory',
                QuestObjectiveType::DEFEAT => 'enemies',
                QuestObjectiveType::REACH_MAP => 'maps',
                QuestObjectiveType::TALK_TO => 'actors',
                default => null,
            });
        }

        return new RecordSchema(
            key: 'quests',
            entryNoun: 'quest',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/Data/quests.php',
            fields: [
                new RecordField('id', 'Id', isReadOnly: true),
                new RecordField('name', 'Name'),
                new RecordField('description', 'Description'),
                new RecordField('giver', 'Giver'),
                RecordField::boolean('optional', 'Optional'),
                new RecordField('rewards.gold', 'Reward Gold', InputControlType::INTEGER, removeWhenEmpty: true),
                new RecordField('rewards.experience', 'Reward EXP', InputControlType::INTEGER, removeWhenEmpty: true),
                new RecordField('prerequisites', 'Prereqs', removeWhenEmpty: true, codec: RecordFieldCodec::CONDITIONS),
            ],
            labelKey: 'name',
            identityKey: 'id',
            blank: [
                'id' => 'new-quest',
                'name' => 'New Quest',
                'description' => '',
                'objectives' => [['type' => QuestObjectiveType::TALK_TO->value, 'target' => 'New Target']],
            ],
            subList: new RecordSubList(
                key: 'objectives',
                prefix: 'objective',
                singular: 'objective',
                fields: [
                    new RecordField(
                        'type',
                        'Type',
                        options: array_map(static fn(QuestObjectiveType $type): string => $type->value, QuestObjectiveType::cases()),
                    ),
                ],
                blank: ['type' => QuestObjectiveType::TALK_TO->value, 'target' => 'New Target'],
                variants: $variants,
                variantKey: 'type',
            ),
            subLists: [
                new RecordSubList(
                    key: 'rewards.items',
                    prefix: 'reward',
                    singular: 'reward item',
                    fields: [
                        RecordField::reference('item', 'Item', 'inventory'),
                        new RecordField('quantity', 'Quantity', InputControlType::INTEGER, removeWhenEmpty: true),
                    ],
                    blank: ['item' => ''],
                    heading: 'Reward Items',
                    scalarKey: 'item',
                    removeWhenEmpty: true,
                ),
            ],
            identityFollowsLabel: true,
        );
    }

    /**
     * Classes — `assets/Data/classes.php`, the plain data the engine's
     * `ClassStore` reads: growth curves, equipment types, skills learned by
     * level. A class is addressed by its numeric id; new ones take the next.
     *
     * @return RecordSchema
     */
    private static function classes(): RecordSchema
    {
        $curves = [];

        foreach (self::CLASS_CURVE_STATS as $stat => $label) {
            $curves[] = new RecordField("parameterCurves.{$stat}.baseValue", "{$label} Base", InputControlType::INTEGER);
            $curves[] = new RecordField("parameterCurves.{$stat}.extraGrowth", "{$label} Growth", InputControlType::INTEGER);
            $curves[] = new RecordField("parameterCurves.{$stat}.flatIncrement", "{$label} Per Level", InputControlType::INTEGER);
        }

        return new RecordSchema(
            key: 'classes',
            entryNoun: 'class',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/Data/classes.php',
            fields: [
                new RecordField('id', 'Id', InputControlType::INTEGER, isReadOnly: true),
                new RecordField('name', 'Name', uniqueAcrossRecords: true),
                new RecordField('description', 'Description'),
                new RecordField('note', 'Note'),
                new RecordField('initialLevel', 'Initial Level', InputControlType::INTEGER),
                new RecordField('maxLevel', 'Max Level', InputControlType::INTEGER),
                new RecordField('equipment.weapons', 'Weapon Types', codec: RecordFieldCodec::CSV_LIST, reference: 'weapon_types'),
                new RecordField('equipment.armor', 'Armor Types', codec: RecordFieldCodec::CSV_LIST, reference: 'armor_types'),
                new RecordField('experienceCurve.baseValue', 'EXP Base', InputControlType::INTEGER),
                new RecordField('experienceCurve.extraValue', 'EXP Extra', InputControlType::INTEGER),
                new RecordField('experienceCurve.accelerationA', 'EXP Accel A', InputControlType::INTEGER),
                new RecordField('experienceCurve.accelerationB', 'EXP Accel B', InputControlType::INTEGER),
                ...$curves,
            ],
            labelKey: 'name',
            identityKey: 'id',
            blank: [
                'id' => 1,
                'name' => 'New Class',
                'description' => '',
                'initialLevel' => 1,
                'maxLevel' => 99,
                'equipment' => ['weapons' => [], 'armor' => []],
                'skillsToLearn' => [],
                'experienceCurve' => ['baseValue' => 30, 'extraValue' => 20, 'accelerationA' => 30, 'accelerationB' => 30],
                'parameterCurves' => [
                    'totalHp' => ['baseValue' => 120, 'extraGrowth' => 500, 'flatIncrement' => 40],
                    'totalMp' => ['baseValue' => 12, 'extraGrowth' => 100, 'flatIncrement' => 10],
                    'attack' => ['baseValue' => 10, 'extraGrowth' => 50, 'flatIncrement' => 1],
                    'defence' => ['baseValue' => 10, 'extraGrowth' => 30, 'flatIncrement' => 1],
                    'magicAttack' => ['baseValue' => 10, 'extraGrowth' => 50, 'flatIncrement' => 1],
                    'magicDefence' => ['baseValue' => 10, 'extraGrowth' => 30, 'flatIncrement' => 1],
                    'speed' => ['baseValue' => 10, 'extraGrowth' => 20, 'flatIncrement' => 1],
                    'grace' => ['baseValue' => 10, 'extraGrowth' => 15, 'flatIncrement' => 1],
                    'evasion' => ['baseValue' => 5, 'extraGrowth' => 10, 'flatIncrement' => 1],
                ],
            ],
            subList: new RecordSubList(
                key: 'skillsToLearn',
                prefix: 'learn',
                singular: 'skill to learn',
                fields: [
                    new RecordField('level', 'Level', InputControlType::INTEGER),
                    RecordField::reference('skill', 'Skill', 'skills'),
                    new RecordField('note', 'Note', removeWhenEmpty: true),
                ],
                blank: ['level' => 2, 'skill' => ''],
            ),
        );
    }

    /**
     * Enemies — one record per file under `assets/Data/Enemies`, the form the
     * engine's `EnemyRecord` reads. Each file returns
     * `['class' => Enemy::class, 'data' => [...]]`; `enemies.php` is the
     * barrel that loads them.
     *
     * Action patterns name skills in the project's skill catalogue, so a
     * pattern is edited by picking the skill rather than rebuilding it.
     *
     * @return RecordSchema
     */
    private static function enemies(): RecordSchema
    {
        return new RecordSchema(
            key: 'enemies',
            entryNoun: 'enemy',
            storage: RecordStorage::DIRECTORY,
            relativePath: 'assets/Data/' . EnemyCatalog::DIRECTORY,
            fields: [
                new RecordField('name', 'Name', uniqueAcrossRecords: true),
                new RecordField('level', 'Level', InputControlType::INTEGER),
                RecordField::reference('imagePath', 'Sprite', 'enemy_sprites'),
                new RecordField('stats.maxHp', 'Max HP', InputControlType::INTEGER),
                new RecordField('stats.maxMp', 'Max MP', InputControlType::INTEGER),
                new RecordField('stats.attack', 'Attack', InputControlType::INTEGER),
                new RecordField('stats.defence', 'Defence', InputControlType::INTEGER),
                new RecordField('stats.magicAttack', 'Magic Attack', InputControlType::INTEGER),
                new RecordField('stats.magicDefence', 'Magic Defence', InputControlType::INTEGER),
                new RecordField('stats.speed', 'Speed', InputControlType::INTEGER),
                new RecordField('stats.grace', 'Grace', InputControlType::INTEGER),
                new RecordField('stats.evasion', 'Evasion', InputControlType::INTEGER),
                new RecordField('rewards.experience', 'Reward EXP', InputControlType::INTEGER),
                new RecordField('rewards.gold', 'Reward Gold', InputControlType::INTEGER),
                new RecordField('elementAffinities', 'Element Affinities', removeWhenEmpty: true, codec: RecordFieldCodec::AFFINITIES),
                new RecordField('stateResistances', 'State Resistances', removeWhenEmpty: true, codec: RecordFieldCodec::KEY_VALUES),
                RecordField::reference('knowledgeSubjectId', 'Knowledge Subject', 'knowledge_subjects', allowsNone: true),
                self::counterAttackField(),
            ],
            labelKey: 'name',
            identityKey: 'name',
            subList: new RecordSubList(
                key: 'actionPatterns',
                prefix: 'pattern',
                singular: 'action pattern',
                fields: [
                    RecordField::reference('skill', 'Skill', 'skills'),
                    new RecordField('rating', 'Rating', InputControlType::INTEGER),
                    new RecordField(
                        'condition.type',
                        'Condition',
                        options: array_map(static fn(ActionConditionType $type): string => $type->value, ActionConditionType::cases()),
                        removeWhenEmpty: true,
                        displayDefault: ActionConditionType::ALWAYS->value,
                    ),
                    new RecordField('condition.range', 'Range (min, max)', removeWhenEmpty: true, codec: RecordFieldCodec::POINT),
                    new RecordField('condition.a', 'Condition A', InputControlType::INTEGER, removeWhenEmpty: true),
                    new RecordField('condition.b', 'Condition B', InputControlType::INTEGER, removeWhenEmpty: true),
                ],
                blank: ['skill' => '', 'rating' => 5],
            ),
            subLists: [
                // What the enemy may drop when defeated, each with its chance
                // (0 to 1). The Engine resolves the item by its definition id.
                new RecordSubList(
                    key: 'rewards.items',
                    prefix: 'drop',
                    singular: 'drop',
                    fields: [
                        RecordField::reference('item', 'Item', 'inventory'),
                        new RecordField('rate', 'Drop Rate', InputControlType::FLOAT),
                    ],
                    blank: ['item' => '', 'rate' => 0.1],
                    heading: 'Drops',
                    removeWhenEmpty: true,
                ),
            ],
            // A new enemy needs a real sprite to load, so it starts on the
            // project's first one; a project with no enemy sprites cannot
            // author an enemy yet, and creation refuses, saying so, rather
            // than writing a record the engine would reject.
            makeBlank: static function (string $name, string $projectRoot): array {
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
                    throw new RecordRefusal('A new enemy starts on the project\'s first enemy sprite. Add one under assets/Graphics/Enemies first.');
                }

                return [
                    'name' => $name,
                    'level' => 1,
                    // graphics() appends the extension itself, so the stem is
                    // what an imagePath stores.
                    'imagePath' => pathinfo($sprites[0], PATHINFO_FILENAME),
                    'stats' => [
                        'maxHp' => 10, 'maxMp' => 10, 'attack' => 5, 'defence' => 5, 'magicAttack' => 5,
                        'magicDefence' => 5, 'speed' => 5, 'grace' => 1, 'evasion' => 0,
                    ],
                    'rewards' => ['experience' => 1, 'gold' => 1],
                ];
            },
            recordClass: Enemy::class,
        );
    }

    /**
     * Skills: one record per file under `assets/Data/Skills`, in the form
     * the Engine's SkillRecord reads, numbered in the order menus list them.
     * A skill's kind (an attack, an ability or a spell) is a value of the
     * record; a spell also states its effect type. Effects are a list, each
     * a `type` with that type's own values. `skills.php` is the barrel that
     * returns them.
     *
     * @return RecordSchema
     */
    private static function skills(): RecordSchema
    {
        $fields = [
            new RecordField('kind', 'Kind', options: array_keys(SkillRecord::KINDS)),
            new RecordField('name', 'Name', uniqueAcrossRecords: true),
            new RecordField('description', 'Description'),
            new RecordField('icon', 'Icon'),
            new RecordField('cost', 'Cost', InputControlType::INTEGER),
            new RecordField('cooldown', 'Cooldown', InputControlType::INTEGER),
            new RecordField('occasion', 'Occasion', options: array_map(static fn(Occasion $occasion): string => $occasion->value, Occasion::cases())),
            new RecordField('scope.side', 'Scope Side', options: array_map(static fn(ItemScopeSide $side): string => $side->value, ItemScopeSide::cases())),
            new RecordField('scope.number', 'Scope Number', options: array_map(static fn(ItemScopeNumber $number): string => $number->value, ItemScopeNumber::cases())),
            new RecordField('scope.status', 'Scope Status', options: array_map(static fn(ItemScopeStatus $status): string => $status->value, ItemScopeStatus::cases())),
            new RecordField('scope.targetCount', 'Target Count', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: 'Auto'),
            new RecordField('invocation.message', 'Invoke Text'),
            new RecordField('invocation.speed', 'Invoke Speed', InputControlType::INTEGER),
            new RecordField('invocation.accuracy', 'Accuracy', InputControlType::INTEGER),
            new RecordField('invocation.repeat', 'Repeat', InputControlType::INTEGER),
            new RecordField('invocation.apGain', 'AP Gain', InputControlType::INTEGER),
            new RecordField('invocation.hitScope', 'Hit Roll', reference: 'resolution_scopes', removeWhenEmpty: true, allowsNone: true, displayDefault: SkillResolutionScope::PER_HIT->value),
            new RecordField('invocation.criticalScope', 'Critical Roll', reference: 'resolution_scopes', removeWhenEmpty: true, allowsNone: true, displayDefault: SkillResolutionScope::PER_HIT->value),
            new RecordField(
                'animationId',
                'Animation',
                InputControlType::INTEGER,
                reference: 'animation_ids',
                removeWhenEmpty: true,
                allowsNone: true,
                displayDefault: '(Legacy fallback)',
            ),
        ];
        // Only a spell has an effect type; left out, the Engine infers it
        // from the spell's effects.
        $effectType = new RecordField('effectType', 'Effect Type', reference: 'magic_effect_types', removeWhenEmpty: true, allowsNone: true, displayDefault: '(from its effects)');
        $formula = static fn(bool $resolves): array => [
            new RecordField('formula', 'Formula'),
            new RecordField('element', 'Element', reference: 'elements', removeWhenEmpty: true, allowsNone: true, displayDefault: '(none)'),
            new RecordField('variance', 'Variance', InputControlType::FLOAT),
            new RecordField('isCriticalHit', 'Can Critical', InputControlType::BOOLEAN, removeWhenEmpty: true),
            ...($resolves ? [new RecordField('resolutionKind', 'Resolves As', reference: 'resolution_kinds', removeWhenEmpty: true, allowsNone: true, displayDefault: '(by effect)')] : []),
        ];
        $variants = [];

        foreach (array_keys(SkillRecord::FORMULA_EFFECTS) as $type) {
            $variants[$type] = $formula($type === 'hp_damage');
        }

        $variants['add_state'] = [
            RecordField::reference('stateId', 'State', 'states'),
            new RecordField('chancePercent', 'Chance %', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '100'),
        ];
        $variants['remove_state'] = [
            new RecordField('stateIds', 'States', reference: 'states', codec: RecordFieldCodec::CSV_LIST),
        ];
        $variants['modify_stat_stage'] = [
            new RecordField('stat', 'Stat', options: Character::buffableStats()),
            new RecordField('delta', 'Stages', InputControlType::INTEGER),
            new RecordField('affectsUser', 'On User', InputControlType::BOOLEAN, removeWhenEmpty: true),
        ];

        return new RecordSchema(
            key: 'skills',
            entryNoun: 'skill',
            storage: RecordStorage::DIRECTORY,
            relativePath: 'assets/Data/' . SkillCatalog::DIRECTORY,
            fields: $fields,
            labelKey: 'name',
            identityKey: 'name',
            blank: [
                'kind' => 'special',
                'name' => 'New Skill',
                'description' => '',
                'icon' => '',
                'cost' => 0,
                'cooldown' => 0,
                'occasion' => Occasion::BATTLE_SCREEN->value,
                'scope' => ['side' => ItemScopeSide::ENEMY->value, 'number' => ItemScopeNumber::ONE->value, 'status' => ItemScopeStatus::ALIVE->value],
                'invocation' => ['message' => '$1 uses $2!', 'speed' => 0, 'accuracy' => 100, 'repeat' => 1, 'apGain' => 10],
                'effects' => [],
            ],
            subList: new RecordSubList(
                key: 'effects',
                prefix: 'effect',
                singular: 'effect',
                fields: [
                    new RecordField('type', 'Type', options: [...array_keys(SkillRecord::FORMULA_EFFECTS), ...array_keys(SkillRecord::STATE_EFFECTS)]),
                ],
                blank: ['type' => 'hp_damage', 'formula' => '$user->stats->attack * 2', 'variance' => 0.2],
                variants: $variants,
                variantKey: 'type',
            ),
            // A spell has an effect type; a learned non-magic ability may grant a counter attack instead.
            fieldsFor: static fn(array $row): array => strval($row['kind'] ?? '') === 'magic' ? [...$fields, $effectType] : [...$fields, self::counterAttackField()],
            recordClass: Skill::class,
            numberedFiles: true,
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
                    ['actor' => '', 'text' => 'Say something.'],
                ],
            ],
            subList: new RecordSubList(
                key: 'beats',
                prefix: 'beat',
                singular: 'beat',
                fields: [
                    RecordField::reference('actor', 'Actor', 'actor_ids', allowsNone: true, noneLabel: '(Non-actor speaker)'),
                    new RecordField('speaker', 'Non-actor Speaker', removeWhenEmpty: true),
                    new RecordField('text', 'Text'),
                ],
                blank: ['actor' => '', 'text' => 'Say something.'],
                exclusiveFields: [['actor', 'speaker']],
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
    private static function commonEvents(bool $graphical = false): RecordSchema
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
            subList: self::eventCommandList('commands', $graphical),
            listPayloadKey: 'commands',
        );
    }

    /**
     * Configuration — the settings in the project's `config.php` that are not
     * UI vocabulary: saving, accessibility, interface, graphics, audio and
     * the inn. One row per setting, typed by what it holds (a switch, a
     * number, a choice of the enum it is authored as). A setting the engine
     * reads with a default is offered even where the file leaves it out.
     * ProjectConfig owns the file and the rules a value must meet, such as
     * the field zoom's range; Terms shares the same owner.
     *
     * @return RecordSchema
     */
    private static function configuration(bool $graphical = false): RecordSchema
    {
        return new RecordSchema(
            key: 'configuration',
            entryNoun: 'setting',
            storage: RecordStorage::CONFIG_SUBTREE,
            relativePath: 'config.php',
            fields: [
                new RecordField('path', 'Setting', isReadOnly: true),
                new RecordField('value', 'Value'),
            ],
            labelKey: 'path',
            identityKey: 'path',
            configPath: ['save', 'accessibility', 'ui', 'graphics', 'audio', 'inn'],
            subLists: $graphical ? InnPresentationFields::getBindingLists('value') : [],
            subListsFor: static fn(array $row): array => ($row['path'] ?? null) === ProjectConfig::INN_PRESENTATION
                && ($list = InnPresentationFields::getBindingList($row['value'] ?? null, 'value')) !== null ? [$list->key] : [],
            fieldsFor: static function (array $row) use ($graphical): array {
                if (($row['path'] ?? null) === ProjectConfig::INN_PRESENTATION) {
                    return [new RecordField('path', 'Setting', isReadOnly: true),
                        ...InnPresentationFields::getFields($row['value'] ?? null, 'value', 'Rest Presentation', $graphical)];
                }
                // A setting the engine knows is the type of its default, so an
                // authored value of another type is repaired on edit.
                $value = array_key_exists('default', $row) ? $row['default'] : ($row['value'] ?? null);
                $default = array_key_exists('default', $row) ? ProjectRecord::stringify($row['default']) : null;

                $reference = ProjectConfig::ENGINE_REFERENCES[strval($row['path'] ?? '')] ?? null;

                return [
                    new RecordField('path', 'Setting', isReadOnly: true),
                    match (true) {
                        // A setting naming another resource is chosen, never spelled.
                        $reference !== null => RecordField::reference('value', 'Value', $reference, allowsNone: true, noneLabel: 'None'),
                        // An enum setting is a choice of its cases by name: some
                        // enums' values (a colour's terminal code) are not text
                        // an author can read.
                        $value instanceof \UnitEnum => new RecordField(
                            'value',
                            'Value',
                            options: array_map(static fn(\UnitEnum $case): string => $case->name, $value::cases()),
                            enumClass: $value::class,
                        ),
                        is_bool($value) => RecordField::boolean('value', 'Value', removeWhenEmpty: false, displayDefault: $default),
                        is_int($value) => new RecordField('value', 'Value', InputControlType::INTEGER, displayDefault: $default),
                        is_float($value) => new RecordField('value', 'Value', InputControlType::FLOAT, displayDefault: $default),
                        default => new RecordField('value', 'Value', displayDefault: $default),
                    },
                ];
            },
        );
    }

    /**
     * UI vocabulary — the `vocab` and `messages` trees of the project's
     * `config.php`, flattened to one editable row per term.
     *
     * ProjectConfig shares these records with Configuration's settings and
     * patches literal leaves only. Unrelated comments and expressions stay
     * untouched; opaque term values are individually read-only.
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
     * Types: the project's elements, the `elements` list of
     * `assets/Data/system.php` that the Engine's element registry reads (its
     * own defaults when the list is empty). Weapon, armor and equipment
     * types are the Engine's own enums, not project data, and nothing reads
     * `assets/Data/Types`. The category shares `system.php` with System,
     * each saving only what it changed.
     *
     * @return RecordSchema
     */
    private static function types(): RecordSchema
    {
        return new RecordSchema(
            key: 'types',
            entryNoun: 'type table',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/Data/system.php',
            fields: [],
            labelKey: 'title',
            identityKey: null,
            projection: new WholeFileProjection(['elements']),
            subLists: [
                // An element is one name, as the file authors it. A list the
                // game would refuse (a name twice, an empty one) is reported
                // by validation; an empty list means the Engine's defaults.
                new RecordSubList(
                    key: 'elements',
                    prefix: 'element',
                    singular: 'element',
                    fields: [new RecordField('name', 'Element')],
                    blank: ['name' => 'New Element'],
                    heading: 'Elements',
                    scalarKey: 'name',
                    removeWhenEmpty: true,
                ),
            ],
            labelFor: static fn(array $payload): string => 'Elements',
        );
    }

    /**
     * Tilesets: one file per tileset under `assets/Data/Tilesets`, in the
     * form the Engine's Tileset reads. A map names its tileset by the file's
     * name, so the file keeps its name when the tileset is renamed. A
     * tileset names its RPG Maker sheets, the tiles drawn above characters
     * or as tables, the shadow its raised tiles cast, the tile that marks
     * missing art, and the pieces maps are built from, keyed by piece id:
     * a stamped piece's glyph rows with its tile rows on each tile layer, or
     * a connected piece's glyph and tile for each shape of a line.
     *
     * @return RecordSchema
     */
    private static function tilesets(bool $graphical = false): RecordSchema
    {
        $layer = new RecordField('layer', 'Tile Layer');
        $tilesFor = static fn(?string $valueField, RecordField $value, array $blank): RecordSubList => new RecordSubList(
            key: 'tiles',
            prefix: 'tiles',
            singular: 'tile layer',
            fields: [$layer, $value],
            blank: $blank,
            keyField: 'layer',
            valueField: $valueField,
        );

        return new RecordSchema(
            key: 'tilesets',
            entryNoun: 'tileset',
            storage: RecordStorage::DIRECTORY,
            relativePath: 'assets/' . Tileset::DIRECTORY,
            fields: [
                new RecordField('name', 'Name'),
                ...array_map(
                    static fn(TilesetSheet $sheet): RecordField => new RecordField(
                        'sheets.' . $sheet->value,
                        'Sheet ' . $sheet->value,
                        reference: 'png_assets',
                        removeWhenEmpty: true,
                        allowsNone: true,
                        displayDefault: '(none)',
                    ),
                    TilesetSheet::cases(),
                ),
                new RecordField('missingArt', 'Missing Art Tile', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '(none)'),
                new RecordField('above', 'Above Characters', codec: RecordFieldCodec::CSV_INTEGERS, removeWhenEmpty: true),
                new RecordField('tables', 'Tables', codec: RecordFieldCodec::CSV_INTEGERS, removeWhenEmpty: true),
                // A shadow is all three or none; validation names what is missing.
                new RecordField('shadows.casters', 'Shadow Casters', codec: RecordFieldCodec::CSV_TOKENS, removeWhenEmpty: true),
                new RecordField('shadows.width', 'Shadow Width', InputControlType::FLOAT, removeWhenEmpty: true),
                new RecordField('shadows.opacity', 'Shadow Opacity', InputControlType::FLOAT, removeWhenEmpty: true),
            ],
            labelKey: 'name',
            identityKey: null,
            blank: ['name' => 'New Tileset', 'sheets' => []],
            subList: new RecordSubList(
                key: 'pieces',
                prefix: 'piece',
                singular: 'piece',
                fields: [
                    new RecordField('id', 'Id'),
                    new RecordField('name', 'Name'),
                    new RecordField('layer', 'Glyph Layer'),
                    ...($graphical ? [new RecordField('occupancy', 'Physical Footprint', codec: RecordFieldCodec::PHYSICAL_FOOTPRINT,
                        removeWhenEmpty: true)] : []),
                    new RecordField(
                        'connects',
                        'Connects',
                        reference: 'piece_connections',
                        removeWhenEmpty: true,
                        allowsNone: true,
                        displayDefault: '(stamped whole)',
                    ),
                ],
                blank: ['id' => 'new-piece', 'name' => 'New piece', 'layer' => 'fixtures', 'glyphs' => ['#']],
                variants: [
                    '' => [
                        new RecordField('glyphs', 'Glyph Rows', InputControlType::MULTILINE, codec: RecordFieldCodec::LINES),
                        new RecordField('effect', 'Effect', reference: 'effects', removeWhenEmpty: true, allowsNone: true, displayDefault: '(none)'),
                    ],
                    TilesetPiece::LINES => [
                        new RecordField('glyphs.horizontal', 'Across Glyph'),
                        new RecordField('glyphs.vertical', 'Down Glyph'),
                        new RecordField('glyphs.corner', 'Corner Glyph'),
                    ],
                ],
                variantKey: 'connects',
                nestedLists: [
                    '' => $tilesFor('rows', new RecordField('rows', 'Tile Rows', InputControlType::MULTILINE, codec: RecordFieldCodec::LINES), ['layer' => 'tiles', 'rows' => ['0']]),
                    TilesetPiece::LINES => $tilesFor('tile', new RecordField('tile', 'Tile', codec: RecordFieldCodec::SHAPE_TILES), ['layer' => 'tiles', 'tile' => '0']),
                ],
                keyField: 'id',
            ),
        );
    }
    /**
     * A counter attack: the skill a battler responds with when a physical
     * hit lands on it, after the attacker returns, never chaining. It is off
     * unless chosen, and choosing none removes it, as the Engine reads an
     * omitted counterAttack. The picker offers only skills the Engine's
     * CounterAttackRule accepts.
     */
    private static function counterAttackField(): RecordField
    {
        return new RecordField('counterAttack.skill', 'Counter Attack', reference: 'counter_skills', removeWhenEmpty: true,
            allowsNone: true, displayDefault: '(no counter)');
    }

    /** Where battle art is bound to battlers as data, the Engine's {@see BattlerBindings::FILE}. */
    public const string BATTLERS_PATH = 'assets/' . BattlerBindings::FILE;

    /**
     * One side of the battler bindings: which art an actor or enemy fights
     * with in a graphical battle, edited from that actor's or enemy's own
     * page. Each record is one identity (an actor's definition id, an
     * enemy's name, as the battle catalog keys them) with its base artwork,
     * its pose roles and its body profile against the scale reference. Image
     * sizes are the files', never stored. The terminal battle never reads it.
     *
     * @param 'actors'|'enemies' $side
     */
    private static function battlerArt(string $side): RecordSchema
    {
        $actors = $side === 'actors';

        return new RecordSchema(
            key: $actors ? 'battler_actors' : 'battler_enemies',
            entryNoun: $actors ? 'actor battle art' : 'enemy battle art',
            storage: RecordStorage::LIST_FILE,
            relativePath: self::BATTLERS_PATH,
            fields: [
                // Set when the art is made for its battler, and never moved to another.
                new RecordField(BattlerBindingProjection::IDENTITY, $actors ? 'Actor' : 'Enemy', isReadOnly: true),
                RecordField::reference('artwork.image', 'Image', 'png_assets', allowsNone: true),
                new RecordField('artwork.pivot', 'Ground Point', codec: RecordFieldCodec::NORMALIZED_POINT, removeWhenEmpty: true, displayDefault: '0.5, 1 (bottom centre)'),
                new RecordField('scale.relativeSize', 'Size', InputControlType::FLOAT, removeWhenEmpty: true),
                new RecordField('scale.sourceSpan', 'Body Span', InputControlType::FLOAT, removeWhenEmpty: true),
                new RecordField('scale.horizontal', 'Measured Across', InputControlType::BOOLEAN, removeWhenEmpty: true, displayDefault: 'false'),
            ],
            labelKey: BattlerBindingProjection::IDENTITY,
            identityKey: BattlerBindingProjection::IDENTITY,
            blank: [BattlerBindingProjection::IDENTITY => ''],
            projection: new BattlerBindingProjection($side),
            subLists: [
                // Each role the battle shows the battler in, a still or a
                // sheet of frames, keyed by role as the file keys it.
                new RecordSubList(
                    key: 'poses',
                    prefix: 'pose',
                    singular: 'pose',
                    fields: [
                        new RecordField('role', 'Role', options: array_map(static fn(BattlePoseRole $role): string => $role->value, BattlePoseRole::cases())),
                        RecordField::reference('image', 'Image', 'png_assets'),
                        new RecordField('pivot', 'Ground Point', codec: RecordFieldCodec::NORMALIZED_POINT, removeWhenEmpty: true, displayDefault: '0.5, 1 (bottom centre)'),
                        new RecordField('columns', 'Columns', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '1'),
                        new RecordField('rows', 'Rows', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '1'),
                        new RecordField('frames', 'Frames', codec: RecordFieldCodec::CSV_INTEGERS, removeWhenEmpty: true, displayDefault: '0'),
                        new RecordField('fps', 'Frames per Second', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '8'),
                        new RecordField('loop', 'Loops', InputControlType::BOOLEAN, removeWhenEmpty: true, displayDefault: 'true'),
                        new RecordField('restFrame', 'Rest Frame', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '0'),
                        new RecordField('scaleSpan', 'Body Span', InputControlType::FLOAT, removeWhenEmpty: true),
                    ],
                    blank: ['role' => BattlePoseRole::IDLE->value, 'image' => ''],
                    heading: 'Poses',
                    removeWhenEmpty: true,
                    keyField: 'role',
                ),
            ],
            saveCheck: self::checkBattlerBindings(...),
            identityGiven: true,
        );
    }

    /**
     * The battle scale's reference: the actor every battler's size is
     * measured against, and how tall that actor stands in arena units.
     */
    private static function battleScaleReference(): RecordSchema
    {
        return new RecordSchema(
            key: 'battle_scale',
            entryNoun: 'battle scale',
            storage: RecordStorage::LIST_FILE,
            relativePath: self::BATTLERS_PATH,
            fields: [
                RecordField::reference('reference.actor', 'Reference Actor', 'actor_ids', allowsNone: true),
                new RecordField('reference.height', 'Reference Height', InputControlType::FLOAT, removeWhenEmpty: true),
            ],
            labelKey: 'reference.actor',
            identityKey: null,
            projection: new WholeFileProjection(['reference']),
            saveCheck: self::checkBattlerBindings(...),
        );
    }

    /**
     * Why the battle could not read battler bindings as they would be
     * saved, or null when it can: the Engine reads them, and binds them
     * beside the battlers the project's battle presentation code registers,
     * refusing an identity both own.
     *
     * @param array<array-key, mixed> $whole The bindings file as it would be written.
     */
    public static function checkBattlerBindings(array $whole, string $projectRoot): ?string
    {
        $assets = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'assets';
        try {
            $bindings = BattlerBindings::getFromArray($whole, $assets);
            BattlePresentationCatalog::loadCode($assets)?->bindBattlers($bindings);
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            return sprintf('The battle could not read %s as it would be saved: %s', BattlerBindings::FILE, $error->getMessage());
        }

        return null;
    }

    /**
     * The shared read-only fields every inventory entry displays.
     *
     * Equipment is shown everywhere by the one icon of its type (one for all
     * swords, one for all daggers), from the theme; an equipment entry's own
     * `icon` is legacy compatibility data, shown and kept exactly as written
     * but no longer a presentation choice to edit. A consumable keeps its own.
     *
     * @param bool $isEquipment Whether the entry is equipment.
     * @return RecordField[]
     */
    private static function inventoryFields(bool $isEquipment = false): array
    {
        return [
            // Identity. The id is what a save, an alias and every reference
            // resolve to, so it is shown and never edited after creation:
            // nothing that names it would follow a rename.
            new RecordField('id', 'Id', isReadOnly: true),
            new RecordField('name', 'Name'),
            new RecordField('description', 'Description'),
            $isEquipment
                ? new RecordField('icon', 'Legacy Icon (type icon shown)', isReadOnly: true)
                : new RecordField('icon', 'Icon'),
            // Compatibility references an older save may still name.
            new RecordField('aliases', 'Aliases', codec: RecordFieldCodec::CSV_LIST, removeWhenEmpty: true),
            // Policy
            new RecordField(
                'userType',
                'Who May Use It',
                options: array_map(static fn(ItemUserType $type): string => $type->value, ItemUserType::cases()),
                removeWhenEmpty: true,
                displayDefault: ItemUserType::ALL->value,
            ),
            // What a record leaves out reads as the Engine's default for its kind.
            RecordField::boolean('isKeyItem', 'Key Item', displayDefault: 'false'),
            RecordField::boolean('consumable', 'Consumable', displayDefault: $isEquipment ? 'false' : 'true'),
            // Trade
            new RecordField('price', 'Price', InputControlType::INTEGER),
            RecordField::boolean('sellable', 'Sellable', removeWhenEmpty: false, displayDefault: 'true'),
            new RecordField('sellRateBasisPoints', 'Sell Rate (basis points)', InputControlType::INTEGER, step: 500, displayDefault: '5000'),
            // Stock
            new RecordField('quantity', 'Quantity', InputControlType::INTEGER, displayDefault: '1'),
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
     * @param EquipmentSlotType $defaultSlot The slot this kind of equipment is equipped to unless it says otherwise.
     * @param RecordField[] $typeAndStats The type row and the stat rows this kind of equipment has.
     * @return RecordField[]
     */
    private static function equipmentFields(EquipmentSlotType $defaultSlot, array $typeAndStats): array
    {
        return [
            ...self::inventoryFields(isEquipment: true),
            // Slot and kind; a record leaves out the slot its kind defaults to.
            new RecordField(
                'semanticSlot',
                'Slot',
                options: array_map(static fn(EquipmentSlotType $slot): string => $slot->value, EquipmentSlotType::cases()),
                removeWhenEmpty: true,
                displayDefault: $defaultSlot->value,
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
            // What the property is worth is the project's own vocabulary,
            // so the parameters are authored as typed pairs rather than
            // being left unauthorable or given a schema of the editor's.
            new RecordField(
                'specialProperty.parameters',
                'Property Parameters',
                codec: RecordFieldCodec::KEY_VALUES,
                removeWhenEmpty: true,
                displayDefault: 'name=value, name=value',
            ),
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
    public static function mapNpcs(bool $graphical = false): RecordSchema
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
                // RPG Maker's Direction Fix: false (the default) is not written.
                RecordField::boolean('directionFix', 'Direction Fix', displayDefault: 'false'),
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
            commandLists: ['script' => self::eventCommandList('script', $graphical)],
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
    public static function eventCommandList(string $key, bool $graphical = false): RecordSubList
    {
        return new RecordSubList(
            key: $key,
            prefix: 'command',
            singular: 'command',
            fields: [
                new RecordField('type', 'Type', options: self::getEventCommandTypes()),
            ],
            blank: ['type' => 'text', 'name' => '', 'text' => 'Something happens.'],
            variants: self::eventCommandVariants($graphical),
            variantKey: 'type',
            nestedLists: [
                'move_route' => MovementRouteFields::getPointList(...),
                ...self::getRegisteredCommandLists($graphical),
            ],
            prepareEdit: static fn(array $entry, string $field): array => MovementRouteFields::prepareEdit(
                InnPresentationFields::prepareEdit($entry, $field), $field),
        );
    }

    /**
     * Returns the steps of a movement route: the one nested list every
     * route-bearing command edits the same way.
     */
    public static function routeStepList(): RecordSubList
    {
        return new RecordSubList(
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
        );
    }

    /**
     * Returns the cinematic category the Cutscenes workspace edits. Not a
     * Database category: it is not listed by all().
     */
    public static function cinematics(): RecordSchema
    {
        return CutsceneSchemas::cinematics();
    }

    /**
     * Returns the summon category the Cutscenes workspace edits. Not a
     * Database category: it is not listed by all().
     */
    public static function summons(): RecordSchema
    {
        return CutsceneSchemas::summons();
    }

    /**
     * Returns the effect timeline category the Cutscenes workspace edits.
     * Not a Database category: it is not listed by all().
     */
    public static function effects(): RecordSchema
    {
        return CutsceneSchemas::effects();
    }

    /**
     * Returns the per-type field sets for event-script commands.
     *
     * Nested arms (`choice.options`, `branch.then`/`else`) are shown as
     * read-only counts: flattening a tree into one settings pane would be
     * unreadable, and silently dropping it on save would be worse.
     *
     * @return array<string, RecordField[]|Closure(array<string, mixed>): RecordField[]>
     */
    public static function eventCommandVariants(bool $graphical = false): array
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
            // The operation chooses the shape: each one asks for exactly
            // what the runtime reads for it, and for nothing else.
            'knowledge' => static fn(array $entry): array => [
                new RecordField(
                    'operation',
                    'Operation',
                    options: KnowledgeCommandShape::operations(),
                ),
                ...KnowledgeCommandShape::fieldsFor(strval($entry['operation'] ?? '')),
            ],
            'move_route' => MovementRouteFields::getFields(...),
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
                // Reserves replace a wiped-out frontline only where an encounter opts in.
                new RecordField('reservePolicy', 'Reserve Policy', options: ['none', 'replace_after_wipeout'], removeWhenEmpty: true, displayDefault: 'none'),
            ],
            'branch' => [
                new RecordField('conditions', 'Conditions', codec: RecordFieldCodec::CONDITIONS),
                // The arms become frames; see describeSubEntryFields.
            ],
            ...self::getRegisteredCommandVariants($graphical),
        ];
    }

    /**
     * Returns every command type a script may hold: the interpreter's
     * built-in vocabulary, then the commands the Engine and the open project
     * register.
     *
     * @return list<string>
     */
    public static function getEventCommandTypes(): array
    {
        return [...self::EVENT_COMMAND_TYPES, ...ScriptCommandRegistry::getCatalog()->types];
    }

    /**
     * Returns the field sets of the registered commands, read from their
     * declarations.
     *
     * A command's first list field is edited as its entries, like a route's
     * steps; any further list field is shown read-only and kept as written.
     *
     * @return array<string, RecordField[]|Closure(array): list<RecordField>>
     */
    public static function getRegisteredCommandVariants(bool $graphical = false): array
    {
        $variants = [];

        foreach (ScriptCommandRegistry::getCatalog()->definitions as $type => $definition) {
            $listField = self::findRegisteredListField($definition);
            $getFields = static function (array $entry) use ($definition, $listField, $graphical): array {
                $fields = [];
                foreach ($definition->fields as $field) {
                    if ($field === $listField) { continue; }
                    if ($field->reference === ScriptCommandReference::STAGE_TIMELINE) {
                        $value = $entry;
                        foreach (explode('.', $field->key) as $segment) { $value = is_array($value) ? ($value[$segment] ?? null) : null; }
                        array_push($fields, ...InnPresentationFields::getFields($value, $field->key, $field->label, $graphical));
                    } else {
                        array_push($fields, ...($field->kind === ScriptCommandFieldKind::LIST
                            ? [new RecordField($field->key, $field->label, isReadOnly: true)] : self::describeRegisteredField($field)));
                    }
                }
                return $fields;
            };
            $variants[$type] = array_any($definition->fields, static fn(ScriptCommandField $field): bool => $field->reference === ScriptCommandReference::STAGE_TIMELINE)
                ? $getFields : $getFields([]);
        }

        return $variants;
    }

    /**
     * Returns the entries list of each registered command that has one.
     *
     * @return array<string, RecordSubList|Closure(array): ?RecordSubList>
     */
    public static function getRegisteredCommandLists(bool $graphical = false): array
    {
        $lists = [];

        foreach (ScriptCommandRegistry::getCatalog()->definitions as $type => $definition) {
            $listField = self::findRegisteredListField($definition);

            if ($listField === null) {
                $stageField = array_find($definition->fields, static fn(ScriptCommandField $field): bool => $field->reference === ScriptCommandReference::STAGE_TIMELINE);
                if ($graphical && $stageField !== null) {
                    $lists[$type] = static function (array $entry) use ($stageField): ?RecordSubList {
                        $value = $entry;
                        foreach (explode('.', $stageField->key) as $segment) { $value = is_array($value) ? ($value[$segment] ?? null) : null; }
                        return InnPresentationFields::getBindingList($value, $stageField->key);
                    };
                }
                continue;
            }

            $lists[$type] = new RecordSubList(
                key: $listField->key,
                // Settings ids read the prefix up to the entry number, so it
                // holds letters alone.
                prefix: lcfirst(implode('', array_map(ucfirst(...), preg_split('/[^A-Za-z]+/', $listField->key, flags: PREG_SPLIT_NO_EMPTY) ?: ['entry']))),
                singular: strtolower($listField->label) . ' entry',
                fields: array_merge(...array_map(self::describeRegisteredField(...), $listField->fields)),
                blank: self::getRegisteredBlankEntry($listField->fields[0]),
            );
        }

        return $lists;
    }

    /** The list field a registered command's entries are edited from: its first. */
    private static function findRegisteredListField(ScriptCommandDefinition $definition): ?ScriptCommandField
    {
        foreach ($definition->fields as $field) {
            if ($field->kind === ScriptCommandFieldKind::LIST) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Describes one declared field as the settings it is edited through. An
     * optional text or reference drops out when cleared; numbers and flags
     * keep what the author sets, since a project's handler decides what an
     * absent one means.
     *
     * @return RecordField[]
     */
    private static function describeRegisteredField(ScriptCommandField $field): array
    {
        return match ($field->kind) {
            ScriptCommandFieldKind::TEXT => [new RecordField($field->key, $field->label, removeWhenEmpty: ! $field->required)],
            ScriptCommandFieldKind::INTEGER => [new RecordField($field->key, $field->label, InputControlType::INTEGER)],
            ScriptCommandFieldKind::NUMBER => [new RecordField($field->key, $field->label, InputControlType::FLOAT)],
            ScriptCommandFieldKind::BOOLEAN => [RecordField::boolean($field->key, $field->label, removeWhenEmpty: false)],
            ScriptCommandFieldKind::OPTION => [new RecordField($field->key, $field->label, options: $field->options, removeWhenEmpty: ! $field->required)],
            ScriptCommandFieldKind::REFERENCE => [RecordField::reference(
                $field->key,
                $field->label,
                ProjectScriptCommands::getReferenceCategory($field->reference
                    ?? throw new LogicException("Reference field {$field->key} names no resource.")),
                allowsNone: ! $field->required,
            )],
            ScriptCommandFieldKind::POSITION => [
                new RecordField("{$field->key}.x", "{$field->label} X", InputControlType::INTEGER),
                new RecordField("{$field->key}.y", "{$field->label} Y", InputControlType::INTEGER),
            ],
            ScriptCommandFieldKind::LIST => [new RecordField($field->key, $field->label, isReadOnly: true)],
        };
    }

    /**
     * Returns a fresh list entry: its first field, empty, so the entry is a
     * keyed record from the start and validation names what it still needs.
     *
     * @return array<string, mixed>
     */
    private static function getRegisteredBlankEntry(ScriptCommandField $field): array
    {
        $value = match ($field->kind) {
            ScriptCommandFieldKind::INTEGER => 0,
            ScriptCommandFieldKind::NUMBER => 0.0,
            ScriptCommandFieldKind::BOOLEAN => false,
            ScriptCommandFieldKind::OPTION => $field->options[0] ?? '',
            ScriptCommandFieldKind::POSITION => ['x' => 0, 'y' => 0],
            default => '',
        };
        $entry = [];
        $target = &$entry;

        foreach (explode('.', $field->key) as $segment) {
            $target[$segment] = [];
            $target = &$target[$segment];
        }

        $target = $value;

        return $entry;
    }
}
