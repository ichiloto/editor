<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\Inspector\InputControlType;

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
    ) {
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
     * @return self
     */
    public static function boolean(string $key, string $label, bool $removeWhenEmpty = true): self
    {
        return new self(
            $key,
            $label,
            InputControlType::BOOLEAN,
            ['false', 'true'],
            $removeWhenEmpty,
        );
    }
}
