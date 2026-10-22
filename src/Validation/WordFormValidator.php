<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Database\WordFormFields;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Localization\WordForms;
use InvalidArgumentException;

/**
 * Checks the project's word forms, unsaved edits included, by the Engine's
 * own reading: word forms the Engine would refuse stop the game from
 * starting, so they are errors. Each word is checked on its own so every
 * broken one is named at once.
 */
final class WordFormValidator
{
    private const string WHERE = 'assets/' . WordForms::FILE;
    private const string HINT = 'Correct it in Database > Word Forms; the game will not start until it is fixed.';

    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $database = $workspace->getRecordDatabase(WordFormFields::CATEGORY);
        if ($database === null || (! is_file($database->backingFilePath()) && ! $database->isDirty())) {
            return [];
        }

        $issues = [];
        $words = [];
        foreach ($database->getRecords() as $record) {
            $entry = (array) $record->toArray();
            $word = $entry['word'] ?? null;
            unset($entry['word']);
            try {
                new WordForms(is_string($word) ? [$word => $entry] : [$entry]);
            } catch (InvalidArgumentException $invalid) {
                $issues[] = Issue::error(self::WHERE, 'The game cannot read a word form: ' . $invalid->getMessage(), self::HINT);
                continue;
            }
            if (isset($words[$word])) {
                $issues[] = Issue::error(self::WHERE, sprintf('The word "%s" is listed twice.', $word),
                    'The game keeps one pair of forms for each word, so list each word once.');
            }
            $words[$word] = true;
        }

        return $issues;
    }
}
