<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes;

use Ichiloto\Editor\Database\CutsceneSchemas;

/**
 * The command tree of a cinematic, or the track and cue list of a summon,
 * as rows an outline pane can draw and walk.
 *
 * Each row knows its depth, its text, and where it lives: the frame path
 * that holds it and its index there, so the pane can open that frame and
 * put the cursor on the row's own fields. Blocks -- sequences, parallel
 * lanes, branches, choices -- have children and may be folded shut. The
 * outline is a projection of the authoritative command tree, never a
 * second copy of it.
 *
 * @package Ichiloto\Editor\Cutscenes
 */
final class CutsceneOutline
{
    /**
     * @var array<int, array{depth: int, text: string, frame: array<int, int|string>, index: int|null, key: string, kind: string, foldable: bool, children: bool}>
     */
    private array $rows = [];

    private function __construct()
    {
    }

    /**
     * Builds the outline of an asset's payload.
     *
     * @param array<string, mixed> $payload The record payload.
     */
    public static function of(CutsceneType $type, array $payload): self
    {
        $outline = new self();

        if ($type === CutsceneType::CINEMATIC) {
            $commands = is_array($payload[CutsceneAsset::COMMANDS_KEY] ?? null) ? array_values($payload[CutsceneAsset::COMMANDS_KEY]) : [];
            $outline->rows[] = ['depth' => 0, 'text' => sprintf('Commands (%d)', count($commands)), 'frame' => [CutsceneAsset::COMMANDS_KEY], 'index' => null, 'key' => 'commands', 'kind' => 'list', 'foldable' => $commands !== [], 'children' => $commands !== []];
            $outline->walk($commands, [CutsceneAsset::COMMANDS_KEY], 1, 'commands');
            $finalizer = is_array($payload[CutsceneSchemas::FINALIZER_KEY] ?? null) ? array_values($payload[CutsceneSchemas::FINALIZER_KEY]) : [];
            $outline->rows[] = ['depth' => 0, 'text' => sprintf('Finalizer (%d)', count($finalizer)), 'frame' => [CutsceneSchemas::FINALIZER_KEY], 'index' => null, 'key' => 'finalizer', 'kind' => 'list', 'foldable' => $finalizer !== [], 'children' => $finalizer !== []];
            $outline->walk($finalizer, [CutsceneSchemas::FINALIZER_KEY], 1, 'finalizer');

            return $outline;
        }

        $tracks = is_array($payload[CutsceneSchemas::TRACKS_KEY] ?? null) ? array_values($payload[CutsceneSchemas::TRACKS_KEY]) : [];
        $outline->rows[] = ['depth' => 0, 'text' => sprintf('Tracks (%d)', count($tracks)), 'frame' => [CutsceneSchemas::TRACKS_KEY], 'index' => null, 'key' => 'tracks', 'kind' => 'list', 'foldable' => $tracks !== [], 'children' => $tracks !== []];

        foreach ($tracks as $index => $track) {
            if (! is_array($track)) {
                continue;
            }

            $keyframes = is_array($track['keyframes'] ?? null) ? array_values($track['keyframes']) : [];
            $key = 'tracks.' . $index;
            $outline->rows[] = [
                'depth' => 1,
                'text' => sprintf('%s %s · %d keyframe%s', strval($track['type'] ?? 'glyph'), strval($track['id'] ?? ('track ' . ($index + 1))), count($keyframes), count($keyframes) === 1 ? '' : 's'),
                'frame' => [CutsceneSchemas::TRACKS_KEY],
                'index' => $index,
                'key' => $key,
                'kind' => 'track',
                'foldable' => $keyframes !== [],
                'children' => $keyframes !== [],
            ];

            foreach ($keyframes as $keyframeIndex => $keyframe) {
                if (! is_array($keyframe)) {
                    continue;
                }

                $frame = intval($keyframe['frame'] ?? 0);
                $duration = max(1, intval($keyframe['duration'] ?? 1));
                $content = is_string($keyframe['content'] ?? null) ? explode("\n", $keyframe['content'])[0] : '';
                $outline->rows[] = [
                    'depth' => 2,
                    'text' => sprintf('f%d +%d  %s', $frame, $duration, $content !== '' ? $content : (isset($keyframe['assetId']) ? 'asset ' . strval($keyframe['assetId']) : '(empty)')),
                    'frame' => [CutsceneSchemas::TRACKS_KEY],
                    'index' => $index,
                    'key' => $key . '.keyframes.' . $keyframeIndex,
                    'kind' => 'keyframe',
                    'foldable' => false,
                    'children' => false,
                ];
            }
        }

        $cues = is_array($payload[CutsceneSchemas::CUES_KEY] ?? null) ? array_values($payload[CutsceneSchemas::CUES_KEY]) : [];
        $outline->rows[] = ['depth' => 0, 'text' => sprintf('Cues (%d)', count($cues)), 'frame' => [CutsceneSchemas::CUES_KEY], 'index' => null, 'key' => 'cues', 'kind' => 'list', 'foldable' => $cues !== [], 'children' => $cues !== []];

        foreach ($cues as $index => $cue) {
            if (! is_array($cue)) {
                continue;
            }

            $outline->rows[] = [
                'depth' => 1,
                'text' => sprintf('f%d  %s %s', intval($cue['frame'] ?? 0), strval($cue['type'] ?? 'cue'), strval($cue['id'] ?? '')),
                'frame' => [CutsceneSchemas::CUES_KEY],
                'index' => $index,
                'key' => 'cues.' . $index,
                'kind' => 'cue',
                'foldable' => false,
                'children' => false,
            ];
        }

        return $outline;
    }

