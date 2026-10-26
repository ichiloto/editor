<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\MasteryFields;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;

/**
 * The mastery tree and maps through the editor session: the tree's regions
 * and branches, each character's own terms authored node by node, their
 * place on the graphical map left to the GUI editor, and a tree or map the
 * Engine could not read refused before anything is written. Synthetic
 * fixtures only.
 */
function masteryProject(): string
{
    $root = makeTemporaryProject();
    writeSkillRecords($root, new SpecialSkill('Cut', 'Cuts.', '', 2, 0), new MagicSkill('Spark', 'Shocks.', '', 4, 0));
    file_put_contents($root . '/assets/Data/mastery.php', "<?php\n\nreturn [\n  'regions' => [\n    ['id' => 'blades', 'name' => 'Blades', 'nodes' => [\n      ['id' => 'cut', 'kind' => 'application', 'skill' => 'Cut'],\n    ]],\n    ['id' => 'storms', 'name' => 'Storms', 'nodes' => [\n      ['id' => 'spark', 'kind' => 'application', 'skill' => 'Spark'],\n    ]],\n  ],\n];\n");

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
    authorMasteryNode($session, $index, 0, ['Id' => 'cut']);
    authorMasteryNode($session, $index, 1, ['Id' => 'spark', 'Cost' => 50, 'OpensAtLevel' => 3, 'Requires' => 'cut', 'Hidden' => 'true']);
    $read = $session->readDatabaseRecord(MasteryFields::MAPS, $index);
    $session->saveDatabase(MasteryFields::MAPS);
    $map = MasteryCatalog::load($root . '/assets')->findMap('Kaelion');

    expect(array_keys($map?->terms ?? []))->toBe(['cut', 'spark'])
        ->and($map->nodes['spark']->skill)->toBe('Spark')
        ->and($map->nodes['spark']->requires)->toBe(['cut'])
        ->and($map->nodes['spark']->cost)->toBe(50)
        ->and($map->nodes['spark']->hidden)->toBeTrue()
        ->and($map->nodes['cut']->hidden)->toBeFalse()
        // A node's price is in Mastery points, and nodes have no study order of their own.
        ->and(masteryRow($read, 'node1Cost')['label'] ?? null)->toContain('SP')
        ->and(array_filter(array_map(static fn(array $row): string => strval($row['key']['field'] ?? ''), $read['rows']),
            static fn(string $field): bool => str_ends_with($field, 'Order')))->toBe([]);
});

it('refuses saving a map the Engine could not read, naming why', function () {
    $root = masteryProject();
    $session = EditorSession::open($root);
    $index = $session->createDatabaseRecord(MasteryFields::MAPS, 'Kaelion')['index'];
    $session->addDatabaseItem(MasteryFields::MAPS, $index, ['frame' => []], false);
    authorMasteryNode($session, $index, 0, ['Id' => 'spark', 'Requires' => 'missing']);

    expect(fn() => $session->saveDatabase(MasteryFields::MAPS))->toThrow(SessionRefusal::class, 'requires unknown node missing')
        ->and(glob($root . '/assets/Data/Mastery/*.php') ?: [])->toBe([]);
});

it('refuses terms for a node the tree lacks, and keeps a skill the tree teaches from being deleted', function () {
    $root = masteryProject();
    $session = EditorSession::open($root);
    $index = $session->createDatabaseRecord(MasteryFields::MAPS, 'Kaelion')['index'];
    $session->addDatabaseItem(MasteryFields::MAPS, $index, ['frame' => []], false);
    $nodes = masteryRow($session->readDatabaseRecord(MasteryFields::MAPS, $index), 'node0Id');
    $session->applyDatabaseRecord(MasteryFields::MAPS, $index, $nodes['key'], 'nowhere');

    expect(fn() => $session->saveDatabase(MasteryFields::MAPS))->toThrow(SessionRefusal::class, 'does not have')
        ->and(fn() => $session->deleteDatabaseRecord('skills', 0))->toThrow(SessionRefusal::class, 'Mastery region Blades');
});

