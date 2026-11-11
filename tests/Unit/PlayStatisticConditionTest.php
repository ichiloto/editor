<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ConditionCodec;
use Ichiloto\Editor\Database\ConditionEditor;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Progression\AchievementFields;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Core\PlayStatistic;
use Ichiloto\Engine\Progress\Achievement;

/**
 * The Engine's `play_statistic` world condition: one of its PlayStatistic
 * counters compared with a whole count from zero up, through the one
 * condition line, the condition editors and Database > Achievements. The
 * counter is picked from the Engine's own cases by their labels, never typed;
 * the Engine's validation judges every line. Projects are synthetic.
 */

/** The first counter the Engine declares, so the tests follow its vocabulary rather than one counter's name. */
function firstPlayStatistic(): PlayStatistic
{
    return PlayStatistic::cases()[0];
}

/** A synthetic project's hand-written achievements file, with a comment worth keeping. */
function writeSyntheticAchievements(string $root, array $achievements): string
{
    $path = $root . '/' . AchievementFields::RELATIVE_PATH;
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }
    file_put_contents($path, "<?php\n\n// Synthetic achievements, written by hand.\nreturn " . var_export($achievements, true) . ";\n");

    return $path;
}

function achievementRow(EditorSession $session, int $index, string $field): array
{
    return array_find($session->readDatabaseRecord(AchievementFields::CATEGORY, $index)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field) ?? throw new RuntimeException('Missing row ' . $field);
}

it('reads and writes a play statistic through the one condition line, with or without an authored operator', function (): void {
    $name = firstPlayStatistic()->value;
    $compared = ['type' => 'play_statistic', 'name' => $name, 'op' => '>=', 'value' => 1];
    $equal = ['type' => 'play_statistic', 'name' => $name, 'value' => 3];

    expect(ConditionCodec::encode($compared))->toBe("play_statistic:{$name}:>=:1")
        // The Engine reads an absent operator as ==, so the line leaves it out as the source does.
        ->and(ConditionCodec::encode($equal))->toBe("play_statistic:{$name}:3")
        ->and(ConditionCodec::decodeAllStrictly("play_statistic:{$name}:>=:1; !play_statistic:{$name}:3; any(play_statistic:{$name}:<:0)"))->toBe([
            $compared,
            [...$equal, 'negate' => true],
            ['type' => 'any', 'conditions' => [['type' => 'play_statistic', 'name' => $name, 'op' => '<', 'value' => 0]]],
        ])
        ->and(ConditionCodec::findCarryIssue([$compared, $equal]))->toBeNull()
        ->and(ConditionCodec::findCarryIssue([[...$compared, 'value' => '1']]))->toContain('the Engine refuses')
        // The Engine reads no other key on a play statistic, so the line refuses one rather than dropping it.
        ->and(ConditionCodec::findCarryIssue([[...$compared, 'note' => 'kept']]))->toContain('the Engine refuses');
});

it('refuses a play statistic the Engine would not read, saying why', function (string $line, string $reason): void {
    expect(fn() => ConditionCodec::decodeAllStrictly($line))->toThrow(InvalidArgumentException::class, $reason);
})->with([
    'no count' => [sprintf('play_statistic:%s', PlayStatistic::cases()[0]->value), 'needs a count'],
    'a negative count' => [sprintf('play_statistic:%s:>=:-1', PlayStatistic::cases()[0]->value), 'whole number from zero up'],
    'a fractional count' => [sprintf('play_statistic:%s:>=:1.5', PlayStatistic::cases()[0]->value), 'whole number from zero up'],
    'an unknown operator' => [sprintf('play_statistic:%s:=>:1', PlayStatistic::cases()[0]->value), 'compares with'],
    'a counter the Engine does not keep' => ['play_statistic:synthetic_unknown_counter:>=:1', 'requires a known counter'],
    'parts past the count' => [sprintf('play_statistic:%s:>=:1:2', PlayStatistic::cases()[0]->value), 'takes an operator and a count'],
]);