    /**
     * Walks a command list, one row per command, recursing into blocks.
     *
     * @param array<int, mixed> $commands
     * @param array<int, int|string> $frame The frame path holding the commands.
     */
    private function walk(array $commands, array $frame, int $depth, string $keyPrefix): void
    {
        foreach ($commands as $index => $command) {
            $key = $keyPrefix . '.' . $index;

            if (! is_array($command)) {
                $this->rows[] = ['depth' => $depth, 'text' => '(not a command)', 'frame' => $frame, 'index' => $index, 'key' => $key, 'kind' => 'command', 'foldable' => false, 'children' => false];

                continue;
            }

            $type = strval($command['type'] ?? '?');
            $children = self::childrenOf($command);
            $this->rows[] = [
                'depth' => $depth,
                'text' => self::summarize($command),
                'frame' => $frame,
                'index' => $index,
                'key' => $key,
                'kind' => 'command',
                'foldable' => $children !== [],
                'children' => $children !== [],
            ];

            foreach ($children as $child) {
                $childFrame = [...$frame, $index, ...$child['path']];
                $childCommands = $child['commands'];
                $childKey = $key . '.' . $child['key'];

                if ($child['label'] !== null) {
                    $this->rows[] = [
                        'depth' => $depth + 1,
                        'text' => $child['label'],
                        'frame' => $childFrame,
                        'index' => null,
                        'key' => $childKey,
                        'kind' => 'arm',
                        'foldable' => $childCommands !== [],
                        'children' => $childCommands !== [],
                    ];
                    $this->walk($childCommands, $childFrame, $depth + 2, $childKey);
                } else {
                    $this->walk($childCommands, $childFrame, $depth + 1, $childKey);
                }
            }
        }
    }

