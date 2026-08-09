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

it('passes a project with nothing wrong with it', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);

    expect(validateProject($root))->toBe([]);
});

it('finds the dangling references in the shipped sample project', function () {
    // The fixture's quests came from a bigger project: they ask for a map, an
    // item, and an enemy it does not have. Worth asserting, because it is
    // exactly the kind of drift the check exists to catch.
    $issues = validateProject(fixturePath('sample-project'));

    expect(issuesMentioning($issues, 'happyville/town-center'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'S-Mana'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'Sewer Rat'))->toHaveCount(1);
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
