<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Database\InventoryCatalog;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\IO\SaveCompatibility\ContentReferenceCategory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/** Validates the project-owned save compatibility manifest. */
final class SaveCompatibilityValidator
{
    private const string WHERE = 'assets/Data/save-compatibility.php';

    /**
     * @var InventoryCatalog|null The project's inventory identity, read once.
     */
    private ?InventoryCatalog $inventoryCatalog = null;

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
        $issues = [...$issues, ...$this->checkMigrations($manifest['migrations'] ?? null, $version)];

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
                    $knownTargets = $this->knownTargets($workspace, $category);

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

    /** @return Issue[] */
    private function checkMigrations(mixed $rawMigrations, mixed $version): array
    {
        if (! is_array($rawMigrations)) {
            return [Issue::error(self::WHERE, 'migrations must be an ordered list.')];
        }

        $issues = [];
        $steps = [];
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

            if ($class === '') {
                $issues[] = Issue::error($where, 'Migration class must be a non-empty class name.');
            }

            $steps[$from] = true;
            $previousFrom = $from;
        }

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

    /** @return string[]|null Null means this category has no closed catalog. */
    private function knownTargets(ProjectWorkspace $workspace, ContentReferenceCategory $category): ?array
    {
        return match ($category) {
            ContentReferenceCategory::MAP => $workspace->mapIds,
            ContentReferenceCategory::ONE_SHOT_EVENT => $this->oneShotEventIds($workspace),
            ContentReferenceCategory::QUEST => array_map(
                static fn(object $quest): string => $quest->getId(),
                $workspace->questDatabase->getQuests()
            ),
            ContentReferenceCategory::ACTOR => array_map(
                static fn(object $actor): string => $actor->getName(),
                $workspace->actorDatabase->getActors()
            ),
            // An alias target is a stable definition id, never a display
            // label, so the catalogue of ids is what it has to be in.
            ContentReferenceCategory::ITEM => $this->inventoryCatalog($workspace)->idsIn('items'),
            ContentReferenceCategory::EQUIPMENT => $this->inventoryCatalog($workspace)->idsIn('weapons', 'armors'),
            ContentReferenceCategory::ABILITY, ContentReferenceCategory::SPELL => array_map(
                static fn(object $skill): string => $skill->getName(),
                $workspace->skillDatabase->getSkills()
            ),
            ContentReferenceCategory::STATE => $this->recordIdentities($workspace, 'states'),
            ContentReferenceCategory::ENEMY => $this->recordLabels($workspace, ['enemies']),
            ContentReferenceCategory::ACHIEVEMENT => $this->phpListIdentities(
                $workspace->projectRoot . '/assets/Data/achievements.php'
            ),
            ContentReferenceCategory::SUMMON => $this->summonIds($workspace),
            ContentReferenceCategory::STORY_EVENT => null,
        };
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

            foreach (array_keys(is_array($map->data['events'] ?? null) ? $map->data['events'] : []) as $marker) {
                if (is_string($marker)) {
                    $ids[] = $map->mapId . ':' . $marker;
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

    /** @return string[] */
    private function summonIds(ProjectWorkspace $workspace): array
    {
        $root = $workspace->projectRoot . '/assets/Cutscenes/Summons';

        if (! is_dir($root)) {
            return [];
        }

        $ids = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.data.php')) {
                continue;
            }

            $ids = [...$ids, ...$this->phpListIdentities($file->getPathname())];
        }

        return array_values(array_unique($ids));
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
