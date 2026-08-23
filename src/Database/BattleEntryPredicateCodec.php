<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * Encodes and decodes battle-entry actor predicates as a single editable
 * line.
 *
 * The engine's battle-entry rules match a rule only when every predicate
 * holds: a stable actor identity, and the entry roster it must occupy
 * (`active`, `reserve`, or `any`). This codec is the editor's one
 * representation of that list, in the idiom the condition and world-write
 * codecs settled: entries separated by `;`, the wire line only ever written
 * by the codec.
 *
 * Wire form: `actor:presence`. The presence is the segment after the last
 * `:`, so an actor identity may itself contain colons. Unparseable segments
 * are dropped rather than written back as garbage.
 *
 * @package Ichiloto\Editor\Database
 */
final class BattleEntryPredicateCodec
{
    /** The entry rosters the engine's predicates match against. */
    public const array PRESENCES = ['active', 'reserve', 'any'];

    /**
     * Encodes a list of predicates into the one-line editable form.
     *
     * @param array<int, mixed> $predicates The predicate payloads.
     * @return string The line.
     */
    public static function encodeAll(array $predicates): string
    {
        return implode('; ', array_map(
            static fn(array $predicate): string => self::encode($predicate),
            array_values(array_filter($predicates, is_array(...))),
        ));
    }

    /**
     * Encodes one predicate as `actor:presence`.
     *
     * @param array<string, mixed> $predicate The predicate payload.
     * @return string The segment.
     */
    public static function encode(array $predicate): string
    {
        return sprintf(
            '%s:%s',
            strval($predicate['actor'] ?? ''),
            strval($predicate['presence'] ?? ''),
        );
    }

    /**
     * Decodes the one-line form back into predicate arrays.
     *
     * @param string $value The encoded predicates.
     * @return array<int, array<string, mixed>> The predicates.
     */
    public static function decodeAll(string $value): array
    {
        $predicates = [];

        foreach (explode(';', $value) as $segment) {
            $predicate = self::decode($segment);

            if ($predicate !== null) {
                $predicates[] = $predicate;
            }
        }

        return $predicates;
    }

    /**
     * Decodes one `actor:presence` segment.
     *
     * @param string $segment The segment.
     * @return array<string, mixed>|null The predicate, or null when unparseable.
     */
    public static function decode(string $segment): ?array
    {
        $segment = trim($segment);
        $split = strrpos($segment, ':');

        if ($split === false) {
            return null;
        }

        $actor = trim(substr($segment, 0, $split));
        $presence = strtolower(trim(substr($segment, $split + 1)));

        if ($actor === '' || ! in_array($presence, self::PRESENCES, true)) {
            return null;
        }

        return ['actor' => $actor, 'presence' => $presence];
    }

    /**
     * Describes one predicate in words.
     *
     * @param array<string, mixed> $predicate The predicate.
     * @param string|null $actorLabel The actor's display name, when known.
     * @return string The description.
     */
    public static function describe(array $predicate, ?string $actorLabel = null): string
    {
        $actor = trim(strval($predicate['actor'] ?? ''));
        $actor = $actor === '' ? '(no actor)' : $actor;

        if ($actorLabel !== null && $actorLabel !== '' && $actorLabel !== $actor) {
            $actor = sprintf('%s (%s)', $actorLabel, $actor);
        }

        return sprintf('%s — %s', $actor, strval($predicate['presence'] ?? 'any'));
    }
}