it('grows a branch whose nodes chain from its foundation, and refuses a ladder the Engine could not read', function () {
    $root = masteryProject();
    writeSkillRecords($root, new SpecialSkill('Cut', 'Cuts.', '', 2, 0), new MagicSkill('Spark', 'Shocks.', '', 4, 0),
        new MagicSkill('Spark II', 'Shocks harder.', '', 8, 0));
    $session = EditorSession::open($root);
    $index = $session->createDatabaseRecord(MasteryFields::BRANCHES, 'lightning')['index'];
    $branch = static fn(): array => $session->readDatabaseRecord(MasteryFields::BRANCHES, $index);
    $session->applyDatabaseRecord(MasteryFields::BRANCHES, $index, masteryRow($branch(), 'region')['key'], 'storms');
    foreach ([0, 1] as $node) {
        $session->addDatabaseItem(MasteryFields::BRANCHES, $index, ['frame' => []], false);
    }
    foreach ([['node0Id', 'lightning'], ['node0Kind', 'foundation'], ['node0Name', 'Lightning'], ['node1Id', 'spark-ii'],
        ['node1Skill', 'Spark II'], ['node1Grade', '2'], ['node1Cost', '90']] as [$field, $value]) {
        $session->applyDatabaseRecord(MasteryFields::BRANCHES, $index, masteryRow($branch(), $field)['key'], $value);
    }

    // A second grade with no first is a broken ladder.
    expect(fn() => $session->saveDatabase(MasteryFields::BRANCHES))->toThrow(SessionRefusal::class, 'without a gap');

    $session->applyDatabaseRecord(MasteryFields::BRANCHES, $index, masteryRow($branch(), 'node1Grade')['key'], '1');
    $session->saveDatabase(MasteryFields::BRANCHES);
    $tree = MasteryCatalog::load($root . '/assets')->tree;

    expect(array_keys($tree->branches))->toBe(['lightning'])
        ->and($tree->getRequirements($tree->nodes['spark-ii']))->toBe(['lightning'])
        ->and($tree->nodes['spark-ii']->cost)->toBe(90)
        ->and(array_keys((require $root . '/assets/Data/mastery.php')))->toBe(['regions', 'branches']);
});

it('leaves each node\'s place on the map to the GUI editor and keeps it through Terminal edits', function () {
    $root = masteryProject();
    mkdir($root . '/assets/Data/Mastery');
    file_put_contents($root . '/assets/Data/Mastery/kaelion.php', "<?php\n\nreturn [\n  'actor' => 'Kaelion',\n  'nodes' => [\n    ['id' => 'cut', 'cost' => 0, 'opensAtLevel' => 1, 'position' => ['x' => 0.2, 'y' => 0.3]],\n  ],\n];\n");
    $terminal = loadRecordDatabase($root, MasteryFields::MAPS);
    $terminalFields = array_column($terminal->getSettingsFields(0), 'field');
    $terminal->setField(0, 'node0Cost', '40');
    $terminal->save();
    $graphical = EditorSession::open($root);

    expect($terminalFields)->toContain('node0Cost')->not->toContain('node0Positionx')
        ->and((require $root . '/assets/Data/Mastery/kaelion.php')['nodes'][0])->toMatchArray(['cost' => 40, 'position' => ['x' => 0.2, 'y' => 0.3]])
        ->and(masteryRow($graphical->readDatabaseRecord(MasteryFields::MAPS, 0), 'node0Positionx')['value'])->toBe('0.2');
});

