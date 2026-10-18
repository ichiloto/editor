<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Dialogue;

use Ichiloto\Editor\Database\Projections\GroupedDictionaryProjection;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordStorage;
use Ichiloto\Editor\Database\RecordSubList;

/**
 * Dialogue Portraits: who a line's artwork shows, one record per identity in the game's dialogue catalogue (Engine
 * DialoguePresentationCatalog). An actor's identity is its stable actor id; any other speaker (a parent, a
 * shopkeeper) is a character with a stable id of its own. Each has a base dialogue portrait and skit bust and its
 * emotions, each an emotion name with its own portrait and bust. Images are project PNGs chosen with the shared
 * picker and stored as asset-relative paths; a missing image is the validator's to report, as the Engine draws the
 * line without it. Speaker names bind the labels lines are spoken under to these identities.
 *
 * A record's artwork rows ({@see getArtworkFields()}) are one set the record owns; a later per-identity form
 * (an identity's vampiric artwork beside its usual) is another such set under the identity.
 */
final class DialoguePortraitFields
{
    public const string CATEGORY = 'dialogue_portraits';
    /** The record field naming which dictionary an identity lives in; the file holds none. */
    public const string KIND = 'kind';
    public const string ACTOR = 'Actor';
    public const string CHARACTER = 'Non-actor character';

    private function __construct() {}

    public static function getSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::CATEGORY,
            entryNoun: 'dialogue portrait',
            storage: RecordStorage::LIST_FILE,
            relativePath: DialogueCatalogFile::RELATIVE_PATH,
            fields: [],
            labelKey: 'id',
            identityKey: 'id',
            makeBlank: self::makeBlank(...),
            projection: new GroupedDictionaryProjection(['actors' => self::ACTOR, 'resources' => self::CHARACTER], 'id', self::KIND,
                fileKeys: ['schema' => DialogueCatalogFile::SCHEMA]),
            fieldsFor: self::getFields(...),
            labelFor: self::describeLabel(...),
            subLists: [self::getEmotionList()],
            saveCheck: DialogueCatalogFile::checkCatalog(...),
            prepareEdit: self::checkEdit(...),
        );
    }

    /**
     * A new identity: a character under the given name as its stable id, with no artwork yet for the author to
     * choose. Speaks As turns it into an actor's identity, chosen among the project's actors.
     */
    public static function makeBlank(string $name, string $projectRoot): array
    {
        if (trim($name) === '') {
            throw new RecordRefusal('A dialogue portrait needs a stable id.');
        }

        return ['id' => trim($name), self::KIND => self::CHARACTER];
    }

    /** @return list<RecordField> */
    public static function getFields(array $entry): array
    {
        $actor = ($entry[self::KIND] ?? null) === self::ACTOR;

        return [
            new RecordField(self::KIND, 'Speaks As', options: [self::ACTOR, self::CHARACTER]),
            // An actor's identity is its stable id, chosen; a character's is typed once and named by speaker names.
            $actor ? RecordField::reference('id', 'Actor', 'actor_ids') : new RecordField('id', 'Stable Id'),
            ...self::getArtworkFields(),
        ];
    }

    /**
     * One artwork set: the dialogue box's portrait and the skit stage's bust, each a project image or none.
     *
     * @return list<RecordField>
     */
    public static function getArtworkFields(): array
    {
        return [
            new RecordField('portrait', 'Dialogue Portrait', reference: 'png_assets', removeWhenEmpty: true, allowsNone: true, displayDefault: '(none)'),
            new RecordField('bust', 'Skit Bust', reference: 'png_assets', removeWhenEmpty: true, allowsNone: true, displayDefault: '(none)'),
        ];
    }

    /**
     * An identity's emotions, keyed by name: what a line asks for by its `emotion`. A missing emotion's image falls
     * back to Neutral's and then the identity's own, as the Engine draws it. An emotion written as a bare image is
     * its portrait.
     */
    public static function getEmotionList(): RecordSubList
    {
        return new RecordSubList(key: 'emotions', prefix: 'emotion', singular: 'emotion',
            fields: [new RecordField('name', 'Name'), ...self::getArtworkFields()],
            blank: ['name' => 'New Emotion'], heading: 'Emotions', scalarKey: 'portrait', removeWhenEmpty: true, keyField: 'name');
    }

    /** An identity reads as its id and what it is. */
    public static function describeLabel(array $entry): string
    {
        return sprintf('%s (%s)', (string) ($entry['id'] ?? ''), ($entry[self::KIND] ?? null) === self::ACTOR ? 'actor' : 'character');
    }

    /** Refuses an id the Engine could not key the identity by, as it is typed: it is never empty. */
    public static function checkEdit(array $entry, string $field): array
    {
        if ($field === 'id' && (! is_string($entry['id'] ?? null) || trim($entry['id']) === '')) {
            throw new RecordRefusal('A dialogue portrait needs a stable id.');
        }

        return $entry;
    }
}
