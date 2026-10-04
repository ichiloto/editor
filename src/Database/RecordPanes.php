<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\ProjectActor;
use Ichiloto\Editor\ProjectClass;
use Ichiloto\Editor\ProjectQuest;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Entities\Roles\ExperienceCurveGenerator;
use Ichiloto\Engine\Entities\Roles\ParameterCurveGenerator;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;

/**
 * The summaries shown beside a Database record: a class's experience and
 * stat curves, a skill's effects and scope, a quest's objectives and
 * rewards, the battle settings, the order battle-entry rules run in, and a
 * preview of the record. The terminal editor shows them in its side panes
 * and the GUI beside the record's rows; both read them from here, from the
 * record as it is now, unsaved edits included.
 *
 * Each category has up to three panes, keyed as the terminal lays them
 * out: `cue`, `frames` and `preview`.
 */
final class RecordPanes
{
    /**
     * The panes for one record, or none for a category without summaries.
     *
     * @return array<'cue'|'frames'|'preview', array{title: string, lines: list<string>}>
     */
    public static function describe(ProjectWorkspace $workspace, string $category, int $index): array
    {
        $data = static fn(): ?array => ($record = $workspace->getRecordDatabase($category)?->getRecordByIndex($index)) === null
            ? null
            : (array) $record->toArray();

        return match ($category) {
            'actors' => self::describeActor($workspace->actorDatabase->getActorByIndex($index)),
            'classes' => self::describeClass(($row = $data()) === null ? null : ProjectClass::fromArray($row, $index + 1)),
            'skills' => self::describeSkill($data(), $workspace->getRecordDatabase('skills')?->getRecordByIndex($index)?->recordId),
            'quests' => self::describeQuest(($row = $data()) === null ? null : new ProjectQuest($row)),
            'system' => self::describeSystem($workspace),
            'battle_entry_rules' => ['cue' => ['title' => 'Execution Order', 'lines' => self::describeBattleEntryOrder($workspace, $index)]],
            default => [],
        };
    }

    /** @return array<'cue'|'frames'|'preview', array{title: string, lines: list<string>}> */
    private static function describeActor(?ProjectActor $actor): array
    {
        if ($actor === null) {
            return self::panes('Collections', ['No actor selected.'], 'Stats', ['No actor selected.'], ['No actor selected.']);
        }

        $abilities = $actor->getAbilities();
        $magic = $actor->getMagic();
        $images = $actor->getImages();
        $battleLines = $actor->getBattleSpriteLines();

        return self::panes(
            'Collections',
            [
                'Abilities',
                sprintf('Learned: %d', count($abilities['learned'] ?? [])),
                sprintf('Learnables: %d', count($abilities['learnables'] ?? [])),
                sprintf('Sort: %s', (string) ($abilities['sortOrder'] ?? 'A-Z')),
                '',
                'Magic',
                sprintf('Learned: %d', count($magic['learned'] ?? [])),
                sprintf('Learnables: %d', count($magic['learnables'] ?? [])),
                sprintf('Sort: %s', (string) ($magic['sortOrder'] ?? 'A-Z')),
            ],
            'Stats',
            [
                sprintf('HP %d/%d', $actor->getStat('currentHp'), $actor->getStat('totalHp')),
                sprintf('MP %d/%d', $actor->getStat('currentMp'), $actor->getStat('totalMp')),
                sprintf('AP %d/%d', $actor->getStat('currentAp'), $actor->getStat('totalAp')),
                sprintf('ATK %d', $actor->getStat('attack')),
                sprintf('DEF %d', $actor->getStat('defence')),
                sprintf('MAT %d', $actor->getStat('magicAttack')),
                sprintf('MDF %d', $actor->getStat('magicDefence')),
                sprintf('SPD %d', $actor->getStat('speed')),
                sprintf('GRC %d', $actor->getStat('grace')),
                sprintf('EVA %d', $actor->getStat('evasion')),
                sprintf('ACC %d', $actor->getStat('accuracy')),
                sprintf('CRT %d', $actor->getStat('critical')),
            ],
            [
                sprintf('Actor ID: %s', $actor->id),
                sprintf('Field sprites: %d', count($images['field'] ?? [])),
                sprintf('Dialog portraits: %d', count($images['dialog'] ?? [])),
                '',
                'Battle Sprite',
                ...($battleLines !== [] ? $battleLines : ['(no battle sprite configured)']),
            ],
        );
    }