    /**
     * Returns the command lists a command owns: a sequence's commands, a
     * parallel block's lanes, a branch's arms, a choice's options and
     * cancel arm.
     *
     * @param array<string, mixed> $command
     * @return array<int, array{path: array<int, int|string>, commands: array<int, mixed>, label: string|null, key: string}>
     */
    public static function childrenOf(array $command): array
    {
        $type = strval($command['type'] ?? '');
        $children = [];

        if ($type === 'sequence') {
            $children[] = ['path' => ['commands'], 'commands' => self::listOf($command['commands'] ?? null), 'label' => null, 'key' => 'commands'];
        }

        if ($type === 'parallel') {
            foreach (self::listOf($command['lanes'] ?? null) as $laneIndex => $lane) {
                if (! is_array($lane)) {
                    continue;
                }

                if (array_is_list($lane)) {
                    $children[] = ['path' => ['lanes', $laneIndex], 'commands' => $lane, 'label' => sprintf('lane %d', $laneIndex + 1), 'key' => 'lanes.' . $laneIndex];

                    continue;
                }

                $children[] = [
                    'path' => ['lanes', $laneIndex, 'commands'],
                    'commands' => self::listOf($lane['commands'] ?? null),
                    'label' => sprintf('lane %s', strval($lane['id'] ?? ($laneIndex + 1))),
                    'key' => 'lanes.' . $laneIndex,
                ];
            }
        }

        if ($type === 'branch') {
            foreach (['then', 'else'] as $arm) {
                if (array_key_exists($arm, $command)) {
                    $children[] = ['path' => [$arm], 'commands' => self::listOf($command[$arm]), 'label' => $arm, 'key' => $arm];
                }
            }
        }

        if ($type === 'choice') {
            foreach (self::listOf($command['options'] ?? null) as $optionIndex => $option) {
                if (is_array($option)) {
                    $children[] = [
                        'path' => ['options', $optionIndex, 'then'],
                        'commands' => self::listOf($option['then'] ?? null),
                        'label' => sprintf('option %d: %s', $optionIndex + 1, strval($option['text'] ?? '')),
                        'key' => 'options.' . $optionIndex,
                    ];
                }
            }

            if (array_key_exists('cancel', $command)) {
                $children[] = ['path' => ['cancel'], 'commands' => self::listOf($command['cancel']), 'label' => 'cancel', 'key' => 'cancel'];
            }
        }

        return $children;
    }

    /**
     * Resolves an outline row key to the list in the payload that holds the
     * row's entry and the entry's index in it.
     *
     * Keys spell the tree as the outline walks it, which leaves two steps
     * implicit: a keyed parallel lane holds its commands under `commands`,
     * and a choice option holds its commands under `then`.
     *
     * @param array<string, mixed> $payload
     * @return array{listPath: array<int, int|string>, index: int}|null
     */
    public static function locate(array $payload, string $key): ?array
    {
        $segments = explode('.', $key);
        $path = [];
        $value = $payload;
        $lastName = null;
        $count = count($segments);

        foreach ($segments as $position => $segment) {
            if (! is_numeric($segment)) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    return null;
                }

                $path[] = $segment;
                $value = $value[$segment];
                $lastName = $segment;

                continue;
            }

            $index = (int) $segment;

            if (is_array($value) && ! array_is_list($value)) {
                $implicit = match ($lastName) {
                    'lanes' => 'commands',
                    'options' => 'then',
                    default => null,
                };

                if ($implicit === null || ! is_array($value[$implicit] ?? null)) {
                    return null;
                }

                $path[] = $implicit;
                $value = $value[$implicit];
            }

            if (! is_array($value) || ! array_is_list($value)) {
                return null;
            }

            if ($position === $count - 1) {
                return ['listPath' => $path, 'index' => $index];
            }

            if (! array_key_exists($index, $value)) {
                return null;
            }

