<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Editor\Validation\Severity;

/**
 * Runs the validator over a project.
 *
 * @param string $root The project root.
 * @return Issue[] The issues found.
 */
function validateProject(string $root): array
{
    return new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));
}

/**
 * Returns the issues whose message contains the given text.
 *
 * @param Issue[] $issues The issues.
 * @param string $text The text to look for.
 * @return Issue[] The matching issues.
 */
function issuesMentioning(array $issues, string $text): array
{
    return array_values(array_filter(
        $issues,
        static fn(Issue $issue): bool => str_contains($issue->message, $text)
    ));
}

/**
 * Rewrites a fixture map's data file.
 *
 * @param string $root The project root.
 * @param callable $edit Given the file's source, returns the new source.
 * @return void
 */
function editTestMapData(string $root, callable $edit): void
{
    $path = $root . '/assets/Maps/test-map/test-map.data.php';

    file_put_contents($path, $edit(file_get_contents($path)));
}

/**
 * Rewrites the temporary project's quests so they only ask for things it has.
 *
 * The shared fixture's quests were lifted from a bigger project and reference
 * its maps and items, which is itself a real finding rather than a reason not
 * to have a clean baseline.
 *
 * @param string $root The project root.
 * @return void
 */
function writeConsistentQuests(string $root): void
{
    file_put_contents($root . '/assets/Data/quests.php', <<<'PHP'
    <?php

    return [
      [
        'id' => 'errand',
        'name' => 'An Errand',
        'objectives' => [
          ['type' => 'reach_map', 'target' => 'test-map'],
          ['type' => 'collect', 'target' => 'S-Potion'],
          ['type' => 'defeat', 'target' => 'Lone Rat'],
        ],
      ],
    ];
    PHP);
}

/**
 * Rewrites the temporary project's skit so it only names things it has.
 *
 * Same story as the quests: the fixture's skit plays on a map from a bigger
 * project and waits on one of its quests.
 *
 * @param string $root The project root.
 * @return void
 */
function writeConsistentSkits(string $root): void
{
    file_put_contents($root . '/assets/Data/Skits/breakfast-banter.php', <<<'PHP'
    <?php

    return [
      'id' => 'breakfast-banter',
      'title' => 'Breakfast Banter',
      'where' => 'test-map',
      'beats' => [
        ['speaker' => 'Liora', 'text' => 'An errand for your mom? Really?'],
      ],
    ];
    PHP);
}

/** Writes a save compatibility manifest into a disposable project. */
function writeSaveCompatibilityManifest(string $root, string $source): void
{
    file_put_contents($root . '/assets/Data/save-compatibility.php', $source);
}

it('passes a project with nothing wrong with it', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);
    writeConsistentSkits($root);

    expect(validateProject($root))->toBe([]);
});

it('finds the dangling references in the shipped sample project', function () {
    // The fixture's quests came from a bigger project: they ask for a map, an
    // item, and an enemy it does not have. Worth asserting, because it is
    // exactly the kind of drift the check exists to catch.
    $issues = validateProject(fixturePath('sample-project'));

    // The map is named twice: a quest objective goes there, and the skit
    // plays there.
    expect(issuesMentioning($issues, 'happyville/town-center'))->toHaveCount(2)
        ->and(issuesMentioning($issues, 'S-Mana'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'Sewer Rat'))->toHaveCount(1)
        // The skit's own condition waits on a quest the fixture does define,
        // and a reference that resolves is not a finding.
        ->and(issuesMentioning($issues, 'breakfast-duty'))->toHaveCount(0);
});

it('catches a skit waiting on a quest that does not exist', function () {
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Data/Skits/breakfast-banter.php', <<<'PHP'
    <?php

    return [
      'id' => 'breakfast-banter',
      'title' => 'Breakfast Banter',
      'where' => 'test-map',
      'conditions' => [
        ['type' => 'quest', 'name' => 'no-such-quest', 'status' => 'active'],
        ['type' => 'switch', 'name' => 'kitchen_visited'],
      ],
      'beats' => [
        ['speaker' => 'Liora', 'text' => 'An errand for your mom? Really?'],
      ],
    ];
    PHP);

    $issues = issuesMentioning(validateProject($root), 'no-such-quest');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Severity::ERROR)
        // The skit simply never plays, which is why it is worth saying.
        ->and($issues[0]->where)->toBe('skit breakfast-banter')
        // A switch is a name the author invents; nothing declares it.
        ->and(issuesMentioning(validateProject($root), 'kitchen_visited'))->toBe([]);
});

