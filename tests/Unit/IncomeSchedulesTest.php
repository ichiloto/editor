<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\RecordAuthoring;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Engine\Income\IncomeCatalog;

function incomeFilePath(string $root): string
{
    return $root . '/assets/' . IncomeCatalog::FILE;
}

/** @return list<string> The validator's findings about income, in report order. */
function incomeIssueMessages(string $root): array
{
    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));

    return array_values(array_map(
        static fn(Issue $issue): string => $issue->message,
        array_filter($issues, static fn(Issue $issue): bool => $issue->where === 'assets/' . IncomeCatalog::FILE
            || str_contains($issue->message, 'income schedule')),
    ));
}

function writeIncomeScript(string $root, string $scriptId, array $commands): void
{
    file_put_contents($root . '/assets/Events/' . $scriptId . '.php', '<?php return ' . var_export($commands, true) . ';');
}

it('writes nothing while a project without income is browsed and validated', function (): void {
    $root = makeTemporaryProject();
    $before = sourceHashTree($root . '/assets/Data');

    $editor = deletionEditor($root);
    openDatabaseCategory($editor, 'income_schedules');
    callEditorMethod($editor, 'getDatabaseSettingsFields');
    callEditorMethod($editor, 'saveActiveDatabase');

    expect(incomeIssueMessages($root))->toBe([])
        ->and(is_file(incomeFilePath($root)))->toBeFalse()
        ->and(sourceHashTree($root . '/assets/Data'))->toBe($before);
});

it('creates the file the Engine reads on the first saved schedule, its id following its name', function (): void {
    $root = makeTemporaryProject();

    $database = loadRecordDatabase($root, 'income_schedules');
    $index = $database->addRecord();
    $database->setField($index, 'name', 'Guild Stipend');
    $database->setField($index, 'conditions', 'event:joined_guild');
    $database->setField($index, 'cadence', 'steps');
    $database->setField($index, 'interval', '50');
    $database->setField($index, 'amount', '30');
    $database->setField($index, 'delivery', 'hold');
    $database->save();

    $catalog = IncomeCatalog::fromProject(incomeFilePath($root));
    $schedule = $catalog->findSchedule('guild-stipend');

    expect(array_keys($catalog->getSchedules()))->toBe(['guild-stipend'])
        ->and($schedule?->name)->toBe('Guild Stipend')
        ->and($schedule?->cadence->value)->toBe('steps')
        ->and($schedule?->interval)->toBe(50)
        ->and($schedule?->amount)->toBe(30)
        ->and($schedule?->delivery->value)->toBe('hold')
        ->and($schedule?->conditions)->toBe([['type' => 'event', 'name' => 'joined_guild']])
        ->and(incomeIssueMessages($root))->toBe([]);
});

it('authors an ordered pay table the Engine applies first match first', function (): void {
    $root = makeTemporaryProject();

    $database = loadRecordDatabase($root, 'income_schedules');
    $index = $database->addRecord();
    $database->setField($index, 'name', 'Wage');
    $database->setField($index, 'amount', '100');
    foreach ([['variable:rank:==:A', '900'], ['variable:rank:==:B', '400']] as [$when, $pay]) {
        $rate = $database->addSubItem($index);
        $database->setField($index, sprintf('rate%dConditions', $rate), $when);
        $database->setField($index, sprintf('rate%dAmount', $rate), $pay);
    }
    $database->save();

    $rates = IncomeCatalog::fromProject(incomeFilePath($root))->findSchedule('wage')?->rates ?? [];

    expect(array_map(static fn($rate): int => $rate->amount, $rates))->toBe([900, 400])
        ->and($rates[0]->conditions)->toBe([['type' => 'variable', 'name' => 'rank', 'op' => '==', 'value' => 'A']]);
});

it('reads absent optional fields as the Engine defaults without writing them', function (): void {
    $root = makeTemporaryProject();

    $database = loadRecordDatabase($root, 'income_schedules');
    $index = $database->addRecord();
    $database->setField($index, 'name', 'Wage');
    $database->save();

    $payload = require incomeFilePath($root);
    $byField = array_column($database->getSettingsFields(0), 'value', 'field');

    expect(array_key_exists('delivery', $payload['schedules'][0]))->toBeFalse()
        ->and(array_key_exists('idleTimeout', $payload['schedules'][0]))->toBeFalse()
        ->and($byField['delivery'])->toBe('deposit')
        ->and($byField['idleTimeout'])->toBe('120');
});

it('keeps a hand-authored file and its comments when one value changes', function (): void {
    $root = makeTemporaryProject();
    file_put_contents(incomeFilePath($root), <<<'PHP'
<?php

// The guild pays its members by the hour.
return [
  'schedules' => [
    [
      'id' => 'stipend',
      'name' => 'Stipend',
      'cadence' => 'active_time',
      'interval' => 3600, // one hour of play
      'amount' => 250,
    ],
  ],
];
PHP);

    $database = loadRecordDatabase($root, 'income_schedules');
    $database->setField(0, 'amount', '300');
    $database->save();
    $source = (string) file_get_contents(incomeFilePath($root));

    expect($source)->toContain('// The guild pays its members by the hour.')
        ->and($source)->toContain('// one hour of play')
        ->and(IncomeCatalog::fromProject(incomeFilePath($root))->findSchedule('stipend')?->amount)->toBe(300);
});

