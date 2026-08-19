<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes;

/**
 * A duration projection over a cinematic's command tree: how long each
 * command, lane and block is authored to take, and where the play waits on
 * the player instead of the clock.
 *
 * The numbers come from the same defaults the Engine's interpreter applies
 * (a wait of 0.5s, a route step of 0.15s, a narration of 2.5s, a transition
 * of 0.24s) and from the commands' own fields; nothing here schedules or
 * plays anything. Dialogue, choices, battles and common events cannot be
 * timed from the asset alone and are marked rather than guessed.
 */
final class CutsceneLaneOverview
{
    public const string INPUT = 'input';
    public const string BATTLE = 'battle';
    public const string UNKNOWN = '?';

    /**
     * @param array<int, array{depth: int, key: string, label: string, seconds: float, marks: string[], kind: string}> $rows
     * @param string[] $totalMarks
     */
    private function __construct(public readonly array $rows, public readonly float $totalSeconds, public readonly array $totalMarks)
    {
    }

    /**
     * Builds the overview of a command list.
     *
     * @param array<int, mixed> $commands
     */
    public static function of(array $commands, string $rootKey = 'commands'): self
    {
        /** @var array<int, array{depth: int, key: string, label: string, seconds: float, marks: string[], kind: string}> $rows */
        $rows = [];
        [$seconds, $marks] = self::walkList($commands, $rootKey, 0, $rows);

        return new self($rows, $seconds, $marks);
    }

