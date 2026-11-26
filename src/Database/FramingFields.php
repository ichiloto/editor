<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandCatalog;
use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Events\CommandMapContext;
use Ichiloto\Editor\Events\CommandTree;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicFramingSubjectKind;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicScriptValidator;
use Ichiloto\Engine\Events\Interpreter\EventCommandRequirements;
use Ichiloto\Engine\Events\Interpreter\EventScriptContext;
use InvalidArgumentException;
use LogicException;

/**
 * Authors the Engine's required-subject framing contract
 * ({@see CinematicCommandSchema::export()} `framingRequirements`): the field
 * subjects a cinematic binds under stable keys of its own, and the `framing`
 * command that names the subjects the camera keeps clear of the text while
 * its body runs, in place of the whole cast.
 *
 * The Engine owns the definition field, the command, its fields, the
 * subject kinds with the field each kind's id is stored in
 * (`referenceSources`) and where the command can run; this reads them from
 * the export and picks each id where the Editor's subject references pick
 * one. A phase's subject is one of the cinematic's bound keys, offered in a
 * cinematic's own script, or a direct reference to the player, an NPC or a
 * staged actor, which is all a reusable script offers: it cannot know which
 * cinematic runs it, and the Engine refuses a bound key there. The Engine's
 * `CinematicDefinition` judges a cinematic's framing when it is hydrated and
 * its `EventCommandRequirements` a reusable script's; this class supplies
 * the rows, where each choice comes from, and keeps a cinematic's phases in
 * step when a bound key changes.
 *
 * @package Ichiloto\Editor\Database
 */
final class FramingFields
{
    /** The reference category of the framing keys the current cinematic binds. */
    public const string SUBJECTS = 'cinematic_framing_subjects';

    /** The setting-row id prefix of the bound subjects, which the Cutscenes pane groups under one heading. */
    public const string SUBJECT_PREFIX = 'framingSubject';

    /** The editor's own field of a phase's subject that is one of the cinematic's bound keys. */
    private const string KEY_FIELD = SubjectBindingFields::KEY_FIELD;

    /** How the kind row of a subject selected by its bound key reads. */
    private const string BOUND_LABEL = '(a bound framing subject)';

    private function __construct()
    {
    }

    /** The command that frames its body on the subjects it selects. */
    public static function getCommandType(): string
    {
        return strval(self::readContract()['command']);
    }

    /** Where a cinematic binds its framing subjects, as a dotted path of its definition. */
    public static function getBindingsKey(): string
    {
        return self::readContract()['definitionField'] . '.' . self::readContract()['bindingField'];
    }

    /**
     * The command field holding a phase's subjects: the one field of the
     * command that is not a command list its block runs.
     */
    public static function getSelectionField(): string
    {
        $fields = array_values(array_diff(self::readContract()['commandFields'], CommandMapContext::getBodyKeys(self::getCommandType())));

        return count($fields) === 1 ? $fields[0]
            : throw new LogicException('The Engine\'s framing command declares no single subject selection field.');
    }

    /**
     * The path of the lane a phase's body runs on, as the Engine's runtime names it: the suffix it adds to the
     * phase's command path, and the label of that lane's own frame.
     *
     * @return array{pathSuffix: string, rootFrame: string}
     */
    public static function getBodyLane(): array
    {
        $lane = self::readContract()['bodyLane'] ?? null;

        return is_array($lane) && is_string($lane['pathSuffix'] ?? null) && is_string($lane['rootFrame'] ?? null) ? $lane
            : throw new LogicException('The Engine\'s framing contract exports no body lane.');
    }

    /**
     * The field of a subject reference that names its kind: the one reference field that is no kind's id field.
     */
    private static function getKindField(): string
    {
        $fields = array_values(array_diff(self::readContract()['referenceFields'],
            array_filter(array_column(self::readContract()['referenceSources'], 'idField'))));

        return count($fields) === 1 ? $fields[0]
            : throw new LogicException('The Engine\'s framing references declare no single kind field.');
    }

    /**
     * The framing a cinematic definition declares, as the Engine's `CinematicFramingDefinition` reads it; empty
     * when it declares none.
     *
     * @param array<string, mixed> $definition The cinematic's data.
     * @return array<string, mixed>
     */
    public static function readDefinition(array $definition): array
    {
        $framing = $definition;
        foreach (explode('.', self::readContract()['definitionField']) as $segment) {
            $framing = is_array($framing) ? ($framing[$segment] ?? null) : null;
        }

        return is_array($framing) ? $framing : [];
    }

