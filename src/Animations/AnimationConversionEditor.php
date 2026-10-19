<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Animations;

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Storage\SourceSetPlan;
use Ichiloto\Editor\UI\SettingsPaneLayout;
use Ichiloto\Engine\Animations\Timelines\EffectCadence;
use InvalidArgumentException;

/**
 * The terminal's form for converting a legacy cell-frame animation to a
 * timeline. It has two modes, each a list of lines a cursor walks, so the
 * pane's shared scrolling keeps the cursor in view at any size:
 *
 * - Choices: who plays it, the binding a battle uses, its name, rate, ticks
 *   per original frame, rest frame and flash policy, then Preview. The
 *   record's facts follow, so walking on past the controls reads them.
 *   Nothing is filled in for the timing, which belongs to the consumer.
 * - Review: every file the previewed plan would write, line by line. Enter
 *   writes exactly that plan; Escape goes back to the choices.
 *
 * Previewing plans the conversion with {@see LegacyAnimationConversion} and
 * holds that exact plan. The plan itself refuses files changed since, and
 * any changed choice forgets it.
 *
 * Nothing here draws, so the behaviour can be exercised without a terminal.
 */
final class AnimationConversionEditor
{
    /** Who plays the timeline: a battle paced by its phases, a battle at a fixed rate, or a field script. */
    public const array CONSUMERS = [
        'battle_phase' => 'Battle, paced by its phases',
        'battle_fixed' => 'Battle, at a fixed rate',
        'field' => 'Field script, at a fixed rate',
    ];

    /** The record keys a battle plays a timeline through. */
    public const array BINDINGS = ['targetEffect' => 'Target effect', 'sourceEffect' => 'Caster effect'];

    /** Choosing the conversion's settings. */
    public const string MODE_CHOICES = 'choices';

    /** Reading the previewed files before writing them. */
    public const string MODE_REVIEW = 'review';

    /** Rows typed into, by name. */
    private const array TYPED = ['timeline', 'fps', 'ticks', 'rest'];

    private bool $isOpen = false;
    private string $mode = self::MODE_CHOICES;
    private int $recordIndex = 0;
    private int $animationId = 0;
    private string $name = '';
    /** @var array<string, mixed> What converting touches, as the service describes it. */
    private array $facts = [];
    private ?string $consumer = null;
    private string $binding = 'targetEffect';
    /** @var array<string, string> The typed rows' text. */
    private array $typed = [];
    private bool $includeFlash = true;
    /** The cursor's line in the mode's lines. */
    private int $cursor = 0;
    /** @var list<?string> The control each line of the choices belongs to, as last laid out; null for facts. */
    private array $lineControls = [];
    /** A control the cursor goes to once the choices are next laid out. */
    private ?string $cursorControl = null;
    private ?SourceSetPlan $plan = null;
    private ?string $error = null;

