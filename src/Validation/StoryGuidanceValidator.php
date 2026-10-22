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
use Throwable;

/**
 * Reports gaps in the wayfinder's guidance along the story: a gate the
 * player must open to go on, which no tracked objective leads to.
 *
 * The analysis is the Engine's ({@see StoryGuidance} over a
 * {@see StoryIndex}), the same reading of the story the runtime wayfinder
 * uses to lead the player through such a gap. This check reads the project
 * as the author has it, unsaved maps, event scripts, cinematics and troops
 * included, and reports each gap where the author fixes it: at the quest
 * or the door. Flags set in battles count: a waymark on the event, or the
 * map, where the battle that sets a flag is fought leads to that flag.
 */
final class StoryGuidanceValidator
{
    private const string HINT = 'Track the step with an objective of the quest that is active then, with a waymark on '
        . 'where the player opens it, so the wayfinder keeps leading.';

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

        return array_map(fn(StoryGate $gate): Issue => $this->describeGap($gate, $index), StoryGuidance::findUnguidedGates($quests, $index));
    }

    private function describeGap(StoryGate $gate, StoryIndex $index): Issue
    {
        $what = $gate->quest !== null ? sprintf('The quest "%s"', $gate->quest->name) : sprintf('The way through event %s', $gate->marker);
        $message = $index->findSetters($gate->flag) === []
            ? sprintf('%s needs "%s", but nothing in the project sets it: no event, NPC, event script, cinematic or battle, '
                . 'so the wayfinder cannot lead to it.', $what, $gate->flag)
            : sprintf('%s needs "%s", but no objective of a main quest leads the player to it, so the wayfinder shows nothing until it is set.',
                $what, $gate->flag);

        return new Issue(Severity::WARNING, $gate->quest !== null ? sprintf('quest %s', $gate->quest->id)
            : sprintf('%s event %s', $gate->map, $gate->marker), $message, self::HINT, map: $gate->map);
    }
}
