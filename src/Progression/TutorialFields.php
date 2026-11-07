<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Progression;

use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\Database\RecordFieldCodec;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordStorage;
use Ichiloto\Editor\Database\RecordSubList;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Engine\Progression\TutorialCatalog;
use Ichiloto\Engine\Progression\TutorialReplayMode;
use Ichiloto\Engine\Progression\TutorialScreenCatalog;
use Ichiloto\Engine\Progression\TutorialStartMode;
use InvalidArgumentException;

/**
 * Database > Tutorials: the project's guided tutorials, the list
 * `assets/Data/tutorials.php` returns and the Engine's {@see TutorialCatalog}
 * reads. A tutorial has a stable id the save keeps its phase under, a library
 * category and title, when it starts (from a script, or automatically once its
 * trigger first holds in play), what the Guide offers later, and its ordered
 * steps. A step names the real screen, view and target the player acts on, the
 * semantic action whose control is shown, and what completes it: an outcome the
 * owning screen reports (with an optional subject), or one world condition. Its
 * optional line is one short sentence, spoken by an optional speaker.
 *
 * The Engine's own catalogue judges every save, so a step or tutorial the
 * runtime could not read is refused before a byte is written.
 *
 * @package Ichiloto\Editor\Progression
 */
final class TutorialFields
{
    /** The Database category. */
    public const string CATEGORY = 'tutorials';

    /** The sub-list of a tutorial's ordered steps. */
    public const string STEPS = 'steps';

    private const string OUTCOME = 'until.outcome';
    private const string SUBJECT = 'until.subject';
    private const string CONDITION = 'until';

    private function __construct()
    {
    }