    /**
     * Formats a duration with its marks for a row.
     *
     * @param string[] $marks
     */
    public static function describe(float $seconds, array $marks): string
    {
        $parts = [];

        if ($seconds > 0.0 || $marks === []) {
            $parts[] = sprintf('%.1fs', $seconds);
        }

        foreach (array_unique($marks) as $mark) {
            $parts[] = match ($mark) {
                self::INPUT => '+input',
                self::BATTLE => '+battle',
                default => '+?',
            };
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<int, mixed> $commands
     * @param array<int, array<string, mixed>> $rows
     * @return array{0: float, 1: string[]}
     */
    private static function walkList(array $commands, string $prefix, int $depth, array &$rows): array
    {
        $total = 0.0;
        $marks = [];

        foreach (array_values($commands) as $index => $command) {
            if (! is_array($command)) {
                continue;
            }

            $key = $prefix . '.' . $index;
            [$seconds, $commandMarks] = self::walkCommand($command, $key, $depth, $rows);
            $total += $seconds;
            $marks = [...$marks, ...$commandMarks];
        }

        return [$total, array_values(array_unique($marks))];
    }

    /**
     * @param array<string, mixed> $command
     * @param array<int, array<string, mixed>> $rows
     * @return array{0: float, 1: string[]}
     */
    private static function walkCommand(array $command, string $key, int $depth, array &$rows): array
    {
        $type = strval($command['type'] ?? '?');

        switch ($type) {
            case 'parallel':
                $rowIndex = count($rows);
                $rows[] = ['depth' => $depth, 'key' => $key, 'label' => 'parallel', 'seconds' => 0.0, 'marks' => [], 'kind' => 'parallel'];
                $longest = 0.0;
                $marks = [];

                foreach (array_values(is_array($command['lanes'] ?? null) ? $command['lanes'] : []) as $laneIndex => $lane) {
                    $laneCommands = is_array($lane) && array_is_list($lane)
                        ? $lane
                        : (is_array($lane) && is_array($lane['commands'] ?? null) ? array_values($lane['commands']) : []);
                    $laneId = is_array($lane) && ! array_is_list($lane) ? strval($lane['id'] ?? ($laneIndex + 1)) : (string) ($laneIndex + 1);
                    $laneRowIndex = count($rows);
                    $laneKey = $key . '.lanes.' . $laneIndex;
                    $rows[] = ['depth' => $depth + 1, 'key' => $laneKey, 'label' => 'lane ' . $laneId, 'seconds' => 0.0, 'marks' => [], 'kind' => 'lane'];
                    [$laneSeconds, $laneMarks] = self::walkList($laneCommands, $laneKey, $depth + 2, $rows);
                    $rows[$laneRowIndex]['seconds'] = $laneSeconds;
                    $rows[$laneRowIndex]['marks'] = $laneMarks;
                    $longest = max($longest, $laneSeconds);
                    $marks = [...$marks, ...$laneMarks];
                }

                $rows[$rowIndex]['seconds'] = $longest;
                $rows[$rowIndex]['marks'] = array_values(array_unique($marks));

                return [$longest, $rows[$rowIndex]['marks']];

            case 'sequence':
                $rowIndex = count($rows);
                $rows[] = ['depth' => $depth, 'key' => $key, 'label' => 'sequence', 'seconds' => 0.0, 'marks' => [], 'kind' => 'block'];
                [$seconds, $marks] = self::walkList(is_array($command['commands'] ?? null) ? $command['commands'] : [], $key . '.commands', $depth + 1, $rows);
                $rows[$rowIndex]['seconds'] = $seconds;
                $rows[$rowIndex]['marks'] = $marks;

                return [$seconds, $marks];

            case 'branch':
                $rowIndex = count($rows);
                $rows[] = ['depth' => $depth, 'key' => $key, 'label' => 'branch', 'seconds' => 0.0, 'marks' => [], 'kind' => 'block'];
                $longest = 0.0;
                $marks = [];

                foreach (['then', 'else'] as $arm) {
                    if (! is_array($command[$arm] ?? null)) {
                        continue;
                    }

                    $armRowIndex = count($rows);
                    $rows[] = ['depth' => $depth + 1, 'key' => $key . '.' . $arm, 'label' => $arm, 'seconds' => 0.0, 'marks' => [], 'kind' => 'arm'];
                    [$armSeconds, $armMarks] = self::walkList($command[$arm], $key . '.' . $arm, $depth + 2, $rows);
                    $rows[$armRowIndex]['seconds'] = $armSeconds;
                    $rows[$armRowIndex]['marks'] = $armMarks;
                    $longest = max($longest, $armSeconds);
                    $marks = [...$marks, ...$armMarks];
                }

                $rows[$rowIndex]['seconds'] = $longest;
                $rows[$rowIndex]['marks'] = array_values(array_unique($marks));

                return [$longest, $rows[$rowIndex]['marks']];

            case 'choice':
                $rowIndex = count($rows);
                $rows[] = ['depth' => $depth, 'key' => $key, 'label' => 'choice', 'seconds' => 0.0, 'marks' => [self::INPUT], 'kind' => 'block'];
                $longest = 0.0;
                $marks = [self::INPUT];

                foreach (array_values(is_array($command['options'] ?? null) ? $command['options'] : []) as $optionIndex => $option) {
                    if (! is_array($option)) {
                        continue;
                    }

                    $optionKey = $key . '.options.' . $optionIndex;
                    $optionRowIndex = count($rows);
                    $rows[] = ['depth' => $depth + 1, 'key' => $optionKey, 'label' => sprintf('option %d', $optionIndex + 1), 'seconds' => 0.0, 'marks' => [], 'kind' => 'arm'];
                    [$optionSeconds, $optionMarks] = self::walkList(is_array($option['then'] ?? null) ? $option['then'] : [], $optionKey, $depth + 2, $rows);
                    $rows[$optionRowIndex]['seconds'] = $optionSeconds;
                    $rows[$optionRowIndex]['marks'] = $optionMarks;
                    $longest = max($longest, $optionSeconds);
                    $marks = [...$marks, ...$optionMarks];
                }

                if (is_array($command['cancel'] ?? null)) {
                    $cancelRowIndex = count($rows);
                    $rows[] = ['depth' => $depth + 1, 'key' => $key . '.cancel', 'label' => 'cancel', 'seconds' => 0.0, 'marks' => [], 'kind' => 'arm'];
                    [$cancelSeconds, $cancelMarks] = self::walkList($command['cancel'], $key . '.cancel', $depth + 2, $rows);
                    $rows[$cancelRowIndex]['seconds'] = $cancelSeconds;
                    $rows[$cancelRowIndex]['marks'] = $cancelMarks;
                    $longest = max($longest, $cancelSeconds);
                    $marks = [...$marks, ...$cancelMarks];
                }

                $rows[$rowIndex]['seconds'] = $longest;
                $rows[$rowIndex]['marks'] = array_values(array_unique($marks));

                return [$longest, $rows[$rowIndex]['marks']];
        }

        [$seconds, $marks] = self::estimate($command);
        $rows[] = ['depth' => $depth, 'key' => $key, 'label' => CutsceneOutline::summarize($command), 'seconds' => $seconds, 'marks' => $marks, 'kind' => 'command'];

        return [$seconds, $marks];
    }

    /**
     * The authored duration of one leaf command, with the Engine's defaults.
     *
     * @param array<string, mixed> $command
     * @return array{0: float, 1: string[]}
     */
    public static function estimate(array $command): array
    {
        $type = strval($command['type'] ?? '');
        $seconds = static fn(string $key, float $default): float => max(0.0, is_numeric($command[$key] ?? null) ? floatval($command[$key]) : $default);

        return match ($type) {
            'wait' => [$seconds('seconds', 0.5), []],
            'narration', 'title_card' => [$seconds('seconds', 2.5), []],
            'transition' => [$seconds('seconds', 0.24), []],
            'text', 'choice' => [0.0, [self::INPUT]],
            'start_battle' => [0.0, [self::BATTLE]],
            'common_event' => [0.0, [self::UNKNOWN]],
            'move_route' => [self::routeSeconds($command), []],
            'camera' => [self::cameraSeconds($command), []],
            'field_animation' => [self::animationSeconds($command), []],
            'cinematic_music' => [max($seconds('fadeIn', 0.0), 0.0), []],
            default => [0.0, []],
        };
    }

    /**
     * @param array<string, mixed> $command
     */
    private static function routeSeconds(array $command): float
    {
        $steps = is_array($command['steps'] ?? null) ? $command['steps'] : [];
        $defaultDelay = is_numeric($command['secondsPerStep'] ?? null)
            ? max(0.0, floatval($command['secondsPerStep']))
            : (is_numeric($command['speed'] ?? null) && floatval($command['speed']) > 0 ? 1.0 / floatval($command['speed']) : 0.15);
        $total = 0.0;

        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $count = max(1, intval($step['count'] ?? 1));
            $delay = is_numeric($step['seconds'] ?? null) ? max(0.0, floatval($step['seconds'])) : $defaultDelay;
            $total += $count * $delay;
        }

        return $total;
    }

    /**
     * @param array<string, mixed> $command
     */
    private static function cameraSeconds(array $command): float
    {
        $operation = strtolower(strval($command['operation'] ?? ''));
        $own = is_numeric($command['seconds'] ?? null) ? max(0.0, floatval($command['seconds'])) : (is_numeric($command['duration'] ?? null) ? max(0.0, floatval($command['duration'])) : 0.0);

        if ($operation === 'route') {
            $total = 0.0;

            foreach (is_array($command['points'] ?? null) ? $command['points'] : [] as $point) {
                if (is_array($point)) {
                    $total += is_numeric($point['seconds'] ?? null) ? max(0.0, floatval($point['seconds'])) : (is_numeric($point['duration'] ?? null) ? max(0.0, floatval($point['duration'])) : 0.0);
                }
            }

            return $total > 0.0 ? $total : $own;
        }

        return $own;
    }

    /**
     * @param array<string, mixed> $command
     */
    private static function animationSeconds(array $command): float
    {
        $frames = is_array($command['frames'] ?? null) ? count($command['frames']) : 0;
        $secondsPerFrame = is_numeric($command['secondsPerFrame'] ?? null) ? max(0.01, floatval($command['secondsPerFrame'])) : 0.12;

        return $frames > 0 ? $frames * $secondsPerFrame : 0.0;
    }
}