it('moves a character\'s own skills onto their map, leaves role skills to the role, and undoes as one step', function () {
    $root = masteryProject();
    writeSkillRecords($root, new SpecialSkill('Cut', 'Cuts.', '', 2, 0), new MagicSkill('Spark', 'Shocks.', '', 4, 0),
        new SpecialSkill('Rally', 'Rallies.', '', 3, 0), new SpecialSkill('Lunge', 'Lunges.', '', 3, 0));
    $actor = $root . '/assets/Data/Actors/Kaelion.php';
    $settings = (string) file_get_contents($root . '/assets/Data/mastery.php');
    $source = (string) file_get_contents($actor);
    file_put_contents($actor, str_replace("    'images' => [", "    'class' => 'Vanguard',\n    'abilities' => [\n      'learned' => ['Cut', 'Rally', 'Lunge'],\n      'learnables' => [\n        ['skill' => 'Spark', 'requirement' => ['experienceRequired' => 300]],\n      ],\n    ],\n    'images' => [", $source));
    $classes = $root . '/assets/Data/classes.php';
    file_put_contents($classes, preg_replace("/'name' => 'Vanguard',\n/", "'name' => 'Vanguard',\n    'skillsToLearn' => [['level' => 2, 'skill' => 'Rally']],\n", (string) file_get_contents($classes), 1));
    $before = (string) file_get_contents($actor);
    $session = EditorSession::open($root);

    $question = $session->adoptMasterySkills('Kaelion');
    $session->adoptMasterySkills('Kaelion', 'write', $question['confirm']);
    $map = MasteryCatalog::load($root . '/assets')->findMap('Kaelion');
    $abilities = (require $actor)['data']['abilities'];

    // Terms go to the tree node that teaches each skill; a skill the tree does not place yet joins the first region's trunk.
    expect($question['question'])->toContain('Cut, Lunge, Spark')
        ->and(array_map(static fn($terms) => [$map->nodes[$terms->id]->skill, $terms->cost], array_values($map?->terms ?? [])))
            ->toBe([['Cut', 0], ['Lunge', 0], ['Spark', 300]])
        ->and($map->nodes['lunge']->region)->toBe('blades')
        ->and($abilities)->toBe(['learned' => [], 'learnables' => []]);

    $session->undo();
    expect(file_get_contents($actor))->toBe($before)
        ->and(file_get_contents($root . '/assets/Data/mastery.php'))->toBe($settings)
        ->and(glob($root . '/assets/Data/Mastery/*.php') ?: [])->toBe([]);
});

