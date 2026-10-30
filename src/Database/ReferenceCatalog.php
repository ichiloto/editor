<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\PermanentGrowthCatalog;
use Ichiloto\Editor\ProjectActor;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\ProjectQuest;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Dialogue\DialogueCatalogFile;
use Ichiloto\Editor\Dialogue\DialogueEmotionFields;
use Ichiloto\Editor\Dialogue\DialogueLineSpeaker;
use Ichiloto\Editor\Dialogue\DialoguePortraitFields;
use Ichiloto\Editor\Dialogue\DialogueSpeakerFields;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeRecordType;
use Ichiloto\Engine\Rendering\Presentation\Text\ProjectTextCatalog;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Battle\CounterAttackRule;
use Ichiloto\Engine\Battle\CrisisAbility;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Entities\Enumerations\ArmorType;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Entities\Magic\MagicEffectType;
use Ichiloto\Engine\Battle\Insight\RevealFact;
use Ichiloto\Engine\Entities\Skills\SkillResolutionScope;
use InvalidArgumentException;
use Throwable;

/**
 * What a field pointing at another resource may point at.
 *
 * A field naming an enemy, an item, or a map is a reference, and an author
 * should choose one rather than spell it. This is the single place that knows
 * what each kind of reference can resolve to, so a picker anywhere in the
 * editor offers the same list and stores the same stable value.
 *
 * @package Ichiloto\Editor\Database
 */
final class ReferenceCatalog
{
    /**
     * The kinds of reference a field may declare.
     */
    /** The encounter terrains the project's terrain kinds meet ({@see \Ichiloto\Engine\Rendering\Tilesets\TerrainKind::$encounters}). */
    public const string ENCOUNTER_TERRAINS = 'encounter_terrains';

    /** The entry choice source of a tileset's tile layers ({@see listEntryChoices()}). */
    public const string TILESET_TILE_LAYERS = 'tileset_tile_layers';

    public const array CATEGORIES = [
        'actors',
        'actor_ids',
        'classes',
        'attack_skills',
        'counter_skills',
        'crisis_abilities',
        // What a crisis ability's variant may use, by the Engine's own rule.
        'crisis_skills',
        'resolution_kinds',
        'piece_connections',
        'resolution_scopes',
        'magic_effect_types',
        // What a reveal effect can uncover about a foe: the Engine's own facts.
        'reveal_facts',
        'weapon_types',
        'armor_types',
        'skills',
        // The regions every Mastery Map shares, by id.
        'mastery_regions',
        // The mastery tree's branches, by id: what a branch may grow within.
        MasteryFields::BRANCHES,
        // Every node of the mastery tree, by id: what a character's map gives terms for.
        MasteryFields::NODES,
        'quests',
        // Income schedules, by stable id, for the income script commands.
        'income_schedules',
        'maps',
        'items',
        'weapons',
        'armors',
        ...\Ichiloto\Editor\Actors\InitialEquipmentAuthoring::REFERENCES,
        'enemies',
        'troops',
        'states',
        'animations',
        'animation_ids',
        'skits',
        'common_events',
        'inventory',
        'bgm',
        'sfx',
        'enemy_sprites',
        'png_assets',
        'elements',
        'map_npcs',
        'map_world_objects',
        // A map's minimap landmarks, by stable id.
        'map_landmarks',
        'world_object_tile_layers',
        // A map event condition's marker, listed for the map that condition names.
        'map_event_markers',
        'knowledge_subjects',
        'knowledge_reports',
        'knowledge_record_types',
        'knowledge_observations',
        'elements_or_any',
        'equipment_availabilities',
        'equipment_acquisition_policies',
        'equipment_special_properties',
        'permanent_growth',
        'cinematics',
        'summons',
        'cinematic_cast',
        'cinematic_subjects',
        'cinematic_checkpoints',
        'cinematic_movement_routes',
        'summon_cues',
        'effect_cues',
        'stage_subjects',
        'stage_attachments',
        'event_markers',
        'tilesets',
        'effects',
        'stage_timelines',
        'field_resources',
        'text_roles',
        'font_assets',
        'map_regions',
        // A map's role, by stable id, from the Map Roles page.
        'map_roles',
        // A map's place type, by stable id, from the Place Types page.
        'place_types',
        'animation_roles',
        'battle_arenas',
        self::ENCOUNTER_TERRAINS,
        // The dialogue catalogue's identities, actor and non-actor, whose artwork lines show.
        'dialogue_identities',
    ];

    /**
     * The reference kinds whose values are pictures an author looks at or
     * sounds an author listens to: how an interface shows or plays each
     * value, and the folder under the project root its values are found in.
     * An interface knows a value is a picture or a sound from this, never
     * from the kind's name.
     */
    private const array MEDIA = [
        'png_assets' => ['kind' => 'image', 'root' => 'assets'],
        // Music and sound are named without their extension; the game's players find the file and play it.
        'bgm' => ['kind' => 'audio', 'root' => 'assets/Audio/BGM'],
        'sfx' => ['kind' => 'audio', 'root' => 'assets/Audio/SFX'],
    ];

    /**
     * Where each audio kind's files live, under assets/Audio.
     */
    private const array AUDIO_DIRECTORIES = [
        'bgm' => 'BGM',
        'sfx' => 'SFX',
    ];

    /**
     * How an interface shows or plays the values of a reference kind, when
     * they are pictures or sounds: `kind` (`image` or `audio`) and `root`,
     * the folder under the project root the values are found in. Null for
     * any other kind.
     *
     * @return array{kind: string, root: string}|null
     */
    public static function describeMedia(string $category): ?array
    {
        return self::MEDIA[$category] ?? null;
    }

    /** The same live workspace root that supplies the project image picker. */
    public function getAssetRoot(): string
    {
        return rtrim($this->workspace->projectRoot, '/') . '/assets';
    }

    public function describeWorldObjectReferences(ProjectMap $map, string $id): array
    {
        return new \Ichiloto\Editor\Field\WorldObjectReferences($this->workspace)->describeReferences($map, $id);
    }

    /**
     * @param ProjectWorkspace $workspace The project.
     * @param ProjectMap|null $currentMap The map the author is working in,
     *   for reference kinds that are map-local (an NPC id).
     */
    /**
     * @var InventoryCatalog|null The project's inventory identity, read once.
     */
    private ?InventoryCatalog $inventoryCatalog = null;

    /** @var array<string, string>|null The battle presentation's arenas, read once. */
    private ?array $arenaNames = null;

    public function __construct(
        private readonly ProjectWorkspace $workspace,
        private readonly ?ProjectMap $currentMap = null,
        private readonly ?CutsceneAsset $currentCutscene = null,
        private readonly ?array $movementCommands = null,
    ) {
    }

