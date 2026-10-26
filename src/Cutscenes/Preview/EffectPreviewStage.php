<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectSegmentComposition;
use Ichiloto\Engine\Battle\Presentation\BattleEffectDirection;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStage;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStagePresentation;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use InvalidArgumentException;

/**
 * An effect's frame as a terminal consumer places it.
 *
 * The Engine's composition chooses what one presentation draws (a track
 * scoped to the other renderer, and image tracks in the terminal, drop out)
 * and in what order. Glyph and text draws then sit at their anchor: the
 * caster or the target, or the top left of the screen. In battle a stroke
 * with a facing is turned toward its recipient exactly as the battle turns
 * it, so both directions can be checked by putting the caster on either
 * side. On the field an effect has one subject, its target.
 *
 * What a terminal cannot draw (images, flashes, shakes) is described, not
 * imitated: the graphical renderer owns those pictures.
 */
final readonly class EffectPreviewStage
{
    public const string CASTER_MARKER = 'C';
    public const string TARGET_MARKER = 'T';

    /**
     * @param bool $forBattle Battle has a caster and a target; the field only a target.
     * @param bool $isCasterOnLeft Which side of the stage the caster stands on, in battle.
     */
    public function __construct(
        public EffectPresentation $presentation,
        public bool $forBattle,
        public bool $isCasterOnLeft = true,
        public bool $forStage = false,
    ) {
        if ($forStage && $forBattle) {
            throw new InvalidArgumentException('An owned stage cannot also preview battle surroundings.');
        }
    }

    /**
     * Draws the glyph and text of the active segments, over the subjects'
     * markers, into rows of the given size.
     *
     * @param array<int, array<string, mixed>> $segments The playhead's active segments.
     * @return string[]
     */
    public function drawFrame(array $segments, int $width, int $height): array
    {
        $width = max(1, $width);
        $height = max(1, $height);
        $cells = array_fill(0, $height, array_fill(0, $width, ' '));
        $origins = $this->findOrigins($width, $height);

        foreach ($origins as $anchor => $origin) {
            $this->place($cells, $anchor === 'caster' ? self::CASTER_MARKER : self::TARGET_MARKER, $origin['x'], $origin['y']);
        }

        $commands = [];

        foreach ($this->composeSegments($segments) as $segment) {
            if (! in_array($segment['layer'] ?? '', ['glyph', 'text'], true)) {
                continue;
            }

            foreach (array_filter((array) ($segment['drawCommands'] ?? []), is_array(...)) as $command) {
                $commands[] = $command;
            }
        }

        usort($commands, static fn(array $a, array $b): int => ($a['zIndex'] ?? 0) <=> ($b['zIndex'] ?? 0));

        foreach ($commands as $command) {
            if (($command['visible'] ?? true) === false) {
                continue;
            }

            $anchor = strval($command['payload']['anchor'] ?? 'target');
            $base = in_array($anchor, ['screen', 'legacy-screen'], true)
                ? ['x' => 0, 'y' => 0]
                : ($origins[$anchor] ?? $origins['target'] ?? ['x' => 0, 'y' => 0]);

            $command = $this->orient($command);
            $position = is_array($command['position'] ?? null) ? $command['position'] : [];
            $x = $base['x'] + intval($position['x'] ?? 0);
            $y = $base['y'] + intval($position['y'] ?? 0);

            foreach (explode("\n", trim(strval($command['content'] ?? ''), "\r\n")) as $row => $line) {
                $this->place($cells, $line, $x, $y + $row);
            }
        }

        return array_map(static fn(array $row): string => implode('', $row), $cells);
    }

    /** Seekable Engine projection, with no scene, audio or gameplay state to retain. */
    public function renderCanvas(CompiledEffectTimeline $timeline, string $assetRoot, int $frame,
        int $width, int $height, bool $reducedMotion = false): ?PresentationCanvas
    {
        if (! $this->forStage || $this->presentation !== EffectPresentation::GRAPHICAL) {
            return null;
        }

        $length = intval($timeline->defaults['lengthFrames'] ?? 1);
        $stage = CinematicStage::fromArray($timeline->defaults['stage'] ?? [], $length, intval($timeline->defaults['restFrame'] ?? 0));
        $frame = max(0, min($frame, $length - 1));

        return CinematicStagePresentation::compose($stage->getFrame($frame, $reducedMotion),
            $timeline->playbackSegments, $assetRoot, max(1, $width), max(1, $height));
    }

    /**
     * Describes the active draws a terminal cannot show: images with their
     * sheet frame and flips, flashes and shakes with their subject.
     *
     * @param array<int, array<string, mixed>> $segments The playhead's active segments.
     * @return string[]
     */
    public function describeUndrawn(array $segments): array
    {
        $lines = $this->forStage && $this->presentation === EffectPresentation::TERMINAL
            ? ['stage images and camera: graphical only'] : [];

        foreach ($this->composeSegments($segments) as $segment) {
            $layer = strval($segment['layer'] ?? '');

            if (in_array($layer, ['glyph', 'text'], true)) {
                continue;
            }

            foreach (array_filter((array) ($segment['drawCommands'] ?? []), is_array(...)) as $command) {
                $command = $this->orient($command);
                $payload = is_array($command['payload'] ?? null) ? $command['payload'] : [];
                $subject = strval($payload['anchor'] ?? 'target');
                $lines[] = match ($layer) {
                    'image' => sprintf('%s frame %d%s%s on %s', basename(strval($command['assetId'] ?? '?')), intval($payload['sourceFrame'] ?? 0),
                        ($payload['flipX'] ?? false) === true ? ' flipX' : '', ($payload['flipY'] ?? false) === true ? ' flipY' : '', $subject),
                    'flash' => sprintf('flash %s on %s', strval($payload['color'] ?? $command['color'] ?? 'white'), $subject),
                    'shake' => sprintf('shake %s on %s', strval($payload['amplitude'] ?? 1), $subject),
                    default => sprintf('%s on %s', $layer, $subject),
                };
            }
        }

        // Image tracks never reach the terminal's composition; say so once.
        if ($this->presentation === EffectPresentation::TERMINAL) {
            foreach ($segments as $segment) {
                if (($segment['layer'] ?? '') === 'image') {
                    $lines[] = 'image tracks: graphical only';

                    break;
                }
            }
        }

        return $lines;
    }

    /**
     * Turns a battle stroke with a facing toward its recipient, the target,
     * exactly as the battle turns it; the field draws every stroke as authored.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    private function orient(array $command): array
    {
        if (! $this->forBattle) {
            return $command;
        }

        return BattleEffectDirection::orientCommand($command, $this->isCasterOnLeft ? 0 : 1, $this->isCasterOnLeft ? 1 : 0);
    }

    /**
     * @param array<int, array<string, mixed>> $segments
     * @return list<array<string, mixed>>
     */
    private function composeSegments(array $segments): array
    {
        return EffectSegmentComposition::compose(array_values($segments), $this->presentation);
    }

    /**
     * Where each subject stands on a stage of this size.
     *
     * @return array<string, array{x: int, y: int}>
     */
    private function findOrigins(int $width, int $height): array
    {
        $y = intdiv($height, 2);

        if ($this->forStage) {
            return [];
        }

        if (! $this->forBattle) {
            return ['target' => ['x' => intdiv($width, 2), 'y' => $y]];
        }

        $left = intdiv($width, 4);
        $right = $width - 1 - intdiv($width, 4);

        return [
            'caster' => ['x' => $this->isCasterOnLeft ? $left : $right, 'y' => $y],
            'target' => ['x' => $this->isCasterOnLeft ? $right : $left, 'y' => $y],
        ];
    }

    /**
     * Writes a line into the cells from a column, clipping at the edges and
     * keeping a wide symbol whole.
     *
     * @param array<int, array<int, string>> $cells
     */
    private function place(array &$cells, string $line, int $x, int $y): void
    {
        if ($y < 0 || $y >= count($cells)) {
            return;
        }

        $width = count($cells[$y]);
        $column = $x;

        foreach (TerminalText::visibleSymbols($line) as $symbol) {
            $symbolWidth = max(1, TerminalText::displayWidth($symbol));

            if (trim($symbol) !== '' && $column >= 0 && $column + $symbolWidth <= $width) {
                $cells[$y][$column] = $symbol;

                for ($offset = 1; $offset < $symbolWidth; $offset++) {
                    $cells[$y][$column + $offset] = '';
                }
            }

            $column += $symbolWidth;
        }
    }
}
