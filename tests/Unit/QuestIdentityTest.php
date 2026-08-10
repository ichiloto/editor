<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\QuestReferences;
use Ichiloto\Editor\Database\Slug;
use Ichiloto\Editor\ProjectQuestDatabase;
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
    $database = ProjectQuestDatabase::fromProject(makeTemporaryProject());

    expect($database->getQuestByIndex($database->addQuest('Feed The Cat'))->getId())->toBe('feed-the-cat')
        // The fixture already has a breakfast-duty, which is the collision
        // the numbering is for.
        ->and($database->getQuestByIndex($database->addQuest('Breakfast Duty'))->getId())->toBe('breakfast-duty-2');
});

it('keeps the id in step with the name while nothing points at it', function () {
    $database = ProjectQuestDatabase::fromProject(makeTemporaryProject());
    $index = $database->addQuest('New Quest');

    $id = $database->renameQuest($index, 'Feed The Cat', mayChangeId: true);

    expect($id)->toBe('feed-the-cat')
        ->and($database->getQuestByIndex($index)->getName())->toBe('Feed The Cat');
});

it('holds the id still once something points at it', function () {
    $database = ProjectQuestDatabase::fromProject(makeTemporaryProject());
    $index = $database->addQuest('New Quest');

    $id = $database->renameQuest($index, 'Feed The Cat', mayChangeId: false);

    // Renaming does not rewrite the triggers and conditions that name it, so
    // the id is what stays put.
    expect($id)->toBeNull()
        ->and($database->getQuestByIndex($index)->getId())->toBe('new-quest')
        ->and($database->getQuestByIndex($index)->getName())->toBe('Feed The Cat');
});

it('numbers a renamed quest away from one that has the name already', function () {
    $database = ProjectQuestDatabase::fromProject(makeTemporaryProject());
    $database->addQuest('Feed The Cat');
    $second = $database->addQuest('New Quest');

    expect($database->renameQuest($second, 'Feed The Cat', mayChangeId: true))->toBe('feed-the-cat-2');
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
