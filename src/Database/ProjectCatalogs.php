<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Dialogue\DialogueCatalogFile;
use Ichiloto\Editor\PermanentGrowthCatalog;
use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattleArenaBindings;
use Ichiloto\Engine\Battle\Presentation\BattlerBindings;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Entities\Inventory\ItemCatalog;
use Ichiloto\Engine\Entities\Inventory\ItemRecord;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SkillDraft;
use Ichiloto\Engine\Field\MapCategoryCatalog;
use Ichiloto\Engine\Field\PlayerPresentationConfig;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeCatalog;
use Ichiloto\Engine\Rendering\Presentation\Text\ProjectTextCatalog;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use InvalidArgumentException;
use Throwable;

/**
 * The project's catalogues as the workspace holds them: what every reader of
 * a catalogue or record by its path reads, so a record edited earlier in a
 * session (in an open change set or not) reaches the authoring, validation
 * and previews after it before anything is written. A catalogue whose files
 * hold no unsaved edit is loaded from disk by the Engine's own loader; one
 * that does is built by the Engine from the payload the workspace would write
 * ({@see ProjectWorkspace::readCurrentData()}).
 *
 * @package Ichiloto\Editor\Database
 */
final readonly class ProjectCatalogs
{
    public const string KNOWLEDGE_FILE = 'Data/knowledge.php';
    public const string EQUIPMENT_OPTIMIZATION_FILE = 'Data/equipment-optimization.php';
    public const string ANIMATIONS_FILE = 'Data/animations.php';
    public const string PLAYER_PRESENTATION_FILE = 'Data/Entities/player.php';

    public function __construct(private ProjectWorkspace $workspace)
    {
    }

    /** @return non-empty-string The project's asset root. */
    public function getAssetRoot(): string
    {
        return rtrim($this->workspace->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'assets';
    }

    /**
     * A data file as the workspace would write it, or as it stands on disk
     * when nothing unsaved holds it. A file that does not exist reads as
     * absent.
     *
     * @param string $assetPath The data file, relative to the asset root.
     * @return array{exists: bool, payload: mixed}
     * @throws Throwable When the file on disk cannot be read.
     */
    public function readData(string $assetPath): array
    {
        $current = $this->readCurrent($assetPath);
        if ($current !== null) {
            return $current;
        }
        $path = $this->getAssetRoot() . DIRECTORY_SEPARATOR . $assetPath;
        if (! is_file($path)) {
            return ['exists' => false, 'payload' => null];
        }

        return ['exists' => true, 'payload' => ProjectDirectoryContext::run($this->workspace->projectRoot,
            static fn(): mixed => (static fn(string $file): mixed => require $file)($path))];
    }

    /**
     * A tileset as the Tilesets page holds it.
     *
     * @throws InvalidArgumentException With the Engine's reason when it is missing or refused.
     */
    public function loadTileset(string $id): Tileset
    {
        $current = $this->readCurrent(Tileset::DIRECTORY . '/' . $id . '.php');
        if ($current === null) {
            return Tileset::load($this->getAssetRoot(), $id);
        }
        if (! $current['exists']) {
            throw new InvalidArgumentException("Tileset {$id} was not found at " . Tileset::DIRECTORY . "/{$id}.php.");
        }

        return Tileset::fromArray($id, $current['payload']);
    }

    /**
     * The id of every tileset the project holds: the Tilesets page's records,
     * unsaved ones included and deleted ones left out, or the files when no
     * page is loaded.
     *
     * @return list<string>
     */
    public function listTilesetIds(): array
    {
        $database = $this->workspace->getRecordDatabase('tilesets');
        if ($database !== null) {
            return array_values(array_map(static fn(ProjectRecord $record): string => $record->recordId, $database->getRecords()));
        }

        return array_map(static fn(string $file): string => basename($file, '.php'),
            glob($this->getAssetRoot() . DIRECTORY_SEPARATOR . Tileset::DIRECTORY . DIRECTORY_SEPARATOR . '*.php') ?: []);
    }

    /**
     * The field presentation (`Data/Presentation/field.php`) as the Field
     * Resources page holds it.
     *
     * @throws InvalidArgumentException With the Engine's reason when it is refused.
     */
    public function loadFieldPresentation(): FieldPresentationCatalog
    {
        $assets = $this->getAssetRoot();
        $current = $this->readCurrent(FieldPresentationCatalog::FILE);

        return ProjectDirectoryContext::run($this->workspace->projectRoot, static fn(): FieldPresentationCatalog => $current === null
            ? FieldPresentationCatalog::load($assets)
            : FieldPresentationCatalog::fromArray(is_array($current['payload']) ? $current['payload'] : [], $assets));
    }

    /**
     * The place categories as the Place Categories page holds them.
     *
     * @throws InvalidArgumentException With the Engine's reason when they are refused.
     */
    public function loadMapCategories(): MapCategoryCatalog
    {
        $current = $this->readCurrent(MapCategoryCatalog::FILE);

        return $current === null ? MapCategoryCatalog::load($this->getAssetRoot()) : MapCategoryCatalog::fromArray($current['payload']);
    }

    /**
     * The map roles as the Map Roles page holds them.
     *
     * @throws InvalidArgumentException With the Engine's reason when they are refused.
     */
    public function loadMapRoles(): \Ichiloto\Engine\Field\MapRoleCatalog
    {
        $current = $this->readCurrent(\Ichiloto\Engine\Field\MapRoleCatalog::FILE);

        return $current === null ? \Ichiloto\Engine\Field\MapRoleCatalog::load($this->getAssetRoot())
            : \Ichiloto\Engine\Field\MapRoleCatalog::fromArray($current['payload']);
    }

    /**
     * The place types as the Place Types page holds them.
     *
     * @throws InvalidArgumentException With the Engine's reason when they are refused.
     */
    public function loadPlaceTypes(): \Ichiloto\Engine\Field\PlaceTypeCatalog
    {
        $current = $this->readCurrent(\Ichiloto\Engine\Field\PlaceTypeCatalog::FILE);

        return $current === null ? \Ichiloto\Engine\Field\PlaceTypeCatalog::load($this->getAssetRoot())
            : \Ichiloto\Engine\Field\PlaceTypeCatalog::fromArray($current['payload']);
    }

    /**
     * The text roles (`Data/Presentation/text.php`) as the Text Roles page
     * holds them; null when the project declares none.
     *
     * @throws InvalidArgumentException With the Engine's reason when they are refused.
     */
    public function loadTextCatalog(): ?ProjectTextCatalog
    {
        $current = $this->readCurrent(ProjectTextCatalog::FILE);
        if ($current === null) {
            return ProjectTextCatalog::load($this->getAssetRoot());
        }

        return $current['exists'] ? ProjectTextCatalog::fromArray($current['payload']) : null;
    }

    /**
     * The dialogue catalogue (`Data/Presentation/dialogue.php`) as the
     * Dialogue Portraits and Speaker Names pages hold it, with the keys
     * neither page owns (theme, skits, skit stage) as the file has them.
     *
     * @throws InvalidArgumentException|\RuntimeException When the Engine would refuse the catalogue.
     */
    public function loadDialoguePresentation(): DialoguePresentationCatalog
    {
        $current = $this->readCurrent(DialoguePresentationCatalog::FILE);
        if ($current === null) {
            return DialoguePresentationCatalog::load($this->getAssetRoot());
        }

        return DialogueCatalogFile::createCatalog(is_array($current['payload']) ? $current['payload'] : [], $this->getAssetRoot());
    }

    /** The permanent-growth definitions as the Permanent Growth page holds them. */
    public function loadPermanentGrowth(): PermanentGrowthCatalog
    {
        $current = $this->workspace->readCurrentData(rtrim($this->workspace->projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . PermanentGrowthCatalog::RELATIVE_PATH);
        if ($current === null) {
            return PermanentGrowthCatalog::fromProject($this->workspace->projectRoot);
        }

        return PermanentGrowthCatalog::fromPayload(is_array($current['payload']) ? $current['payload'] : []);
    }

    /**
     * The knowledge catalogue's declarations as the Knowledge pages hold
     * them; empty when the project declares none.
     *
     * @return array<array-key, mixed>
     * @throws Throwable When the file on disk cannot be read.
     */
    public function readKnowledgeData(): array
    {
        $payload = $this->readData(self::KNOWLEDGE_FILE)['payload'];

        return is_array($payload) ? $payload : [];
    }

    /**
     * The knowledge catalogue the Engine makes of {@see readKnowledgeData()}.
     *
     * @throws Throwable When the Engine refuses it or the file cannot be read.
     */
    public function loadKnowledgeCatalog(): KnowledgeCatalog
    {
        return new KnowledgeCatalog($this->readKnowledgeData());
    }

    /**
     * The battler bindings (`Data/Presentation/battlers.php`) as the battle
     * art pages hold them; null when the project declares none.
     *
     * @throws Throwable With the Engine's reason when they are refused.
     */
    public function loadBattlerBindings(): ?BattlerBindings
    {
        $current = $this->readCurrent(BattlerBindings::FILE);
        if ($current === null) {
            return BattlerBindings::load($this->getAssetRoot());
        }
        if (! $current['exists']) {
            return null;
        }

        return BattlerBindings::getFromArray(is_array($current['payload']) ? $current['payload'] : [], $this->getAssetRoot());
    }

    /**
     * The battle arenas (`Data/Presentation/arenas.php`) as the Battle Arenas
     * page holds them; null when the project declares none.
     *
     * @throws Throwable With the Engine's reason when they are refused.
     */
    public function loadArenaBindings(): ?BattleArenaBindings
    {
        $current = $this->readCurrent(BattleArenaBindings::FILE);
        if ($current === null) {
            return BattleArenaBindings::load($this->getAssetRoot());
        }
        if (! $current['exists']) {
            return null;
        }

        return BattleArenaBindings::getFromArray(is_array($current['payload']) ? $current['payload'] : []);
    }

    /**
     * The battle presentation: the code-owned catalogue
     * (`Data/Presentation/battle.php`, which no page edits) with the current
     * battler bindings and arenas bound to it. Null when the project has none.
     *
     * @throws Throwable With the Engine's reason when it is refused.
     */
    public function loadBattlePresentation(): ?BattlePresentationCatalog
    {
        $catalog = BattlePresentationCatalog::loadCode($this->getAssetRoot());
        if ($catalog === null) {
            return null;
        }
        $bindings = $this->loadBattlerBindings();
        $catalog = $bindings === null ? $catalog : $catalog->bindBattlers($bindings);
        $arenas = $this->loadArenaBindings();

        return $arenas === null ? $catalog : $catalog->bindArenas($arenas);
    }

    /** The player's presentation (`Data/Entities/player.php`) as the Player page holds it. */
    public function loadPlayerPresentation(): PlayerPresentationConfig
    {
        $current = $this->readCurrent(self::PLAYER_PRESENTATION_FILE);
        if ($current === null) {
            return ProjectDirectoryContext::run($this->workspace->projectRoot, static fn(): PlayerPresentationConfig => PlayerPresentationConfig::load());
        }

        return PlayerPresentationConfig::fromArray(is_array($current['payload']) ? $current['payload'] : []);
    }

    /**
     * The skill catalogue as the Skills page and the summons hold it: when
     * either holds an unsaved edit, built by the Engine from the skill
     * records as they stand (in file order, a record that cannot be read
     * reported against its file) and the current summons; otherwise read
     * by the Engine from disk.
     */
    public function loadSkillCatalog(): SkillCatalog
    {
        $database = $this->workspace->getRecordDatabase('skills');
        if (($database === null || ! $database->isDirty()) && ! $this->holdsUnsaved(CutsceneType::SUMMON)) {
            return SkillCatalog::load($this->getAssetRoot());
        }
        if ($database === null) {
            $disk = SkillCatalog::load($this->getAssetRoot());
            $skills = [];
            $drafts = [];
            foreach ($disk->getSkills() as $name => $skill) {
                $skills[$disk->getSourceFile((string) $name) ?? (string) $name] = $skill;
            }
            foreach ($disk->getDrafts() as $name => $draft) {
                $drafts[$disk->getSourceFile((string) $name) ?? (string) $name] = $draft;
            }

            return SkillCatalog::fromSkills($skills, $disk->getProblems(), $this->loadSummons(), $drafts);
        }

        // A record still awaiting its author's values reads as a draft, as the Engine reads it.
        [$records, $problems] = self::readRecordFiles($database, SkillCatalog::readRecordData(...));
        $drafts = array_filter($records, static fn(mixed $record): bool => $record instanceof SkillDraft);

        return SkillCatalog::fromSkills(array_diff_key($records, $drafts), $problems, $this->loadSummons(), $drafts);
    }

    /**
     * What the Engine's inventory catalogue would report against the item,
     * weapon and armor record files as their pages hold them: each file it
     * cannot read, by its file under `assets/Data`, in its order.
     *
     * @return list<string>
     */
    public function listItemRecordProblems(): array
    {
        $databases = array_map($this->workspace->getRecordDatabase(...), InventoryCatalog::CATEGORIES);
        $disk = null;
        $problems = [];
        foreach ($databases as $index => $database) {
            if ($database !== null && $database->isDirty()) {
                $problems = [...$problems, ...self::readRecordFiles($database, ItemRecord::readItem(...))[1]];
                continue;
            }
            $disk ??= ItemCatalog::load($this->getAssetRoot())->getProblems();
            $directory = ItemCatalog::DIRECTORIES[$index] . '/';
            $problems = [...$problems, ...array_values(array_filter($disk, static fn(string $problem): bool => str_starts_with($problem, $directory)))];
        }

        return $problems;
    }

    /**
     * The summons as the Cutscenes workspace holds them, by name, a summon
     * the Engine refuses left out as its loader leaves it out.
     *
     * @return list<SummonCutsceneDefinition>
     */
    public function loadSummons(): array
    {
        $cutscenes = $this->workspace->cutscenes;
        if ($cutscenes === null || ! $this->holdsUnsaved(CutsceneType::SUMMON)) {
            return new SummonCutsceneLibrary($this->getAssetRoot() . DIRECTORY_SEPARATOR . 'Cutscenes' . DIRECTORY_SEPARATOR . 'Summons')->load();
        }
        $definitions = [];
        foreach ($cutscenes->assets(CutsceneType::SUMMON) as $asset) {
            if ($asset->isDeleted()) {
                continue;
            }
            try {
                $definitions[] = $asset->summonDefinition();
            } catch (Throwable) {
                continue;
            }
        }
        usort($definitions, static fn(SummonCutsceneDefinition $left, SummonCutsceneDefinition $right): int => $left->name <=> $right->name);

        return $definitions;
    }

    /**
     * The data file of every summon as the Cutscenes workspace holds them,
     * deleted ones left out and new ones included, in path order.
     *
     * @return list<string> Absolute paths.
     */
    public function listSummonDataPaths(): array
    {
        $paths = [];
        foreach (glob($this->getAssetRoot() . '/Cutscenes/Summons/*/*.data.php') ?: [] as $path) {
            $paths[ProjectRecordDatabase::canonicalizePath($path)] = $path;
        }
        foreach ($this->workspace->cutscenes?->assets(CutsceneType::SUMMON) ?? [] as $asset) {
            $path = ProjectRecordDatabase::canonicalizePath($asset->dataPath());
            if ($asset->isDeleted()) {
                unset($paths[$path]);
            } else {
                $paths[$path] ??= $asset->dataPath();
            }
        }
        $paths = array_values($paths);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * The id of every effect timeline as the Cutscenes workspace holds them,
     * deleted ones left out.
     *
     * @return list<string>
     */
    public function listEffectTimelineIds(): array
    {
        $cutscenes = $this->workspace->cutscenes;
        if ($cutscenes === null) {
            return new EffectTimelineLibrary($this->getAssetRoot())->findTimelineIds();
        }
        $ids = $cutscenes->ids(CutsceneType::EFFECT);
        sort($ids, SORT_STRING);

        return array_values($ids);
    }

    /**
     * An effect timeline as the Cutscenes workspace holds it, compiled by the
     * Engine as battle, the field or an owned stage plays it.
     *
     * @throws InvalidArgumentException With the Engine's reason when it is missing or refused.
     */
    public function loadEffectTimeline(string $id, bool $forBattle = false,
        EffectPresentation $presentation = EffectPresentation::GRAPHICAL, bool $forStage = false): CompiledEffectTimeline
    {
        $library = new EffectTimelineLibrary($this->getAssetRoot());
        EffectTimelineLibrary::assertId($id);
        $current = $this->readCurrent(EffectTimelineLibrary::DIRECTORY . "/{$id}/{$id}.timeline.php");

        return ProjectDirectoryContext::run($this->workspace->projectRoot, static function () use ($library, $id, $forBattle, $presentation, $forStage, $current): CompiledEffectTimeline {
            if ($current === null) {
                return $library->load($id, $forBattle, $presentation, $forStage);
            }
            if (! $current['exists']) {
                throw new InvalidArgumentException("Effect timeline {$id} was not found inside the asset root.");
            }

            return $library->compile($id, $current['payload'], $forBattle, $presentation, $forStage);
        });
    }

    /**
     * Reads a category's record files as the Engine reads a directory of
     * them, from the records as they stand: in file order, each keyed by its
     * file under `assets/Data`, a record that cannot be read reported
     * against its file.
     *
     * @template T
     * @param \Closure(array<array-key, mixed>): T $read The Engine's reader of one record's data.
     * @return array{0: array<string, T>, 1: list<string>}
     */
    private static function readRecordFiles(ProjectRecordDatabase $database, \Closure $read): array
    {
        $directory = basename($database->backingFilePath());
        $records = $database->getRecords();
        usort($records, static fn(ProjectRecord $left, ProjectRecord $right): int => strcmp($left->recordId, $right->recordId));
        $found = [];
        $problems = [];
        foreach ($records as $record) {
            $file = $directory . '/' . $record->recordId . '.php';
            if (($reason = $record->getReadOnlyReason()) !== null) {
                $problems[] = sprintf('%s: %s', $file, $reason);
                continue;
            }
            try {
                $found[$file] = $read((array) $record->toArray());
            } catch (Throwable $failure) {
                $problems[] = sprintf('%s: %s', $file, $failure->getMessage());
            }
        }

        return [$found, $problems];
    }

    /** Whether the Cutscenes workspace holds an unsaved edit of one form. */
    private function holdsUnsaved(CutsceneType $type): bool
    {
        foreach ($this->workspace->cutscenes?->dirtyAssets() ?? [] as $asset) {
            if ($asset->type === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $assetPath The data file, relative to the asset root.
     * @return array{exists: bool, payload: mixed}|null
     */
    private function readCurrent(string $assetPath): ?array
    {
        return $this->workspace->readCurrentData($this->getAssetRoot() . DIRECTORY_SEPARATOR . $assetPath);
    }
}
