<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Field\Enumerations\SkitTrigger;
use Ichiloto\Engine\Field\SkitPlaybackPlan;
use InvalidArgumentException;
use LogicException;

/**
 * Authors the Engine's Skit playback contract
 * ({@see CinematicCommandSchema::export()} `skitPlayback`): the `play_skit`
 * command that selects a current Skit by its stable id, and the Skit's own
 * trigger and lifecycle sound cues.
 *
 * The Engine owns the command name, the reference field, the trigger values,
 * their default and the cue names. This reads them from the export, so the
 * Editor offers exactly what the runtime accepts and changes with it.
 */
final class SkitPlaybackFields
{
    /** The reference kind listing the project's Skits by stable id. */
    public const string REFERENCE_CATEGORY = 'skits';

    /** The Skit key holding its lifecycle sound cues. */
    public const string SOUNDS_KEY = 'sounds';

    /** The Skit key holding its trigger. */
    public const string TRIGGER_KEY = 'trigger';

    private function __construct()
    {
    }

    /** The command that plays a selected Skit. */
    public static function getCommandType(): string
    {
        return strval(self::readContract()['command']);
    }

    /** The command field naming the Skit by its stable id. */
    public static function getReferenceField(): string
    {
        return strval(self::readContract()['referenceField']);
    }

    /** @return list<string> The triggers a Skit may declare. */
    public static function getTriggers(): array
    {
        return array_values(array_map(strval(...), (array) self::readContract()['triggers']));
    }

    /** The trigger a Skit that declares none has. */
    public static function getDefaultTrigger(): string
    {
        return strval(self::readContract()['defaultTrigger']);
    }

    /** @return list<string> The lifecycle sound cues a Skit may name. */
    public static function getSoundCues(): array
    {
        return array_values(array_map(strval(...), (array) self::readContract()['soundCues']));
    }

    /**
     * The fields of the `play_skit` command: its Skit, chosen from the
     * project's Skits by stable id.
     *
     * @return list<RecordField>
     */
    public static function getCommandFields(): array
    {
        return [RecordField::reference(self::getReferenceField(), 'Skit', self::REFERENCE_CATEGORY)];
    }

    /**
     * A Skit's playback fields: its trigger, absent for the Engine's default,
     * and one optional SFX for each lifecycle cue. A cleared cue drops out,
     * and with no cue left the sounds key does too.
     *
     * @return list<RecordField>
     */
    public static function getSkitFields(): array
    {
        return [
            new RecordField(self::TRIGGER_KEY, 'Trigger', options: self::getTriggers(), removeWhenEmpty: true,
                displayDefault: self::getDefaultTrigger()),
            ...array_map(static fn(string $cue): RecordField => new RecordField(
                self::SOUNDS_KEY . '.' . $cue,
                ucfirst($cue) . ' Sound',
                reference: 'sfx',
                removeWhenEmpty: true,
                allowsNone: true,
                displayDefault: '(none)',
            ), self::getSoundCues()),
        ];
    }

    /**
     * Reads a Skit's trigger and sound cues as the Engine reads them,
     * throwing the reason it would skip the Skit.
     *
     * @param array<array-key, mixed> $skit The Skit as authored.
     * @return array{trigger: SkitTrigger, sounds: array<string, string>}
     * @throws InvalidArgumentException When the Engine would refuse either.
     */
    public static function readSkitPlayback(array $skit, string $context): array
    {
        $trigger = $skit[self::TRIGGER_KEY] ?? self::getDefaultTrigger();
        $read = is_string($trigger) ? SkitTrigger::tryFrom($trigger) : null;
        if ($read === null) {
            throw new InvalidArgumentException(sprintf('%s trigger must be one of %s.', $context, implode(', ', self::getTriggers())));
        }

        return ['trigger' => $read, 'sounds' => SkitPlaybackPlan::readSounds($skit[self::SOUNDS_KEY] ?? [], $context)];
    }

    /**
     * Why the Engine would skip a Skit file as it would be saved, or null.
     *
     * @param array<array-key, mixed> $payload
     */
    public static function checkSkit(array $payload): ?string
    {
        try {
            self::readSkitPlayback($payload, sprintf('Skit %s', strval($payload['id'] ?? '(unnamed)')));
        } catch (InvalidArgumentException $error) {
            return $error->getMessage();
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function readContract(): array
    {
        $contract = CinematicCommandSchema::export()['skitPlayback'] ?? null;

        return is_array($contract) ? $contract : throw new LogicException('The Engine exports no skitPlayback contract.');
    }
}
