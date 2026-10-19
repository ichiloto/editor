<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Cutscenes\Presentation\PartyStageSelection;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandReference;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandRegistry;
use InvalidArgumentException;

/** Shared authoring of the Engine's scalar or explicitly subject-resolved rest stage. */
final class InnPresentationFields
{
    /** @return list<RecordField> */
    public static function getFields(mixed $value, string $key, string $label, bool $graphical): array
    {
        if (! $graphical) {
            return [is_array($value) || is_object($value)
                ? new RecordField($key, $label, isReadOnly: true)
                : RecordField::reference($key, $label, 'stage_timelines', allowsNone: true, noneLabel: 'None')];
        }

        return [
            ...(! is_array($value) && ! is_object($value)
                ? [RecordField::reference($key, $label, 'stage_timelines', allowsNone: true, noneLabel: 'None')]
                : []),
            new RecordField($key . '.treatment', $label . ' Treatment', options: [PartyStageSelection::LEADER, PartyStageSelection::PARTY]),
        ];
    }

    /** @return list<RecordSubList> */
    public static function getBindingLists(string $key): array
    {
        return [
            new RecordSubList(
                key: $key . '.leaders', prefix: 'innLeader', singular: 'leader binding',
                fields: [RecordField::reference('actor', 'Leader', 'actor_ids'), RecordField::reference('timeline', 'Stage', 'stage_timelines')],
                blank: ['actor' => '', 'timeline' => ''], heading: 'Leader Bindings', keyField: 'actor', valueField: 'timeline',
            ),
            new RecordSubList(
                key: $key . '.parties', prefix: 'innParty', singular: 'party binding',
                fields: [new RecordField('actors', 'Guests', codec: RecordFieldCodec::CSV_LIST, reference: 'actor_ids'),
                    RecordField::reference('timeline', 'Stage', 'stage_timelines')],
                blank: ['actors' => [], 'timeline' => ''], heading: 'Exact Party Bindings',
            ),
        ];
    }

    public static function getBindingList(mixed $value, string $key): ?RecordSubList
    {
        if (! is_array($value)) { return null; }
        return match ($value['treatment'] ?? null) {
            PartyStageSelection::LEADER => self::getBindingLists($key)[0],
            PartyStageSelection::PARTY => self::getBindingLists($key)[1],
            default => null,
        };
    }

    /**
     * Blank picker rows are authoring drafts, never invented resource ids.
     * Only complete rows participate in draft DTO validation; saving always
     * validates the actual, unfiltered value with the authoritative DTO.
     */
    public static function assertValid(mixed $value, ?ReferenceCatalog $references = null, bool $complete = true): void
    {
        if ($value === null) { return; }
        if (is_string($value)) {
            EffectTimelineLibrary::assertId($value);
            self::assertReference($value, 'stage_timelines', $references);
            return;
        }
        if ($value instanceof PartyStageSelection) { $value = $value->toArray(); }
        if (! is_array($value)) { throw new InvalidArgumentException('A rest presentation must be a stage id or party stage selection.'); }

        $checked = $value;
        if (! $complete && ($value['treatment'] ?? null) === PartyStageSelection::LEADER && is_array($value['leaders'] ?? null)) {
            $checked['leaders'] = array_filter($value['leaders'], static fn(mixed $timeline, mixed $actor): bool => $actor !== '' && $timeline !== '', ARRAY_FILTER_USE_BOTH);
        }
        if (! $complete && ($value['treatment'] ?? null) === PartyStageSelection::PARTY && is_array($value['parties'] ?? null)) {
            $checked['parties'] = array_values(array_filter($value['parties'], static fn(mixed $binding): bool =>
                ! is_array($binding) || array_diff(array_keys($binding), ['actors', 'timeline']) !== []
                || (($binding['actors'] ?? null) !== [] && ($binding['timeline'] ?? null) !== '')));
        }
        // Mixed bindings, unknown keys, duplicate compositions and limits remain Engine-owned.
        PartyStageSelection::fromArray($checked);
        if (count((array) ($value['leaders'] ?? [])) > 1024 || count((array) ($value['parties'] ?? [])) > 1024) {
            throw new InvalidArgumentException('A rest presentation has too many bindings.');
        }
        foreach ((array) ($value['leaders'] ?? []) as $actor => $timeline) {
            self::assertReference((string) $actor, 'actor_ids', $references, ! $complete);
            self::assertReference($timeline, 'stage_timelines', $references, ! $complete);
        }
        foreach ((array) ($value['parties'] ?? []) as $binding) {
            if (! is_array($binding) || ! is_array($binding['actors'] ?? null) || ! array_is_list($binding['actors'])
                || array_diff(array_keys($binding), ['actors', 'timeline']) !== [] || ! array_key_exists('timeline', $binding)) {
                throw new InvalidArgumentException('A party binding needs an actor list and stage timeline.');
            }
            foreach ($binding['actors'] as $actor) { self::assertReference($actor, 'actor_ids', $references); }
            if (count(array_unique($binding['actors'], SORT_REGULAR)) !== count($binding['actors'])) {
                throw new InvalidArgumentException('A party binding cannot repeat a guest.');
            }
            self::assertReference($binding['timeline'], 'stage_timelines', $references, ! $complete);
        }
    }