it('builds a play statistic a part at a time: the counter picked by its label, the operator stepped, the count typed', function (): void {
    $statistic = firstPlayStatistic();
    $editor = new ConditionEditor();
    $editor->open('conditions', 'Conditions', []);
    $editor->add();
    $types = ConditionCodec::getLeafTypes();
    $editor->cycleType(array_search('play_statistic', $types, true) - array_search('switch', $types, true));

    expect($editor->encoded())->toBe("play_statistic:{$statistic->value}:>=:1")
        ->and($editor->nameReference())->toBe(['label' => 'Play Statistic', 'category' => ReferenceCatalog::PLAY_STATISTICS])
        ->and($editor->getValueLabel())->toBe('Count');

    $editor->cycleExtra(1);
    $editor->setValue(' 12 ');
    expect($editor->encoded())->toBe("play_statistic:{$statistic->value}:<:12")
        ->and($editor->rows())->toBe([sprintf('Play Statistic %s < 12', $statistic->getLabel())]);

    foreach (['-1', '2.5', 'many', ''] as $refused) {
        expect(fn() => $editor->setValue($refused))->toThrow(InvalidArgumentException::class, 'whole number from zero up');
    }
    $editor->setValue('0');
    $editor->toggleNegate();
    expect($editor->encoded())->toBe("!play_statistic:{$statistic->value}:<:0");

    // The grammar an interface without this cursor builds from: the Engine's counters, an operator and a whole count.
    $session = EditorSession::open(npcSessionProject([]));
    $grammar = array_column($session->describeWorldStateGrammar()['conditions'], null, 'type')['play_statistic'];
    expect($grammar)->toMatchArray(['label' => 'Play Statistic', 'nameCategory' => ReferenceCatalog::PLAY_STATISTICS])
        ->and(array_column($grammar['extras'], 'kind', 'key'))->toBe(['op' => 'options', 'value' => 'integer'])
        ->and($session->listReferences(null, ReferenceCatalog::PLAY_STATISTICS))->toBe(array_map(
            static fn(PlayStatistic $case): array => ['value' => $case->value, 'label' => $case->getLabel()], PlayStatistic::cases()))
        ->and($session->encodeWorldState('conditions', [['type' => 'play_statistic', 'name' => $statistic->value, 'op' => '>=', 'value' => 2]]))
        ->toBe(['line' => "play_statistic:{$statistic->value}:>=:2", 'descriptions' => [sprintf('Play Statistic %s >= 2', $statistic->getLabel())]])
        ->and(fn() => $session->encodeWorldState('conditions', [['type' => 'play_statistic', 'name' => $statistic->value, 'op' => '>=', 'value' => -2]]))
        ->toThrow(SessionRefusal::class, 'whole number from zero up');
});

it('builds a play statistic in the Terminal: the counter from the Engine\'s list, the count typed and checked', function (): void {
    [$root] = createLandmarkProject();
    $editor = metadataEditor($root);
    // A landmark's reveal conditions, opened from its Inspector row as an author opens them.
    setEditorProperty($editor, 'editingMode', 'event');
    setEditorProperty($editor, 'cursorX', 3);
    setEditorProperty($editor, 'cursorY', 0);
    selectInspectorField($editor, 'Minimap');
    callEditorMethod($editor, 'activateInspectorField');
    setEditorProperty($editor, 'editingMode', 'map');
    foreach (callEditorMethod($editor, 'getInspectorFields') as $index => $row) {
        if (($row['target'] ?? null) === 'map-landmarks' && ($row['field'] ?? null) === 'revealConditions') {
            setEditorProperty($editor, 'selectedInspectorFieldIndex', $index);
        }
    }
    callEditorMethod($editor, 'activateInspectorField');
    $conditions = getEditorProperty($editor, 'conditionEditor');
    expect($conditions->isOpen())->toBeTrue();
    $types = ConditionCodec::getLeafTypes();
    callEditorMethod($editor, 'dispatchInput', 'a');
    foreach (range(1, array_search('play_statistic', $types, true) - array_search('switch', $types, true)) as $step) {
        callEditorMethod($editor, 'dispatchInput', 't');
    }

    // The counter is chosen from the Engine's cases, read by their labels; nothing is typed.
    callEditorMethod($editor, 'dispatchInput', 'n');
    $picker = getEditorProperty($editor, 'referencePicker');
    expect($picker->isOpen())->toBeTrue()
        ->and($picker->category())->toBe(ReferenceCatalog::PLAY_STATISTICS)
        ->and($picker->matches())->toBe(PlayStatistic::values())
        ->and($picker->labelFor(firstPlayStatistic()->value))->toBe(firstPlayStatistic()->getLabel())
        ->and(getEditorProperty($editor, 'conditionTypedPart'))->toBeNull();
    $picker->close();

    callEditorMethod($editor, 'dispatchInput', 'v');
    callEditorMethod($editor, 'dispatchInput', "\x7f");
    foreach (str_split('25') as $digit) { callEditorMethod($editor, 'dispatchInput', $digit); }
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect($conditions->encoded())->toBe(sprintf('play_statistic:%s:>=:25', firstPlayStatistic()->value));

    // A count the Engine refuses stays in front of the author, and the condition keeps its count.
    callEditorMethod($editor, 'dispatchInput', 'v');
    callEditorMethod($editor, 'dispatchInput', '-');
    callEditorMethod($editor, 'dispatchInput', "\r");
    expect(getEditorProperty($editor, 'conditionTypedPart'))->not->toBeNull()
        ->and($conditions->encoded())->toBe(sprintf('play_statistic:%s:>=:25', firstPlayStatistic()->value));
    callEditorMethod($editor, 'dispatchInput', "\033");
    expect(getEditorProperty($editor, 'conditionTypedPart'))->toBeNull();

    callEditorMethod($editor, 'dispatchInput', "\r");
    expect(mapDataOf($editor)['landmarks'][0]['revealConditions'])
        ->toBe([['type' => 'play_statistic', 'name' => firstPlayStatistic()->value, 'op' => '>=', 'value' => 25]]);
});

