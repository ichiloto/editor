<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\ProjectActor;
use Ichiloto\Editor\ProjectClass;
use Ichiloto\Editor\ProjectQuest;
use Ichiloto\Editor\ProjectSkill;
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
    ];

    /**
     * Where each audio kind's files live, under assets/Audio.
     */
    private const array AUDIO_DIRECTORIES = [
        'bgm' => 'BGM',
        'sfx' => 'SFX',
    ];

    public function __construct(private readonly ProjectWorkspace $workspace)
    {
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
            // entitled to sell.
            'inventory' => array_values(array_unique([
                ...$this->recordValues('items'),
                ...$this->recordValues('weapons'),
                ...$this->recordValues('armors'),
            ])),
            'bgm', 'sfx' => $this->audioValues($category),
            'animations' => array_map(
                static fn(object $animation): string => $animation->name ?? '',
                $this->workspace->animationDatabase->getAnimations()
            ),
            default => $this->recordValues($category),
        };
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
