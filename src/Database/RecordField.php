<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Dialogue\DialogueLineSpeaker;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;

/**
 * One editable field on a Database record.
 *
 * A field with `options` is cycled with Left/Right (the one enum idiom Phase 4
 * settled on); every other field is typed through an `InputControl` of the
 * declared type.
 */
final readonly class RecordField
{
    /**
     * @param string $key The payload key, or a dotted path for nested reads.
     * @param string $label The settings-pane label.
     * @param InputControlType $type The control type driving typing and stepping.
     * @param string[] $options The cycleable option values, when this is an enum field.
     * @param bool $removeWhenEmpty Whether an empty/zero/false value drops the key entirely.
     * @param bool $isReadOnly Whether the field is displayed but never edited.
     * @param int $step The step size for numeric adjustment.
     * @param RecordFieldCodec $codec How a list-shaped value projects onto one line.
     * @param string|null $reference The kind of resource this names, when it
     * names one. A reference is chosen from a picker rather than typed, so an
     * author never has to remember or spell another record's name.
     * @param string|null $displayDefault What an absent key reads as in the
     * pane -- the value the runtime falls back to (`fixed`, `@`, "the NPC's
     * name") -- so an unset field is never a blank the author has to decode.
     * For a reference that allows none, this is also the label of the
     * picker's "none" row, since picking it leaves the key absent.
     * @param string|null $blankLabel For a reference field, a pickable row
     * that stores the empty string, when the runtime gives `''` a meaning of
     * its own distinct from an absent key (a dialogue page with no speaker).
     * The stored `''` reads back as this label.
     * @param string[]|null $writeTypes For a world-write field, the write
     * types this surface may author, when the runtime restricts it to fewer
     * than the writer's full vocabulary (a transactional boundary rejects
     * quest acceptance). Null offers everything the writer applies.
     * @param bool $uniqueAcrossRecords For a list field, whether a member may
     * be held by one record of the category at most (an animation role plays
     * one animation), so taking one another record holds is refused.
     * @param string|null $referenceMapKey For a map-local reference (an event
     * marker, an NPC), the entry field naming the map its choices come from,
     * when that is not the map the author has selected.
     */
    public function __construct(
        public string $key,
        public string $label,
        public InputControlType $type = InputControlType::TEXT,
        public array $options = [],
        public bool $removeWhenEmpty = false,
        public bool $isReadOnly = false,
        public int $step = 1,
        public RecordFieldCodec $codec = RecordFieldCodec::NONE,
        public ?string $reference = null,
        public ?string $enumClass = null,
        public bool $allowsNone = false,
        public ?string $displayDefault = null,
        public ?string $blankLabel = null,
        public ?array $writeTypes = null,
        public bool $uniqueAcrossRecords = false,
        /** For a line's emotion, how the line names its speaker, whose catalogue emotions it is chosen from. */
        public ?DialogueLineSpeaker $dialogueSpeaker = null,
        public ?string $referenceMapKey = null,
    ) {
    }

    /**
     * Returns a member of the troop that owns the entry, chosen by number and
     * enemy name and stored as its index in the troop's enemies.
     *
     * @param bool $required Whether the entry must name one; an optional one can be None.
     */
    public static function troopMember(string $key, string $label, bool $required = true): self
    {
        return new self($key, $label, InputControlType::INTEGER, removeWhenEmpty: ! $required,
            codec: RecordFieldCodec::TROOP_MEMBER, allowsNone: ! $required);
    }

    /**
     * Returns a reference to something on a map the entry itself names: a
     * quest waymark's event marker is chosen from the waymark's map.
     *
     * @param string $key The payload key.
     * @param string $label The settings-pane label.
     * @param string $category The map-local kind of resource it names.
     * @param string $mapKey The entry field holding the map id.
     * @return self The field, unset by default.
     */
    public static function mapReference(string $key, string $label, string $category, string $mapKey): self
    {
        return new self($key, $label, removeWhenEmpty: true, reference: $category, allowsNone: true, referenceMapKey: $mapKey);
    }

    /**
     * Returns a dialogue line's emotion: chosen among the emotions the line's speaker has in the game's dialogue
     * catalogue, as the rule says who speaks. Absent, the Engine shows Neutral.
     *
     * @param DialogueLineSpeaker $speaker How the line names its speaker.
     * @return self The field.
     */
    public static function emotion(DialogueLineSpeaker $speaker): self
    {
        return new self('emotion', 'Emotion', removeWhenEmpty: true, displayDefault: DialogueContext::DEFAULT_EMOTION, dialogueSpeaker: $speaker);
    }

    /**
     * Returns a field that names another resource.
     *
     * @param string $key The payload key.
     * @param string $label The settings-pane label.
     * @param string $category The kind of resource it names.
     * @param bool $allowsNone Whether the reference may be left unset.
     * @param string|null $noneLabel What "unset" reads as, when it means
     * something more specific than none.
     * @param string|null $blankLabel A pickable row storing `''`, when the
     * runtime distinguishes an empty value from an absent one.
     * @return self The field.
     */
    public static function reference(
        string $key,
        string $label,
        string $category,
        bool $allowsNone = false,
        ?string $noneLabel = null,
        ?string $blankLabel = null,
    ): self {
        return new self(
            $key,
            $label,
            reference: $category,
            allowsNone: $allowsNone,
            displayDefault: $noneLabel,
            blankLabel: $blankLabel,
        );
    }

    /**
     * Returns the label of the picker row that leaves the reference unset.
     *
     * @return string The label.
     */
    public function noneLabel(): string
    {
        return $this->displayDefault ?? '(None)';
    }

    /**
     * Returns a read-only variant of this field (used for object-backed rows).
     *
     * @return self
     */
    public function asReadOnly(): self
    {
        return new self(
            $this->key,
            $this->label,
            $this->type,
            $this->options,
            $this->removeWhenEmpty,
            true,
            $this->step,
            $this->codec,
            $this->reference,
            $this->enumClass,
            $this->allowsNone,
            $this->displayDefault,
            $this->blankLabel,
            $this->writeTypes,
            dialogueSpeaker: $this->dialogueSpeaker,
        );
    }

    /**
     * Returns a boolean field, stored as a real bool and cycled false/true.
     *
     * Booleans use the option idiom rather than a BOOLEAN control because the
     * Database pane cycles options with Left/Right already; this keeps one
     * interaction for every non-text field in the pane.
     *
     * @param string $key The payload key.
     * @param string $label The settings-pane label.
     * @param bool $removeWhenEmpty Whether `false` drops the key (matching engine defaults).
     * @param string|null $displayDefault What an absent key reads as, such as the engine's `false`.
     * @return self
     */
    public static function boolean(string $key, string $label, bool $removeWhenEmpty = true, ?string $displayDefault = null): self
    {
        return new self(
            $key,
            $label,
            InputControlType::BOOLEAN,
            ['false', 'true'],
            $removeWhenEmpty,
            displayDefault: $displayDefault,
        );
    }
}