            $path[] = $index;
            $value = $value[$index];
        }

        return null;
    }

    /**
     * Reads a value at a payload path.
     *
     * @param array<string, mixed> $payload
     * @param array<int, int|string> $path
     */
    public static function valueAt(array $payload, array $path): mixed
    {
        $value = $payload;

        foreach ($path as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Writes a value at a payload path, creating the way there.
     *
     * @param array<string, mixed> $payload
     * @param array<int, int|string> $path
     * @return array<string, mixed>
     */
    public static function withValueAt(array $payload, array $path, mixed $value): array
    {
        if ($path === []) {
            return is_array($value) ? $value : $payload;
        }

        $target = &$payload;

        foreach ($path as $segment) {
            if (! is_array($target)) {
                $target = [];
            }

            if (! array_key_exists($segment, $target) || ! is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }

        $target = $value;

        return $payload;
    }

    /**
     * The child list a block command would receive a nested command into:
     * a sequence's commands, a parallel block's last lane, a branch's then
     * arm, a choice's first option.
     *
     * @param array<string, mixed> $command
     * @param array<int, int|string> $commandPath
     * @return array<int, int|string>|null
     */
    public static function nestingTarget(array $command, array $commandPath): ?array
    {
        $type = strval($command['type'] ?? '');

        switch ($type) {
            case 'sequence':
                return [...$commandPath, 'commands'];
            case 'branch':
                return [...$commandPath, 'then'];
            case 'parallel':
                $lanes = self::listOf($command['lanes'] ?? null);

                if ($lanes === []) {
                    return null;
                }

                $last = count($lanes) - 1;
                $lane = $lanes[$last];

                return is_array($lane) && ! array_is_list($lane)
                    ? [...$commandPath, 'lanes', $last, 'commands']
                    : [...$commandPath, 'lanes', $last];
            case 'choice':
                $options = self::listOf($command['options'] ?? null);

                if ($options === [] || ! is_array($options[0])) {
                    return array_key_exists('cancel', $command) ? [...$commandPath, 'cancel'] : null;
                }

                return [...$commandPath, 'options', 0, 'then'];
        }

        return null;
    }

    /**
     * @return array<int, mixed>
     */
    private static function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * Describes one command in a line: its type and what it most tells.
     *
     * @param array<string, mixed> $command
     */
    public static function summarize(array $command): string
    {
        $type = strval($command['type'] ?? '?');
        $detail = match ($type) {
            'text' => self::quote(strval($command['text'] ?? ''), strval($command['name'] ?? '')),
            'narration', 'title_card' => self::quote(strval($command['title'] ?? $command['text'] ?? ''), '') . self::seconds($command, 'seconds'),
            'choice' => self::quote(strval($command['prompt'] ?? $command['title'] ?? ''), '') . sprintf(' · %d options', count(self::listOf($command['options'] ?? null))),
            'wait' => self::seconds($command, 'seconds'),
            'set_switch' => strval($command['name'] ?? '') . ' = ' . (($command['value'] ?? true) ? 'on' : 'off'),
            'set_variable' => strval($command['name'] ?? '') . ' ' . (strval($command['op'] ?? 'set') === 'add' ? '+=' : '=') . ' ' . strval(is_scalar($command['value'] ?? null) ? $command['value'] : ''),
            'record_event' => strval($command['name'] ?? ''),
            'give_item' => sprintf('%s ×%d', strval($command['item'] ?? ''), intval($command['quantity'] ?? 1)),
            'give_gold' => strval($command['amount'] ?? '0') . ' gold',
            'play_sound' => strval($command['sound'] ?? ''),
            'play_music' => strval($command['music'] ?? ''),
            'accept_quest', 'common_event' => strval($command['id'] ?? ''),
            'knowledge' => strval($command['operation'] ?? '') . ' ' . strval($command['subject'] ?? ''),
            'move_player' => sprintf('→ %s, %s', strval($command['x'] ?? '?'), strval($command['y'] ?? '?')),
            'move_route' => self::routeSummary($command),
            'transfer' => sprintf('→ %s (%s, %s)', strval($command['map'] ?? '?'), strval($command['x'] ?? '?'), strval($command['y'] ?? '?')),
            'start_battle' => strval($command['troop'] ?? ''),
            'branch' => 'if ' . self::conditionSummary($command),
            'sequence' => sprintf('%d commands', count(self::listOf($command['commands'] ?? null))),
            'parallel' => sprintf('%d lanes', count(self::listOf($command['lanes'] ?? null))),
            'checkpoint' => strval($command['name'] ?? ''),
            'camera' => self::cameraSummary($command),
            'stage_actor' => strval((is_array($command['actor'] ?? null) ? $command['actor'] : $command)['id'] ?? '') . self::at(is_array($command['actor'] ?? null) ? $command['actor'] : $command),
            'show_actor', 'hide_actor', 'remove_actor' => strval($command['actorId'] ?? $command['id'] ?? ''),
            'field_animation' => strval($command['animation'] ?? $command['id'] ?? '') . self::target($command['target'] ?? null),
            'transition' => strval($command['style'] ?? 'fade') . ' ' . strval($command['direction'] ?? 'out') . self::seconds($command, 'seconds'),
            'cinematic_music' => strval($command['track'] ?? $command['music'] ?? '') . (($command['loop'] ?? false) ? ' (loop)' : ''),
            default => '',
        };

        return trim($type . ($detail !== '' ? '  ' . $detail : ''));
    }

    /**
     * @param array<string, mixed> $command
     */
    private static function routeSummary(array $command): string
    {
        $subject = strval($command['subject'] ?? 'player');
        $who = match ($subject) {
            'npc' => 'npc ' . strval($command['npcId'] ?? '?'),
            'staged_actor' => strval($command['actorId'] ?? '?'),
            default => 'player',
        };
        $steps = 0;

        foreach (self::listOf($command['steps'] ?? null) as $step) {
            $steps += is_array($step) ? max(0, intval($step['count'] ?? 1)) : 0;
        }

        return sprintf('%s · %d step%s', $who, $steps, $steps === 1 ? '' : 's') . self::seconds($command, 'secondsPerStep', '/step');
    }

    /**
     * @param array<string, mixed> $command
     */
    private static function cameraSummary(array $command): string
    {
        $operation = strval($command['operation'] ?? '?');
        $target = is_array($command['target'] ?? null) ? $command['target'] : $command;
        $detail = match ($operation) {
            'focus', 'pan', 'track' => self::target($target),
            'route' => sprintf(' %d points', count(self::listOf($command['points'] ?? null))),
            'shake' => ' ×' . strval($command['magnitude'] ?? 1),
            default => '',
        };

        return $operation . $detail . self::seconds($command, 'seconds');
    }

    private static function target(mixed $target): string
    {
        if (! is_array($target)) {
            return '';
        }

        $kind = strval($target['kind'] ?? $target['subject'] ?? '');

        return match ($kind) {
            'position', 'screen_position' => sprintf(' @ %s, %s', strval($target['x'] ?? '?'), strval($target['y'] ?? '?')),
            '' => '',
            default => ' @ ' . $kind . (isset($target['id']) ? ' ' . strval($target['id']) : ''),
        };
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function at(array $entry): string
    {
        return isset($entry['x'], $entry['y']) ? sprintf(' @ %s, %s', strval($entry['x']), strval($entry['y'])) : '';
    }

    /**
     * @param array<string, mixed> $command
     */
    private static function seconds(array $command, string $key, string $suffix = ''): string
    {
        return isset($command[$key]) && is_numeric($command[$key]) ? sprintf(' %ss%s', rtrim(rtrim(number_format(floatval($command[$key]), 2, '.', ''), '0'), '.'), $suffix) : '';
    }

    /**
     * @param array<string, mixed> $command
     */
    private static function conditionSummary(array $command): string
    {
        $parts = [];

        foreach (self::listOf($command['conditions'] ?? null) as $condition) {
            if (is_array($condition)) {
                $parts[] = strval($condition['type'] ?? '?') . ':' . strval($condition['name'] ?? $condition['id'] ?? '');
            }
        }

        return $parts === [] ? 'always' : implode('; ', $parts);
    }

    private static function quote(string $text, string $speaker): string
    {
        $text = str_replace("\n", ' ', $text);

        if (mb_strlen($text) > 40) {
            $text = mb_substr($text, 0, 39) . '…';
        }

        return ($speaker !== '' ? $speaker . ': ' : '') . ($text !== '' ? '"' . $text . '"' : '');
    }

    /**
     * Returns every row.
     *
     * @return array<int, array{depth: int, text: string, frame: array<int, int|string>, index: int|null, key: string, kind: string, foldable: bool, children: bool}>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * Returns the rows not hidden under a folded ancestor.
     *
     * @param array<string, true> $collapsed The keys folded shut.
     * @return array<int, array{depth: int, text: string, frame: array<int, int|string>, index: int|null, key: string, kind: string, foldable: bool, children: bool}>
     */
    public function visibleRows(array $collapsed): array
    {
        $visible = [];
        $hiddenBelow = null;

        foreach ($this->rows as $row) {
            if ($hiddenBelow !== null) {
                if ($row['depth'] > $hiddenBelow) {
                    continue;
                }

                $hiddenBelow = null;
            }

            $visible[] = $row;

            if ($row['foldable'] && isset($collapsed[$row['key']])) {
                $hiddenBelow = $row['depth'];
            }
        }

        return $visible;
    }
}