it('reports an unknown condition type with its content context', function () {
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Data/Skits/breakfast-banter.php', <<<'PHP'
    <?php

    return [
      'id' => 'breakfast-banter',
      'title' => 'Breakfast Banter',
      'where' => 'test-map',
      'conditions' => [
        ['type' => 'phase_of_moon', 'name' => 'full'],
      ],
      'beats' => [
        ['speaker' => 'Liora', 'text' => 'This must remain guarded.'],
      ],
    ];
    PHP);

    $issues = issuesMentioning(validateProject($root), 'unknown condition type "phase_of_moon"');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Severity::ERROR)
        ->and($issues[0]->where)->toBe('skit breakfast-banter')
        ->and($issues[0]->hint)->toContain('inaccessible');
});

it('validates conditions in manually authored achievements', function () {
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Data/achievements.php', <<<'PHP'
    <?php

    return [
      [
        'id' => 'unsafe-achievement',
        'name' => 'Unsafe Achievement',
        'conditions' => [
          ['type' => 'obsolete_flag', 'name' => 'old-condition'],
        ],
      ],
    ];
    PHP);

    $issues = issuesMentioning(validateProject($root), 'unknown condition type "obsolete_flag"');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Severity::ERROR)
        ->and($issues[0]->where)->toBe('achievement unsafe-achievement');
});

it('catches a script command naming something the project does not have', function () {
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Events/errand.php', <<<'PHP'
    <?php

    return [
      ['type' => 'play_music', 'music' => 'no-such-track'],
      ['type' => 'choice', 'prompt' => 'Well?', 'options' => [
        ['text' => 'Take it', 'then' => [
          ['type' => 'give_item', 'item' => 'No-Such-Potion'],
          ['type' => 'branch', 'conditions' => [['type' => 'item', 'name' => 'Nothing At All']], 'then' => [
            ['type' => 'start_battle', 'troop' => 'No-Such-Troop'],
          ]],
        ]],
      ]],
    ];
    PHP);

    $issues = validateProject($root);

    // Every one of these is nested a level deeper than the last, and a
    // command that quietly does nothing is the hardest kind of bug to see.
    expect(issuesMentioning($issues, 'No-Such-Potion'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'Nothing At All'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'No-Such-Troop'))->toHaveCount(1)
        // A missing track is quieter than that: a warning, not an error.
        ->and(issuesMentioning($issues, 'no-such-track')[0]->severity)->toBe(Severity::WARNING);
});

it('catches a marker placed on a map that defines no such event', function () {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Maps/test-map/test-map.event.php';
    $source = file_get_contents($path);

    // Drop a stray marker onto the layer, the way a mis-click would.
    file_put_contents($path, preg_replace('/\n( +)\n/', "\nZ\n", $source, 1));

    $issues = issuesMentioning(validateProject($root), 'places marker "Z"');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Severity::ERROR)
        // This is the crash the engine throws on load, found before running it.
        ->and($issues[0]->hint)->toContain('Unmapped event markers');
});

it('catches an event defined but never placed', function () {
    $root = makeTemporaryProject();

    editTestMapData($root, static fn(string $source): string => str_replace(
        "'events' => [",
        "'events' => [\n    'Q' => ['class' => 'Ichiloto\\\\Engine\\\\Events\\\\Triggers\\\\DialogueEventTrigger', 'data' => []],",
        $source
    ));

    $issues = issuesMentioning(validateProject($root), 'Marker "Q" is defined but never placed');

    // Harmless, but it is dead content: nothing can ever trigger it.
    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Severity::WARNING);
});

it('catches a door that leads nowhere', function () {
    $root = makeTemporaryProject();

    editTestMapData($root, static fn(string $source): string => str_replace(
        "'events' => [",
        "'events' => [\n    'A' => ['class' => 'Ichiloto\\\\Engine\\\\Events\\\\Triggers\\\\TransferPlayerTrigger', 'data' => ['destinationMap' => 'happyville/nowhere']],",
        $source
    ));

    $issues = issuesMentioning(validateProject($root), 'happyville/nowhere');

    expect($issues[0]->severity)->toBe(Severity::ERROR)
        ->and($issues[0]->hint)->toContain('crashes the game');
});