    /** @return array<'cue'|'frames'|'preview', array{title: string, lines: list<string>}> */
    private static function describeClass(?ProjectClass $class): array
    {
        if ($class === null) {
            return self::panes('Experience Curve', ['No class selected.'], 'Stat Curves', ['No class selected.'], ['No class selected.']);
        }

        $curve = $class->getExperienceCurve();
        $labels = [
            'totalHp' => 'HP', 'totalMp' => 'MP', 'attack' => 'ATK', 'defence' => 'DEF', 'magicAttack' => 'MAT',
            'magicDefence' => 'MDF', 'speed' => 'SPD', 'grace' => 'GRC', 'evasion' => 'EVA',
        ];
        $curves = [];

        foreach ($class->getParameterCurves() as $key => $parameter) {
            $curves[] = sprintf('%-3s %d +%d / %d', $labels[$key] ?? strtoupper($key), $parameter['baseValue'], $parameter['extraGrowth'], $parameter['flatIncrement']);
        }

        $experience = new ExperienceCurveGenerator(
            baseValue: $curve['baseValue'],
            extraValue: $curve['extraValue'],
            accelerationA: $curve['accelerationA'],
            accelerationB: $curve['accelerationB'],
        );
        $generator = static function (string $key) use ($class): ParameterCurveGenerator {
            $parameter = $class->getParameterCurve($key);

            return new ParameterCurveGenerator(1, $parameter['baseValue'], $parameter['extraGrowth'], $parameter['flatIncrement']);
        };
        [$hp, $mp, $attack] = [$generator('totalHp'), $generator('totalMp'), $generator('attack')];
        $samples = [];

        foreach ([1, 10, 25, 50, 99] as $level) {
            if ($level <= $class->getMaxLevel()) {
                $samples[] = sprintf('Lv%02d HP%-4d MP%-3d ATK%-3d EXP%-6d', $level, $hp->getValue($level), $mp->getValue($level), $attack->getValue($level), $experience->getValue($level));
            }
        }

        return self::panes(
            'Experience Curve',
            [
                sprintf('Base: %d', $curve['baseValue']),
                sprintf('Extra: %d', $curve['extraValue']),
                sprintf('Accel A: %d', $curve['accelerationA']),
                sprintf('Accel B: %d', $curve['accelerationB']),
                '',
                sprintf('Initial Lv: %d', $class->getInitialLevel()),
                sprintf('Max Lv: %d', $class->getMaxLevel()),
                sprintf('Traits: %d', count($class->getTraits())),
            ],
            'Stat Curves',
            $curves,
            [sprintf('Class ID: %04d', $class->id), sprintf('Name: %s', $class->getName()), '', 'Curve Samples', ...$samples],
        );
    }

