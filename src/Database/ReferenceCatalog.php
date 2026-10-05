<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\PermanentGrowthCatalog;
use Ichiloto\Editor\ProjectActor;
use Ichiloto\Editor\ProjectQuest;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Battle\CounterAttackRule;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Entities\Enumerations\ArmorType;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Entities\Magic\MagicEffectType;
use Ichiloto\Engine\Entities\Skills\SkillResolutionScope;
use InvalidArgumentException;

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
    public const array CATEGORIES = [
        'actors',
        'actor_ids',
        'classes',
        'attack_skills',
        'counter_skills',
        'resolution_kinds',
        'piece_connections',
        'resolution_scopes',
        'magic_effect_types',
        'weapon_types',
        'armor_types',
        'skills',
        'quests',
        'maps',
        'items',
        'weapons',
        'armors',
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
        'summon_cues',
        'effect_cues',
        'event_markers',
        'tilesets',
        'effects',
        'map_regions',
        'animation_roles',
        'battle_arenas',
    ];

    /**
     * Where each audio kind's files live, under assets/Audio.
     */
    private const array AUDIO_DIRECTORIES = [
        'bgm' => 'BGM',
        'sfx' => 'SFX',
    ];

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
            // A class restricts what its members equip by the engine's type names.
            // How a skill effect resolves, how often a skill rolls, and what
            // kind of spell it is: the Engine's own vocabularies.
            'resolution_kinds' => array_map(static fn(ResolutionKind $kind): string => $kind->value, ResolutionKind::cases()),
            // How a tileset piece joins the cells beside it.
            'piece_connections' => [\Ichiloto\Engine\Rendering\Tilesets\TilesetPiece::LINES],
            'resolution_scopes' => array_map(static fn(SkillResolutionScope $scope): string => $scope->value, SkillResolutionScope::cases()),
            'magic_effect_types' => array_map(static fn(MagicEffectType $type): string => $type->value, MagicEffectType::cases()),
            'weapon_types' => array_map(static fn(WeaponType $type): string => $type->value, WeaponType::cases()),
            'armor_types' => array_map(static fn(ArmorType $type): string => $type->value, ArmorType::cases()),
            // Spells and abilities may be authored in any of the Engine's
            // skill files; a reference names the skill wherever it lives.
            'skills' => $this->workspace->getSkillNames(),
            'quests' => array_map(static fn(ProjectQuest $quest): string => $quest->getId(), $this->workspace->getQuests()),
            'maps' => $this->workspace->mapIds,
            // A shop's stock is whatever the engine's ItemStore holds, and
            // that is everything in items.php: items, weapons and armors
            // alike. Offering only the items would refuse a sword a shop is
            // entitled to sell. What is offered -- and stored -- is the
            // stable definition id, which is the identity the runtime
            // resolves and the one thing a rename does not change.
            'inventory' => $this->inventoryCatalog()->ids(),
            'items' => $this->inventoryCatalog()->idsIn('items'),
            'weapons' => $this->inventoryCatalog()->idsIn('weapons'),
            'armors' => $this->inventoryCatalog()->idsIn('armors'),
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
            'permanent_growth' => PermanentGrowthCatalog::fromProject($this->workspace->projectRoot)->ids(),
            'knowledge_record_types' => $this->knowledgeRecordTypes(),
            'knowledge_observations' => $this->knowledgeObservations(),
            'knowledge_subjects' => $this->knowledgeIds('subjects'),
            'knowledge_reports' => $this->knowledgeIds('reports'),
            // NPC ids are map-local, so the choices are the current map's:
            // read live from the collection, a just-created NPC is offered
            // at once and a deleted one is gone.
            'map_npcs' => $this->currentMap?->getNpcs()->ids() ?? [],
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
            'summon_cues' => $this->getTimelineCueIds(CutsceneType::SUMMON),
            'effect_cues' => $this->getTimelineCueIds(CutsceneType::EFFECT),
            'event_markers' => $this->currentMap?->getEventMarkers() ?? [],
            // A map's kind is one of the project's tilesets, by file stem.
            'tilesets' => array_keys($this->loadTilesetNames()),
            // The scenes a graphical battle can take place in, by the key a
            // map's encounters or a start_battle command name one with.
            'battle_arenas' => array_map(strval(...), array_keys($this->loadArenaNames())),
            // A region is the display name the game shows for where the party
            // is; choosing from the names the maps already use keeps one
            // region spelled one way.
            'map_regions' => $this->mapRegions(),
            // The battle roles an animation can play, as the Engine resolves them.
            'animation_roles' => \Ichiloto\Engine\Animations\ActionAnimationResolver::getSupportedRoles(),
            // Effect timelines are folders the Engine lists by stable id.
            'effects' => new EffectTimelineLibrary($this->workspace->projectRoot . DIRECTORY_SEPARATOR . 'assets')->findTimelineIds(),
            default => $this->recordValues($category),
        };
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
            ...($this->currentMap?->getNpcs()->ids() ?? []),
            ...$this->valuesFor('actors'),
            ...($this->currentMap?->getEventMarkers() ?? []),
        ]));
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
        if ($category === 'tilesets') {
            return $this->loadTilesetNames();
        }

        if ($category === 'battle_arenas') {
            return $this->loadArenaNames();
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

        if ($category === 'permanent_growth') {
            $catalog = PermanentGrowthCatalog::fromProject($this->workspace->projectRoot);
            $labels = [];

            foreach ($catalog->ids() as $id) {
                $labels[$id] = $catalog->describe($id);
            }

            return $labels;
        }

        if (! in_array($category, ['inventory', 'items', 'weapons', 'armors'], true)) {
            return [];
        }

        $labels = [];

        foreach ($this->inventoryCatalog()->definitions() as $id => $definition) {
            $labels[$id] = $this->inventoryCatalog()->describe($id);
        }

        return $labels;
    }

    /**
     * Returns the project's inventory identity, read once.
     *
     * @return InventoryCatalog The catalogue.
     */
    private function inventoryCatalog(): InventoryCatalog
    {
        return $this->inventoryCatalog ??= InventoryCatalog::fromWorkspace($this->workspace);
    }

    /**
     * Determines whether a category is one this knows how to resolve.
     *
     * @param string $category The kind of reference.
     * @return bool True when it is.
     */
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

        return array_values(array_filter(
            array_map(strval(...), (array) ($catalog['recordTypes'] ?? [])),
            static fn(string $type): bool => trim($type) !== '',
        ));
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
        $path = rtrim($this->workspace->projectRoot, '/') . '/assets/Data/knowledge.php';

        if (! is_file($path)) {
            return [];
        }

        try {
            $catalog = (static fn(): mixed => require $path)();
        } catch (\Throwable) {
            return [];
        }

        return is_array($catalog) ? $catalog : [];
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
    private function loadTilesetNames(): array
    {
        $assetRoot = rtrim($this->workspace->projectRoot, '/') . '/assets';
        $names = [];
        foreach (glob($assetRoot . '/' . Tileset::DIRECTORY . '/*.php') ?: [] as $file) {
            $id = basename($file, '.php');
            try {
                $names[$id] = Tileset::load($assetRoot, $id)->name;
            } catch (\Throwable) {
                continue;
            }
        }

        return $names;
    }

    /**
     * Returns the arenas the project's battle presentation declares, key =>
     * display name, in authored order: none for a project without one, or
     * whose presentation cannot be read, which validation reports.
     *
     * @return array<string, string>
     */
    private function loadArenaNames(): array
    {
        try {
            return $this->arenaNames ??= BattlePresentationCatalog::load(rtrim($this->workspace->projectRoot, '/') . '/assets')?->getArenaChoices() ?? [];
        } catch (\Throwable) {
            return $this->arenaNames = [];
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
     * Returns the values a schema-driven category defines.
     *
     * @param string $category The category key.
     * @return string[] The values.
     */
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