it('catches encounters the engine cannot read', function () {
    $root = makeTemporaryProject();

    // The shape a project drifts into: a list of troops rather than weights.
    editTestMapData($root, static fn(string $source): string => str_replace(
        "return [",
        "return [\n  'encounters' => [['troop' => 'Slimes', 'rate' => 0.1]],",
        $source
    ));

    $issues = issuesMentioning(validateProject($root), 'names no troops the engine can read');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Severity::ERROR)
        ->and($issues[0]->hint)->toContain("'rate' => steps");
});

it('catches encounters naming a troop the project does not have', function () {
    $root = makeTemporaryProject();

    editTestMapData($root, static fn(string $source): string => str_replace(
        "return [",
        "return [\n  'encounters' => ['troops' => ['Wyverns' => 3], 'rate' => 20],",
        $source
    ));

    expect(issuesMentioning(validateProject($root), '"Wyverns"'))->toHaveCount(1);
});

it('catches a block written twice in the same file', function () {
    $root = makeTemporaryProject();

    editTestMapData($root, static fn(string $source): string => str_replace(
        "return [",
        "return [\n  'encounters' => ['troops' => ['Slimes' => 3], 'rate' => 20],\n  'encounters' => [],",
        $source
    ));

    $issues = issuesMentioning(validateProject($root), '"encounters" is declared 2 times');

    // PHP keeps the last one and says nothing, which is how a project ends up
    // with encounters that never fire.
    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Severity::ERROR);
});

it('does not mistake a repeated value for a repeated key', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);

    // Two different keys that happen to share a value.
    editTestMapData($root, static fn(string $source): string => str_replace(
        "return [",
        "return [\n  'bgm' => 'Echo',\n  'weather' => 'Echo',",
        $source
    ));

    expect(issuesMentioning(validateProject($root), 'declared 2 times'))->toBe([]);
});

it('catches a quest asking for something the project does not have', function () {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/quests.php';

    file_put_contents($path, <<<'PHP'
    <?php

    return [
      [
        'id' => 'ghost-hunt',
        'name' => 'Ghost Hunt',
        'objectives' => [
          ['type' => 'collect', 'target' => 'Spectral Lantern'],
          ['type' => 'reach_map', 'target' => 'nowhere/at-all'],
        ],
      ],
    ];
    PHP);

    $issues = validateProject($root);

    expect(issuesMentioning($issues, 'Spectral Lantern'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'nowhere/at-all'))->toHaveCount(1);
});

it('catches a quest waiting on a quest that does not exist', function () {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/quests.php';

    file_put_contents($path, <<<'PHP'
    <?php

    return [
      [
        'id' => 'second',
        'name' => 'Second',
        'prerequisites' => [['type' => 'quest', 'name' => 'first', 'status' => 'completed']],
        'objectives' => [['type' => 'talk_to', 'target' => 'Mom']],
      ],
    ];
    PHP);

    $issues = issuesMentioning(validateProject($root), 'waits on the quest "first"');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->hint)->toContain('can never be accepted');
});

it('sorts what will crash the game above what is merely dead', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);

    editTestMapData($root, static fn(string $source): string => str_replace(
        "'events' => [",
        "'events' => [\n    'Q' => ['class' => 'Ichiloto\\\\Engine\\\\Events\\\\Triggers\\\\TransferPlayerTrigger', 'data' => ['destinationMap' => 'nowhere']],",
        $source
    ));

    $issues = validateProject($root);

    expect($issues[0]->severity)->toBe(Severity::ERROR);
});

it('accepts a complete sequential compatibility manifest with catalog-backed targets', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);
    writeConsistentSkits($root);
    writeSaveCompatibilityManifest($root, <<<'PHP'
    <?php

    return [
      'contentVersion' => 1,
      'migrations' => [
        ['from' => 0, 'to' => 1, 'class' => 'FixtureContentVersion0To1'],
      ],
      'aliases' => [
        'maps' => [['from' => 'old-map', 'to' => 'test-map']],
        'quests' => [['from' => 'old-errand', 'to' => 'errand']],
        'states' => [['from' => 'old-poison', 'to' => 'poison']],
      ],
      'tombstones' => [],
    ];
    PHP);

    expect(validateProject($root))->toBe([]);
});

