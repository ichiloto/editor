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
use Ichiloto\Engine\Progression\TutorialCatalog;
use Ichiloto\Engine\Progression\TutorialInstructionPlacement;
use Ichiloto\Engine\Progression\TutorialProjectReferences;
use Ichiloto\Engine\Progression\TutorialReferenceSource;
use Ichiloto\Engine\Progression\TutorialReplayMode;
use Ichiloto\Engine\Progression\TutorialScreenCatalog;
use Ichiloto\Engine\Progression\TutorialStartContext;
use Ichiloto\Engine\Progression\TutorialStartMode;
use Ichiloto\Engine\Progression\TutorialStep;
use InvalidArgumentException;

/**
 * Database > Tutorials: the project's guided tutorials, the list
 * `assets/Data/tutorials.php` returns and the Engine's {@see TutorialCatalog}
 * reads. A tutorial has a stable id the save keeps its phase under, a library
 * category and title, an optional summary, when it starts (from a script, or automatically once its
 * trigger first holds in play), where an automatic one starts (in the field,
 * the default a record may leave unwritten, or on the screen that owns its
 * lesson: the Summons library, or a battle's command window, which starts its
 * one step when the trigger holds there), what the Guide offers later, and its
 * ordered steps. A step names the real screen, view and target the player acts on, the
 * semantic action whose control is shown, and what completes it: an outcome the
 * owning screen reports (with an optional subject), or one world condition. Its
 * optional line is one short sentence, spoken by an optional speaker.
 *
 * Screens, views, targets, actions, outcomes and their subjects are picked from
 * what the Engine's {@see TutorialScreenCatalog} declares, the targets and
 * subjects a view draws from the project resolved by the Engine's
 * {@see TutorialProjectReferences}. The Engine's own catalogue and that
 * resolver's validation judge every save, so a step the runtime could not read
 * or match is refused before a byte is written.
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

    /** The entry-choice sources a step's pickers read ({@see self::listChoices()}). */
    public const string SCREENS = 'tutorial_screens';
    public const string VIEWS = 'tutorial_views';
    public const string TARGETS = 'tutorial_targets';
    public const string ACTIONS = 'tutorial_actions';
    public const string OUTCOMES = 'tutorial_outcomes';
    public const string SUBJECTS = 'tutorial_subjects';
    public const string SPEAKERS = 'tutorial_speakers';

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
                // Optional text, trimmed and never empty, as the Engine's TutorialDefinition reads it: left out, it writes no key; cleared, it is removed.
                new RecordField('summary', 'Summary', removeWhenEmpty: true),
                new RecordField('start', 'Starts', options: array_column(TutorialStartMode::cases(), 'value')),
                // Absent means the field. Another context belongs to an automatic start, and its first step to the
                // screen and view that context owns: the Engine's catalogue and references judge both on save.
                new RecordField('startContext', 'Starts In', options: ['', ...array_values(array_diff(
                    array_column(TutorialStartContext::cases(), 'value'), [TutorialStartContext::FIELD->value]))],
                    removeWhenEmpty: true, displayDefault: TutorialStartContext::FIELD->value),
                // Every condition must hold; an automatic tutorial needs at least one, unless a battle starts it.
                new RecordField('trigger', 'Trigger', removeWhenEmpty: true, codec: RecordFieldCodec::CONDITIONS),
                new RecordField('replay', 'Guide Offers', options: array_column(TutorialReplayMode::cases(), 'value')),
                RecordField::reference('demo', 'Demonstration', 'stage_timelines', allowsNone: true, noneLabel: '(none)'),
                // Played once the tutorial is completed or skipped.
                RecordField::reference('onFinish', 'Follow-On Cinematic', 'cinematics', allowsNone: true, noneLabel: '(none)'),
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
            $catalog = new TutorialCatalog(array_values($whole));
            if ($projectRoot !== '') {
                new TutorialProjectReferences(rtrim($projectRoot, '/') . '/assets')->validateCatalog($catalog);
            }
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
            new RecordField('target.screen', 'Screen', entryChoices: self::SCREENS),
            new RecordField('target.view', 'View', entryChoices: self::VIEWS),
            new RecordField('target.id', 'Target', entryChoices: self::TARGETS),
            new RecordField('action', 'Action', entryChoices: self::ACTIONS),
            new RecordField(self::OUTCOME, 'Completed By Outcome', options: [''], removeWhenEmpty: true,
                displayDefault: $byCondition ? '(a world condition)' : null, entryChoices: self::OUTCOMES),
            ...($byCondition ? [] : [RecordField::entryChoice(self::SUBJECT, 'Outcome Subject', self::SUBJECTS, '(any)')]),
            new RecordField(self::CONDITION, 'Completed By Condition', codec: RecordFieldCodec::WORLD_CONDITION),
            // One sentence on one line, held to the Engine's budget.
            new RecordField('say', sprintf('Line (one sentence, up to %d cells)', TutorialStep::MAX_INSTRUCTION_CELLS),
                removeWhenEmpty: true, displayDefault: '(no line)'),
            RecordField::entryChoice('speaker', 'Speaker', self::SPEAKERS, '(no speaker)'),
            new RecordField('placement', 'Instruction Placement',
                options: ['', ...array_column(TutorialInstructionPlacement::cases(), 'value')],
                removeWhenEmpty: true, displayDefault: TutorialInstructionPlacement::CENTERED->value),
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
        if ($field === 'target.screen' || $field === 'target.view') {
            $step = self::keepStepOnDeclaredView($step);
        }

        return $step;
    }

    /**
     * After its screen or view changes, a step keeps only what that view declares: the screen's first view when
     * its own is not there, its first fixed target, action and outcome where the step's are not, and no subject
     * the new outcome cannot name. A target a view draws from the project is left for the author to pick.
     *
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    private static function keepStepOnDeclaredView(array $step): array
    {
        $views = TutorialScreenCatalog::getViews()[$step['target']['screen'] ?? ''] ?? [];
        if ($views === []) {
            return $step;
        }
        if (! isset($views[$step['target']['view'] ?? ''])) {
            $step['target']['view'] = array_key_first($views);
        }
        $declared = $views[$step['target']['view']];
        if (! in_array($step['target']['id'] ?? null, $declared['targets'], true)) {
            $step['target']['id'] = $declared['targets'][0] ?? '';
        }
        if (! in_array($step['action'] ?? null, $declared['actions'], true)) {
            $step['action'] = $declared['actions'][0] ?? '';
        }
        $until = is_array($step['until'] ?? null) ? $step['until'] : [];
        if (array_key_exists('outcome', $until) && ! isset($declared['outcomes'][$until['outcome']])) {
            $step['until'] = ['outcome' => (string) array_key_first($declared['outcomes'])];
        }

        return $step;
    }

    /**
     * What a step's picker offers, from the Engine's declarations for the step's own screen and view and the
     * project identities the Engine's resolver lists.
     *
     * @param array<string, mixed> $step
     * @return list<string>
     */
    public static function listChoices(string $source, array $step, TutorialProjectReferences $references): array
    {
        $views = TutorialScreenCatalog::getViews();
        $screen = (string) ($step['target']['screen'] ?? '');
        $view = (string) ($step['target']['view'] ?? '');
        $declared = $views[$screen][$view] ?? null;
        $subjects = $declared['outcomes'][$step['until']['outcome'] ?? ''] ?? [];

        return array_values(array_map(strval(...), match ($source) {
            self::SCREENS => array_keys($views),
            self::VIEWS => array_keys($views[$screen] ?? []),
            self::TARGETS => $references->getTargetIds($screen, $view),
            self::ACTIONS => $declared['actions'] ?? [],
            self::OUTCOMES => array_keys($declared['outcomes'] ?? []),
            self::SUBJECTS => is_string($subjects) ? $references->getIds($subjects) : $subjects,
            self::SPEAKERS => $references->getIds(TutorialReferenceSource::ACTOR),
            default => [],
        }));
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