    private static function assertReference(mixed $value, string $category, ?ReferenceCatalog $references, bool $blankAllowed = false): void
    {
        if ($value === '' && $blankAllowed) { return; }
        if (! is_string($value) || trim($value) === '' || $value !== trim($value)) {
            throw new InvalidArgumentException('Choose a nonempty ' . ($category === 'actor_ids' ? 'actor identity' : 'stage timeline') . '.');
        }
        if ($category === 'stage_timelines') { EffectTimelineLibrary::assertId($value); }
        if ($references !== null && ! in_array($value, $references->valuesFor($category), true)) {
            throw new InvalidArgumentException(sprintf('Unknown %s "%s"; choose a project resource.', $category, $value));
        }
    }

    /** Validates registered stage references at any command depth, not just inn commands. */
    public static function assertCommandsValid(array $payload, ?ReferenceCatalog $references = null, bool $complete = true): void
    {
        $definition = is_string($payload['type'] ?? null) ? (ScriptCommandRegistry::getCatalog()->definitions[$payload['type']] ?? null) : null;
        foreach ($definition?->fields ?? [] as $field) {
            if ($field->reference !== ScriptCommandReference::STAGE_TIMELINE) { continue; }
            $value = $payload;
            foreach (explode('.', $field->key) as $segment) { $value = is_array($value) ? ($value[$segment] ?? null) : null; }
            self::assertValid($value, $references, $complete);
        }
        foreach ($payload as $nested) {
            if (is_array($nested)) { self::assertCommandsValid($nested, $references, $complete); }
        }
    }

    public static function prepareEdit(array $entry, string $field): array
    {
        $definition = is_string($entry['type'] ?? null) ? (ScriptCommandRegistry::getCatalog()->definitions[$entry['type']] ?? null) : null;
        foreach ($definition?->fields ?? [] as $declared) {
            if ($declared->reference === ScriptCommandReference::STAGE_TIMELINE
                && ($field === $declared->key || str_starts_with($field, $declared->key . '.'))) {
                self::assertCommandsValid($entry, complete: false);
            }
        }
        return $entry;
    }

    /** Only changed presentation values are authored; unrelated Terminal edits preserve graphical data. */
    public static function assertChangedCommandsValid(array $old, array $new, ?ReferenceCatalog $references = null, bool $complete = true): void
    {
        if ($old === $new) { return; }
        $definition = is_string($new['type'] ?? null) ? (ScriptCommandRegistry::getCatalog()->definitions[$new['type']] ?? null) : null;
        foreach ($definition?->fields ?? [] as $field) {
            if ($field->reference !== ScriptCommandReference::STAGE_TIMELINE) { continue; }
            $before = $old;
            $after = $new;
            foreach (explode('.', $field->key) as $segment) {
                $before = is_array($before) ? ($before[$segment] ?? null) : null;
                $after = is_array($after) ? ($after[$segment] ?? null) : null;
            }
            if ($before !== $after) { self::assertValid($after, $references, $complete); }
        }
        foreach ($new as $key => $nested) {
            if (is_array($nested)) { self::assertChangedCommandsValid(is_array($old[$key] ?? null) ? $old[$key] : [], $nested, $references, $complete); }
        }
    }
}
