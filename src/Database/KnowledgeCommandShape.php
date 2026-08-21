<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeProgressService;

/**
 * Which fields a knowledge operation actually reads.
 *
 * `KnowledgeProgressService::apply()` reads a different set of the command
 * for each operation: discovering a subject reads a source and nothing else,
 * while superseding a report reads the report being replaced and the one
 * replacing it. Showing every field for every operation invites an author to
 * fill in one the runtime will never look at, and then to wonder why nothing
 * happened.
 *
 * The vocabulary stays the engine's: the operations come from
 * `KnowledgeProgressService::OPERATIONS`, and this only says which fields
 * each of them takes. A test holds the two exactly in step, so an operation
 * the engine adds is a failing test here rather than a silently unauthorable
 * command, and an operation this invents cannot exist at all.
 *
 * @package Ichiloto\Editor\Database
 */
final class KnowledgeCommandShape
{
    /**
     * The fields each operation reads, in the order an author fills them.
     */
    public const array FIELDS = [
        'discover' => ['subject', 'source'],
        'observe' => ['subject', 'observation', 'source', 'confidence'],
        'unlock_report' => ['subject', 'report', 'source', 'confidence'],
        'amend_report' => ['subject', 'report', 'source', 'confidence'],
        'record_outcome' => ['subject', 'outcome'],
        'withdraw_report' => ['subject', 'report', 'source'],
        'supersede_report' => ['subject', 'report', 'replacement', 'source'],
    ];

    /**
     * Returns the operations the runtime understands.
     *
     * @return string[] The operations.
     */
    public static function operations(): array
    {
        return KnowledgeProgressService::OPERATIONS;
    }

    /**
     * Returns the field names one operation reads.
     *
     * @param string $operation The operation.
     * @return string[] The field names.
     */
    public static function fieldNamesFor(string $operation): array
    {
        return self::FIELDS[$operation] ?? [];
    }

    /**
     * Returns the settings-pane fields one operation offers.
     *
     * @param string $operation The operation.
     * @return RecordField[] The fields.
     */
    public static function fieldsFor(string $operation): array
    {
        $declared = self::declaredFields();

        return array_values(array_filter(array_map(
            static fn(string $name): ?RecordField => $declared[$name] ?? null,
            self::fieldNamesFor($operation),
        )));
    }

    /**
     * Every field a knowledge command can carry, by name.
     *
     * @return array<string, RecordField> The fields.
     */
    private static function declaredFields(): array
    {
        return [
            'subject' => RecordField::reference('subject', 'Subject', 'knowledge_subjects'),
            'report' => RecordField::reference('report', 'Report', 'knowledge_reports'),
            'replacement' => RecordField::reference('replacement', 'Replacement Report', 'knowledge_reports'),
            // An observation is one the subject itself authors, so it is
            // picked from that subject rather than spelled.
            'observation' => RecordField::reference('observation', 'Observation', 'knowledge_observations'),
            'outcome' => new RecordField('outcome', 'Outcome'),
            // The runtime defaults an absent source to story.event, so the
            // row says that rather than reading as blank.
            'source' => new RecordField('source', 'Source', removeWhenEmpty: true, displayDefault: 'story.event'),
            'confidence' => new RecordField(
                'confidence',
                'Confidence',
                InputControlType::FLOAT,
                removeWhenEmpty: true,
                displayDefault: '1.0',
            ),
        ];
    }
}
