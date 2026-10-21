<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\MasteryFields;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;

/**
 * Mastery regions and maps through the editor session: each character's map
 * authored node by node, its place on the graphical map left to the GUI
 * editor, and a map the Engine could not read refused before anything is
 * written. Synthetic fixtures only.
 */
function masteryProject(): string
{
    $root = makeTemporaryProject();
    writeSkillRecords($root, new SpecialSkill('Cut', 'Cuts.', '', 2, 0), new MagicSkill('Spark', 'Shocks.', '', 4, 0));
    file_put_contents($root . '/assets/Data/mastery.php', "<?php\n\nreturn [\n  'regions' => [\n    ['id' => 'blades', 'name' => 'Blades'],\n    ['id' => 'storms', 'name' => 'Storms'],\n  ],\n];\n");

    return $root;
}

/** The row of a record read, by its field id. */
function masteryRow(array $read, string $field): array
{
    return array_find($read['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === $field)
        ?? throw new RuntimeException("No row {$field}.");
}

/** Authors one node's fields on a map record through the session. */
function authorMasteryNode(EditorSession $session, int $index, int $node, array $values): void
{
    foreach ($values as $field => $value) {
        $session->applyDatabaseRecord(MasteryFields::MAPS, $index, masteryRow($session->readDatabaseRecord(MasteryFields::MAPS, $index), "node{$node}{$field}")['key'], (string) $value);
    }
}

it('authors a character\'s map node by node, and the Engine reads it back', function () {
    $root = masteryProject();
    $session = EditorSession::open($root);
    $created = $session->createDatabaseRecord(MasteryFields::MAPS, 'Kaelion');
    $index = $created['index'];
    foreach ([0, 1] as $node) {
        $session->addDatabaseItem(MasteryFields::MAPS, $index, ['frame' => []], false);
    }
    authorMasteryNode($session, $index, 0, ['Id' => 'cut', 'Skill' => 'Cut', 'Region' => 'blades', 'Order' => 1]);
    authorMasteryNode($session, $index, 1, ['Id' => 'spark', 'Skill' => 'Spark', 'Region' => 'storms', 'Cost' => 50,
        'OpensAtLevel' => 3, 'Requires' => 'cut', 'Order' => 2]);
    $session->saveDatabase(MasteryFields::MAPS);
    $map = MasteryCatalog::load($root . '/assets')->findMap('Kaelion');

    expect(array_keys($map?->nodes ?? []))->toBe(['cut', 'spark'])
        ->and($map->nodes['spark']->requires)->toBe(['cut'])
        ->and($map->nodes['spark']->cost)->toBe(50);
});

it('refuses saving a map the Engine could not read, naming why', function () {
    $root = masteryProject();
    $session = EditorSession::open($root);
    $index = $session->createDatabaseRecord(MasteryFields::MAPS, 'Kaelion')['index'];
    $session->addDatabaseItem(MasteryFields::MAPS, $index, ['frame' => []], false);
    authorMasteryNode($session, $index, 0, ['Id' => 'spark', 'Skill' => 'Spark', 'Region' => 'storms', 'Requires' => 'missing']);

    expect(fn() => $session->saveDatabase(MasteryFields::MAPS))->toThrow(SessionRefusal::class, 'requires unknown node missing')
        ->and(glob($root . '/assets/Data/Mastery/*.php') ?: [])->toBe([]);
});

it('refuses a node in an undeclared region and keeps a skill a map teaches from being deleted', function () {
    $root = masteryProject();
    $session = EditorSession::open($root);
    $index = $session->createDatabaseRecord(MasteryFields::MAPS, 'Kaelion')['index'];
    $session->addDatabaseItem(MasteryFields::MAPS, $index, ['frame' => []], false);
    authorMasteryNode($session, $index, 0, ['Id' => 'cut', 'Skill' => 'Cut', 'Region' => 'blades']);

    authorMasteryNode($session, $index, 0, ['Region' => 'nowhere']);

    expect(fn() => $session->saveDatabase(MasteryFields::MAPS))->toThrow(SessionRefusal::class, 'unknown region "nowhere"')
        ->and(fn() => $session->deleteDatabaseRecord('skills', 0))->toThrow(SessionRefusal::class, 'Mastery Map of Kaelion');
});

it('leaves each node\'s place on the map to the GUI editor and keeps it through Terminal edits', function () {
    $root = masteryProject();
    mkdir($root . '/assets/Data/Mastery');
    file_put_contents($root . '/assets/Data/Mastery/kaelion.php', "<?php\n\nreturn [\n  'actor' => 'Kaelion',\n  'nodes' => [\n    ['id' => 'cut', 'skill' => 'Cut', 'region' => 'blades', 'cost' => 0, 'opensAtLevel' => 1, 'order' => 1, 'position' => ['x' => 0.2, 'y' => 0.3]],\n  ],\n];\n");
    $terminal = loadRecordDatabase($root, MasteryFields::MAPS);
    $terminalFields = array_column($terminal->getSettingsFields(0), 'field');
    $terminal->setField(0, 'node0Order', '4');
    $terminal->save();
    $graphical = EditorSession::open($root);

    expect($terminalFields)->toContain('node0Order')->not->toContain('node0Positionx')
        ->and((require $root . '/assets/Data/Mastery/kaelion.php')['nodes'][0])->toMatchArray(['order' => 4, 'position' => ['x' => 0.2, 'y' => 0.3]])
        ->and(masteryRow($graphical->readDatabaseRecord(MasteryFields::MAPS, 0), 'node0Positionx')['value'])->toBe('0.2');
});

it('moves a character\'s own skills onto their map, leaves role skills to the role, and undoes as one step', function () {
    $root = masteryProject();
    writeSkillRecords($root, new SpecialSkill('Cut', 'Cuts.', '', 2, 0), new MagicSkill('Spark', 'Shocks.', '', 4, 0),
        new SpecialSkill('Rally', 'Rallies.', '', 3, 0));
    $actor = $root . '/assets/Data/Actors/Kaelion.php';
    $source = (string) file_get_contents($actor);
    file_put_contents($actor, str_replace("    'images' => [", "    'class' => 'Vanguard',\n    'abilities' => [\n      'learned' => ['Cut', 'Rally'],\n      'learnables' => [\n        ['skill' => 'Spark', 'requirement' => ['experienceRequired' => 300]],\n      ],\n    ],\n    'images' => [", $source));
    $classes = $root . '/assets/Data/classes.php';
    file_put_contents($classes, preg_replace("/'name' => 'Vanguard',\n/", "'name' => 'Vanguard',\n    'skillsToLearn' => [['level' => 2, 'skill' => 'Rally']],\n", (string) file_get_contents($classes), 1));
    $before = (string) file_get_contents($actor);
    $session = EditorSession::open($root);

    $question = $session->adoptMasterySkills('Kaelion');
    $session->adoptMasterySkills('Kaelion', 'write', $question['confirm']);
    $map = MasteryCatalog::load($root . '/assets')->findMap('Kaelion');
    $abilities = (require $actor)['data']['abilities'];

    expect($question['question'])->toContain('Cut, Spark')
        ->and(array_map(static fn($node) => [$node->skill, $node->cost], array_values($map?->nodes ?? [])))->toBe([['Cut', 0], ['Spark', 300]])
        ->and($abilities)->toBe(['learned' => [], 'learnables' => []]);

    $session->undo();
    expect(file_get_contents($actor))->toBe($before)
        ->and(glob($root . '/assets/Data/Mastery/*.php') ?: [])->toBe([]);
});

it('gives the map page every node\'s place, moves a node and links two nodes, each one undo step', function () {
    $root = masteryProject();
    mkdir($root . '/assets/Data/Mastery');
    file_put_contents($root . '/assets/Data/Mastery/kaelion.php', "<?php\n\nreturn [\n  'actor' => 'Kaelion',\n  'nodes' => [\n    ['id' => 'cut', 'skill' => 'Cut', 'region' => 'blades', 'cost' => 0, 'opensAtLevel' => 1, 'order' => 1],\n    ['id' => 'spark', 'skill' => 'Spark', 'region' => 'storms', 'cost' => 50, 'opensAtLevel' => 2, 'order' => 2],\n  ],\n];\n");
    $session = EditorSession::open($root);
    $page = $session->readMasteryPage(0);

    expect(array_column($page['regions'], 'id'))->toBe(['blades', 'storms'])
        ->and(array_map(static fn(array $node): array => [$node['id'], $node['x'], $node['placed']], $page['nodes']))
            ->toBe([['cut', 0.25, false], ['spark', 0.75, false]]);

    $session->placeMasteryNode(0, 'spark', 0.6, 0.4);
    $session->toggleMasteryRequirement(0, 'spark', 'cut');
    $moved = array_column($session->readMasteryPage(0)['nodes'], null, 'id')['spark'];

    expect([$moved['x'], $moved['y'], $moved['placed'], $moved['requires']])->toBe([0.6, 0.4, true, ['cut']])
        ->and(fn() => $session->toggleMasteryRequirement(0, 'cut', 'spark'))->toThrow(SessionRefusal::class, 'loop')
        ->and(fn() => $session->placeMasteryNode(0, 'cut', 1.5, 0.2))->toThrow(SessionRefusal::class, 'between 0 and 1');

    $session->undo();
    $session->undo();
    expect(array_column($session->readMasteryPage(0)['nodes'], null, 'id')['spark']['placed'])->toBeFalse();
});
