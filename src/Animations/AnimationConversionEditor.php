<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Animations;

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Storage\SourceSetPlan;
use Ichiloto\Engine\Animations\Timelines\EffectCadence;
use InvalidArgumentException;

/**
 * The terminal's form for converting a legacy cell-frame animation to a
 * timeline, a row at a time: who plays it, the binding a battle uses, its
 * name, rate, ticks per original frame, rest frame and flash policy. Nothing
 * is filled in for the timing, which belongs to the consumer.
 *
 * Previewing plans the conversion with {@see LegacyAnimationConversion} and
 * holds that exact plan; writing applies it, so what is written is what was
 * shown, and the plan itself refuses files changed since. Any change to a
 * choice forgets the preview.
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

    /** Rows typed into, by name. */
    private const array TYPED = ['timeline', 'fps', 'ticks', 'rest'];

    private bool $isOpen = false;
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
    private int $selectedIndex = 0;
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
        $this->recordIndex = $recordIndex;
        $this->animationId = intval($facts['id'] ?? 0);
        $this->name = strval($facts['name'] ?? '');
        $this->facts = $facts;
        $this->consumer = null;
        $this->binding = 'targetEffect';
        // The name is the record's, in lowercase words; the timing is the author's.
        $this->typed = ['timeline' => trim(strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $this->name) ?? ''), '-'), 'fps' => '', 'ticks' => '', 'rest' => ''];
        $this->includeFlash = true;
        $this->selectedIndex = 0;
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

    public function getRecordIndex(): int
    {
        return $this->recordIndex;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return list<string> The rows shown now, in order; the binding and rate rows only where the consumer has them. */
    public function getRows(): array
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
            $this->plan !== null ? 'write' : null,
        ]));
    }

    public function getSelectedRow(): string
    {
        $rows = $this->getRows();

        return $rows[min($this->selectedIndex, count($rows) - 1)];
    }

    public function move(int $delta): void
    {
        $count = count($this->getRows());
        $this->selectedIndex = max(0, min($count - 1, $this->selectedIndex + $delta));
    }

    /** Steps the selected choice row: the consumer, the binding or the flash policy. */
    public function cycle(int $step): void
    {
        switch ($this->getSelectedRow()) {
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

    /** Types into the selected text row; a row of choices takes no typing. */
    public function type(string $text): void
    {
        $row = $this->getSelectedRow();
        if (! in_array($row, self::TYPED, true)) {
            return;
        }
        $this->typed[$row] .= $text;
        $this->forgetPreview();
    }

    public function backspace(): void
    {
        $row = $this->getSelectedRow();
        if (in_array($row, self::TYPED, true) && $this->typed[$row] !== '') {
            $this->typed[$row] = mb_substr($this->typed[$row], 0, -1);
            $this->forgetPreview();
        }
    }

    /**
     * Plans the conversion for the choices shown and holds that plan.
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

        return $this->plan !== null;
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

    /** @return list<string> The form's lines, without layout. */
    public function describeRows(string $projectRoot): array
    {
        $facts = $this->facts;
        $plays = static fn(mixed $list): string => is_array($list) && $list !== [] ? implode(', ', array_map(strval(...), $list)) : 'nothing';
        $lines = [
            sprintf('%d authored frames over %d (%s anchored), %d cues, %d with a flash.',
                intval($facts['frames'] ?? 0), intval($facts['maxFrames'] ?? 0), strval($facts['position'] ?? ''),
                intval($facts['cues'] ?? 0), intval($facts['flashes'] ?? 0)),
            sprintf('Battle plays it through: %s.', $plays($facts['battle'] ?? [])),
            sprintf('Field scripts that play it: %s. They keep playing the record until their command names the timeline.', $plays($facts['field'] ?? [])),
            '',
        ];
        $selected = $this->getSelectedRow();
        foreach ($this->getRows() as $row) {
            $value = match ($row) {
                'consumer' => sprintf('Played by: %s', $this->consumer === null ? '(choose with Left/Right)' : self::CONSUMERS[$this->consumer]),
                'binding' => sprintf('Binds as: %s', self::BINDINGS[$this->binding]),
                'timeline' => sprintf('Timeline name: %s', $this->typed['timeline']),
                'fps' => sprintf('Frames per second: %s', $this->typed['fps']),
                'ticks' => sprintf('Ticks per original frame: %s', $this->typed['ticks']),
                'rest' => sprintf('Rest frame (from 0): %s', $this->typed['rest']),
                'flash' => sprintf('Flash cues: %s', $this->includeFlash ? 'become flash tracks' : 'left out'),
                'preview' => '[ Preview the files ]',
                'write' => sprintf('[ Write %d files now ]', count($this->plan?->getChangedPaths() ?? [])),
            };
            $lines[] = ($row === $selected ? '> ' : '  ') . $value;
        }
        if ($this->error !== null) {
            $lines = [...$lines, '', '  ' . $this->error];
        }
        if ($this->plan !== null) {
            $root = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            foreach ($this->plan->getProposedSources() as $path => $source) {
                $lines[] = '';
                $lines[] = '  ' . (str_starts_with($path, $root) ? substr($path, strlen($root)) : $path);
                foreach (explode("\n", rtrim($source, "\n")) as $line) {
                    $lines[] = '    ' . $line;
                }
            }
        }

        return $lines;
    }

    private function forgetPreview(): void
    {
        $this->plan = null;
        $this->error = null;
    }
}