it('gives the map page every node\'s place, moves a node and links two nodes, each one undo step', function () {
    $root = masteryProject();
    mkdir($root . '/assets/Data/Mastery');
    file_put_contents($root . '/assets/Data/Mastery/kaelion.php', "<?php\n\nreturn [\n  'actor' => 'Kaelion',\n  'nodes' => [\n    ['id' => 'cut', 'cost' => 0, 'opensAtLevel' => 1],\n    ['id' => 'spark', 'cost' => 50, 'opensAtLevel' => 2],\n  ],\n];\n");
    $session = EditorSession::open($root);
    $page = $session->readMasteryPage(0);

    expect(array_column($page['regions'], 'id'))->toBe(['blades', 'storms'])
        ->and($page['pointsLabel'])->toBe('SP')
        ->and($page['levelLabel'])->toBe('Lv')
        ->and(array_map(static fn(array $node): array => [$node['id'], $node['name'], $node['region'], $node['x'], $node['placed']], $page['nodes']))
            ->toBe([['cut', 'Cut', 'blades', 0.25, false], ['spark', 'Spark', 'storms', 0.75, false]]);

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

it('names prices and levels on the map page in the project\'s own words for Mastery points and the level', function () {
    $root = masteryProject();
    $config = $root . '/config.php';
    file_put_contents($config, preg_replace("/'vocab' => \\[/", "'vocab' => [\n    'mastery' => ['points' => 'JP'],\n    'stats' => ['level_short' => 'Lvl'],", (string) file_get_contents($config), 1));
    mkdir($root . '/assets/Data/Mastery');
    file_put_contents($root . '/assets/Data/Mastery/kaelion.php', "<?php\n\nreturn ['actor' => 'Kaelion', 'nodes' => [['id' => 'cut']]];\n");
    $page = EditorSession::open($root)->readMasteryPage(0);

    expect([$page['pointsLabel'], $page['levelLabel']])->toBe(['JP', 'Lvl']);
});

it('gives an enemy and a quest the Mastery points they reward, and leaves records without them unchanged', function () {
    $root = masteryProject();
    writeRegularBatEnemy($root);
    $session = EditorSession::open($root);
    $enemy = $session->readDatabaseRecord('enemies', 0);
    $quest = $session->readDatabaseRecord('quests', 0);
    $enemySource = (string) file_get_contents($root . '/assets/Data/Enemies/regular-bat.php');

    expect(masteryRow($enemy, 'rewards.skillPoints')['label'] ?? null)->toBe('Reward SP')
        ->and(masteryRow($quest, 'rewards.skillPoints')['label'] ?? null)->toBe('Reward SP')
        ->and($enemySource)->not->toContain('skillPoints');

    $session->applyDatabaseRecord('enemies', 0, masteryRow($enemy, 'rewards.skillPoints')['key'], '12');
    $session->applyDatabaseRecord('quests', 0, masteryRow($quest, 'rewards.skillPoints')['key'], '30');
    $session->saveDatabase('enemies');
    $session->saveDatabase('quests');

    expect((require $root . '/assets/Data/Enemies/regular-bat.php')['data']['rewards']['skillPoints'] ?? null)->toBe(12)
        ->and((require $root . '/assets/Data/quests.php')[0]['rewards']['skillPoints'] ?? null)->toBe(30);
});

it('warns where the tree prices or opens a rung below the one it builds on, and stays quiet for a rising ladder', function (array $terms, array $flagged) {
    $root = masteryProject();
    writeSkillRecords($root, new SpecialSkill('Cut', 'Cuts.', '', 2, 0), new MagicSkill('Spark', 'Shocks.', '', 4, 0),
        new MagicSkill('Bolt', 'Shocks more.', '', 8, 0), new MagicSkill('Storm Call', 'Shocks all.', '', 16, 0));
    $node = static fn(string $id, array $data): array => ['id' => $id, ...$data, ...$terms[$id]];
    file_put_contents($root . '/assets/Data/mastery.php', '<?php return ' . var_export(['regions' => [['id' => 'storms', 'name' => 'Storms', 'description' => 'Weather.']], 'branches' => [
        ['id' => 'storm', 'region' => 'storms', 'name' => 'Storm', 'kind' => 'aspect', 'description' => 'Storms.', 'nodes' => [
            $node('storm', ['kind' => 'foundation', 'name' => 'Storm']),
            $node('spark', ['kind' => 'application', 'grade' => 1, 'skill' => 'Spark']),
            $node('bolt', ['kind' => 'application', 'grade' => 2, 'skill' => 'Bolt']),
            $node('storm-call', ['kind' => 'advanced', 'skill' => 'Storm Call']),
        ]],
    ]], true) . ';');
    $issues = array_values(array_filter(new \Ichiloto\Editor\Validation\MasteryValidator()->validate(\Ichiloto\Editor\ProjectWorkspace::fromProject($root)),
        static fn($issue): bool => $issue->code === \Ichiloto\Editor\Validation\MasteryValidator::LADDER));

    expect(array_map(static fn($issue): string => strstr($issue->message, ' (', true), $issues))->toBe($flagged);
})->with([
    'rising' => [['storm' => ['cost' => 10, 'opensAtLevel' => 2], 'spark' => ['cost' => 20, 'opensAtLevel' => 3],
        'bolt' => ['cost' => 90, 'opensAtLevel' => 6], 'storm-call' => ['cost' => 1045, 'opensAtLevel' => 20]], []],
    'a grade cheaper than the grade below' => [['storm' => ['cost' => 10, 'opensAtLevel' => 2], 'spark' => ['cost' => 20, 'opensAtLevel' => 3],
        'bolt' => ['cost' => 15, 'opensAtLevel' => 6], 'storm-call' => ['cost' => 1045, 'opensAtLevel' => 20]], ['Bolt']],
    'an advanced application opening before the highest grade' => [['storm' => ['cost' => 10, 'opensAtLevel' => 2], 'spark' => ['cost' => 20, 'opensAtLevel' => 3],
        'bolt' => ['cost' => 90, 'opensAtLevel' => 6], 'storm-call' => ['cost' => 1045, 'opensAtLevel' => 5]], ['Storm Call']],
    'an unpriced rung is not compared' => [['storm' => [], 'spark' => ['cost' => 5, 'opensAtLevel' => 1], 'bolt' => [], 'storm-call' => ['cost' => 1, 'opensAtLevel' => 1]], []],
]);

it('reports what awaits an author: draft skills, unpriced nodes, ungranted foundations and terms that skip a chain step', function () {
    $root = masteryProject();
    file_put_contents($root . '/assets/Data/Skills/0099-tempest.php', '<?php return ' . var_export(['class' => \Ichiloto\Engine\Entities\Skills\Skill::class,
        'data' => ['kind' => 'magic', 'name' => 'Tempest', 'description' => '', 'icon' => '', 'cooldown' => 0, 'occasion' => 'Battle Screen',
            'scope' => ['side' => 'Enemy', 'number' => 'All', 'status' => 'Alive'], 'invocation' => ['speed' => 0, 'accuracy' => 0, 'repeat' => 1, 'apGain' => 0],
            'effects' => [['type' => 'hp_damage']], 'unassigned' => ['cost', 'effects.0.formula']]], true) . ';');
    file_put_contents($root . '/assets/Data/mastery.php', '<?php return ' . var_export(['regions' => [['id' => 'storms', 'name' => 'Storms', 'description' => 'Weather.']], 'branches' => [
        ['id' => 'storm', 'region' => 'storms', 'name' => 'Storm', 'kind' => 'aspect', 'description' => 'Storms.', 'nodes' => [
            ['id' => 'storm', 'kind' => 'foundation', 'name' => 'Storm'],
            ['id' => 'spark', 'kind' => 'application', 'grade' => 1, 'skill' => 'Spark', 'cost' => 40],
            ['id' => 'tempest', 'kind' => 'advanced', 'skill' => 'Tempest'],
        ]],
    ]], true) . ';');
    mkdir($root . '/assets/Data/Mastery');
    file_put_contents($root . '/assets/Data/Mastery/kaelion.php', "<?php\n\nreturn ['actor' => 'Kaelion', 'nodes' => [['id' => 'spark', 'cost' => 0]]];\n");
    $issues = new \Ichiloto\Editor\Validation\MasteryValidator()->validate(\Ichiloto\Editor\ProjectWorkspace::fromProject($root));
    $codes = array_map(static fn($issue): ?string => $issue->code, $issues);

    expect(array_filter($issues, static fn($issue): bool => $issue->severity === \Ichiloto\Editor\Validation\Severity::ERROR))->toBe([])
        ->and($codes)->toBe(['skill.unassigned', 'mastery.unpriced', 'mastery.foundation-ungranted', 'mastery.unchained'])
        ->and($issues[0]->message)->toContain('Tempest awaits author values (cost, effects.0.formula)')
        ->and($issues[1]->message)->toContain('Storm, Tempest')
        ->and($issues[3]->message)->toContain('Kaelion learns Spark without Storm');
});

it('warns where a discipline, its own trunk or a branch has no summary for Info, and edits the summaries', function () {
    $root = masteryProject();
    $validate = static fn(): array => array_values(array_filter(new \Ichiloto\Editor\Validation\MasteryValidator()
        ->validate(\Ichiloto\Editor\ProjectWorkspace::fromProject($root)), static fn($issue): bool => $issue->code === 'mastery.summary-missing'));

    expect(array_map(static fn($issue): string => $issue->message, $validate()))->toBe([
        'Blades has no summary: Info offers nothing on it.', 'The Blades trunk has no summary: Info offers nothing on the family its own nodes form.',
        'Storms has no summary: Info offers nothing on it.', 'The Storms trunk has no summary: Info offers nothing on the family its own nodes form.',
    ])->and($validate()[0]->severity)->toBe(\Ichiloto\Editor\Validation\Severity::WARNING);

    $session = EditorSession::open($root);
    foreach ([0, 1] as $index) {
        foreach (['description' => 'What it covers.', 'trunkDescription' => 'What its own skills cover.'] as $field => $text) {
            $session->applyDatabaseRecord(MasteryFields::REGIONS, $index, masteryRow($session->readDatabaseRecord(MasteryFields::REGIONS, $index), $field)['key'], $text);
        }
    }
    $session->saveDatabase(MasteryFields::REGIONS);
    MasteryCatalog::forgetProjectCatalogs();

    expect($validate())->toBe([])
        ->and(MasteryCatalog::load($root . '/assets')->tree->regions[1]->trunkDescription)->toBe('What its own skills cover.');
});

it('moves a branch within another branch of its region as a sub-family, keeping its node ids, and refuses one within another region', function () {
    $root = masteryProject();
    writeSkillRecords($root, new SpecialSkill('Cut', 'Cuts.', '', 2, 0), new MagicSkill('Spark', 'Shocks.', '', 4, 0),
        new MagicSkill('Haste', 'Hurries.', '', 6, 0));
    $settings = $root . '/assets/Data/mastery.php';
    // An authored source with a comment the move must keep.
    file_put_contents($settings, str_replace("];\n", "  'branches' => [\n    // Odd forces.\n    ['id' => 'odd', 'region' => 'storms', 'name' => 'Odd', 'kind' => 'non-elemental', 'nodes' => []],\n"
        . "    ['id' => 'hours', 'region' => 'blades', 'name' => 'Hours', 'kind' => 'aspect', 'nodes' => [\n      ['id' => 'haste', 'kind' => 'application', 'skill' => 'Haste'],\n    ]],\n  ],\n];\n",
        file_get_contents($settings)));
    $session = EditorSession::open($root);
    $branch = static fn(): array => $session->readDatabaseRecord(MasteryFields::BRANCHES, 1);
    $choices = array_column($session->listReferences(null, MasteryFields::BRANCHES), 'value');

    // Within a branch of another region, the Engine could not read the tree.
    $session->applyDatabaseRecord(MasteryFields::BRANCHES, 1, masteryRow($branch(), 'within')['key'], 'odd');
    expect($choices)->toBe(['odd', 'hours'])
        ->and(fn() => $session->saveDatabase(MasteryFields::BRANCHES))->toThrow(SessionRefusal::class, 'in another region');

    $session->applyDatabaseRecord(MasteryFields::BRANCHES, 1, masteryRow($branch(), 'region')['key'], 'storms');
    $session->saveDatabase(MasteryFields::BRANCHES);
    $tree = MasteryCatalog::load($root . '/assets')->tree;
    $board = new \Ichiloto\Engine\Progression\Mastery\MasteryBoard($tree);

    expect($tree->branches['hours']->within)->toBe('odd')
        ->and($tree->nodes['haste']->region)->toBe('storms')
        ->and(array_map(static fn($family): string => $family->id, $board->getFamilies('storms')))->toBe(['odd', 'trunk:storms'])
        ->and($board->findFamily('odd')->nodes)->toBe(['haste'])
        ->and(file_get_contents($settings))->toContain('// Odd forces.')->toContain("'within' => 'odd'");
});