it('reports schedules the game would refuse, each one by name', function (): void {
    $root = makeTemporaryProject();
    file_put_contents(incomeFilePath($root), '<?php return ' . var_export(['schedules' => [
        ['id' => 'walk', 'name' => 'Walk Pay', 'cadence' => 'steps', 'amount' => 5],
        ['id' => 'calendar', 'name' => 'Calendar', 'cadence' => 'event', 'interval' => 7, 'amount' => 5],
        ['id' => 'fine', 'name' => 'Fine', 'cadence' => 'battles', 'interval' => 2, 'amount' => 5],
    ]], true) . ';');

    expect(incomeIssueMessages($root))->toBe([
        'The game cannot read an income schedule: Schedule [0] (walk) interval must be a positive whole number of steps.',
        'The game cannot read an income schedule: Schedule [1] (calendar) interval does not apply to the event cadence; payday pays it.',
    ]);
});

it('offers schedules to the income commands by name and refuses one that does not exist', function (): void {
    $root = makeTemporaryProject();
    file_put_contents(incomeFilePath($root), '<?php return ' . var_export(['schedules' => [
        ['id' => 'stipend', 'name' => 'Guild Stipend', 'cadence' => 'event', 'amount' => 5],
    ]], true) . ';');
    writeIncomeScript($root, 'pay-desk', [
        ['type' => 'collect_income', 'schedule' => 'stipend', 'resultVariable' => 'collected'],
        ['type' => 'payday', 'schedule' => 'bonus'],
    ]);

    $workspace = ProjectWorkspace::fromProject($root);
    $references = new ReferenceCatalog($workspace);
    $variants = RecordSchemaCatalog::getRegisteredCommandVariants();
    $schedule = array_find($variants['payday'] ?? [], static fn($field): bool => $field->key === 'schedule');
    $messages = array_map(static fn(Issue $issue): string => $issue->message, new ProjectValidator()->validate($workspace));

    expect($references->valuesFor('income_schedules'))->toBe(['stipend'])
        ->and($references->labelsFor('income_schedules'))->toBe(['stipend' => 'Guild Stipend (stipend)'])
        ->and($schedule?->reference)->toBe('income_schedules')
        ->and(array_filter($messages, static fn(string $message): bool => str_contains($message, '"bonus"')))->not->toBe([])
        ->and(array_filter($messages, static fn(string $message): bool => str_contains($message, '"stipend"')))->toBe([]);
});

it('keeps an id a script names, refusing to rename it with the name or delete it', function (): void {
    $root = makeTemporaryProject();
    file_put_contents(incomeFilePath($root), '<?php return ' . var_export(['schedules' => [
        ['id' => 'stipend', 'name' => 'Stipend', 'cadence' => 'event', 'amount' => 5],
        ['id' => 'unused', 'name' => 'Unused', 'cadence' => 'event', 'amount' => 5],
    ]], true) . ';');
    writeIncomeScript($root, 'pay-desk', [['type' => 'adjust_income', 'schedule' => 'stipend', 'amount' => -5]]);

    $workspace = ProjectWorkspace::fromProject($root);
    $database = $workspace->getRecordDatabase('income_schedules');
    $database?->setField(0, 'name', 'Guild Stipend');
    $database?->setField(1, 'name', 'Spare Pay');

    expect($database?->getRecordByIndex(0)?->get('id'))->toBe('stipend')
        ->and($database?->getRecordByIndex(1)?->get('id'))->toBe('spare-pay')
        ->and($database?->describeDeletionReferences(0))->toBe(['event script pay-desk'])
        ->and($database?->describeDeletionReferences(1))->toBe([]);
});

it('offers only the fields a cadence uses and drops the rest when the cadence changes', function (): void {
    $root = makeTemporaryProject();

    $database = loadRecordDatabase($root, 'income_schedules');
    $index = $database->addRecord();
    $database->setField($index, 'name', 'Wage');
    $database->setField($index, 'idleTimeout', '45');
    $offered = static fn(): array => array_column($database->getSettingsFields($index), 'field');

    expect($offered())->toContain('interval', 'idleTimeout');

    $database->setField($index, 'cadence', 'steps');
    expect($offered())->toContain('interval')->not->toContain('idleTimeout')
        ->and($database->getRecordByIndex($index)?->get('idleTimeout'))->toBeNull();

    $database->setField($index, 'cadence', 'event');
    $database->save();

    expect($offered())->not->toContain('interval')
        ->and(IncomeCatalog::fromProject(incomeFilePath($root))->findSchedule('wage')?->interval)->toBeNull();
});
