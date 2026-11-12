<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicConversation;

/**
 * Authors a cinematic's field conversation (`presentation.conversation`), as
 * `CinematicCommandSchema::export()['conversationFacing']` describes it: its
 * speakers bound to the player, a map NPC or a staged cast member under stable
 * keys, the addressee each speaker turns to by default, its phase groups of
 * speakers, the group it starts in, and the speaker
 * and addressee a text line selects. The Engine's `CinematicConversation`
 * judges the whole when the cinematic is saved; this class supplies the rows,
 * where each choice comes from, and keeps the cinematic's references to a
 * speaker or group in step when its key changes.
 *
 * @package Ichiloto\Editor\Database
 */
final class ConversationFields
{
    /** Where a cinematic's conversation lives. */
    public const string CONVERSATION_KEY = 'presentation.conversation';

    public const string SPEAKERS_KEY = self::CONVERSATION_KEY . '.speakers';
    public const string ADDRESSEES_KEY = self::CONVERSATION_KEY . '.addressees';
    public const string GROUPS_KEY = self::CONVERSATION_KEY . '.groups';
    public const string INITIAL_GROUP_KEY = self::CONVERSATION_KEY . '.initialGroup';

    /** The reference category of the speaker keys the current cinematic binds. */
    public const string SPEAKERS = 'cinematic_conversation_speakers';

    /** The reference category of the groups the current cinematic declares. */
    public const string GROUPS = ReferenceCatalog::CINEMATIC_CONVERSATION_GROUPS;

    /** The setting-row id prefixes of the lists, which the Cutscenes pane groups under one heading. */
    public const string SPEAKER_PREFIX = 'conversationSpeaker';
    public const string ADDRESSEE_PREFIX = 'conversationAddressee';
    public const string GROUP_PREFIX = 'conversationGroup';

    /** The reference category each subject kind's id is picked from; the player names none. */
    private const array SUBJECT_REFERENCES = ['npc' => ['map_npcs', 'NPC'], 'staged_actor' => ['cinematic_cast', 'Staged Actor']];

    private function __construct()
    {
    }

    /**
     * The conversation a cinematic definition declares; empty when it has none.
     *
     * @param array<string, mixed> $definition The cinematic's data.
     * @return array<string, mixed>
     */
    public static function readConversation(array $definition): array
    {
        $presentation = is_array($definition['presentation'] ?? null) ? $definition['presentation'] : [];

        return is_array($presentation['conversation'] ?? null) ? $presentation['conversation'] : [];
    }

    /**
     * The speaker keys a cinematic definition binds, in authored order.
     *
     * @param array<string, mixed> $definition The cinematic's data.
     * @return list<string>
     */
    public static function listSpeakers(array $definition): array
    {
        return self::listKeys(self::readConversation($definition)['speakers'] ?? null);
    }

    /**
     * The conversation groups a cinematic definition declares, by id.
     *
     * @param array<string, mixed> $definition The cinematic's data.
     * @return list<string>
     */
    public static function listGroups(array $definition): array
    {
        return self::listKeys(self::readConversation($definition)['groups'] ?? null);
    }

    /**
     * The cinematic's conversation rows: the group it starts in.
     *
     * @return list<RecordField>
     */
    public static function getFields(): array
    {
        return [
            RecordField::reference(self::INITIAL_GROUP_KEY, 'Initial Conversation Group', self::GROUPS, allowsNone: true,
                noneLabel: '(none: a conversation without groups)'),
        ];
    }

    /**
     * The conversation's lists beside the cast: speakers, their default
     * addressees and the phase groups.
     *
     * @return list<RecordSubList>
     */
    public static function getLists(): array
    {
        return [self::getSpeakerList(), self::getAddresseeList(), self::getGroupList()];
    }

    /**
     * A text line's choice of the bound speaker who says it and the bound
     * speaker it is said to; absent, the Engine matches the line's name and
     * picks the addressee itself.
     *
     * @return list<RecordField>
     */
    public static function getLineFields(): array
    {
        return [
            RecordField::reference('speaker', 'Conversation Speaker', self::SPEAKERS, allowsNone: true, noneLabel: '(matches the speaker name)'),
            RecordField::reference('addressee', 'Addressee', self::SPEAKERS, allowsNone: true, noneLabel: '(the Engine chooses)'),
        ];
    }

