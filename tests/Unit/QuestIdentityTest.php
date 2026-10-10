<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\QuestReferences;
use Ichiloto\Editor\Database\Slug;
use Ichiloto\Editor\Database\RecordAuthoring;
use Ichiloto\Editor\ProjectWorkspace;

it('makes an id out of a name', function () {
    expect(Slug::of('Breakfast Duty'))->toBe('breakfast-duty')
        ->and(Slug::of('  The Baron\'s Ledger!  '))->toBe('the-barons-ledger')
        ->and(Slug::of('Café Errand'))->toBe('cafe-errand')
        // A name with nothing an id can be made from yields nothing, and the
        // caller decides what to call it instead.
        ->and(Slug::of('???'))->toBe('');
});

it('numbers an id rather than colliding', function () {
    expect(Slug::unique('An Errand', ['an-errand']))->toBe('an-errand-2')
        ->and(Slug::unique('An Errand', ['an-errand', 'an-errand-2']))->toBe('an-errand-3')
        ->and(Slug::unique('???', ['untitled'], 'untitled'))->toBe('untitled-2');
});

it('gives a new quest an id from its name', function () {
    $database = ProjectWorkspace::fromProject(makeTemporaryProject())->getRecordDatabase('quests');
    $first = $database->addRecord();
    $second = $database->addRecord();
    $database->setField($first, 'name', 'Feed The Cat');
    $database->setField($second, 'name', 'Breakfast Duty');

    expect($database->getRecordByIndex($first)->get('id'))->toBe('feed-the-cat')
        // The fixture already has a breakfast-duty, which is the collision
        // the numbering is for.
        ->and($database->getRecordByIndex($second)->get('id'))->toBe('breakfast-duty-2');
});

it('keeps the id in step with the name while nothing points at it', function () {
    $database = ProjectWorkspace::fromProject(makeTemporaryProject())->getRecordDatabase('quests');
    $index = $database->addRecord();

    $change = new RecordAuthoring()->applyField($database, $index, [], 'name', 'Feed The Cat', 'Name');

    expect($database->getRecordByIndex($index)->get('id'))->toBe('feed-the-cat')
        ->and($database->getRecordByIndex($index)->get('name'))->toBe('Feed The Cat')
        ->and($change->note)->toBe('Its id is now feed-the-cat.');

    $change->command->undo();

    expect($database->getRecordByIndex($index)->get('id'))->toBe('new-quest');
});

it('holds the id still once something points at it', function () {
    // The fixture's skit waits on breakfast-duty.
    $database = ProjectWorkspace::fromProject(makeTemporaryProject())->getRecordDatabase('quests');

    $change = new RecordAuthoring()->applyField($database, 0, [], 'name', 'Feed The Cat', 'Name');

    // Renaming does not rewrite the triggers and conditions that name it, so
    // the id is what stays put.
    expect($database->getRecordByIndex(0)->get('id'))->toBe('breakfast-duty')
        ->and($database->getRecordByIndex(0)->get('name'))->toBe('Feed The Cat')
        ->and($change->note)->toBe('Its id stays breakfast-duty, which other things point at.');
});

it('numbers a renamed quest away from one that has the name already', function () {
    $database = ProjectWorkspace::fromProject(makeTemporaryProject())->getRecordDatabase('quests');
    $first = $database->addRecord();
    $second = $database->addRecord();
    $database->setField($first, 'name', 'Feed The Cat');
    $database->setField($second, 'name', 'Feed The Cat');

    expect($database->getRecordByIndex($second)->get('id'))->toBe('feed-the-cat-2');
});

it('finds what points at a quest', function () {
    $workspace = ProjectWorkspace::fromProject(fixturePath('sample-project'));
    $references = new QuestReferences($workspace);

    // The fixture's skit waits on this one.
    expect($references->exist('breakfast-duty'))->toBeTrue()
        ->and($references->describe('breakfast-duty'))->toContain('skit breakfast-banter');
});

it('finds nothing pointing at a quest nothing mentions', function () {
    $references = new QuestReferences(ProjectWorkspace::fromProject(fixturePath('sample-project')));

    expect($references->exist('pest-control'))->toBeFalse()
        ->and($references->exist(''))->toBeFalse();
});

it('finds a quest a script grants, however deeply it is nested', function () {
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Events/errand.php', <<<'PHP'
    <?php

    return [
      ['type' => 'choice', 'prompt' => 'Well?', 'options' => [
        ['text' => 'Go on then', 'then' => [
          ['type' => 'accept_quest', 'id' => 'errand'],
        ]],
      ]],
    ];
    PHP);

    $references = new QuestReferences(ProjectWorkspace::fromProject($root));

    expect($references->describe('errand'))->toContain('event script errand');
});

it('finds a map event gated on a quest', function () {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $source = file_get_contents($path);

    // str_replace's fourth argument is by reference, so this is a preg.
    file_put_contents($path, preg_replace(
        "/'events' => \[/",
        "'events' => [\n    'A' => ['class' => 'X', 'conditions' => [['type' => 'quest', 'name' => 'errand', 'status' => 'active']]],",
        $source,
        1
    ));

    $references = new QuestReferences(ProjectWorkspace::fromProject($root));

    expect($references->describe('errand'))->toContain('test-map event A');
});