    /**
     * Returns what a reference of the given kind may be set to.
     *
     * The values are what gets stored: a name where the engine matches by
     * name, a map id where it loads by path. An empty list means the project
     * defines nothing of that kind yet, which a picker reports rather than
     * silently offering nothing.
     *
     * @param string $category The kind of reference.
     * @return string[] The selectable values, in the order the project lists them.
     */
    public function valuesFor(string $category): array
    {
        return match ($category) {
            'actors' => array_map(
                static fn(ProjectActor $actor): string => $actor->getName(),
                $this->workspace->actorDatabase->getActors()
            ),
            // Surfaces the runtime resolves by durable identity store the
            // definition id, which is the one thing a rename never changes.
            'actor_ids' => array_map(
                static fn(ProjectActor $actor): string => $actor->getDefinitionId(),
                array_values(array_filter($this->workspace->actorDatabase->getActors(),
                    static fn(ProjectActor $actor): bool => $actor->hasDefinitionId()))
            ),
            // What an actor's Attack command may use: a basic skill from the
            // catalogue that can be used in battle.
            'attack_skills' => $this->attackSkillNames(),
            // What a counter attack may respond with, by the Engine's own rule.
            'counter_skills' => $this->counterSkillNames(),
            'crisis_skills' => self::getCrisisSkillNames($this->workspace->loadSkillCatalog()),
            'crisis_abilities', 'states' => array_values(array_filter(array_map(
                static fn(ProjectRecord $record): mixed => $record->get('id'),
                $this->workspace->getRecordDatabase($category)?->getRecords() ?? []),
                static fn(mixed $id): bool => is_string($id) && $id !== '')),
            // A class restricts what its members equip by the engine's type names.
            // How a skill effect resolves, how often a skill rolls, and what
            // kind of spell it is: the Engine's own vocabularies.
            'resolution_kinds' => array_map(static fn(ResolutionKind $kind): string => $kind->value, ResolutionKind::cases()),
            // How a tileset piece joins the cells beside it.
            'piece_connections' => [\Ichiloto\Engine\Rendering\Tilesets\TilesetPiece::LINES],
            'resolution_scopes' => array_map(static fn(SkillResolutionScope $scope): string => $scope->value, SkillResolutionScope::cases()),
            'magic_effect_types' => array_map(static fn(MagicEffectType $type): string => $type->value, MagicEffectType::cases()),
            'reveal_facts' => array_map(static fn(RevealFact $fact): string => $fact->value, RevealFact::cases()),
            'weapon_types' => array_map(static fn(WeaponType $type): string => $type->value, WeaponType::cases()),
            'armor_types' => array_map(static fn(ArmorType $type): string => $type->value, ArmorType::cases()),
            // Spells and abilities may be authored in any of the Engine's
            // skill files; a reference names the skill wherever it lives.
            'skills' => $this->workspace->getSkillNames(),
            'mastery_regions' => array_map(static fn(ProjectRecord $record): string => strval($record->get('id')),
                $this->workspace->getRecordDatabase(MasteryFields::REGIONS)?->getRecords() ?? []),
            MasteryFields::NODES => $this->masteryNodeIds(),
            MasteryFields::BRANCHES => array_map(static fn(ProjectRecord $record): string => strval($record->get('id')),
                $this->workspace->getRecordDatabase(MasteryFields::BRANCHES)?->getRecords() ?? []),
            'quests' => array_map(static fn(ProjectQuest $quest): string => $quest->getId(), $this->workspace->getQuests()),
            'income_schedules' => array_keys($this->incomeScheduleNames()),
            'maps' => $this->workspace->mapIds,
            // A shop's stock is whatever the engine's ItemStore holds, and
            // that is everything in items.php: items, weapons and armors
            // alike. Offering only the items would refuse a sword a shop is
            // entitled to sell. What is offered -- and stored -- is the
            // stable definition id, which is the identity the runtime
            // resolves and the one thing a rename does not change.
            'inventory' => $this->getInventoryCatalog()->ids(),
            'items' => $this->getInventoryCatalog()->idsIn('items'),
            'weapons' => $this->getInventoryCatalog()->idsIn('weapons'),
            'armors' => $this->getInventoryCatalog()->idsIn('armors'),
            'initial_weapon', 'initial_shield', 'initial_head', 'initial_body', 'initial_accessory' =>
                \Ichiloto\Editor\Actors\InitialEquipmentAuthoring::getItemIds($this->workspace,
                    \Ichiloto\Engine\Entities\Inventory\EquipmentSlotType::from(substr($category, 8))),
            'bgm', 'sfx' => $this->audioValues($category),
            // The engine loads an enemy's image as
            // Graphics/Enemies/<value>.txt, appending the extension itself,
            // so the file stems are the values.
            'enemy_sprites' => $this->fileValues('assets/Graphics/Enemies'),
            'png_assets' => self::getPngAssets($this->workspace->projectRoot),
            'elements' => $this->elementValues(),
            // An Optimize weight may apply to one element or to whichever
            // element an outcome happened to be, which the runtime spells
            // with a wildcard rather than a name.
            'elements_or_any' => ['*', ...$this->elementValues()],
            'equipment_availabilities' => $this->equipmentVocabulary('availability'),
            'equipment_acquisition_policies' => $this->equipmentVocabulary('acquisitionPolicy'),
            'equipment_special_properties' => $this->equipmentVocabulary('specialProperty'),
            'permanent_growth' => $this->workspace->catalogs->loadPermanentGrowth()->ids(),
            'knowledge_record_types' => $this->knowledgeRecordTypes(),
            'knowledge_observations' => $this->knowledgeObservations(),
            'knowledge_subjects' => $this->knowledgeIds('subjects'),
            'knowledge_reports' => $this->knowledgeIds('reports'),
            // NPC ids are map-local, so the choices are the current map's:
            // read live from the collection, a just-created NPC is offered
            // at once and a deleted one is gone.
            'map_npcs' => $this->getSubjectMap()?->getNpcs()->ids() ?? [],
            'map_world_objects' => $this->getSubjectMap() === null ? [] : array_column(\Ichiloto\Editor\Field\WorldObjectAuthoring::readEntries($this->getSubjectMap()), 'id'),
            'map_landmarks' => array_values(array_filter(array_column($this->readSubjectMapLandmarks(), 'id'), is_string(...))),
            'world_object_tile_layers' => $this->currentMap === null ? [] : array_column($this->currentMap->describeTileLayers()['layers'], 'name'),
            // Only meaningful for a named map; the session lists them for the map a condition names.
            'map_event_markers' => [],
            // Older references name an animation; current ones store its id.
            'animations' => array_map(
                static fn(ProjectRecord $animation): string => strval($animation->get('name')),
                $this->animationRecords(),
            ),
            'animation_ids' => array_map(
                static fn(ProjectRecord $animation): string => strval($animation->get('id')),
                $this->animationRecords(),
            ),
            // Cutscenes are folders, so the stable id is the folder name.
            'cinematics' => $this->workspace->cutscenes?->ids(CutsceneType::CINEMATIC) ?? [],
            'summons' => $this->workspace->cutscenes?->ids(CutsceneType::SUMMON) ?? [],
            // Cutscene-local kinds read the cinematic the author is in: the
            // cast it declares, the checkpoints it names, and everything a
            // subject reference may point at on its map.
            'cinematic_cast' => $this->castIds(['staged_actor']),
            'cinematic_subjects' => $this->subjectIds(),
            'cinematic_checkpoints' => $this->checkpointIds(),
            'cinematic_movement_routes' => $this->getMovementRouteIds(),
            'summon_cues' => $this->getTimelineCueIds(CutsceneType::SUMMON),
            'effect_cues' => $this->getTimelineCueIds(CutsceneType::EFFECT),
            // A summon sequence's stage: the subjects it registers, and every
            // named point on them (each image picks one on its own subject).
            'stage_subjects' => array_keys($this->getStageSubjects()),
            'stage_attachments' => array_keys($this->getStageAttachments()),
            'event_markers' => $this->getSubjectMap()?->getEventMarkers() ?? [],
            // A map's kind is one of the project's tilesets, by file stem.
            'tilesets' => array_keys($this->loadTilesetNames()),
            // The scenes a graphical battle can take place in, by the key a
            // map's encounters or a start_battle command name one with.
            'battle_arenas' => array_map(strval(...), array_keys($this->loadArenaNames())),
            // The encounter terrains the project's tilesets' kinds meet, each an arena may draw a variant for.
            self::ENCOUNTER_TERRAINS => $this->loadEncounterTerrains(),
            // A region is the display name the game shows for where the party
            // is; choosing from the names the maps already use keeps one
            // region spelled one way.
            'map_regions' => $this->mapRegions(),
            // The battle roles an animation can play, as the Engine resolves them.
            'animation_roles' => \Ichiloto\Engine\Animations\ActionAnimationResolver::getSupportedRoles(),
            // Effect timelines are folders the Engine lists by stable id.
            'effects' => $this->workspace->catalogs->listEffectTimelineIds(),
            'stage_timelines' => $this->stageTimelineIds(),
            'field_resources' => array_map(strval(...), array_keys($this->loadFieldResourceNames())),
            'text_roles' => $this->loadTextRoleIds(),
            'font_assets' => self::getFontAssets($this->workspace->projectRoot),
            'dialogue_identities' => array_column($this->listDialogueIdentities(), 'id'),
            // play_skit names a Skit by the stable id the Engine keys Skits by, never its title.
            SkitPlaybackFields::REFERENCE_CATEGORY => array_keys($this->listSkitTitles()),
            default => $this->recordValues($category),
        };
    }

