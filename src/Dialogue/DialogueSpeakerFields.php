<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Dialogue;

use Ichiloto\Editor\Database\Projections\KeyedDictionaryProjection;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordStorage;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;
use Throwable;

/**
 * Speaker Names: which dialogue identity's artwork a line shows when it is spoken under a label, one record per
 * label in the game's dialogue catalogue (its `speakers`). A line names its speaker as display text ("Mother",
 * "Fira Nel"); the Engine shows the artwork of the identity that exact label is bound to, never one inferred from
 * the text. Several labels may share one identity.
 */
final class DialogueSpeakerFields
{
    public const string CATEGORY = 'dialogue_speakers';

    private function __construct() {}

    public static function getSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::CATEGORY,
            entryNoun: 'speaker name',
            storage: RecordStorage::LIST_FILE,
            relativePath: DialogueCatalogFile::RELATIVE_PATH,
            fields: [
                new RecordField('name', 'Shown Name'),
                RecordField::reference('identity', 'Portrait Of', 'dialogue_identities'),
            ],
            labelKey: 'name',
            identityKey: 'name',
            makeBlank: self::makeBlank(...),
            projection: new KeyedDictionaryProjection('speakers', 'name', fileKeys: ['schema' => DialogueCatalogFile::SCHEMA], valueKey: 'identity'),
            labelFor: self::describeLabel(...),
            saveCheck: DialogueCatalogFile::checkCatalog(...),
            prepareEdit: self::checkEdit(...),
        );
    }

    /**
     * A new label, which saves at once as the Engine admits it: bound to the identity of that id when there is one,
     * else to the catalogue's first identity for the author to change, as a new text role starts on the project's
     * first font.
     */
    public static function makeBlank(string $name, string $projectRoot): array
    {
        if (trim($name) === '') {
            throw new RecordRefusal('A speaker name is the label lines are spoken under; it cannot be empty.');
        }
        try {
            $catalogue = DialoguePresentationCatalog::load(rtrim($projectRoot, '/') . '/assets');
        } catch (Throwable $error) {
            throw new RecordRefusal('The dialogue catalogue cannot be read: ' . $error->getMessage(), previous: $error);
        }
        $identities = array_map(strval(...), [...array_keys($catalogue->actors), ...array_keys($catalogue->resources)]);
        if ($identities === []) {
            throw new RecordRefusal('A speaker name shows a dialogue portrait. Add one under Dialogue Portraits and save it first.');
        }

        return ['name' => $name, 'identity' => in_array($name, $identities, true) ? $name : $identities[0]];
    }

    /** A label reads as itself and whose portrait it shows. */
    public static function describeLabel(array $entry): string
    {
        return sprintf('%s -> %s', (string) ($entry['name'] ?? ''), (string) ($entry['identity'] ?? ''));
    }

    /** A label is matched exactly by the Engine, so it is never empty. */
    public static function checkEdit(array $entry, string $field): array
    {
        if ($field === 'name' && trim((string) ($entry['name'] ?? '')) === '') {
            throw new RecordRefusal('A speaker name is the label lines are spoken under; it cannot be empty.');
        }

        return $entry;
    }
}
