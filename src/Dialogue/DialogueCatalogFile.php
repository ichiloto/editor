<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Dialogue;

use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\SkitStageStyle;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Throwable;

/**
 * The game's dialogue presentation catalogue (Engine DialoguePresentationCatalog) as the Editor reads and writes
 * it: where it lives, the catalogue the Engine makes of a payload, and each identity's emotions. Its Dialogue
 * Portraits and Speaker Names pages edit it; every line's emotion is chosen from it.
 */
final class DialogueCatalogFile
{
    /** Where the catalogue lives in a project: under its assets, where the Engine reads it. */
    public const string RELATIVE_PATH = 'assets/' . DialoguePresentationCatalog::FILE;

    /** The schema id DialoguePresentationCatalog::load() requires, written into a catalogue the Editor creates. */
    public const string SCHEMA = DialoguePresentationCatalog::SCHEMA;

    /** The keys load() accepts. A skit's backdrop is the place's, never this file's ({@see \Ichiloto\Engine\Field\SkitBackdrop}). */
    private const array KEYS = DialoguePresentationCatalog::KEYS;

    private function __construct() {}

    /**
     * The catalogue the Engine makes of a payload: its identities, speaker names and skit stage, admitted
     * by the Engine's own constructor. The theme is admitted too when an asset root is given; without one it is
     * left out, since naming emotions needs no artwork.
     *
     * @throws \InvalidArgumentException When the Engine would refuse the payload.
     */
    public static function createCatalog(array $whole, ?string $assetRoot = null): DialoguePresentationCatalog
    {
        if (array_key_exists(DialoguePresentationCatalog::RETIRED_SKITS_KEY, $whole)) {
            throw new \InvalidArgumentException('Dialogue catalogue "skits" is retired: a skit\'s backdrop follows the map, its region or the skit. Run `ichiloto upgrade`.');
        }
        if (($whole['schema'] ?? null) !== self::SCHEMA || array_diff(array_keys($whole), self::KEYS) !== []) {
            throw new \InvalidArgumentException(sprintf('Dialogue catalogue requires schema %s and only the keys %s.', self::SCHEMA, implode(', ', self::KEYS)));
        }
        foreach (['actors', 'resources', 'speakers', 'skitStage'] as $key) {
            if (array_key_exists($key, $whole) && ! is_array($whole[$key])) {
                throw new \InvalidArgumentException(sprintf('Dialogue catalogue "%s" must be an array.', $key));
            }
        }
        $theme = $assetRoot !== null && is_array($whole['theme'] ?? null) ? new MenuPresentationCatalog($assetRoot, $whole['theme']) : null;

        return new DialoguePresentationCatalog($whole['actors'] ?? [], $theme, $whole['speakers'] ?? [],
            SkitStageStyle::getFromArray($whole['skitStage'] ?? []), $whole['resources'] ?? []);
    }

    /** Why the Engine would not read the catalogue as a save would write it, or null when it would. */
    public static function checkCatalog(array $whole, string $projectRoot): ?string
    {
        try {
            self::createCatalog($whole, rtrim($projectRoot, '/') . '/assets');
        } catch (Throwable $error) {
            return $error->getMessage();
        }

        return null;
    }

    /** The record an identity has in the catalogue, actor or not, or null when it has none. */
    public static function findIdentity(DialoguePresentationCatalog $catalogue, ?string $identity): ?array
    {
        if ($identity === null) {
            return null;
        }
        $record = $catalogue->actors[$identity] ?? $catalogue->resources[$identity] ?? null;

        return is_array($record) ? $record : null;
    }

    /**
     * The emotions a line spoken by an identity may show: Neutral, which the Engine always accepts and falls back
     * to, then every emotion the identity names, in the catalogue's order.
     *
     * @return list<string>
     */
    public static function listEmotions(DialoguePresentationCatalog $catalogue, ?string $identity): array
    {
        $emotions = self::findIdentity($catalogue, $identity)['emotions'] ?? [];
        $emotions = is_array($emotions) ? array_keys($emotions) : [];

        return array_values(array_unique([DialogueContext::DEFAULT_EMOTION, ...array_map(strval(...), $emotions)]));
    }
}
