<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Database\Projections\KeyedDictionaryProjection;
use Ichiloto\Engine\Localization\WordForms;
use Throwable;

/**
 * Word Forms: the project's exceptions to the inflector's English rules, one
 * record per word in the Engine's word forms file (Engine WordForms). A word
 * is written as the game writes it, such as an item's whole name, with the
 * singular and plural forms the game must show for it. The Engine registers
 * them at boot, so every inflected name respects them; its reading validates
 * every save.
 */
final class WordFormFields
{
    public const string CATEGORY = 'word_forms';

    private function __construct() {}

    public static function getSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::CATEGORY,
            entryNoun: 'word',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/' . WordForms::FILE,
            fields: self::getFields(),
            labelKey: 'word',
            identityKey: 'word',
            makeBlank: self::makeBlank(...),
            projection: new KeyedDictionaryProjection('words', identityKey: 'word', fileKeys: ['schema' => WordForms::SCHEMA]),
            saveCheck: self::checkCatalog(...),
            prepareEdit: self::checkEdit(...),
            requireUnchangedSource: true,
        );
    }

    /** A new word keeps its own spelling in both forms until the author says otherwise. */
    public static function makeBlank(string $name, string $projectRoot): array
    {
        $word = trim($name);
        if ($word === '') {
            throw new RecordRefusal('Name the word as the game writes it, such as an item\'s name.');
        }

        return ['word' => $word, 'singular' => $word, 'plural' => $word];
    }

    /** @return list<RecordField> */
    public static function getFields(): array
    {
        return [
            new RecordField('word', 'Word'),
            new RecordField('singular', 'Singular'),
            new RecordField('plural', 'Plural'),
        ];
    }

    /** Refuses a blank or space-padded spelling as it is typed: the inflector matches a word exactly. */
    public static function checkEdit(array $entry, string $field): array
    {
        $value = $entry[$field] ?? null;
        if (is_string($value) && trim($value) !== '' && trim($value) === $value) {
            return $entry;
        }

        throw new RecordRefusal(sprintf('The %s is written in full, without spaces around it.', $field === 'word' ? 'word' : "$field form"));
    }

    /** Why the Engine would not read the word forms as a save would write them, or null when it would. */
    public static function checkCatalog(array $whole, string $projectRoot): ?string
    {
        try {
            WordForms::fromArray($whole);
        } catch (Throwable $error) {
            return $error->getMessage();
        }

        return null;
    }
}