it('reports invalid and negative content versions plus missing migration steps', function () {
    $root = makeTemporaryProject();
    writeSaveCompatibilityManifest($root, <<<'PHP'
    <?php

    return [
      'contentVersion' => '2',
      'migrations' => [],
      'aliases' => [],
      'tombstones' => [],
    ];
    PHP);

    expect(issuesMentioning(validateProject($root), 'contentVersion must be a non-negative integer'))->toHaveCount(1);

    writeSaveCompatibilityManifest($root, <<<'PHP'
    <?php

    return [
      'contentVersion' => 2,
      'migrations' => [
        ['from' => 0, 'to' => 1, 'class' => 'FirstMigration'],
      ],
      'aliases' => [],
      'tombstones' => [],
    ];
    PHP);

    expect(issuesMentioning(validateProject($root), 'Content migration step 1 to 2 is missing'))->toHaveCount(1);

    writeSaveCompatibilityManifest($root, <<<'PHP'
    <?php

    return [
      'contentVersion' => -1,
      'migrations' => [],
      'aliases' => [],
      'tombstones' => [],
    ];
    PHP);

    expect(issuesMentioning(validateProject($root), 'contentVersion must be a non-negative integer'))->toHaveCount(1);
});

it('detects alias cycles self aliases contradictions and tombstone conflicts', function () {
    $root = makeTemporaryProject();
    writeSaveCompatibilityManifest($root, <<<'PHP'
    <?php

    return [
      'contentVersion' => 0,
      'migrations' => [],
      'aliases' => [
        'maps' => [
          ['from' => 'a', 'to' => 'b'],
          ['from' => 'b', 'to' => 'a'],
          ['from' => 'same', 'to' => 'same'],
          ['from' => 'old', 'to' => 'test-map'],
          ['from' => 'old', 'to' => 'missing-map'],
        ],
      ],
      'tombstones' => [
        'maps' => ['old'],
      ],
    ];
    PHP);

    $issues = validateProject($root);

    expect(issuesMentioning($issues, 'contains a cycle'))->not->toBe([])
        ->and(issuesMentioning($issues, 'is a self-alias'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'mapped to both'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'both an alias source and an incompatible tombstone'))->toHaveCount(1);
});

it('detects invalid categories one-shot syntax and missing catalog targets', function () {
    $root = makeTemporaryProject();
    writeSaveCompatibilityManifest($root, <<<'PHP'
    <?php

    return [
      'contentVersion' => 0,
      'migrations' => [],
      'aliases' => [
        'unknown_kind' => [['from' => 'old', 'to' => 'new']],
        'maps' => [['from' => 'old-map', 'to' => 'no-such-map']],
        'one_shot_events' => [['from' => 'bad-event', 'to' => 'test-map:A']],
      ],
      'tombstones' => [],
    ];
    PHP);

    $issues = validateProject($root);

    expect(issuesMentioning($issues, 'not a saved-content category'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'exact mapId:marker syntax'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'Alias target "no-such-map" is not defined'))->toHaveCount(1);
});

it('detects duplicate migration steps and impossible registration order', function () {
    $root = makeTemporaryProject();
    writeSaveCompatibilityManifest($root, <<<'PHP'
    <?php

    return [
      'contentVersion' => 3,
      'migrations' => [
        ['from' => 1, 'to' => 2, 'class' => 'SecondMigration'],
        ['from' => 0, 'to' => 1, 'class' => 'FirstMigration'],
        ['from' => 0, 'to' => 1, 'class' => 'DuplicateFirstMigration'],
      ],
      'aliases' => [],
      'tombstones' => [],
    ];
    PHP);

    $issues = validateProject($root);

    expect(issuesMentioning($issues, 'registered more than once'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'impossible order'))->toHaveCount(2)
        ->and(issuesMentioning($issues, 'Content migration step 2 to 3 is missing'))->toHaveCount(1);
});
