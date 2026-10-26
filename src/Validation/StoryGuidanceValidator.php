<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Validation;

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Quests\Quest;
use Ichiloto\Engine\Quests\Story\StoryContentReader;
use Ichiloto\Engine\Quests\Story\StoryGate;
use Ichiloto\Engine\Quests\Story\StoryGuidance;
use Ichiloto\Engine\Quests\Story\StoryIndex;
use Ichiloto\Engine\Quests\Story\StorySource;
use Throwable;

/**
 * Reports gaps in the wayfinder's guidance along the story: a gate the
 * player must open to go on, or what starts a main quest, which no authored
 * waymark leads to.
 *
 * The analysis is the Engine's ({@see StoryGuidance} over a
 * {@see StoryIndex}), the same reading of the story the runtime wayfinder
 * uses to lead the player through such a gap. This check reads the project
 * as the author has it, unsaved maps, event scripts, cinematics and troops
 * included, and reports each gap where the author fixes it: at the quest
 * or the door, on the map where the step is taken. Flags set in battles
 * count: a waymark on the event, or the map, where the battle that sets a
 * flag is fought leads to that flag.
 */
final class StoryGuidanceValidator
{
    private const string HINT = 'Track the step with an objective of the quest that is active then, with a waymark on '
        . 'where the player opens it, or lead there with a start waymark of the quest that can start only once it is open, '
        . 'so the wayfinder keeps leading.';
    private const string START_HINT = 'Give the quest a start waymark on where it starts, or lead there with an objective '
        . 'of the main quest active before it, so the wayfinder keeps leading.';

    /** @return Issue[] */
    public function validate(ProjectWorkspace $workspace): array
    {
        $quests = [];
        foreach ($workspace->getQuests() as $quest) {
            try {
                $quests[] = Quest::fromArray($quest->toArray());
            } catch (Throwable) {
                // A malformed quest is reported by the quest checks; it guides nothing here.
            }
        }
        $troops = $workspace->getRecordDatabase('troops');
        $reader = StoryContentReader::fromAssetRoot($workspace->projectRoot . '/assets', $workspace->readCurrentData(...),
            $troops instanceof ProjectRecordDatabase
                ? array_map(static fn($record): array => (array) $record->toArray(), $troops->getRecords()) : null);
        $maps = [];
        foreach ($workspace->maps as $map) {
            $maps[$map->mapId] = $map->getEditableData();
        }
        $index = StoryIndex::read($reader, $maps);

        return [
            ...array_map(fn(StoryGate $gate): Issue => $this->describeGap($gate, $index), StoryGuidance::findUnguidedGates($quests, $index)),
            ...array_map(fn(Quest $quest): Issue => $this->describeStartGap($quest, $index), StoryGuidance::findUnguidedStarts($quests, $index)),
        ];
    }

    private function describeStartGap(Quest $quest, StoryIndex $index): Issue
    {
        $starters = $index->findStarters($quest->id);
        $message = $starters === []
            ? sprintf('Nothing in the project starts the quest "%s": no event, NPC, event script, cinematic or battle, '
                . 'so the wayfinder cannot lead to it.', $quest->name)
            : sprintf('The quest "%s" starts at %s, but no objective of a main quest active before it, and no start '
                . 'waymark, leads there, so the wayfinder shows nothing until it starts.', $quest->name,
                implode(', ', array_map(static fn(StorySource $source): string => $source->describe(), $starters)));

        return new Issue(Severity::WARNING, sprintf('quest %s', $quest->id), $message, self::START_HINT, map: $starters[0]->map ?? null);
    }

    private function describeGap(StoryGate $gate, StoryIndex $index): Issue
    {
        $what = $gate->quest !== null ? sprintf('The quest "%s"', $gate->quest->name) : sprintf('The way through event %s', $gate->marker);
        $message = $index->findSetters($gate->flag) === []
            ? sprintf('%s needs "%s", but nothing in the project sets it: no event, NPC, event script, cinematic or battle, '
                . 'so the wayfinder cannot lead to it.', $what, $gate->flag)
            : sprintf('%s needs "%s", but no objective or start waymark of a main quest leads the player to it, so the wayfinder shows nothing until it is set.',
                $what, $gate->flag);

        return new Issue(Severity::WARNING, $gate->quest !== null ? sprintf('quest %s', $gate->quest->id)
            : sprintf('%s event %s', $gate->map, $gate->marker), $message, self::HINT, map: $gate->map);
    }
}