    /**
     * @param array<string, mixed>|null $data The skill's record data.
     * @return array<'cue'|'frames'|'preview', array{title: string, lines: list<string>}>
     */
    private static function describeSkill(?array $data, ?string $file): array
    {
        if ($data === null) {
            return self::panes('Effects', ['No skill selected.'], 'Scope', ['No skill selected.'], ['No skill selected.']);
        }

        $scope = (array) ($data['scope'] ?? []);
        $invocation = (array) ($data['invocation'] ?? []);
        $effects = array_values(array_map(self::describeSkillEffect(...), array_filter((array) ($data['effects'] ?? []), is_array(...))));
        $effects = $effects === [] ? ['No effects configured.'] : $effects;

        return self::panes(
            'Effects',
            $effects,
            'Scope',
            [
                sprintf('Side: %s', strval($scope['side'] ?? '')),
                sprintf('Number: %s', strval($scope['number'] ?? '')),
                sprintf('Status: %s', strval($scope['status'] ?? '')),
                sprintf('Targets: %s', ($scope['targetCount'] ?? null) === null ? 'Auto' : strval($scope['targetCount'])),
                '',
                sprintf('Occasion: %s', strval($data['occasion'] ?? '')),
                sprintf('Repeat: %d', intval($invocation['repeat'] ?? 1)),
                sprintf('AP Gain: %d', intval($invocation['apGain'] ?? 0)),
            ],
            [
                sprintf('File: %s/%s.php', SkillCatalog::DIRECTORY, strval($file)),
                sprintf('Name: %s', strval($data['name'] ?? '')),
                sprintf('Kind: %s', ucfirst(strval($data['kind'] ?? ''))),
                sprintf('Occasion: %s', strval($data['occasion'] ?? '')),
                sprintf('Cost: %d MP', intval($data['cost'] ?? 0)),
                sprintf('Cooldown: %d', intval($data['cooldown'] ?? 0)),
                '',
                sprintf('Scope: %s / %s / %s', strval($scope['side'] ?? ''), strval($scope['number'] ?? ''), strval($scope['status'] ?? '')),
                sprintf('Invoke: %s', strval($invocation['message'] ?? '')),
                '',
                'Effects',
                ...$effects,
            ],
        );
    }

    /**
     * One effect of a skill record, as the Effects pane lists it.
     *
     * @param array<string, mixed> $effect The effect's record data.
     */
    private static function describeSkillEffect(array $effect): string
    {
        $parts = match (true) {
            isset($effect['formula']) => [strval($effect['formula'])],
            isset($effect['stateId']) => [sprintf('%s, %d%%', strval($effect['stateId']), intval($effect['chancePercent'] ?? 100))],
            isset($effect['stateIds']) && is_array($effect['stateIds']) => [implode(', ', array_map('strval', $effect['stateIds']))],
            isset($effect['stat']) => [sprintf('%s %+d%s', strval($effect['stat']), intval($effect['delta'] ?? 0), ($effect['affectsUser'] ?? false) === true ? ' on the user' : '')],
            default => [],
        };

        foreach (['element', 'resolutionKind'] as $key) {
            if (isset($effect[$key])) {
                $parts[] = strval($effect[$key]);
            }
        }

        if (isset($effect['variance'])) {
            $parts[] = sprintf('±%d%%', (int) round(floatval($effect['variance']) * 100));
        }

        return sprintf('%s: %s', ucfirst(str_replace('_', ' ', strval($effect['type'] ?? 'effect'))), implode(' · ', $parts));
    }

    /** @return array<'cue'|'frames'|'preview', array{title: string, lines: list<string>}> */
    private static function describeQuest(?ProjectQuest $quest): array
    {
        if ($quest === null) {
            return self::panes('Objectives', ['No quest selected.'], 'Rewards', ['No quest selected.'], ['No quest selected.']);
        }

        return self::panes(
            'Objectives',
            $quest->getObjectiveSummaryLines(),
            'Rewards',
            [
                sprintf('Gold: %d', $quest->getRewardGold()),
                sprintf('EXP: %d', $quest->getRewardExperience()),
                sprintf('Items: %s', $quest->getRewardItemsString() === '' ? '-' : $quest->getRewardItemsString()),
                '',
                'Prereqs',
                ...$quest->getPrerequisiteSummaryLines(),
            ],
            [
                sprintf('Quest ID: %s', $quest->getId()),
                sprintf('Name: %s', $quest->getName()),
                sprintf('Giver: %s', $quest->getGiver() === '' ? '-' : $quest->getGiver()),
                sprintf('Objectives: %d', count($quest->getObjectives())),
                sprintf('Prereqs: %d', count($quest->getPrerequisites())),
                '',
                'Description',
                $quest->getDescription() === '' ? '(none)' : $quest->getDescription(),
                '',
                'Objectives',
                ...$quest->getObjectiveSummaryLines(),
            ],
        );
    }