    /** The speakers, keyed by their stable conversation key. */
    private static function getSpeakerList(): RecordSubList
    {
        return new RecordSubList(
            key: self::SPEAKERS_KEY,
            prefix: self::SPEAKER_PREFIX,
            singular: 'speaker',
            fields: [],
            blank: ['key' => 'speaker', 'kind' => 'player'],
            heading: 'Conversation Speakers',
            removeWhenEmpty: true,
            keyField: 'key',
            prepareEdit: static fn(array $entry, string $field): array => self::prepareSpeakerEdit($entry, $field),
            getFields: static fn(array $entry): array => self::getSpeakerFields($entry),
            remapKeyReferences: static fn(array $payload, string $old, ?string $new): array => self::remapSpeaker($payload, $old, $new),
        );
    }

    /** Per-speaker default addressees of the whole conversation. */
    private static function getAddresseeList(): RecordSubList
    {
        return new RecordSubList(
            key: self::ADDRESSEES_KEY,
            prefix: self::ADDRESSEE_PREFIX,
            singular: 'addressee',
            fields: [
                RecordField::reference('speaker', 'Speaker', self::SPEAKERS),
                RecordField::reference('addressee', 'Addresses', self::SPEAKERS),
            ],
            blank: ['speaker' => '', 'addressee' => ''],
            heading: 'Conversation Addressees',
            removeWhenEmpty: true,
            keyField: 'speaker',
            valueField: 'addressee',
        );
    }

    /**
     * The phase groups, keyed by their stable group id. A group's own
     * addressees and facing overrides are kept as authored: the record
     * layer edits nested lists only in a record's own list, and these groups
     * sit beside the cast.
     */
    private static function getGroupList(): RecordSubList
    {
        return new RecordSubList(
            key: self::GROUPS_KEY,
            prefix: self::GROUP_PREFIX,
            singular: 'group',
            fields: [
                new RecordField('id', 'Group Id'),
                // An empty group suspends field participation, so an empty list is written, not dropped.
                new RecordField('speakers', 'Speakers', codec: RecordFieldCodec::CSV_LIST, reference: self::SPEAKERS),
            ],
            blank: ['id' => 'group', 'speakers' => []],
            heading: 'Conversation Groups',
            removeWhenEmpty: true,
            keyField: 'id',
            remapKeyReferences: static fn(array $payload, string $old, ?string $new): array => self::remapGroup($payload, $old, $new),
        );
    }

    /**
     * A speaker's key, the kind of subject it binds, and that subject's id
     * where the kind names one.
     *
     * @return list<RecordField>
     */
    private static function getSpeakerFields(array $entry): array
    {
        $fields = [
            new RecordField('key', 'Speaker Key'),
            new RecordField('kind', 'Subject', options: CinematicConversation::SUBJECT_KINDS),
        ];
        $reference = self::SUBJECT_REFERENCES[strval($entry['kind'] ?? '')] ?? null;
        if ($reference !== null) {
            $fields[] = RecordField::reference('id', $reference[1], $reference[0]);
        }

        return $fields;
    }

    /** A new subject kind keeps no id the old kind named. */
    private static function prepareSpeakerEdit(array $entry, string $field): array
    {
        if ($field === 'kind' && ! isset(self::SUBJECT_REFERENCES[strval($entry['kind'] ?? '')])) {
            unset($entry['id']);
        }

        return $entry;
    }

