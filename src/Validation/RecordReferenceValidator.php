<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Database\InventoryCatalog;
use Ichiloto\Editor\Database\ProjectRecord;
use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Checks that what a Database record names exists: every field of a
 * schema-driven record, and of each entry in the lists it holds (an enemy's
 * drops, a quest's reward items, a troop's members), that names another
 * project definition. The pickers keep new values honest, but an entry
 * added and never picked, or a definition renamed or deleted since, names
 * nothing, and the runtime refuses to load the record that holds it.
 *
 * Only definitions the whole project shares are checked here. Actor
 * references are ActorReferenceValidator's, names that only mean something
 * inside one map or cutscene are their own validators', and the lists a
 * check with more context already reads are left to it.
 */
final class RecordReferenceValidator
{
    /** The kinds of definition checked, by reference kind, with what one is called. */
    private const array KINDS = [
        'classes' => 'class',
        'common_events' => 'common event',
        'effects' => 'effect',
        'enemies' => 'enemy',
        'inventory' => 'item',
        'knowledge_subjects' => 'knowledge subject',
        'maps' => 'map',
        'quests' => 'quest',
        'skills' => 'skill',
        'troops' => 'troop',
    ];

    /**
     * Categories, or lists of them, whose references a check with more
     * context owns (null: the whole category), so nothing is reported twice.
     */
    private const array OWNED_ELSEWHERE = [
        // ProjectValidator::checkSkitReferences: the map and every beat.
        'skits' => null,
        // ProjectValidator::checkScriptReferences, with the map and cast each script runs with.
        'common_events' => null,
        // ProjectValidator::checkQuests: what an objective names depends on its type.
        'quests' => ['objectives'],
    ];

    /** @var array<string, list<string>> What each kind holds, read once per validation. */
    private array $known = [];

    /** Resolves an inventory reference as the Engine's item store does: by id or by name. */
    private ?InventoryCatalog $inventory = null;

    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $catalog = new ReferenceCatalog($workspace);
        $this->inventory = InventoryCatalog::fromWorkspace($workspace);
        $this->known = [];
        foreach (array_keys(self::KINDS) as $kind) {
            $this->known[$kind] = array_map('strval', array_values($catalog->valuesFor($kind)));
        }

        $issues = [];
        foreach ($workspace->recordDatabases as $key => $database) {
            if (array_key_exists($key, self::OWNED_ELSEWHERE) && self::OWNED_ELSEWHERE[$key] === null) {
                continue;
            }
            foreach ($database->getRecords() as $record) {
                $issues = [...$issues, ...$this->checkRecord($database, $record)];
            }
        }

        return $issues;
    }

    /** @return Issue[] */
    private function checkRecord(ProjectRecordDatabase $database, ProjectRecord $record): array
    {
        $schema = $database->schema;
        $label = trim(strval($record->get($schema->labelKey) ?? '')) ?: '(unnamed)';
        $where = sprintf('%s %s', $schema->entryNoun, $label);
        $payload = $record->toArray();
        $issues = [];

        foreach ($schema->fieldsFor($payload) as $field) {
            $issue = $this->checkValue($field, $record->get($field->key), $where, false);
            if ($issue !== null) {
                $issues[] = $issue;
            }
        }

        foreach ($schema->getInlineSubLists() as $list) {
            if (in_array($list->key, self::OWNED_ELSEWHERE[$schema->key] ?? [], true)) {
                continue;
            }
            foreach ($record->getSubList($list->key, $list->scalarKey) as $position => $entry) {
                $entryWhere = sprintf('%s, %s %d', $where, $list->singular, $position + 1);
                foreach ($list->fieldsFor($entry) as $field) {
                    $issue = $this->checkValue($field, self::readPath($entry, $field->key), $entryWhere, true);
                    if ($issue !== null) {
                        $issues[] = $issue;
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * The problem with one value that names a definition, or null when it
     * names one that exists, or may name none. A list entry exists to name
     * something, so in one an empty value is a problem even where the field
     * is optional elsewhere.
     */
    private function checkValue(RecordField $field, mixed $value, string $where, bool $inEntry): ?Issue
    {
        $kind = $field->reference;
        if ($kind === null || ! array_key_exists($kind, self::KINDS) || ($value !== null && ! is_scalar($value))) {
            return null;
        }

        $noun = self::KINDS[$kind];
        $text = trim(strval($value ?? ''));

        if ($text === '') {
            return $inEntry && ! $field->allowsNone
                ? Issue::error($where, sprintf('Its %s names no %s.', strtolower($field->label), $noun), sprintf('Choose a %s, or remove the entry.', $noun))
                : null;
        }

        $exists = $kind === 'inventory' && $this->inventory !== null
            ? $this->inventory->has($text)
            : in_array($text, $this->known[$kind], true);

        return $exists
            ? null
            : Issue::error($where, sprintf('Its %s names the %s "%s", which the project does not define.', strtolower($field->label), $noun, $text),
                sprintf('Choose one of the project\'s %ss.', $noun));
    }

    /** @param array<string, mixed> $entry */
    private static function readPath(array $entry, string $key): mixed
    {
        $current = $entry;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}