    /** @return array<'cue'|'frames'|'preview', array{title: string, lines: list<string>}> */
    private static function describeSystem(ProjectWorkspace $workspace): array
    {
        // The battle settings as the system record holds them, with the
        // defaults the engine reads where it holds none.
        $battle = $workspace->getSystemField('battle');
        $battle = is_array($battle) ? $battle : [];
        $activeTime = is_array($battle['activeTime'] ?? null) ? $battle['activeTime'] : [];
        $engine = strval($battle['engine'] ?? 'traditional');
        $mode = strval($activeTime['mode'] ?? 'wait');
        $fillRate = max(1, intval($activeTime['baseFillRate'] ?? 35));
        $speedFactor = max(0, intval($activeTime['speedFactorPercent'] ?? 35));
        $activeTimeOn = $engine === 'active_time';

        return self::panes(
            'Battle Settings',
            [
                sprintf('Engine: %s', $engine),
                sprintf('ATB Mode: %s', $mode),
                sprintf('Base Fill Rate: %d', $fillRate),
                sprintf('Speed Factor: %d%%', $speedFactor),
            ],
            'Notes',
            $activeTimeOn
                ? ['Active Time Battle is enabled.', 'Mode: wait', 'This first slice uses wait-mode flow', 'during command selection and resolution.']
                : ['Traditional turn-based battles.', 'ATB settings are stored but inactive.', 'Switch Battle Engine to active_time', 'to enable gauge-driven turns.'],
            $activeTimeOn
                ? [
                    'Battle Engine', 'Active Time Battle', '',
                    sprintf('Mode: %s', $mode), sprintf('Base Fill Rate: %d', $fillRate), sprintf('Speed Factor: %d%%', $speedFactor), '',
                    'This engine fills battler gauges', 'continuously and resolves actions', 'as battlers become ready.',
                ]
                : [
                    'Battle Engine', 'Traditional Turn-Based', '',
                    'Battlers act in a queued round order.', 'ATB settings are ignored until you', 'switch the project to active_time.',
                ],
        );
    }

    /**
     * The order matching battle-entry rules run in: priority, then
     * declaration order, the given rule marked.
     *
     * @return list<string>
     */
    private static function describeBattleEntryOrder(ProjectWorkspace $workspace, int $selected): array
    {
        $rules = [];

        foreach ($workspace->getRecordDatabase('battle_entry_rules')?->getRecords() ?? [] as $index => $record) {
            $priority = $record->get('priority');
            $rules[] = [
                'index' => $index,
                'priority' => is_int($priority) ? $priority : 0,
                'id' => trim(strval($record->get('id') ?? '')) ?: '(no id)',
            ];
        }

        if ($rules === []) {
            return ['No rules yet.', '', 'Battles begin unchanged.'];
        }

        usort($rules, static fn(array $left, array $right): int => [$left['priority'], $left['index']] <=> [$right['priority'], $right['index']]);
        $lines = ['Runs in this order:'];

        foreach ($rules as $position => $rule) {
            $lines[] = sprintf(
                '%s%2d. %s%s',
                $rule['index'] === $selected ? '> ' : '  ',
                $position + 1,
                $rule['id'],
                $rule['priority'] !== 0 ? sprintf('  (p %d)', $rule['priority']) : '',
            );
        }

        return $lines;
    }

    /**
     * @param list<string> $cue
     * @param list<string> $frames
     * @param list<string> $preview
     * @return array<'cue'|'frames'|'preview', array{title: string, lines: list<string>}>
     */
    private static function panes(string $cueTitle, array $cue, string $framesTitle, array $frames, array $preview): array
    {
        return [
            'cue' => ['title' => $cueTitle, 'lines' => array_values($cue)],
            'frames' => ['title' => $framesTitle, 'lines' => array_values($frames)],
            'preview' => ['title' => 'Preview', 'lines' => array_values($preview)],
        ];
    }
}
