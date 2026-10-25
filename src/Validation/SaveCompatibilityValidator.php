<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Engine\Field\NpcPages;
use Ichiloto\Editor\Database\InventoryCatalog;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Exceptions\InvalidSaveCompatibilityManifestException;
use Ichiloto\Engine\IO\SaveCompatibility\ContentReferenceCategory;
use Ichiloto\Engine\IO\SaveCompatibility\MapRetirement;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;
use Throwable;

/** Validates the project-owned save compatibility manifest. */
final class SaveCompatibilityValidator
{
    private const string WHERE = 'assets/Data/save-compatibility.php';
    private const string DECLARED_EDITS_HINT = 'Use a class for a project migration, or declared position edits: mapShifts (map, axis x or y, the 0-based line inserted at, a count of at least 1) for inserted rows and columns, mapRemovals (map, axis, the 0-based first removed line at, a count of at least 1, the cell to that saves on removed lines land on) for removed rows and columns, relocations (map, cells, to) for cells authored content now occupies, mapRetirements (map, into, to, and optional landings of x, y, width, height and to) for a retired map whose saves move onto another map.';

    /**
     * @var InventoryCatalog|null The project's inventory identity, read once.
     */
    private ?InventoryCatalog $inventoryCatalog = null;
    private ?SkillCatalog $skillCatalog = null;

    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $issues = [];

        if ($workspace->projectId === '') {
            $issues[] = Issue::error(
                'ichiloto.json',
                'The project has no stable save identity.',
                'Add a non-empty id and never change it after production saves exist.'
            );
        }

        $path = $workspace->projectRoot . '/' . self::WHERE;

        if (! is_file($path)) {
            $issues[] = Issue::error(
                self::WHERE,
                'The save compatibility manifest is missing.',
                'Add the project content version, migrations, aliases, and tombstones.'
            );

            return $issues;
        }

        try {
            $manifest = require $path;
        } catch (Throwable $throwable) {
            $issues[] = Issue::error(self::WHERE, 'The manifest could not be loaded: ' . $throwable->getMessage());

            return $issues;
        }

        if (! is_array($manifest)) {
            $issues[] = Issue::error(self::WHERE, 'The manifest must return an array.');

            return $issues;
        }

        $version = $manifest['contentVersion'] ?? null;

        if (! is_int($version) || $version < 0) {
            $issues[] = Issue::error(
                self::WHERE,
                'contentVersion must be a non-negative integer.',
                'Version 0 is legacy; the initial production content version is normally 1.'
            );
        }

        $aliasResult = $this->checkAliases($workspace, $manifest['aliases'] ?? null);
        $issues = [...$issues, ...$aliasResult['issues']];
        $issues = [...$issues, ...$this->checkTombstones(
            $manifest['tombstones'] ?? null,
            $aliasResult['sources']
        )];
        $issues = [...$issues, ...$this->checkMigrations($workspace, $manifest['migrations'] ?? null, $version,
            is_array($manifest['aliases']['maps'] ?? null) ? $manifest['aliases']['maps'] : [])];