    /**
     * The framing subjects a cinematic definition binds, by key.
     *
     * @param array<string, mixed> $definition The cinematic's data.
     * @return array<string, mixed>
     */
    public static function readBindings(array $definition): array
    {
        $bound = self::readDefinition($definition)[self::readContract()['bindingField']] ?? null;

        return is_array($bound) ? $bound : [];
    }

    /**
     * The framing keys a cinematic definition binds, in authored order.
     *
     * @param array<string, mixed> $definition The cinematic's data.
     * @return list<string>
     */
    public static function listSubjects(array $definition): array
    {
        return array_values(array_filter(array_map(strval(...), array_keys(self::readBindings($definition))),
            static fn(string $key): bool => trim($key) !== ''));
    }

    /**
     * The cinematic's framing list beside the cast: the subjects it binds.
     *
     * @return list<RecordSubList>
     */
    public static function getLists(): array
    {
        $kindField = self::getKindField();
        $sources = self::readContract()['referenceSources'];

        return [new RecordSubList(
            key: self::getBindingsKey(),
            prefix: self::SUBJECT_PREFIX,
            singular: 'framing subject',
            fields: [],
            blank: [self::KEY_FIELD => 'subject', $kindField => CinematicFramingSubjectKind::PLAYER->value],
            heading: 'Framing Subjects',
            removeWhenEmpty: true,
            keyField: self::KEY_FIELD,
            prepareEdit: static fn(array $entry, string $field): array => SubjectBindingFields::prepareEdit($entry, $field, $sources, $kindField),
            getFields: static fn(array $entry): array => SubjectBindingFields::getFields($entry, 'Subject Key', $sources, $kindField),
            remapKeyReferences: static fn(array $payload, string $old, ?string $new): array => self::remapSubject($payload, $old, $new),
        )];
    }

    /**
     * The subjects a `framing` command selects, as a list nested in the
     * command. A subject stored as a bare key is one the cinematic binds; one
     * stored as a reference names the player, an NPC or a staged actor
     * itself.
     *
     * @param bool $bindsKeys Whether the command is in a cinematic's own script, whose bound keys it may select. A
     *   key a reusable script already holds is listed and kept as written, and becomes a direct reference when a
     *   kind is chosen for it.
     */
    public static function getSelectionList(bool $bindsKeys): RecordSubList
    {
        $kindField = self::getKindField();
        $sources = self::readContract()['referenceSources'];
        $kinds = array_map(strval(...), array_keys($sources));

        return new RecordSubList(
            key: self::getSelectionField(),
            prefix: 'subject',
            singular: 'required subject',
            fields: [],
            blank: [$kindField => CinematicFramingSubjectKind::PLAYER->value],
            heading: 'Required Subjects',
            scalarKey: self::KEY_FIELD,
            removeWhenEmpty: true,
            prepareEdit: static fn(array $entry, string $field): array => self::prepareSelectionEdit($entry, $field, $sources, $kindField),
            getFields: static function (array $entry) use ($bindsKeys, $kinds, $kindField, $sources): array {
                $kind = new RecordField($kindField, 'Subject', options: $bindsKeys ? ['', ...$kinds] : $kinds, removeWhenEmpty: true,
                    displayDefault: self::BOUND_LABEL);
                if (! array_key_exists(self::KEY_FIELD, $entry)) {
                    return SubjectBindingFields::getReferenceFields($entry, $kind, $sources, $kindField);
                }
                $key = RecordField::reference(self::KEY_FIELD, 'Bound Subject', self::SUBJECTS);

                return [$kind, $bindsKeys ? $key : $key->asReadOnly()];
            },
        );
    }

    /**
     * How a `framing` command reads in one line: who it requires, and how
     * many commands it frames.
     *
     * @param array<string, mixed> $command
     */
    public static function describeSelection(array $command): string
    {
        $kindField = self::getKindField();
        $sources = self::readContract()['referenceSources'];
        $subjects = $command[self::getSelectionField()] ?? null;
        $names = array_map(static function (mixed $subject) use ($kindField, $sources): string {
            if (! is_array($subject)) {
                return is_string($subject) ? $subject : '?';
            }
            $kind = strval($subject[$kindField] ?? '?');
            $id = $subject[$sources[$kind]['idField'] ?? ''] ?? null;

            return str_replace('_', ' ', $kind) . (is_scalar($id) ? ' ' . strval($id) : '');
        }, is_array($subjects) ? array_values($subjects) : []);
        $count = array_sum(array_map(static fn(array $body): int => count($body['commands']), CommandMapContext::getBodies($command)));

        return sprintf('%s · %d commands', $names === [] ? '(no subject)' : implode(', ', $names), $count);
    }