it('authors an achievement\'s play statistic condition in its own source, refusing one the Engine would not read', function (): void {
    $statistic = firstPlayStatistic();
    $root = makeTemporaryProject();
    $path = writeSyntheticAchievements($root, [
        ['id' => 'synthetic-first', 'name' => 'Synthetic First', 'points' => 5, 'conditions' => [['type' => 'event', 'name' => 'synthetic_event']]],
        ['id' => 'synthetic-second', 'name' => 'Synthetic Second'],
    ]);
    $original = (string) file_get_contents($path);
    $session = EditorSession::open($root);

    expect(achievementRow($session, 0, 'conditions'))->toMatchArray(['kind' => 'conditions', 'value' => 'event:synthetic_event']);
    $session->applyDatabaseRecord(AchievementFields::CATEGORY, 0, achievementRow($session, 0, 'conditions')['key'], "play_statistic:{$statistic->value}:>=:1");
    expect(fn() => $session->applyDatabaseRecord(AchievementFields::CATEGORY, 1, achievementRow($session, 1, 'conditions')['key'],
        'play_statistic:synthetic_unknown_counter:>=:1'))->toThrow(SessionRefusal::class, 'requires a known counter');
    $session->saveDatabase(AchievementFields::CATEGORY);

    $saved = require $path;
    expect(file_get_contents($path))->toContain('// Synthetic achievements, written by hand.')
        ->and($saved[0]['conditions'])->toBe([['type' => 'play_statistic', 'name' => $statistic->value, 'op' => '>=', 'value' => 1]])
        ->and(Achievement::fromArray($saved[0])->conditions)->toBe($saved[0]['conditions'])
        ->and($saved[1])->toBe(['id' => 'synthetic-second', 'name' => 'Synthetic Second']);

    $session->undo();
    $session->saveDatabase(AchievementFields::CATEGORY);
    expect(file_get_contents($path))->toBe($original);
});

it('keeps a hand-written play statistic it cannot carry as written, and refuses a save the Engine would skip', function (): void {
    $statistic = firstPlayStatistic();
    $root = makeTemporaryProject();
    $path = writeSyntheticAchievements($root, [
        ['id' => 'synthetic-text-count', 'name' => 'Text Count', 'conditions' => [['type' => 'play_statistic', 'name' => $statistic->value, 'value' => '1']]],
        ['id' => 'synthetic-other', 'name' => 'Other'],
    ]);
    $original = (string) file_get_contents($path);
    $session = EditorSession::open($root);

    expect(achievementRow($session, 0, 'conditions')['kind'])->toBe('info')
        ->and(fn() => $session->applyDatabaseRecord(AchievementFields::CATEGORY, 0, achievementRow($session, 0, 'conditions')['key'],
            "play_statistic:{$statistic->value}:1"))->toThrow(SessionRefusal::class);

    // The Engine refuses that count, so no save of the file is written while it stands.
    $session->applyDatabaseRecord(AchievementFields::CATEGORY, 1, achievementRow($session, 1, 'name')['key'], 'Renamed');
    expect(fn() => $session->saveDatabase(AchievementFields::CATEGORY))->toThrow(SessionRefusal::class, 'requires a known counter');
    expect(file_get_contents($path))->toBe($original);

    // Two achievements under one id: the Engine keeps one, so the save is refused before a byte is written.
    $path = writeSyntheticAchievements($root, [['id' => 'synthetic-one', 'name' => 'One'], ['id' => 'synthetic-two', 'name' => 'Two']]);
    $original = (string) file_get_contents($path);
    $session = EditorSession::open($root);
    $session->applyDatabaseRecord(AchievementFields::CATEGORY, 1, achievementRow($session, 1, 'id')['key'], 'synthetic-one');
    expect(fn() => $session->saveDatabase(AchievementFields::CATEGORY))->toThrow(SessionRefusal::class, 'the game would keep only one');
    expect(file_get_contents($path))->toBe($original);
});