    public static function getSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::CATEGORY,
            entryNoun: 'tutorial',
            storage: RecordStorage::LIST_FILE,
            relativePath: 'assets/' . TutorialCatalog::FILE,
            fields: [
                // Saves keep each tutorial's phase under this id, so it is chosen, not derived from the title.
                new RecordField('id', 'Id'),
                new RecordField('title', 'Title'),
                new RecordField('category', 'Category'),
                new RecordField('start', 'Starts', options: array_column(TutorialStartMode::cases(), 'value')),
                // Every condition must hold; an automatic tutorial needs at least one.
                new RecordField('trigger', 'Trigger', removeWhenEmpty: true, codec: RecordFieldCodec::CONDITIONS),
                new RecordField('replay', 'Guide Offers', options: array_column(TutorialReplayMode::cases(), 'value')),
                new RecordField('demo', 'Demonstration', removeWhenEmpty: true, displayDefault: '(none)'),
                new RecordField('onFinish', 'Follow-On Script', removeWhenEmpty: true, displayDefault: '(none)'),
            ],
            labelKey: 'title',
            identityKey: 'id',
            // A new tutorial starts from a script and offers nothing in the Guide until its author says so. Its
            // one step is the first fixed target the Engine declares, grouped under that step's screen until the
            // author chooses where it belongs.
            makeBlank: static function (string $name): array {
                $step = self::createBlankStep();

                return ['id' => 'new-tutorial', 'title' => $name, 'category' => $step['target']['screen'],
                    'start' => TutorialStartMode::SCRIPT->value, 'steps' => [$step], 'replay' => TutorialReplayMode::NONE->value];
            },
            saveCheck: self::checkSource(...),
            subLists: [
                new RecordSubList(
                    key: self::STEPS,
                    prefix: 'step',
                    singular: 'step',
                    fields: self::getStepFields([]),
                    blank: self::createBlankStep(),
                    heading: 'Steps',
                    prepareEdit: self::prepareStepEdit(...),
                    getFields: self::getStepFields(...),
                ),
            ],
        );
    }

    /**
     * Why the Engine could not read the tutorials as a save would write them, or null when it can.
     *
     * @param array<array-key, mixed> $whole Every tutorial, as it would be saved.
     */
    public static function checkSource(array $whole, string $projectRoot = ''): ?string
    {
        try {
            new TutorialCatalog(array_values($whole));
        } catch (InvalidArgumentException $error) {
            return $error->getMessage();
        }

        return null;
    }

    /**
     * A step's rows. Its completion is an outcome or one world condition: both rows show, so either can be
     * chosen, and a subject belongs only to an outcome.
     *
     * @param array<string, mixed> $step
     * @return list<RecordField>
     */
    public static function getStepFields(array $step): array
    {
        $until = $step['until'] ?? null;
        $byCondition = is_array($until) && ! array_key_exists('outcome', $until) && $until !== [];

        return [
            new RecordField('target.screen', 'Screen'),
            new RecordField('target.view', 'View'),
            new RecordField('target.id', 'Target'),
            RecordField::reference('action', 'Action', ReferenceCatalog::INPUT_ACTIONS),
            new RecordField(self::OUTCOME, 'Completed By Outcome', removeWhenEmpty: true,
                displayDefault: $byCondition ? '(a world condition)' : null),
            ...($byCondition ? [] : [new RecordField(self::SUBJECT, 'Outcome Subject', removeWhenEmpty: true, displayDefault: '(any)')]),
            new RecordField(self::CONDITION, 'Completed By Condition', codec: RecordFieldCodec::WORLD_CONDITION),
            new RecordField('say', 'Line', removeWhenEmpty: true, displayDefault: '(no line)'),
            new RecordField('speaker', 'Speaker', removeWhenEmpty: true, displayDefault: '(no speaker)'),
            new RecordField('focus', 'Move Focus To Target', InputControlType::BOOLEAN, removeWhenEmpty: true),
        ];
    }

    /**
     * Keeps a step's completion one thing: naming an outcome drops a world condition, and choosing a world
     * condition drops the outcome and its subject.
     *
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    public static function prepareStepEdit(array $step, string $field): array
    {
        $until = is_array($step['until'] ?? null) ? $step['until'] : [];
        if ($field === self::OUTCOME || $field === self::SUBJECT) {
            $step['until'] = array_intersect_key($until, ['outcome' => true, 'subject' => true]);
        }

        return $step;
    }

    /**
     * The order a tutorial's steps run in, one line each: where the player acts, with which action, what
     * completes the step and the line said there.
     *
     * @param array<string, mixed>|null $tutorial
     * @return list<string>
     */
    public static function describeStepOrder(?array $tutorial): array
    {
        $steps = is_array($tutorial['steps'] ?? null) ? array_values($tutorial['steps']) : [];
        if ($steps === []) {
            return ['No steps yet.'];
        }

        $text = static fn(mixed $value, string $missing): string => is_string($value) && trim($value) !== '' ? trim($value) : $missing;
        $lines = [];
        foreach ($steps as $position => $step) {
            $step = is_array($step) ? $step : [];
            $target = is_array($step['target'] ?? null) ? $step['target'] : [];
            $until = is_array($step['until'] ?? null) ? $step['until'] : [];
            $completion = array_key_exists('outcome', $until)
                ? 'until ' . $text($until['outcome'], '(no outcome)') . (isset($until['subject']) ? ' (' . $text($until['subject'], '') . ')' : '')
                : 'until ' . ($until === [] ? '(no completion)' : \Ichiloto\Editor\Database\ConditionCodec::encode($until));
            $say = is_string($step['say'] ?? null) && $step['say'] !== ''
                ? sprintf(' · %s"%s"', is_string($step['speaker'] ?? null) ? $step['speaker'] . ': ' : '', $step['say']) : '';
            $lines[] = sprintf('%d. %s › %s › %s · %s · %s%s', $position + 1, $text($target['screen'] ?? null, '?'),
                $text($target['view'] ?? null, '?'), $text($target['id'] ?? null, '?'), $text($step['action'] ?? null, '(no action)'),
                $completion, $say);
        }

        return $lines;
    }

    /**
     * A new step: the first fixed target the Engine declares for teaching, its view's first action, and the first
     * outcome that view reports, naming that target when the outcome's subjects are the view's own targets.
     *
     * @return array{target: array{screen: string, view: string, id: string}, action: string, until: array<string, string>}
     */
    private static function createBlankStep(): array
    {
        foreach (TutorialScreenCatalog::getViews() as $screen => $views) {
            foreach ($views as $view => $declared) {
                $target = $declared['targets'][0] ?? null;
                $action = $declared['actions'][0] ?? null;
                $outcome = array_key_first($declared['outcomes']);
                if ($target === null || $action === null || $outcome === null) {
                    continue;
                }
                $subjects = $declared['outcomes'][$outcome];

                return ['target' => ['screen' => $screen, 'view' => $view, 'id' => $target], 'action' => $action,
                    'until' => ['outcome' => $outcome, ...(is_array($subjects) && in_array($target, $subjects, true) ? ['subject' => $target] : [])]];
            }
        }

        throw new RecordRefusal('The Engine declares no screen a tutorial can point at yet.');
    }
}
