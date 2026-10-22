<?php

declare(strict_types=1);

use Assegai\Util\Text;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\Database\WordFormFields;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Engine\Localization\WordForms;

/** @return list<string> The validator's findings about word forms. */
function wordFormIssueMessages(string $root): array
{
    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));

    return array_values(array_map(static fn(Issue $issue): string => $issue->message,
        array_filter($issues, static fn(Issue $issue): bool => $issue->where === 'assets/' . WordForms::FILE)));
}

it('writes nothing while a project without word forms is browsed and validated', function (): void {
    $root = makeTemporaryProject();
    $before = sourceHashTree($root . '/assets');

    $editor = deletionEditor($root);
    openDatabaseCategory($editor, WordFormFields::CATEGORY);
    callEditorMethod($editor, 'saveActiveDatabase');

    expect(wordFormIssueMessages($root))->toBe([])
        ->and(is_file($root . '/assets/' . WordForms::FILE))->toBeFalse()
        ->and(sourceHashTree($root . '/assets'))->toBe($before);
});

it('authors a word the game then keeps from the English rules', function (): void {
    $root = makeTemporaryProject();

    $database = loadRecordDatabase($root, WordFormFields::CATEGORY);
    $index = $database->addRecord();
    $database->setField($index, 'word', 'T-Vexmana');
    $database->setField($index, 'singular', 'T-Vexmana');
    $database->setField($index, 'plural', 'T-Vexmana');
    $database->save();

    $forms = WordForms::load($root . '/assets');
    expect($forms->words)->toBe(['T-Vexmana' => ['singular' => 'T-Vexmana', 'plural' => 'T-Vexmana']])
        ->and(wordFormIssueMessages($root))->toBe([]);

    expect(new Text('T-Vexmana')->getSingularForm())->toBe('T-Vexmanum');
    $forms->registerExceptions();
    expect(new Text('T-Vexmana')->getSingularForm())->toBe('T-Vexmana')
        ->and(new Text('T-Vexmana')->getPluralForm())->toBe('T-Vexmana')
        ->and(new Text('Lanternfish')->getPluralForm())->toBe('Lanternfishes');
});

it('authors a plural of its own beside the word', function (): void {
    $root = makeTemporaryProject();

    $database = loadRecordDatabase($root, WordFormFields::CATEGORY);
    $index = $database->addRecord();
    $database->setField($index, 'word', 'Duskstratum');
    $database->setField($index, 'singular', 'Duskstratum');
    $database->setField($index, 'plural', 'Duskstrata');
    $database->save();

    expect(WordForms::load($root . '/assets')->words)->toBe(['Duskstratum' => ['singular' => 'Duskstratum', 'plural' => 'Duskstrata']]);
});

it('refuses a blank or padded form as it is typed', function (string $field, string $value): void {
    expect(fn() => WordFormFields::checkEdit(['word' => 'Mana', 'singular' => 'Mana', 'plural' => 'Mana', $field => $value], $field))
        ->toThrow(RecordRefusal::class);
})->with([['word', ' Mana'], ['singular', ''], ['plural', 'Mana ']]);

it('reports word forms the game could not read as errors', function (): void {
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/' . WordForms::FILE, "<?php return ['schema' => '" . WordForms::SCHEMA
        . "', 'words' => ['Mana' => ['singular' => 'Mana']]];");

    expect(wordFormIssueMessages($root))->toHaveCount(1)
        ->and(wordFormIssueMessages($root)[0])->toContain('plural');
});