    /**
     * The effect timelines the Engine admits as a stage of their own, such
     * as an inn's rest, by stable id: those its stage loading accepts (fixed,
     * once, a stage descriptor, stage images, no cues). Admission is the
     * Engine's; a timeline it refuses is not offered.
     *
     * @return list<string>
     */
    /**
     * The project's named field resources (assets/Data/Presentation/field.php), by stable id, with their display
     * names. A catalogue that cannot be read offers none; project validation reports why.
     *
     * @return array<string, string>
     */
    private function loadFieldResourceNames(): array
    {
        try {
            $catalog = $this->getFieldPresentationCatalog();
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn($resource): string => $resource->name, $catalog->resources);
    }

    /**
     * The project's field presentation (assets/Data/Presentation/field.php) as the Engine reads it, with the
     * Field Resources page's records as it holds them, unsaved edits included: what saving would leave. Every
     * reader of field resources (pickers, world objects, validation) reads it, so a resource added in this
     * session is usable at once.
     *
     * @throws \InvalidArgumentException When the catalogue is read-only or the Engine refuses it.
     */
    public function getFieldPresentationCatalog(): FieldPresentationCatalog
    {
        return $this->workspace->catalogs->loadFieldPresentation();
    }

    /**
     * The project's text roles (assets/Data/Presentation/text.php), by stable id. A catalogue that cannot be read
     * offers none; project validation reports why.
     *
     * @return list<string>
     */
    private function loadTextRoleIds(): array
    {
        try {
            $catalog = $this->workspace->catalogs->loadTextCatalog();
        } catch (Throwable) {
            return [];
        }

        return $catalog === null ? [] : array_map(strval(...), array_keys($catalog->toArray()['roles']));
    }