    /**
     * The cinematic with a bound framing key renamed in every `framing`
     * command of its script that selects it. Removing a key a command still
     * selects is refused, naming where.
     *
     * @param array<string, mixed> $payload The cinematic record.
     * @return array<string, mixed>
     * @throws RecordRefusal When a removed key is still selected.
     */
    public static function remapSubject(array $payload, string $old, ?string $new): array
    {
        $uses = [];
        $field = self::getSelectionField();
        $list = CutsceneAsset::COMMANDS_KEY;
        if (is_array($payload[$list] ?? null)) {
            $payload[$list] = CommandTree::rewrite($payload[$list], static function (array $command) use ($old, $new, $field, $list, &$uses): array {
                if (($command['type'] ?? null) !== self::getCommandType() || ! is_array($command[$field] ?? null)
                    || ! in_array($old, $command[$field], true)) {
                    return $command;
                }
                $uses[] = sprintf('a %s command in %s', self::getCommandType(), $list);
                $command[$field] = array_map(static fn(mixed $subject): mixed => $subject === $old ? $new : $subject, $command[$field]);

                return $command;
            });
        }
        if ($new === null && $uses !== []) {
            throw new RecordRefusal(sprintf('Framing subject "%s" is still selected by %s; choose another subject there first.', $old,
                implode(', ', array_unique($uses))));
        }

        return $payload;
    }

    /**
     * Has the Engine judge every `framing` command a save adds or changes
     * in a reusable event script, which no cinematic's hydration reads: its
     * shape and its body through the Engine's cinematic script validator,
     * and its subjects as a reusable script's, direct references the caller's
     * cinematic need not bind ({@see EventCommandRequirements}). An unchanged
     * command is left as the file holds it.
     *
     * @param array<array-key, mixed> $old The script as it was loaded.
     * @param array<array-key, mixed> $new The script as it would be saved.
     * @param string $scriptId The reusable event script's id.
     * @throws InvalidArgumentException With the Engine's reason.
     */
    public static function assertChangedCommandsValid(array $old, array $new, string $scriptId, ?ScriptCommandCatalog $commands = null): void
    {
        if ($old === $new) {
            return;
        }
        $loaded = self::listCommands($old);
        foreach (self::listCommands($new) as $command) {
            $unchanged = array_search($command, $loaded, true);
            if ($unchanged !== false) {
                unset($loaded[$unchanged]);
                continue;
            }
            try {
                CinematicScriptValidator::validate([$command], 'common_event:' . $scriptId, scriptCommands: $commands);
                EventCommandRequirements::validate([$command], EventScriptContext::REUSABLE,
                    path: 'common_event:' . $scriptId, scriptCommands: $commands);
            } catch (InvalidArgumentException $refusal) {
                throw new InvalidArgumentException(sprintf('Event script %s has a %s command requiring %s that the Engine refuses: %s', $scriptId,
                    self::getCommandType(), self::describeSelection($command), $refusal->getMessage()), previous: $refusal);
            }
        }
    }

    /**
     * Keeps a phase's subject either a bound key or a direct reference
     * after one field edit: choosing a kind makes it a reference of that
     * kind, and clearing the kind makes it a bound key again, waiting for the
     * key to be picked.
     *
     * @param array<string, array{idField: string|null, identity?: string}> $sources The Engine's reference sources.
     * @return array<string, mixed>
     */
    private static function prepareSelectionEdit(array $entry, string $field, array $sources, string $kindField): array
    {
        if ($field !== $kindField) {
            return $entry;
        }
        // A kind keeps only the id it names; no kind keeps none.
        $entry = SubjectBindingFields::prepareEdit($entry, $field, $sources, $kindField);
        if (array_key_exists($kindField, $entry)) {
            unset($entry[self::KEY_FIELD]);

            return $entry;
        }

        return $entry + [self::KEY_FIELD => ''];
    }

    /**
     * Every `framing` command a value holds, at any depth.
     *
     * @return list<array<string, mixed>>
     */
    private static function listCommands(array $payload): array
    {
        $commands = ($payload['type'] ?? null) === self::getCommandType() ? [$payload] : [];
        foreach ($payload as $child) {
            if (is_array($child)) {
                array_push($commands, ...self::listCommands($child));
            }
        }

        return $commands;
    }

    /** @return array<string, mixed> */
    private static function readContract(): array
    {
        static $contract = CinematicCommandSchema::export()['framingRequirements'] ?? null;

        return is_array($contract) ? $contract : throw new LogicException('The Engine exports no framingRequirements contract.');
    }
}