    /**
     * The cinematic with a speaker's key renamed wherever the cinematic names
     * it: default addressees, facing overrides, groups and their addressees,
     * and the speaker and addressee its text lines select. Removing a speaker
     * something still names is refused, naming what names it.
     *
     * @param array<string, mixed> $payload The cinematic record.
     * @return array<string, mixed>
     * @throws RecordRefusal When a removed speaker is still named.
     */
    public static function remapSpeaker(array $payload, string $old, ?string $new): array
    {
        $uses = [];
        $conversation = self::readConversation($payload);
        $rename = static function (mixed $map, string $where) use ($old, $new, &$uses): mixed {
            if (! is_array($map)) {
                return $map;
            }
            $renamed = [];
            foreach ($map as $speaker => $value) {
                if ($value === $old) {
                    $uses[] = $where;
                    $value = $new;
                }
                if ((string) $speaker === $old) {
                    $uses[] = $where;
                    $speaker = $new;
                }
                if ($speaker !== null) {
                    $renamed[$speaker] = $value;
                }
            }
            return $renamed;
        };
        foreach (['addressees' => 'default addressees', 'facing' => 'facing overrides'] as $field => $where) {
            if (array_key_exists($field, $conversation)) {
                $conversation[$field] = $rename($conversation[$field], "the conversation's $where");
            }
        }
        foreach (is_array($conversation['groups'] ?? null) ? $conversation['groups'] : [] as $id => $group) {
            if (! is_array($group)) {
                continue;
            }
            if (is_array($group['speakers'] ?? null) && in_array($old, $group['speakers'], true)) {
                $uses[] = sprintf('group "%s"', $id);
                $group['speakers'] = array_map(static fn(mixed $speaker): mixed => $speaker === $old ? $new : $speaker, $group['speakers']);
            }
            foreach (['addressees' => 'addressees', 'facing' => 'facing overrides'] as $field => $where) {
                if (array_key_exists($field, $group)) {
                    $group[$field] = $rename($group[$field], sprintf('group "%s" %s', $id, $where));
                }
            }
            $conversation['groups'][$id] = $group;
        }
        foreach ([\Ichiloto\Editor\Cutscenes\CutsceneAsset::COMMANDS_KEY, CutsceneSchemas::FINALIZER_KEY] as $list) {
            if (is_array($payload[$list] ?? null)) {
                $payload[$list] = self::renameLineSpeakers($payload[$list], $old, $new, $list, $uses);
            }
        }
        if ($new === null && $uses !== []) {
            throw new RecordRefusal(sprintf('Speaker "%s" is still named by %s; choose another speaker there first.', $old,
                implode(', ', array_unique($uses))));
        }

        return $uses === [] ? $payload : self::writeConversation($payload, $conversation);
    }

    /**
     * The cinematic with a group's id renamed where it is named: the initial
     * group and the commands that select it. Removing a group something still
     * names is refused, naming what names it.
     *
     * @param array<string, mixed> $payload The cinematic record.
     * @return array<string, mixed>
     * @throws RecordRefusal When a removed group is still named.
     */
    public static function remapGroup(array $payload, string $old, ?string $new): array
    {
        $uses = [];
        $conversation = self::readConversation($payload);
        if (($conversation['initialGroup'] ?? null) === $old) {
            $uses[] = 'the initial group';
            $conversation['initialGroup'] = $new;
        }
        foreach ([\Ichiloto\Editor\Cutscenes\CutsceneAsset::COMMANDS_KEY, CutsceneSchemas::FINALIZER_KEY] as $list) {
            if (is_array($payload[$list] ?? null)) {
                $payload[$list] = self::renameGroupSelections($payload[$list], $old, $new, $list, $uses);
            }
        }
        if ($new === null && $uses !== []) {
            throw new RecordRefusal(sprintf('Group "%s" is still selected by %s; choose another group there first.', $old,
                implode(', ', array_unique($uses))));
        }

        return $uses === [] ? $payload : self::writeConversation($payload, $conversation);
    }