        return $issues;
    }

    /**
     * @return array{issues: Issue[], sources: array<string, array<string, true>>}
     */
    private function checkAliases(ProjectWorkspace $workspace, mixed $rawAliases): array
    {
        if (! is_array($rawAliases)) {
            return [
                'issues' => [Issue::error(self::WHERE, 'aliases must be an array keyed by compatibility category.')],
                'sources' => [],
            ];
        }

        $issues = [];
        $sources = [];

        foreach ($rawAliases as $categoryName => $entries) {
            $category = is_string($categoryName) ? ContentReferenceCategory::tryFrom($categoryName) : null;
            $where = self::WHERE . ' aliases.' . strval($categoryName);

            if (! $category instanceof ContentReferenceCategory) {
                $issues[] = Issue::error($where, sprintf('"%s" is not a saved-content category.', strval($categoryName)));
                continue;
            }

            if (! is_array($entries)) {
                $issues[] = Issue::error($where, 'Alias entries must be a list of from/to pairs.');
                continue;
            }

            $graph = [];

            foreach ($entries as $index => $entry) {
                if (! is_array($entry)) {
                    $issues[] = Issue::error($where, sprintf('Alias entry %s must contain from and to strings.', strval($index)));
                    continue;
                }

                $from = trim(strval($entry['from'] ?? ''));
                $to = trim(strval($entry['to'] ?? ''));

                if ($from === '' || $to === '') {
                    $issues[] = Issue::error($where, sprintf('Alias entry %s requires non-empty from and to values.', strval($index)));
                    continue;
                }

                if ($category === ContentReferenceCategory::ONE_SHOT_EVENT) {
                    if (! $this->isOneShotEventIdentity($from) || ! $this->isOneShotEventIdentity($to)) {
                        $issues[] = Issue::error($where, sprintf(
                            'One-shot event alias "%s" to "%s" must use exact mapId:marker syntax.',
                            $from,
                            $to
                        ));
                    }
                }

                if ($from === $to) {
                    $issues[] = Issue::error($where, sprintf('"%s" is a self-alias.', $from));
                }

                if (isset($graph[$from]) && $graph[$from] !== $to) {
                    $issues[] = Issue::error($where, sprintf(
                        '"%s" is mapped to both "%s" and "%s".',
                        $from,
                        $graph[$from],
                        $to
                    ));
                    continue;
                }

                $graph[$from] = $to;
                $sources[$category->value][$from] = true;
            }

            foreach ($graph as $from => $to) {
                $seen = [$from => true];
                $current = $to;

                while (isset($graph[$current])) {
                    if (isset($seen[$current])) {
                        $issues[] = Issue::error($where, sprintf('The alias chain from "%s" contains a cycle.', $from));
                        break;
                    }

                    $seen[$current] = true;
                    $current = $graph[$current];
                }

                if (! isset($graph[$current])) {
                    $knownTargets = $this->listKnownTargets($workspace, $category);

                    if ($knownTargets !== null && ! in_array($current, $knownTargets, true)) {
                        $issues[] = Issue::error($where, sprintf(
                            'Alias target "%s" is not defined in the current %s catalog.',
                            $current,
                            $category->value
                        ));
                    }
                }
            }
        }

        return ['issues' => $issues, 'sources' => $sources];
    }

    /**
     * @param array<string, array<string, true>> $aliasSources
     * @return Issue[]
     */
    private function checkTombstones(mixed $rawTombstones, array $aliasSources): array
    {
        if (! is_array($rawTombstones)) {
            return [Issue::error(self::WHERE, 'tombstones must be an array keyed by compatibility category.')];
        }

        $issues = [];

        foreach ($rawTombstones as $categoryName => $entries) {
            $category = is_string($categoryName) ? ContentReferenceCategory::tryFrom($categoryName) : null;
            $where = self::WHERE . ' tombstones.' . strval($categoryName);

            if (! $category instanceof ContentReferenceCategory) {
                $issues[] = Issue::error($where, sprintf('"%s" is not a saved-content category.', strval($categoryName)));
                continue;
            }

            if (! is_array($entries)) {
                $issues[] = Issue::error($where, 'Tombstones must be a list of identities.');
                continue;
            }

            foreach ($entries as $index => $entry) {
                $identity = is_string($entry) ? trim($entry) : '';

                if ($identity === '') {
                    $issues[] = Issue::error($where, sprintf('Tombstone %s must be a non-empty string.', strval($index)));
                    continue;
                }

                if ($category === ContentReferenceCategory::ONE_SHOT_EVENT && ! $this->isOneShotEventIdentity($identity)) {
                    $issues[] = Issue::error($where, sprintf('"%s" must use exact mapId:marker syntax.', $identity));
                }

                if (isset($aliasSources[$category->value][$identity])) {
                    $issues[] = Issue::error($where, sprintf(
                        '"%s" is both an alias source and an incompatible tombstone.',
                        $identity
                    ));
                }
            }
        }

        return $issues;
    }

    /**
     * @param array<mixed> $mapAliases The manifest's map aliases, as written.
     * @return Issue[]
     */
    private function checkMigrations(ProjectWorkspace $workspace, mixed $rawMigrations, mixed $version, array $mapAliases): array
    {
        if (! is_array($rawMigrations)) {
            return [Issue::error(self::WHERE, 'migrations must be an ordered list.')];
        }

        $issues = [];
        $steps = [];
        $retirements = [];
        $previousFrom = -1;

        foreach ($rawMigrations as $index => $entry) {
            $where = self::WHERE . ' migrations[' . strval($index) . ']';

            if (! is_array($entry) || ! is_int($entry['from'] ?? null) || ! is_int($entry['to'] ?? null)) {
                $issues[] = Issue::error($where, 'Migration from and to versions must be integers.');
                continue;
            }

            $from = $entry['from'];
            $to = $entry['to'];
            $class = is_string($entry['class'] ?? null) ? trim($entry['class']) : '';

            if ($from < 0 || $to !== $from + 1) {
                $issues[] = Issue::error($where, 'A migration must describe one non-negative adjacent version step.');
            }

            if (isset($steps[$from])) {
                $issues[] = Issue::error($where, sprintf('Migration step %d to %d is registered more than once.', $from, $to));
            }

            if ($from <= $previousFrom) {
                $issues[] = Issue::error($where, sprintf('Migration step %d to %d is in an impossible order.', $from, $to));
            }

            try {
                $edits = SaveCompatibilityManifest::readDeclaredPositionEdits($entry, 'This migration');
            } catch (InvalidSaveCompatibilityManifestException $exception) {
                $issues[] = Issue::error($where, $exception->getMessage(), self::DECLARED_EDITS_HINT);
                $edits = [];
            }
            foreach ($edits ?? [] as $edit) {
                if ($edit instanceof MapRetirement) {
                    $retirements[] = ['where' => $where, 'retirement' => $edit];
                }
            }

            if ($edits === null && $class === '') {
                $issues[] = Issue::error($where, 'Migration class must be a non-empty class name.');
            }

            $steps[$from] = true;
            $previousFrom = $from;
        }

        $issues = [...$issues, ...$this->checkRetirementDestinations($workspace, $retirements, $mapAliases)];

        if (is_int($version) && $version >= 0) {
            for ($from = 0; $from < $version; $from++) {
                if (! isset($steps[$from])) {
                    $issues[] = Issue::error(
                        self::WHERE,
                        sprintf('Content migration step %d to %d is missing.', $from, $from + 1),
                        'Every version must be reachable sequentially from legacy content version 0.'
                    );
                }
            }

            foreach (array_keys($steps) as $from) {
                if ($from >= $version) {
                    $issues[] = Issue::error(
                        self::WHERE,
                        sprintf('Migration step %d to %d is beyond current contentVersion %d.', $from, $from + 1, $version)
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * Reports a retirement whose saves end on no map of the project: its
     * destination, followed through later retirements and map aliases as a
     * save landing there moves, is not a current map.
     *
     * @param list<array{where: string, retirement: MapRetirement}> $retirements In manifest order.
     * @param array<mixed> $mapAliases
     * @return Issue[]
     */
    private function checkRetirementDestinations(ProjectWorkspace $workspace, array $retirements, array $mapAliases): array
    {
        $aliases = [];
        foreach ($mapAliases as $alias) {
            if (is_array($alias) && is_string($alias['from'] ?? null) && is_string($alias['to'] ?? null)) {
                $aliases[trim($alias['from'])] = trim($alias['to']);
            }
        }
        $resolve = static function (string $mapId) use ($aliases): string {
            for ($seen = []; isset($aliases[$mapId]) && ! isset($seen[$mapId]); $mapId = $aliases[$mapId]) {
                $seen[$mapId] = true;
            }

            return $mapId;
        };
        $issues = [];
        foreach ($retirements as $index => ['where' => $where, 'retirement' => $retirement]) {
            $current = $resolve($retirement->into);
            foreach (array_slice($retirements, $index + 1) as ['retirement' => $later]) {
                if ($resolve($later->map) === $current) {
                    $current = $resolve($later->into);
                }
            }
            if (! in_array($current, $workspace->mapIds, true)) {
                $issues[] = Issue::error($where, sprintf('Saves on %s move into %s, which is not a map in the project.', $retirement->map, $current),
                    'Retire the map into one that exists, or alias the destination to the map that took its place.');
            }
        }

        return $issues;
    }

    /**
     * Lists the current identities an alias of a category may end at, as the
     * workspace holds them, unsaved edits included.
     *
     * @return string[]|null Null means this category has no closed catalog.
     */
    public function listKnownTargets(ProjectWorkspace $workspace, ContentReferenceCategory $category): ?array
    {
        return match ($category) {
            ContentReferenceCategory::MAP => $workspace->mapIds,
            ContentReferenceCategory::ONE_SHOT_EVENT => $this->oneShotEventIds($workspace),
            ContentReferenceCategory::QUEST => array_map(
                static fn(object $quest): string => $quest->getId(),
                $workspace->getQuests()
            ),
            // The durable identity a save reconstructs an actor by, which
            // is the definition id where one is declared and the display
            // name only where none is. A legacy display name is a legitimate
            // alias *source*; it is not a current canonical target.
            ContentReferenceCategory::ACTOR => array_map(
                static fn(object $actor): string => $actor->getDefinitionId(),
                $workspace->actorDatabase->getActors()
            ),
            // An alias target is a stable definition id, never a display
            // label, so the catalogue of ids is what it has to be in.
            ContentReferenceCategory::ITEM => $this->inventoryCatalog($workspace)->idsIn('items'),
            ContentReferenceCategory::EQUIPMENT => $this->inventoryCatalog($workspace)->idsIn('weapons', 'armors'),
            // A save keeps abilities and spells in separate books, and the
            // runtime restores each book from its own kind of skill,
            // wherever the catalogue authors it.
            ContentReferenceCategory::ABILITY => array_keys($this->skillCatalog($workspace)->getAbilities()),
            ContentReferenceCategory::SPELL => array_keys($this->skillCatalog($workspace)->getSpells()),
            ContentReferenceCategory::STATE => $this->recordIdentities($workspace, 'states'),
            ContentReferenceCategory::ENEMY => $this->recordLabels($workspace, ['enemies']),
            ContentReferenceCategory::ACHIEVEMENT => $this->phpListIdentities(
                $workspace->projectRoot . '/assets/Data/achievements.php'
            ),
            ContentReferenceCategory::SUMMON => $this->summonIds($workspace),
            ContentReferenceCategory::STORY_EVENT => null,
            // A retired subject folds into one the knowledge catalogue still declares.
            ContentReferenceCategory::KNOWLEDGE_SUBJECT => $this->knowledgeSubjectIds($workspace),
        };
    }

    /**
     * The knowledge subjects the project declares; null when the catalogue
     * cannot be read, which its own validation reports.
     *
     * @return string[]|null
     */
    private function knowledgeSubjectIds(ProjectWorkspace $workspace): ?array
    {
        try {
            return array_map(static fn(object $subject): string => $subject->id, $workspace->catalogs->loadKnowledgeCatalog()->subjects());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Returns the project's inventory catalogue, read once per validation.
     *
     * @param ProjectWorkspace $workspace The project.
     * @return InventoryCatalog The catalogue.
     */
    private function inventoryCatalog(ProjectWorkspace $workspace): InventoryCatalog
    {
        return $this->inventoryCatalog ??= InventoryCatalog::fromWorkspace($workspace);
    }

    /**
     * Returns the project's skill catalogue, read once per validation.
     *
     * @param ProjectWorkspace $workspace The project.
     * @return SkillCatalog The catalogue.
     */
    private function skillCatalog(ProjectWorkspace $workspace): SkillCatalog
    {
        return $this->skillCatalog ??= $workspace->loadSkillCatalog();
    }

    /** @param string[] $categories @return string[] */
    private function recordLabels(ProjectWorkspace $workspace, array $categories): array
    {
        $labels = [];

        foreach ($categories as $category) {
            $database = $workspace->getRecordDatabase($category);

            if ($database instanceof ProjectRecordDatabase) {
                $labels = [...$labels, ...$database->getEntryLabels()];
            }
        }

        return array_values(array_unique($labels));
    }

    /** @return string[] */
    private function recordIdentities(ProjectWorkspace $workspace, string $category): array
    {
        $database = $workspace->getRecordDatabase($category);

        if (! $database instanceof ProjectRecordDatabase || $database->schema->identityKey === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(object $record): string => trim(strval($record->get($database->schema->identityKey))),
            $database->getRecords()
        )));
    }

    /** @return string[] */
    private function oneShotEventIds(ProjectWorkspace $workspace): array
    {
        $ids = [];

        foreach ($workspace->maps as $map) {
            if (! $map instanceof ProjectMap) {
                continue;
            }

            foreach (array_keys(is_array($map->getEditableData()['events'] ?? null) ? $map->getEditableData()['events'] : []) as $marker) {
                if (is_string($marker)) {
                    $ids[] = $map->mapId . ':' . $marker;
                }
            }

            // An NPC page with an id is a saved identity beside the map's events, as each event marker is.
            foreach (is_array($map->getEditableData()['npcs'] ?? null) ? $map->getEditableData()['npcs'] : [] as $entry) {
                try {
                    $pages = is_array($entry) ? NpcPages::fromEntry($entry) : null;
                } catch (\InvalidArgumentException) {
                    continue; // npc.page reports it.
                }
                foreach ($pages?->pages ?? [] as $page) {
                    if ($page->id !== null && $pages->npcId !== null) {
                        $ids[] = $map->mapId . ':' . NpcPages::getCompletionMarker($pages->npcId, $page->id);
                    }
                }
            }
        }

        return $ids;
    }

    /** @return string[] */
    private function phpListIdentities(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        try {
            $entries = require $path;
        } catch (Throwable) {
            return [];
        }

        $ids = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null) && trim($entry['id']) !== '') {
                $ids[] = trim($entry['id']);
            }
        }

        return $ids;
    }

    /**
     * The summons the game defines, as the Engine's summon library loads them:
     * one definition per directory, each a single record.
     *
     * @return string[]
     */
    private function summonIds(ProjectWorkspace $workspace): array
    {
        return array_values(array_unique(array_map(
            static fn(SummonCutsceneDefinition $definition): string => $definition->id,
            $workspace->catalogs->loadSummons(),
        )));
    }

    private function isOneShotEventIdentity(string $identity): bool
    {
        if (substr_count($identity, ':') !== 1) {
            return false;
        }

        [$mapId, $marker] = array_map('trim', explode(':', $identity, 2));

        return $mapId !== '' && $marker !== '';
    }
}
