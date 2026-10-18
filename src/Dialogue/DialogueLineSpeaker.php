<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Dialogue;

use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;

/**
 * How one kind of dialogue line names who speaks it, as the Engine reads that line: an event or cinematic `text`
 * command and a map Dialogue event's page by their `name`, an NPC's line by its `name` or else the NPC's own, a skit
 * beat by its `actor` id or else its `speaker` label. The speaker's dialogue identity is the Engine catalogue's:
 * an actor id stands for itself, a label resolves through the catalogue's speaker names. A line's emotion is chosen
 * among that identity's emotions, so the rule lives in one place for the fields that offer them and the validation
 * that checks them.
 */
final readonly class DialogueLineSpeaker
{
    /**
     * @param string|null $actorKey The line key holding a stable actor id, which the Engine prefers when present.
     * @param string $nameKey The line key holding the speaker's display label.
     * @param string|null $ownerNameKey The owning record's key whose value speaks when the line has no label key.
     */
    private function __construct(public ?string $actorKey, public string $nameKey, public ?string $ownerNameKey)
    {
    }

    /** An event or cinematic `text` command, or a map Dialogue event's page: its `name` speaks. */
    public static function forText(): self
    {
        return new self(null, 'name', null);
    }

    /** An NPC's dialogue line: its `name`, or the NPC's own name when the line has none. */
    public static function forNpcLine(): self
    {
        return new self(null, 'name', 'name');
    }

    /** A skit beat: its `actor` id, or else its non-actor `speaker` label. */
    public static function forSkitBeat(): self
    {
        return new self('actor', 'speaker', null);
    }

    /** The stable actor id a line names, or null when it names none. */
    public function getActorId(array $line): ?string
    {
        $actor = $this->actorKey === null ? null : ($line[$this->actorKey] ?? null);

        return is_string($actor) && $actor !== '' ? $actor : null;
    }

    /** The label a line is spoken under, as the Engine shows it. */
    public function getSpeakerName(array $line, array $owner = []): string
    {
        if (array_key_exists($this->nameKey, $line) || $this->ownerNameKey === null) {
            return is_scalar($line[$this->nameKey] ?? null) ? (string) $line[$this->nameKey] : '';
        }

        return is_scalar($owner[$this->ownerNameKey] ?? null) ? (string) $owner[$this->ownerNameKey] : '';
    }

    /** Who a line names, for an author: its actor id or its label. */
    public function describeSpeaker(array $line, array $owner = []): string
    {
        return $this->getActorId($line) ?? $this->getSpeakerName($line, $owner);
    }

    /** The catalogue identity whose artwork a line shows, or null when its speaker has none. */
    public function resolveIdentity(array $line, array $owner, DialoguePresentationCatalog $catalogue): ?string
    {
        $name = $this->getSpeakerName($line, $owner);

        return $catalogue->resolveSpeakerId($this->getActorId($line), $name);
    }
}