    /**
     * The project's font files under assets, as asset-root-relative paths: what a text role's fonts are chosen
     * from. The project owns its fonts; nothing is offered from the machine.
     *
     * @return list<string>
     */
    public static function getFontAssets(string $projectRoot): array
    {
        $root = realpath(rtrim($projectRoot, '/') . '/assets');
        if ($root === false) {
            return [];
        }
        $paths = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $resolved = $file->getRealPath();
            if ($file->isFile() && in_array(strtolower($file->getExtension()), ['ttf', 'otf', 'ttc', 'otc'], true)
                && $resolved !== false && str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
                $paths[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
        sort($paths);

        return $paths;
    }

    private function stageTimelineIds(): array
    {
        $catalogs = $this->workspace->catalogs;

        return array_values(array_filter(
            $catalogs->listEffectTimelineIds(),
            static function (string $id) use ($catalogs): bool {
                try {
                    $catalogs->loadEffectTimeline($id, forStage: true);

                    return true;
                } catch (Throwable) {
                    return false;
                }
            },
        ));
    }

    /** @return string[] The catalogue's basic skills usable in battle, in catalogue order. */
    private function attackSkillNames(): array
    {
        return array_keys(array_filter(
            $this->workspace->loadSkillCatalog()->getSkills(),
            static fn(Skill $skill): bool => $skill instanceof BasicSkill
                && in_array($skill->occasion, [Occasion::ALWAYS, Occasion::BATTLE_SCREEN], true),
        ));
    }

    /**
     * The skills a counter attack may respond with: those the Engine's
     * CounterAttackRule accepts (a battle-usable basic or special skill
     * targeting one living opponent, without summon or required weapons).
     *
     * @return list<string>
     */
    private function counterSkillNames(): array
    {
        $catalog = $this->workspace->loadSkillCatalog();
        $names = [];
        foreach (array_keys($catalog->getSkills()) as $name) {
            try {
                new CounterAttackRule((string) $name)->resolveSkill($catalog);
                $names[] = (string) $name;
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return $names;
    }

    /**
     * The skills a crisis ability's variant may use: those the Engine's
     * CrisisAbility accepts (a battle-usable basic or special skill that is
     * not a summon), in catalogue order. A one-variant probe asks the Engine
     * itself, so the rule has one owner.
     *
     * @return list<string>
     */
    public static function getCrisisSkillNames(SkillCatalog $catalog): array
    {
        $names = [];
        foreach (array_keys($catalog->getSkills()) as $name) {
            $probe = ['name' => 'probe', 'hpBelowPercent' => 10.0, 'variants' => [['skill' => (string) $name]]];
            try {
                CrisisAbility::fromArray($probe)?->validateSkills($catalog);
                $names[] = (string) $name;
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return $names;
    }

    /** @return ProjectRecord[] The project's animations, in file order. */
    private function animationRecords(): array
    {
        return $this->workspace->getRecordDatabase('animations')?->getRecords() ?? [];
    }

    /**
     * The region names the project's maps use, each once, in name order.
     *
     * @return string[]
     */
    private function mapRegions(): array
    {
        $regions = [];
        foreach ($this->workspace->maps as $map) {
            $region = $map->getMapField('region');
            if (is_string($region) && trim($region) !== '') {
                $regions[trim($region)] = true;
            }
        }
        $regions = array_keys($regions);
        natcasesort($regions);

        return array_values($regions);
    }

    /**
     * Returns the ids of the current cinematic's cast members of the given
     * kinds, staged actors declared in the data file and actors staged by a
     * `stage_actor` command alike.
     *
     * @param string[] $kinds The cast kinds to list.
     * @return string[]
     */
    private function castIds(array $kinds): array
    {
        if ($this->currentCutscene === null || $this->currentCutscene->type !== CutsceneType::CINEMATIC) {
            return [];
        }

        $ids = [];

        foreach ((array) ($this->currentCutscene->data()['cast'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $kind = strtolower(strval($entry['kind'] ?? 'staged_actor'));
            $id = trim(strval($entry['id'] ?? ''));

            if ($id !== '' && in_array($kind, $kinds, true)) {
                $ids[$id] = $id;
            }
        }

        if (in_array('staged_actor', $kinds, true)) {
            self::collectStagedActorIds($this->currentCutscene->commands(), $ids);
        }

        return array_values($ids);
    }

    /**
     * Walks a command tree for `stage_actor` commands, so an actor staged
     * mid-scene is offered to the commands after it.
     *
     * @param array<int, mixed> $commands
     * @param array<string, string> $ids
     */
    private static function collectStagedActorIds(array $commands, array &$ids): void
    {
        foreach ($commands as $command) {
            if (! is_array($command)) {
                continue;
            }

            if (($command['type'] ?? '') === 'stage_actor') {
                $actor = is_array($command['actor'] ?? null) ? $command['actor'] : $command;
                $id = trim(strval($actor['id'] ?? ''));

                if ($id !== '') {
                    $ids[$id] = $id;
                }
            }

            foreach (['commands', 'then', 'else', 'cancel'] as $arm) {
                if (is_array($command[$arm] ?? null)) {
                    self::collectStagedActorIds($command[$arm], $ids);
                }
            }

            foreach (['lanes', 'options'] as $list) {
                foreach ((array) ($command[$list] ?? []) as $member) {
                    if (is_array($member)) {
                        self::collectStagedActorIds(array_values((array) ($member['commands'] ?? $member['then'] ?? (array_is_list($member) ? $member : []))), $ids);
                    }
                }
            }
        }
    }

    /**
     * Returns everything a subject id may name in the current cinematic:
     * staged actors, map NPCs, party actors and the map's event markers.
     *
     * @return string[]
     */
    private function subjectIds(): array
    {
        return array_values(array_unique([
            ...$this->castIds(['staged_actor', 'npc', 'party_actor', 'player']),
            ...($this->getSubjectMap()?->getNpcs()->ids() ?? []),
            ...$this->valuesFor('map_world_objects'),
            ...$this->valuesFor('actors'),
            ...($this->getSubjectMap()?->getEventMarkers() ?? []),
        ]));
    }

    /** @return list<array<string, mixed>> The landmark declarations of the map a reference reads, or none when unreadable. */
    private function readSubjectMapLandmarks(): array
    {
        $map = $this->getSubjectMap();
        if ($map === null) { return []; }
        try {
            return \Ichiloto\Editor\Field\MapLandmarks::readMap($map)->entries;
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    /**
     * The map whose NPCs, world objects and markers a subject reference names: the explicitly selected map, or
     * without one a cinematic's declared start map.
     */
    /**
     * The values a field chooses among that its own entry decides ({@see RecordField::$entryChoices}), read from
     * what the field lives in. `world_object_attachments`: the attachments declared by the resource of the world
     * object a subject reference (`at`) names, on the subject map; none until it names an object that has some.
     * `terrain_grounds`: the ground kinds of the tileset a terrain kind belongs to, those that stand on nothing,
     * other than the kind itself. `mastery_within_branches`: the tree's branches other than the branch choosing, any
     * of which it may grow within once it shares that branch's region. `tileset_whole_pieces`: the pieces of the tileset a place icon belongs to that are
     * stamped whole, any of which can show a place type. `tileset_thickets`: the thickets of the tileset a terrain
     * kind belongs to. `tileset_tile_layers`: the tile layers the tileset's pieces, terrain kinds and stacks write,
     * any of which it can give a drawing offset.
     *
     * @param array<string, mixed> $holder What the field lives in.
     * @param array<string, mixed> $owner The record holding the entry.
     * @return list<string>
     * @throws InvalidArgumentException For a source nothing reads.
     */
    public function listEntryChoices(string $source, array $holder, array $owner = []): array
    {
        if ($source === 'terrain_grounds') {
            // A keyed entry carries its key as its id while edited, and not as stored.
            $self = array_diff_key($holder, ['id' => true]);
            $kinds = is_array($owner['kinds'] ?? null) ? $owner['kinds'] : [];
            return array_values(array_map(strval(...), array_keys(array_filter($kinds, static fn(mixed $kind, int|string $id): bool =>
                is_array($kind) && ($kind['ground'] ?? null) === null && (string) $id !== ($holder['id'] ?? null)
                && array_diff_key($kind, ['id' => true]) !== $self, ARRAY_FILTER_USE_BOTH))));
        }
        if ($source === 'tileset_whole_pieces') {
            $pieces = is_array($owner['pieces'] ?? null) ? $owner['pieces'] : [];
            return array_values(array_map(strval(...), array_keys(array_filter($pieces, static fn(mixed $piece): bool
                => is_array($piece) && ($piece['connects'] ?? null) === null))));
        }
        if ($source === MasteryFields::WITHIN_CHOICES) {
            // Every branch of the tree other than the one choosing.
            return array_values(array_filter(array_map(static fn(ProjectRecord $record): string => strval($record->get('id')),
                $this->workspace->getRecordDatabase(MasteryFields::BRANCHES)?->getRecords() ?? []),
                static fn(string $id): bool => $id !== '' && $id !== ($holder['id'] ?? null)));
        }
        if ($source === self::TILESET_TILE_LAYERS) {
            return self::listTilesetTileLayers($owner);
        }
        if ($source === 'tileset_thickets') {
            $thickets = is_array($owner['thickets'] ?? null) ? $owner['thickets'] : [];
            return array_values(array_map(strval(...), array_keys($thickets)));
        }
        if ($source !== 'world_object_attachments') {
            throw new InvalidArgumentException(sprintf('There is no entry choice source %s.', $source));
        }
        $objectId = ($holder['kind'] ?? null) === 'world_object' ? ($holder['id'] ?? null) : null;
        $map = $this->getSubjectMap();
        $found = $map === null || !is_string($objectId) ? null : \Ichiloto\Editor\Field\WorldObjectAuthoring::findAttachments($map, $objectId);
        return array_map(static fn(\Ichiloto\Engine\Field\WorldObjectAttachment $attachment): string => $attachment->id, $found['attachments'] ?? []);
    }

    /**
     * The tile layers a tileset's pieces, terrain kinds and stacks write, as
     * the Engine reads the tileset, in the order they first appear; none
     * while the tileset cannot be read.
     *
     * @param array<string, mixed> $tileset
     * @return list<string>
     */
    private static function listTilesetTileLayers(array $tileset): array
    {
        try {
            $read = \Ichiloto\Engine\Rendering\Tilesets\Tileset::fromArray('edited', $tileset);
        } catch (InvalidArgumentException) {
            return [];
        }
        $layers = [];
        foreach ($read->getPlacements(true) as $piece) {
            array_push($layers, ...$read->getPieceTileLayers($piece));
        }

        return array_values(array_unique([...$layers, ...$read->getStackLayers()]));
    }

    public function getSubjectMap(): ?ProjectMap
    {
        if ($this->currentMap !== null) {
            return $this->currentMap;
        }
        if ($this->currentCutscene?->type === CutsceneType::CINEMATIC) {
            $id = $this->currentCutscene->data()['startMap'] ?? null;
            if (is_string($id) && $id !== '') {
                return array_find($this->workspace->maps, static fn(ProjectMap $map): bool => $map->mapId === $id);
            }
        }

        return null;
    }

    /**
     * Returns the checkpoints the current cinematic declares.
     *
     * @return string[]
     */
    private function checkpointIds(): array
    {
        if ($this->currentCutscene === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $checkpoint): string => is_scalar($checkpoint) ? trim(strval($checkpoint)) : '',
            (array) ($this->currentCutscene->data()['checkpoints'] ?? []),
        ), static fn(string $checkpoint): bool => $checkpoint !== ''));
    }

    /** A cutscene's own references: its cast, and the map-local subjects on the map it declares it starts on. */
    public function createCutsceneContext(CutsceneAsset $cutscene): self
    {
        return new self($this->workspace, $this->currentMap, $cutscene, $this->movementCommands);
    }

    /**
     * A reusable event script's references: its map-local subjects are on the map its callers run it on, when they
     * all run it on one ({@see \Ichiloto\Editor\Events\CommonEventMaps}); otherwise it names none.
     */
    public function createCommonEventContext(string $scriptId): self
    {
        $maps = \Ichiloto\Editor\Events\CommonEventMaps::read($this->workspace)->findMaps($scriptId);
        $map = count($maps) === 1 ? array_find($this->workspace->maps, static fn(ProjectMap $map): bool => $map->mapId === $maps[0]) : null;
        return new self($this->workspace, $map, null, $this->movementCommands);
    }

    /** A projection keeps the actual invocation as reference owner, rather than its one displayed command. */
    public function createMovementContext(array $commands): self
    {
        return new self($this->workspace, $this->currentMap, $this->currentCutscene, $commands);
    }

    /** Live invocation-local declarations; runtime checks completion, subject and one-time consumption. */
    public function getMovementRouteIds(?ProjectRecord $record = null, array $path = []): array
    {
        $commands = $this->movementCommands ?? ($record === null
            ? ($this->currentCutscene?->type === CutsceneType::CINEMATIC ? $this->currentCutscene->commands() : [])
            : MovementRouteFields::getOwnerCommands((array) $record->toArray(), $path));
        $ids = $visited = $events = [];
        foreach ($this->workspace->getRecordDatabase('common_events')?->getRecords() ?? [] as $eventRecord) {
            $events[$eventRecord->recordId] = $eventRecord->getSubList('commands');
        }
        $walk = static function (array $commands) use (&$walk, &$ids, &$visited, $events): void {
            foreach ($commands as $value) {
                if (!is_array($value)) { continue; }
                if (($value['type'] ?? '') === 'move_route' && is_string($value['remember'] ?? null)
                    && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $value['remember']) === 1
                    && strtolower(trim(strval($value['subject'] ?? 'player'))) !== 'staged_actor') {
                    $ids[$value['remember']] = $value['remember'];
                }
                $eventId = is_string($value['id'] ?? null) ? trim($value['id']) : '';
                if (($value['type'] ?? '') === 'common_event'
                    && isset($events[$eventId]) && !isset($visited[$eventId])) {
                    $visited[$eventId] = true;
                    $walk($events[$eventId]);
                }
                foreach (MovementRouteFields::getChildCommandLists($value) as $child) {
                    $walk($child);
                }
            }
        };
        $walk($commands);
        return array_values($ids);
    }

    /**
     * Returns the stable cue ids the current summon's or effect's timeline declares.
     *
     * @return string[]
     */
    private function getTimelineCueIds(CutsceneType $type): array
    {
        // An effect's payload is the sequence being edited, so its cues are
        // that sequence's.
        if ($this->currentCutscene?->type !== $type) {
            return [];
        }

        $ids = [];

        foreach ((array) ($this->currentCutscene->payload()[CutsceneSchemas::CUES_KEY] ?? []) as $cue) {
            $id = is_array($cue) ? trim(strval($cue['id'] ?? '')) : '';

            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * The subjects of the stage the current timeline sequence declares, by id.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getStageSubjects(): array
    {
        if (! in_array($this->currentCutscene?->type, [CutsceneType::SUMMON, CutsceneType::EFFECT], true)) {
            return [];
        }

        $stage = $this->currentCutscene->payload()['stage'] ?? null;
        $subjects = [];

        foreach (is_array($stage) && is_array($stage['subjects'] ?? null) ? $stage['subjects'] : [] as $subject) {
            $id = is_array($subject) ? trim(strval($subject['id'] ?? '')) : '';

            if ($id !== '') {
                $subjects[$id] ??= $subject;
            }
        }

        return $subjects;
    }

    /**
     * The named points of the current stage's subjects, by id, each with the
     * subjects that name it.
     *
     * @return array<string, list<string>>
     */
    private function getStageAttachments(): array
    {
        $points = [];

        foreach ($this->getStageSubjects() as $subject => $data) {
            foreach (is_array($data['attachments'] ?? null) ? $data['attachments'] : [] as $point) {
                $id = is_array($point) ? trim(strval($point['id'] ?? '')) : '';

                if ($id !== '') {
                    $points[$id][] = $subject;
                }
            }
        }

        return $points;
    }

    /**
     * Returns how each value of a reference kind should be shown, when the
     * value stored is not what an author recognises.
     *
     * An inventory reference stores `item.s-potion` and reads as
     * `S-Potion (item.s-potion)`: the name to recognise it by, and the
     * identity that will actually be written.
     *
     * @param string $category The kind of reference.
     * @return array<string|int, string> Labels keyed by stored value; PHP converts numeric ids to integer keys.
     */
    public function labelsFor(string $category): array
    {
        if (in_array($category, ['states', 'crisis_abilities'], true)) {
            $labels = [];
            foreach ($this->workspace->getRecordDatabase($category)?->getRecords() ?? [] as $record) {
                $id = $record->get('id');
                $name = $record->get('name');
                if (is_string($id) && $id !== '') {
                    $labels[$id] = is_string($name) && $name !== '' ? sprintf('%s (%s)', $name, $id) : $id;
                }
            }
            return $labels;
        }
        if ($category === 'event_markers') {
            // Each event read as what it is ("H · Script tracks"), from the map the picker reads.
            $map = $this->getSubjectMap();
            return $map === null ? [] : $this->listMapEventMarkers($map->mapId)['labels'];
        }

        if ($category === 'map_landmarks') {
            $labels = [];
            foreach ($this->readSubjectMapLandmarks() as $entry) {
                $name = is_array($entry['name'] ?? null) ? ($entry['name']['default'] ?? null) : ($entry['name'] ?? null);
                if (is_string($entry['id'] ?? null) && is_string($name) && $name !== '') {
                    $labels[$entry['id']] = sprintf('%s (%s)', $name, $entry['id']);
                }
            }
            return $labels;
        }

        if ($category === 'tilesets') {
            return $this->loadTilesetNames();
        }

        if ($category === 'income_schedules') {
            $labels = [];
            foreach ($this->incomeScheduleNames() as $id => $name) {
                $labels[$id] = sprintf('%s (%s)', $name, $id);
            }
            return $labels;
        }

        if ($category === 'battle_arenas') {
            return $this->loadArenaNames();
        }

        if ($category === 'field_resources') {
            $labels = [];
            foreach ($this->loadFieldResourceNames() as $id => $name) {
                $labels[(string) $id] = sprintf('%s (%s)', $name, $id);
            }

            return $labels;
        }

        if ($category === SkitPlaybackFields::REFERENCE_CATEGORY) {
            $labels = [];
            foreach ($this->listSkitTitles() as $id => $title) {
                $labels[$id] = $title !== '' && $title !== $id ? sprintf('%s (%s)', $title, $id) : $id;
            }

            return $labels;
        }

        if ($category === 'dialogue_identities') {
            $labels = [];
            foreach ($this->listDialogueIdentities() as $identity) {
                $labels[$identity['id']] = $identity['label'];
            }

            return $labels;
        }

        if ($category === 'animation_ids') {
            $labels = [];
            foreach ($this->animationRecords() as $animation) {
                $labels[strval($animation->get('id'))] = sprintf('%s (%s)', strval($animation->get('name')), strval($animation->get('id')));
            }
            return $labels;
        }

        if ($category === 'animation_roles') {
            // A role plays one animation, so the picker says which holds it.
            $database = $this->workspace->getRecordDatabase('animations');
            $owners = [];
            foreach ($database?->getRecords() ?? [] as $record) {
                foreach ((array) $record->get('roles') as $role) {
                    $owners[(string) $role] ??= $database->getEntryLabel($record);
                }
            }
            $labels = [];
            foreach ($this->valuesFor('animation_roles') as $role) {
                $labels[$role] = isset($owners[$role]) ? sprintf('%s (on %s)', $role, $owners[$role]) : $role;
            }

            return $labels;
        }

        if ($category === 'stage_attachments') {
            // A point is picked on the image's own subject: the label says which have it.
            $labels = [];
            foreach ($this->getStageAttachments() as $id => $subjects) {
                $labels[$id] = sprintf('%s (on %s)', $id, implode(', ', $subjects));
            }

            return $labels;
        }

        if ($category === 'elements_or_any') {
            return ['*' => '* (whichever element it was)'];
        }

        if ($category === 'actor_ids') {
            $labels = [];

            foreach ($this->workspace->actorDatabase->getActors() as $actor) {
                $id = $actor->getDefinitionId();
                $name = $actor->getName();
                if ($id === '') { continue; }

                if ($name !== '' && $name !== $id) {
                    $labels[$id] = sprintf('%s (%s)', $name, $id);
                }
            }

            return $labels;
        }

        if ($category === 'map_roles') {
            // Each role read by its name, from the catalogue as its page holds it.
            $labels = [];
            try {
                $roles = $this->getMapRoleCatalog();
                foreach ($roles->getIds() as $id) { $labels[$id] = sprintf('%s (%s)', $roles->getName($id), $id); }
            } catch (\InvalidArgumentException) {
                // An unreadable catalogue offers its ids as they are; validation names why.
            }

            return $labels;
        }

        if ($category === 'place_types') {
            // Each type read by its name, from the catalogue as its page holds it.
            $labels = [];
            try {
                $types = $this->getPlaceTypeCatalog();
                foreach ($types->getIds() as $id) { $labels[$id] = sprintf('%s (%s)', $types->getName($id), $id); }
            } catch (\InvalidArgumentException) {
                // An unreadable catalogue offers no types; validation names why.
            }

            return $labels;
        }

        if ($category === 'permanent_growth') {
            $catalog = $this->workspace->catalogs->loadPermanentGrowth();
            $labels = [];

            foreach ($catalog->ids() as $id) {
                $labels[$id] = $catalog->describe($id);
            }

            return $labels;
        }

        if (! in_array($category, ['inventory', 'items', 'weapons', 'armors',
            ...\Ichiloto\Editor\Actors\InitialEquipmentAuthoring::REFERENCES], true)) {
            return [];
        }

        $labels = [];

        foreach ($this->getInventoryCatalog()->definitions() as $id => $definition) {
            $labels[$id] = $this->getInventoryCatalog()->describe($id);
        }

        return $labels;
    }

    /**
     * Returns the project's inventory identity, read once.
     *
     * @return InventoryCatalog The catalogue.
     */
    public function getInventoryCatalog(): InventoryCatalog
    {
        return $this->inventoryCatalog ??= InventoryCatalog::fromWorkspace($this->workspace);
    }

    /**
     * Determines whether a category is one this knows how to resolve.
     *
     * @param string $category The kind of reference.
     * @return bool True when it is.
     */
    /**
     * The events of a map a map event condition names, each read as what it is
     * ("E · Chest"): what a marker is chosen from in both editors.
     *
     * @return array{values: list<string>, labels: array<string, string>}
     */
    public function listMapEventMarkers(string $mapId): array
    {
        $index = array_search($mapId, $this->workspace->mapIds, true);
        $map = $index === false ? null : ($this->workspace->maps[$index] ?? null);
        $events = $map?->getMapDataField(['events']);
        $values = $labels = [];
        foreach (is_array($events) ? $events : [] as $marker => $event) {
            $kind = preg_replace('/(EventTrigger|Trigger)$/', '', basename(str_replace('\\', '/', strval($event['class'] ?? ''))));
            $what = $event['data']['scriptId'] ?? $event['data']['cinematicId'] ?? $event['data']['destinationMap'] ?? null;
            $values[] = (string) $marker;
            $labels[(string) $marker] = trim(sprintf('%s · %s %s', $marker, $kind, is_string($what) ? $what : ''));
        }
        return ['values' => $values, 'labels' => $labels];
    }

    public static function knows(string $category): bool
    {
        return in_array($category, self::CATEGORIES, true);
    }

    /**
     * Returns the tracks the project has for an audio kind.
     *
     * The engine plays a track by the name it is filed under, resolving the
     * extension itself, so what is stored is the file's stem rather than its
     * path. Two encodings of one track therefore collapse into the single
     * name that plays either.
     *
     * @param string $category Either bgm or sfx.
     * @return string[] The track names, sorted.
     */
    private function audioValues(string $category): array
    {
        $directory = sprintf(
            '%s/assets/Audio/%s',
            rtrim($this->workspace->projectRoot, '/'),
            self::AUDIO_DIRECTORIES[$category] ?? ''
        );

        if (! is_dir($directory)) {
            return [];
        }

        $names = [];

        foreach ((array) scandir($directory) as $entry) {
            if (! is_string($entry) || str_starts_with($entry, '.')) {
                continue;
            }

            if (! is_file($directory . '/' . $entry)) {
                continue;
            }

            $names[] = pathinfo($entry, PATHINFO_FILENAME);
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Returns the vocabulary the project's own equipment uses for a field.
     *
     * An availability, an acquisition policy and a special property are all
     * project words -- the runtime imposes no list of them -- so what a
     * picker can offer is what the project has already said somewhere,
     * including what a record says by leaving the engine's default in place.
     * A special property is a shape rather than a string, and it is the
     * `type` inside it that Optimize weighs.
     *
     * @param string $field The equipment field.
     * @return string[] The distinct values, sorted.
     */
    private function equipmentVocabulary(string $field): array
    {
        $values = [];

        foreach (InventoryCatalog::CATEGORIES as $category) {
            $database = $this->workspace->getRecordDatabase($category);

            if (! $database instanceof ProjectRecordDatabase) {
                continue;
            }

            foreach ($database->getRecords() as $record) {
                $definition = InventoryCatalog::readDefinition($record);
                $value = $definition !== null && property_exists($definition, $field) ? $definition->{$field} : null;

                if ($field === 'specialProperty') {
                    $value = is_array($value) ? ($value['type'] ?? null) : null;
                }

                if (is_scalar($value) && trim(strval($value)) !== '') {
                    $values[] = trim(strval($value));
                }
            }
        }

        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * Returns the kinds of record the project's catalogue declares.
     *
     * @return string[] The record types.
     */
    private function knowledgeRecordTypes(): array
    {
        $catalog = $this->knowledgeCatalog();

        $types = [];

        foreach ((array) ($catalog['recordTypes'] ?? []) as $entry) {
            try {
                $types[] = KnowledgeRecordType::fromEntry($entry)->id;
            } catch (InvalidArgumentException) {
                // Validation reports an entry the runtime cannot read.
            }
        }

        return $types;
    }

    /**
     * Returns every observation the project's subjects author.
     *
     * The runtime refuses an observation a subject does not author, so what
     * is offered is what some subject has declared. The list spans subjects
     * because a command names its subject separately; validation is what
     * checks the pair.
     *
     * @return string[] The observation ids, in authored order.
     */
    private function knowledgeObservations(): array
    {
        $observations = [];

        foreach ((array) ($this->knowledgeCatalog()['subjects'] ?? []) as $subject) {
            foreach (is_array($subject) && is_array($subject['observations'] ?? null) ? $subject['observations'] : [] as $observation) {
                $observation = is_scalar($observation) ? trim(strval($observation)) : '';

                if ($observation !== '') {
                    $observations[] = $observation;
                }
            }
        }

        return array_values(array_unique($observations));
    }

    /**
     * Returns the stable ids the project's knowledge catalogue declares.
     *
     * The file is read for its ids alone, so a project whose catalogue is
     * mid-edit still offers what it has rather than nothing.
     *
     * @param string $section Either subjects or reports.
     * @return string[] The ids, in authored order.
     */
    private function knowledgeIds(string $section): array
    {
        $ids = [];

        foreach ((array) ($this->knowledgeCatalog()[$section] ?? []) as $entry) {
            $id = is_array($entry) ? trim(strval($entry['id'] ?? '')) : '';

            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Returns the project's knowledge catalogue as authored.
     *
     * Read for its declarations alone, so a catalogue mid-edit still offers
     * what it has rather than nothing.
     *
     * @return array<string, mixed> The catalogue.
     */
    private function knowledgeCatalog(): array
    {
        try {
            return $this->workspace->catalogs->readKnowledgeData();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Returns the elements the game knows ({@see ProjectWorkspace::getElementIdentities()}).
     * A list the game would refuse offers nothing; validation says why.
     *
     * @return string[] The element identities, in authored order.
     */
    private function elementValues(): array
    {
        try {
            return $this->workspace->getElementIdentities();
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    /**
     * Returns the files a project has in a directory, names as stored.
     *
     * @param string $relativeDirectory The directory under the project root.
     * @return string[] The filenames, sorted.
     */
    private function fileValues(string $relativeDirectory): array
    {
        $directory = rtrim($this->workspace->projectRoot, '/') . '/' . $relativeDirectory;

        if (! is_dir($directory)) {
            return [];
        }

        $names = [];

        foreach ((array) scandir($directory) as $entry) {
            if (is_string($entry) && ! str_starts_with($entry, '.') && is_file($directory . '/' . $entry)) {
                $names[] = pathinfo($entry, PATHINFO_FILENAME);
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * The name of every tileset in assets/Data/Tilesets that loads, by id. One
     * that cannot load is not offered; validation reports it.
     *
     * @return array<string, string>
     */
    /**
     * The encounter terrains every terrain kind of the project's tilesets
     * meets, each once, in tileset then kind order. A tileset that cannot be
     * read offers none; project validation reports why.
     *
     * @return list<string>
     */
    private function loadEncounterTerrains(): array
    {
        $catalogs = $this->workspace->catalogs;
        $terrains = [];
        foreach ($catalogs->listTilesetIds() as $id) {
            try {
                foreach ($catalogs->loadTileset($id)->kinds as $kind) {
                    if ($kind->encounters !== null) {
                        $terrains[$kind->encounters] = $kind->encounters;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return array_values($terrains);
    }

    private function loadTilesetNames(): array
    {
        $catalogs = $this->workspace->catalogs;
        $names = [];
        foreach ($catalogs->listTilesetIds() as $id) {
            try {
                $names[$id] = $catalogs->loadTileset($id)->name;
            } catch (\Throwable) {
                continue;
            }
        }

        return $names;
    }

    /** A project map by id, unsaved edits included, as the workspace holds it. */
    public function findMap(string $mapId): ?ProjectMap
    {
        $index = array_search($mapId, $this->workspace->mapIds, true);
        return is_int($index) ? ($this->workspace->maps[$index] ?? null) : null;
    }

    /** @return list<ProjectMap> Every project map, unsaved edits included. */
    public function listMaps(): array
    {
        return array_values($this->workspace->maps);
    }

    /**
     * The project's place categories as the Engine reads them: its generic ones and those the project declares,
     * as its Place Categories page holds them.
     *
     * @throws \InvalidArgumentException When the project's catalogue cannot be read.
     */
    /**
     * The project's dialogue catalogue as the Engine makes it, from its Dialogue Portraits and Speaker Names pages
     * with their unsaved edits, or from the file when those pages are not open.
     *
     * @throws \InvalidArgumentException|\RuntimeException When the Engine would refuse the catalogue.
     */
    public function getDialoguePresentationCatalog(): DialoguePresentationCatalog
    {
        return $this->workspace->catalogs->loadDialoguePresentation();
    }

    /**
     * The identities and speaker names of the project's dialogue catalogue as its Dialogue Portraits and Speaker
     * Names pages hold them, unsaved edits included, in the catalogue's own shape; null when those pages are not
     * open, where the file is the Engine's to read.
     *
     * @return array<string, mixed>|null
     */
    public function getDialogueCatalogPayload(): ?array
    {
        $portraits = $this->workspace->getRecordDatabase(DialoguePortraitFields::CATEGORY);
        $speakers = $this->workspace->getRecordDatabase(DialogueSpeakerFields::CATEGORY);
        if ($portraits?->schema->projection === null || $speakers?->schema->projection === null) {
            return null;
        }
        $whole = $this->workspace->catalogs->readData(DialoguePresentationCatalog::FILE)['payload'];

        return is_array($whole) ? $whole : ['schema' => DialogueCatalogFile::SCHEMA];
    }

    /**
     * The catalogue's identities, actors first, each with how a picker shows it.
     *
     * @return list<array{id: string, label: string}>
     */
    private function listDialogueIdentities(): array
    {
        try {
            $catalogue = $this->getDialoguePresentationCatalog();
        } catch (Throwable) {
            // Validation reports a catalogue that cannot be read; nothing can be chosen from it.
            return [];
        }
        $identities = [];
        foreach (array_keys($catalogue->actors) as $id) {
            $identities[] = ['id' => (string) $id, 'label' => sprintf('%s (actor)', $id)];
        }
        foreach (array_keys($catalogue->resources) as $id) {
            $identities[] = ['id' => (string) $id, 'label' => sprintf('%s (character)', $id)];
        }

        return $identities;
    }

    /**
     * The emotions one line may show, as its speaker's catalogue identity names them: who speaks, the identity
     * whose artwork shows (null when the speaker has none), the emotions offered, and why none beyond Neutral are
     * offered when the catalogue cannot be read.
     *
     * @return array{speaker: string, identity: ?string, emotions: list<string>, issue: ?string}
     */
    public function describeDialogueEmotions(DialogueLineSpeaker $rule, array $line, array $owner = []): array
    {
        try {
            $catalogue = $this->getDialoguePresentationCatalog();
        } catch (Throwable $error) {
            return DialogueEmotionFields::describeUnreadableCatalogue($rule, $line, $owner, $error);
        }

        return DialogueEmotionFields::describeChoices($rule, $line, $owner, $catalogue);
    }

    /**
     * The project's map roles as the Map Roles page holds them, unsaved edits included.
     *
     * @throws \InvalidArgumentException With the Engine's reason when they are refused.
     */
    public function getMapRoleCatalog(): \Ichiloto\Engine\Field\MapRoleCatalog
    {
        $database = $this->workspace->getRecordDatabase(\Ichiloto\Editor\Field\MapRoleFields::CATEGORY);
        if (($reason = $database?->getReadOnlyReason()) !== null) {
            throw new \InvalidArgumentException($reason);
        }

        return $this->workspace->catalogs->loadMapRoles();
    }

    /**
     * The project's place types as the Place Types page holds them, unsaved edits included.
     *
     * @throws \InvalidArgumentException With the Engine's reason when they are refused.
     */
    public function getPlaceTypeCatalog(): \Ichiloto\Engine\Field\PlaceTypeCatalog
    {
        $database = $this->workspace->getRecordDatabase(\Ichiloto\Editor\Field\PlaceTypeFields::CATEGORY);
        if (($reason = $database?->getReadOnlyReason()) !== null) {
            throw new \InvalidArgumentException($reason);
        }

        return $this->workspace->catalogs->loadPlaceTypes();
    }

    /**
     * The project's regions as the Regions page holds them, unsaved edits included.
     *
     * @throws \InvalidArgumentException With the Engine's reason when they are refused.
     */
    public function getRegionCatalog(): \Ichiloto\Engine\Field\RegionCatalog
    {
        $database = $this->workspace->getRecordDatabase(\Ichiloto\Editor\Field\RegionFields::CATEGORY);
        if (($reason = $database?->getReadOnlyReason()) !== null) {
            throw new \InvalidArgumentException($reason);
        }

        return $this->workspace->catalogs->loadRegions();
    }

    public function getMapCategoryCatalog(): \Ichiloto\Engine\Field\MapCategoryCatalog
    {
        // The project's own categories as the Place Categories page holds them, unsaved edits included.
        $database = $this->workspace->getRecordDatabase(\Ichiloto\Editor\Field\MapCategoryFields::CATEGORY);
        if (($reason = $database?->getReadOnlyReason()) !== null) {
            throw new \InvalidArgumentException($reason);
        }

        return $this->workspace->catalogs->loadMapCategories();
    }

    /**
     * Returns the arenas the project declares, key => display name, in
     * authored order: those its battle presentation code declares, then
     * those bound as data (unsaved edits included). None for a project
     * without either, or whose arenas cannot be read, which validation
     * reports.
     *
     * @return array<string, string>
     */
    private function loadArenaNames(): array
    {
        try {
            // The code never changes in a session; the data is read as it is now.
            $this->arenaNames ??= BattlePresentationCatalog::loadCode(rtrim($this->workspace->projectRoot, '/') . '/assets')?->getArenaChoices() ?? [];
            $data = array_map(static fn(array $arena): string => $arena['name'], $this->workspace->catalogs->loadArenaBindings()?->arenas ?? []);

            return [...$this->arenaNames, ...$data];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Returns asset-root-relative PNG choices without following paths outside the asset root. */
    public static function getPngAssets(string $projectRoot): array
    {
        $root = realpath(rtrim($projectRoot, '/') . '/assets');
        if ($root === false) {
            return [];
        }
        $paths = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $resolved = $file->getRealPath();
            if ($file->isFile() && strtolower($file->getExtension()) === 'png' && $resolved !== false
                && str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
                $paths[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }
        sort($paths);
        return $paths;
    }

    /**
     * Every node of the mastery tree, by id, as the regions and branches are
     * being edited: trunk nodes region by region, then each branch's nodes.
     *
     * @return list<string>
     */
    private function masteryNodeIds(): array
    {
        $ids = [];
        foreach ([MasteryFields::REGIONS, MasteryFields::BRANCHES] as $category) {
            foreach ($this->workspace->getRecordDatabase($category)?->getRecords() ?? [] as $record) {
                foreach ($record->getSubList('nodes') as $node) {
                    $id = trim(strval($node['id'] ?? ''));
                    if ($id !== '') {
                        $ids[] = $id;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The project's income schedules as the Income page holds them, unsaved
     * edits included: name by stable id.
     *
     * @return array<string, string>
     */
    private function incomeScheduleNames(): array
    {
        $names = [];
        foreach ($this->workspace->getRecordDatabase('income_schedules')?->getRecords() ?? [] as $record) {
            $id = trim(strval($record->get('id')));
            if ($id !== '') {
                $names[$id] = trim(strval($record->get('name'))) ?: $id;
            }
        }
        return $names;
    }

    /**
     * Returns the values a schema-driven category defines.
     *
     * @param string $category The category key.
     * @return string[] The values.
     */
    /**
     * The project's Skits' titles, keyed by stable id, in the order the
     * Skit category lists them.
     *
     * @return array<string, string>
     */
    private function listSkitTitles(): array
    {
        $titles = [];
        foreach ($this->workspace->getRecordDatabase(SkitPlaybackFields::REFERENCE_CATEGORY)?->getRecords() ?? [] as $record) {
            $id = $record->get('id');
            if (is_string($id) && trim($id) !== '') {
                $titles[$id] = trim(strval($record->get('title') ?? ''));
            }
        }

        return $titles;
    }

    private function recordValues(string $category): array
    {
        $database = $this->workspace->getRecordDatabase($category);

        if (! $database instanceof ProjectRecordDatabase) {
            return [];
        }

        return array_values(array_filter(
            $database->getEntryLabels(),
            static fn(string $label): bool => trim($label) !== ''
        ));
    }
}