    /**
     * The conversation keys that the speakers, default addressees and groups
     * name but no speaker binds, each with where it is named; empty when every
     * name is bound. Read beside the Engine's refusal, to say where to look.
     *
     * @param array<string, mixed> $definition The cinematic's data.
     * @return list<string>
     */
    public static function findUnboundNames(array $definition): array
    {
        $conversation = self::readConversation($definition);
        $bound = self::listSpeakers($definition);
        $found = [];
        $check = static function (mixed $map, string $where) use ($bound, &$found): void {
            foreach (is_array($map) ? $map : [] as $speaker => $value) {
                foreach (array_unique([(string) $speaker, ...(is_string($value) ? [$value] : [])]) as $name) {
                    if (! in_array($name, $bound, true)) {
                        $found[] = sprintf('%s names "%s"', $where, $name);
                    }
                }
            }
        };
        $check($conversation['addressees'] ?? null, "The conversation's default addressees");
        foreach (is_array($conversation['groups'] ?? null) ? $conversation['groups'] : [] as $id => $group) {
            foreach (is_array($group['speakers'] ?? null) ? $group['speakers'] : [] as $speaker) {
                if (! is_string($speaker) || ! in_array($speaker, $bound, true)) {
                    $found[] = sprintf('Group "%s" names "%s"', $id, is_scalar($speaker) ? strval($speaker) : gettype($speaker));
                }
            }
            $check(is_array($group) ? ($group['addressees'] ?? null) : null, sprintf('Group "%s" addressees', $id));
        }

        return array_values(array_unique($found));
    }

    /**
     * Where a cinematic's text lines select a speaker or addressee, with what
     * they select, for validation.
     *
     * @param array<int, mixed> $commands
     * @return list<array{field: string, key: string}>
     */
    public static function listLineSelections(array $commands): array
    {
        $found = [];
        self::walkCommands($commands, static function (array $command) use (&$found): array {
            if (($command['type'] ?? null) === 'text') {
                foreach (['speaker', 'addressee'] as $field) {
                    if (is_string($command[$field] ?? null)) {
                        $found[] = ['field' => $field, 'key' => $command[$field]];
                    }
                }
            }
            return $command;
        });

        return $found;
    }

    /** @return list<string> */
    private static function listKeys(mixed $map): array
    {
        return is_array($map)
            ? array_values(array_filter(array_map(strval(...), array_keys($map)), static fn(string $key): bool => trim($key) !== ''))
            : [];
    }

    /** @param list<string> $uses */
    private static function renameLineSpeakers(array $commands, string $old, ?string $new, string $list, array &$uses): array
    {
        return self::walkCommands($commands, static function (array $command) use ($old, $new, $list, &$uses): array {
            if (($command['type'] ?? null) !== 'text') {
                return $command;
            }
            foreach (['speaker', 'addressee'] as $field) {
                if (($command[$field] ?? null) === $old) {
                    $uses[] = sprintf('a text line in %s', $list);
                    $command[$field] = $new;
                }
            }
            return $command;
        });
    }

    /** @param list<string> $uses */
    private static function renameGroupSelections(array $commands, string $old, ?string $new, string $list, array &$uses): array
    {
        $command = CinematicCommandSchema::export()['conversationFacing']['groupSelection'];

        return self::walkCommands($commands, static function (array $entry) use ($old, $new, $list, $command, &$uses): array {
            if (($entry['type'] ?? null) === $command['command'] && ($entry[$command['referenceField']] ?? null) === $old) {
                $uses[] = sprintf('a %s command in %s', $command['command'], $list);
                $entry[$command['referenceField']] = $new;
            }
            return $entry;
        });
    }

    /**
     * Visits every command of a command tree, nested blocks, lanes, arms and
     * options included, rewriting each with what the visitor returns.
     *
     * @param array<int|string, mixed> $commands
     * @param \Closure(array<string, mixed>): array<string, mixed> $visit
     * @return array<int|string, mixed>
     */
    private static function walkCommands(array $commands, \Closure $visit): array
    {
        foreach ($commands as $index => $command) {
            if (! is_array($command)) {
                continue;
            }
            if (is_string($command['type'] ?? null)) {
                $command = $visit($command);
            }
            foreach ($command as $key => $value) {
                if (is_array($value)) {
                    $command[$key] = self::walkCommands($value, $visit);
                }
            }
            $commands[$index] = $command;
        }

        return $commands;
    }

    /** The payload with its conversation replaced. */
    private static function writeConversation(array $payload, array $conversation): array
    {
        $record = new ProjectRecord($payload);
        $record->set(self::CONVERSATION_KEY, $conversation);

        return (array) $record->toArray();
    }
}
