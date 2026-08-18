<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\PermanentGrowthCatalog;
use Ichiloto\Editor\ProjectActor;
use Ichiloto\Editor\ProjectClass;
use Ichiloto\Editor\ProjectQuest;
use Ichiloto\Editor\ProjectSkill;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;

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
        'classes',
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
        'skits',
        'common_events',
        'inventory',
        'bgm',
        'sfx',
        'enemy_sprites',
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

    public function __construct(
        private readonly ProjectWorkspace $workspace,
        private readonly ?ProjectMap $currentMap = null,
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
            'classes' => array_map(
                static fn(ProjectClass $class): string => $class->getName(),
                $this->workspace->classDatabase->getClasses()
            ),
            'skills' => array_map(
                static fn(ProjectSkill $skill): string => $skill->getName(),
                $this->workspace->skillDatabase->getSkills()
            ),
            'quests' => array_map(
                static fn(ProjectQuest $quest): string => $quest->getId(),
                $this->workspace->questDatabase->getQuests()
            ),
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
            'animations' => array_map(
                static fn(object $animation): string => $animation->name ?? '',
                $this->workspace->animationDatabase->getAnimations()
            ),
            default => $this->recordValues($category),
        };
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
     * @return array<string, string> Labels keyed by stored value.
     */
    public function labelsFor(string $category): array
    {
        if ($category === 'elements_or_any') {
            return ['*' => '* (whichever element it was)'];
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
     * picker can offer is what the project has already said somewhere. A
     * special property is a shape rather than a string, and it is the `type`
     * inside it that Optimize weighs.
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
                $value = $record->get($field);

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
     * Returns the elements the project's own element enum declares.
     *
     * A project defines its elements as a PHP enum under assets/Data/Types
     * (the Types category), and the engine matches them by their string
     * values. The cases are read from the source, so the list is available
     * whether or not the enum is loaded.
     *
     * @return string[] The element values, in declaration order.
     */
    private function elementValues(): array
    {
        $directory = rtrim($this->workspace->projectRoot, '/') . '/assets/Data/Types';

        if (! is_dir($directory)) {
            return [];
        }

        foreach (glob($directory . '/*.php') ?: [] as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('/^\s*enum\s+\w*Element\w*\s*:/m', $source) !== 1) {
                continue;
            }

            preg_match_all("/^\\s*case\\s+\\w+\\s*=\\s*'([^']+)'\\s*;/m", $source, $matches);

            return $matches[1];
        }

        return [];
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