    /**
     * Opens the form on a legacy animation record.
     *
     * @param array<string, mixed> $facts What converting touches ({@see LegacyAnimationConversion::describe()}).
     */
    public function open(int $recordIndex, array $facts): void
    {
        $this->isOpen = true;
        $this->mode = self::MODE_CHOICES;
        $this->recordIndex = $recordIndex;
        $this->animationId = intval($facts['id'] ?? 0);
        $this->name = strval($facts['name'] ?? '');
        $this->facts = $facts;
        $this->consumer = null;
        $this->binding = 'targetEffect';
        // The name is the record's, in lowercase words; the timing is the author's.
        $this->typed = ['timeline' => trim(strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $this->name) ?? ''), '-'), 'fps' => '', 'ticks' => '', 'rest' => ''];
        $this->includeFlash = true;
        $this->cursor = 0;
        $this->plan = null;
        $this->error = null;
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->plan = null;
        $this->facts = [];
    }

    public function isOpen(): bool
    {
        return $this->isOpen;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getRecordIndex(): int
    {
        return $this->recordIndex;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return list<string> The controls shown now, in order; the binding and rate only where the consumer has them. */
    public function getControls(): array
    {
        $battle = $this->consumer !== null && $this->consumer !== 'field';

        return array_values(array_filter([
            'consumer',
            $battle ? 'binding' : null,
            'timeline',
            $this->consumer !== null && $this->consumer !== 'battle_phase' ? 'fps' : null,
            'ticks',
            'rest',
            'flash',
            'preview',
            $this->plan !== null ? 'review' : null,
        ]));
    }

    /**
     * The control under the cursor, on any of its wrapped lines, or null on
     * a line of facts, a message or a reviewed file. Before the choices are
     * first laid out, each control is one line.
     */
    public function getSelectedControl(): ?string
    {
        if ($this->mode !== self::MODE_CHOICES) {
            return null;
        }

        return $this->lineControls === [] ? ($this->getControls()[$this->cursor] ?? null) : ($this->lineControls[$this->cursor] ?? null);
    }

    public function getCursor(): int
    {
        return $this->cursor;
    }

    /**
     * The last line a scrolling pane must show so the whole selected
     * control is on screen: the end of its wrapped lines, or the cursor's
     * own line elsewhere. A control taller than the pane shows from its start.
     */
    public function getCursorEnd(int $visibleRows): int
    {
        $control = $this->getSelectedControl();
        $end = $this->cursor;
        while ($control !== null && ($this->lineControls[$end + 1] ?? null) === $control) {
            $end++;
        }

        return min($end, $this->cursor + max(1, $visibleRows) - 1);
    }

    /**
     * The mode's lines at a width, with the cursor's line marked: the
     * choices with the facts after them, or every reviewed file. Each line is
     * one the cursor can rest on, so following the cursor shows them all.
     *
     * @return list<string>
     */
    public function getLines(int $width, string $projectRoot): array
    {
        $lines = $this->mode === self::MODE_REVIEW ? $this->getReviewLines($width, $projectRoot) : $this->getChoiceLines($width);
        $this->cursor = max(0, min($this->cursor, count($lines) - 1));
        foreach ($lines as $index => $line) {
            $lines[$index] = ($index === $this->cursor ? '> ' : '  ') . $line;
        }

        return $lines;
    }

    /**
     * Moves the cursor a step, within as many lines as the mode has at that
     * width. Among the choices a step is a whole control, however many
     * lines it wraps to, landing on its first line; through facts and a
     * review it is a line.
     */
    public function move(int $delta, int $lineCount): void
    {
        $last = max(0, $lineCount - 1);
        $step = $delta <=> 0;
        foreach (range(1, abs($delta)) as $ignored) {
            $from = $this->cursor;
            $control = $this->mode === self::MODE_CHOICES ? ($this->lineControls[$from] ?? null) : null;
            $at = max(0, min($last, $from + $step));
            // Off the rest of this control's lines when going down; onto the first line of the one above when going up.
            while ($step > 0 && $control !== null && $at < $last && ($this->lineControls[$at] ?? null) === $control) {
                $at++;
            }
            if ($step < 0 && $this->mode === self::MODE_CHOICES && ($this->lineControls[$at] ?? null) !== null) {
                while ($at > 0 && ($this->lineControls[$at - 1] ?? null) === $this->lineControls[$at]) {
                    $at--;
                }
            }
            // A last control taller than the rest stays where it is rather than landing mid-way.
            $this->cursor = $step > 0 && $control !== null && ($this->lineControls[$at] ?? null) === $control ? $from : $at;
        }
    }

    /** Steps the selected choice: the consumer, the binding or the flash policy. */
    public function cycle(int $step): void
    {
        switch ($this->getSelectedControl()) {
            case 'consumer':
                $keys = array_keys(self::CONSUMERS);
                $at = $this->consumer === null ? ($step > 0 ? -1 : 0) : (int) array_search($this->consumer, $keys, true);
                $this->consumer = $keys[(($at + $step) % count($keys) + count($keys)) % count($keys)];
                // Battles have always played flash cues; field animations never did.
                $this->includeFlash = $this->consumer !== 'field';
                break;
            case 'binding':
                $this->binding = $this->binding === 'targetEffect' ? 'sourceEffect' : 'targetEffect';
                break;
            case 'flash':
                $this->includeFlash = ! $this->includeFlash;
                break;
            default:
                return;
        }
        $this->forgetPreview();
    }

    /** Types into the selected text control; any other line takes no typing. */
    public function type(string $text): void
    {
        $control = $this->getSelectedControl();
        if (! in_array($control, self::TYPED, true)) {
            return;
        }
        $this->typed[$control] .= $text;
        $this->forgetPreview();
    }

    public function backspace(): void
    {
        $control = $this->getSelectedControl();
        if (in_array($control, self::TYPED, true) && $this->typed[$control] !== '') {
            $this->typed[$control] = mb_substr($this->typed[$control], 0, -1);
            $this->forgetPreview();
        }
    }

    /**
     * Plans the conversion for the choices shown, holds that plan, and opens
     * it for review from its first line.
     *
     * @return bool Whether a plan is held; otherwise the error says why.
     */
    public function preview(ProjectWorkspace $workspace): bool
    {
        $this->plan = null;
        try {
            if ($this->consumer === null) {
                throw new InvalidArgumentException('Choose who plays the timeline: a battle or a field script.');
            }
            $whole = static fn(string $text, string $what): int => preg_match('/\A\d+\z/', trim($text)) === 1
                ? intval(trim($text)) : throw new InvalidArgumentException(sprintf('%s needs a whole number.', $what));
            $this->plan = LegacyAnimationConversion::plan(
                $workspace,
                $this->animationId,
                trim($this->typed['timeline']),
                $this->consumer === 'battle_phase' ? EffectCadence::BATTLE_PHASE : EffectCadence::FIXED,
                $this->consumer === 'battle_phase' ? null : $whole($this->typed['fps'], 'Frames per second'),
                $whole($this->typed['ticks'], 'Ticks per original frame'),
                $whole($this->typed['rest'], 'Rest frame'),
                $this->includeFlash,
                $this->consumer === 'field' ? null : $this->binding,
            );
            $this->error = null;
        } catch (InvalidArgumentException $refusal) {
            $this->error = $refusal->getMessage();
        }
        if ($this->plan !== null) {
            $this->review();
        }

        return $this->plan !== null;
    }

    /** Opens the held plan for review from its first line. */
    public function review(): void
    {
        if ($this->plan !== null) {
            $this->mode = self::MODE_REVIEW;
            $this->cursor = 0;
        }
    }

    /** Back from review to the choices, the cursor on the review control's first line once laid out. */
    public function returnToChoices(): void
    {
        $this->mode = self::MODE_CHOICES;
        $this->cursorControl = 'review';
    }

    /** The plan previewed for the choices shown, which writing applies exactly. */
    public function getPlan(): ?SourceSetPlan
    {
        return $this->plan;
    }

    public function setError(?string $error): void
    {
        $this->error = $error;
    }

    /** Why the last preview or write could not be made, if it could not. */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * The controls, a message if any, then the record's facts, every one
     * wrapped to the pane so none is cut short; each line remembers the
     * control it belongs to.
     *
     * @return list<string>
     */
    private function getChoiceLines(int $width): array
    {
        $width = max(8, $width - 2);
        $lines = [];
        $this->lineControls = [];
        foreach ($this->getControls() as $control) {
            $label = match ($control) {
                'consumer' => sprintf('Played by: %s', $this->consumer === null ? '(choose with Left/Right)' : self::CONSUMERS[$this->consumer]),
                'binding' => sprintf('Binds as: %s', self::BINDINGS[$this->binding]),
                'timeline' => sprintf('Timeline name: %s', $this->typed['timeline']),
                'fps' => sprintf('Frames per second: %s', $this->typed['fps']),
                'ticks' => sprintf('Ticks per original frame: %s', $this->typed['ticks']),
                'rest' => sprintf('Rest frame (from 0): %s', $this->typed['rest']),
                'flash' => sprintf('Flash cues: %s', $this->includeFlash ? 'become flash tracks' : 'left out'),
                'preview' => '[ Preview the files ]',
                'review' => sprintf('[ Review and write %d files ]', count($this->plan?->getChangedPaths() ?? [])),
            };
            foreach (SettingsPaneLayout::wrapProse($label, $width) as $line) {
                $lines[] = $line;
                $this->lineControls[] = $control;
            }
        }
        $facts = $this->facts;
        $plays = static fn(mixed $list): string => is_array($list) && $list !== [] ? implode(', ', array_map(strval(...), $list)) : 'nothing';
        $prose = [
            ...($this->error === null ? [] : ['', $this->error]),
            '',
            sprintf('%d authored frames over %d (%s anchored), %d cues, %d with a flash.',
                intval($facts['frames'] ?? 0), intval($facts['maxFrames'] ?? 0), strval($facts['position'] ?? ''),
                intval($facts['cues'] ?? 0), intval($facts['flashes'] ?? 0)),
            sprintf('Battle plays it through: %s.', $plays($facts['battle'] ?? [])),
            sprintf('Field scripts that play it: %s. They keep playing the record until their command names the timeline.', $plays($facts['field'] ?? [])),
        ];
        foreach ($prose as $paragraph) {
            $lines = [...$lines, ...($paragraph === '' ? [''] : SettingsPaneLayout::wrapProse($paragraph, $width))];
        }
        $this->lineControls = array_pad($this->lineControls, count($lines), null);
        if ($this->cursorControl !== null) {
            $at = array_search($this->cursorControl, $this->lineControls, true);
            $this->cursor = is_int($at) ? $at : 0;
            $this->cursorControl = null;
        }

        return $lines;
    }

    /** @return list<string> Every file the held plan would write, path then source, wrapped. */
    private function getReviewLines(int $width, string $projectRoot): array
    {
        $width = max(8, $width - 2);
        $root = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $sources = $this->plan?->getProposedSources() ?? [];
        $lines = SettingsPaneLayout::wrapProse(sprintf('Review the %d files Enter writes; Esc returns to the choices.', count($sources)), $width);
        foreach ($sources as $path => $source) {
            $lines[] = '';
            $lines = [...$lines, ...SettingsPaneLayout::wrapProse(str_starts_with($path, $root) ? substr($path, strlen($root)) : $path, $width)];
            foreach (explode("\n", rtrim($source, "\n")) as $line) {
                // Code keeps its indentation; a line wider than the pane continues below it.
                $lines = [...$lines, ...($line === '' ? [''] : SettingsPaneLayout::wrapProse('  ' . $line, $width))];
            }
        }

        return $lines;
    }

    private function forgetPreview(): void
    {
        $this->plan = null;
        $this->error = null;
        $this->mode = self::MODE_CHOICES;
    }
}
