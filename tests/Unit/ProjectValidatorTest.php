<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Editor\Validation\Severity;

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

/**
 * Defines the enemies the temporary project's troops field.
 *
 * Same story again: the fixture's troops name a bigger project's enemies,
 * and a troop whose enemy is not defined cannot load.
 *
 * @param string $root The project root.
 * @return void
 */
function writeConsistentEnemies(string $root): void
{
    is_dir($root . '/assets/Data/Enemies') || mkdir($root . '/assets/Data/Enemies', 0777, true);

    foreach (['regular-bat' => 'Regular Bat', 'sewer-rat' => 'Sewer Rat'] as $file => $name) {
        file_put_contents($root . '/assets/Data/Enemies/' . $file . '.php', <<<PHP
        <?php

        return [
          'class' => \\Ichiloto\\Engine\\Entities\\Enemies\\Enemy::class,
          'data' => [
            'name' => '{$name}',
            'level' => 1,
            'imagePath' => '{$file}',
            'stats' => ['maxHp' => 10, 'maxMp' => 0, 'attack' => 3, 'defence' => 2, 'magicAttack' => 1, 'magicDefence' => 1, 'speed' => 4, 'grace' => 1, 'evasion' => 0],
            'rewards' => ['experience' => 1, 'gold' => 1],
          ],
        ];

        PHP);
    }
}

/** Writes a save compatibility manifest into a disposable project. */
function writeSaveCompatibilityManifest(string $root, string $source): void
{
    file_put_contents($root . '/assets/Data/save-compatibility.php', $source);
}

/** Writes a plain summon definition and its required timeline fixture. */
function writeValidatorSummon(string $root, string $id, array $data): void
{
    $directory = $root . '/assets/Cutscenes/Summons/' . $id;
    mkdir($directory, 0777, true);
    file_put_contents($directory . '/' . $id . '.data.php', "<?php\n\nreturn " . var_export($data, true) . ";\n");
    file_put_contents(
        $directory . '/' . $id . '.timeline.php',
        "<?php\n\nreturn ['fps' => 12, 'lengthFrames' => 1, 'tracks' => [], 'cues' => []];\n",
    );
}

/** Writes an actor with an explicit class name and starting summon ids. */
function writeValidatorActor(string $root, string $fileId, string $name, string $className, mixed $summons): void
{
    $data = [
        'class' => 'Ichiloto\\Engine\\Entities\\Character',
        'data' => [
            'name' => $name,
            'class' => $className,
            'currentExp' => 0,
            'stats' => ['currentHp' => 100, 'currentMp' => 20, 'currentAp' => 10],
            'summons' => $summons,
        ],
    ];
    file_put_contents(
        $root . '/assets/Data/Actors/' . $fileId . '.php',
        "<?php\n\nreturn " . var_export($data, true) . ";\n",
    );
}

it('passes a project with nothing wrong with it', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);
    writeConsistentSkits($root);
    writeConsistentEnemies($root);

    expect(validateProject($root))->toBe([]);
});

it('accepts conditional event cues from the shared runtime contract', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);
    writeConsistentSkits($root);
    writeConsistentEnemies($root);
    editTestMapData(
        $root,
        static fn(string $source): string => str_replace(
            "'data' => [\n        'lootType' => 'item',",
            "'cue' => [\n        'symbol' => '!',\n        'color' => 'bright-yellow',\n        'conditions' => [['type' => 'event', 'name' => 'response_ready']],\n      ],\n      'data' => [\n        'lootType' => 'item',",
            $source,
        ),
    );

    expect(validateProject($root))->toBe([]);
});

it('validates conditional event cues with the shared condition vocabulary', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);
    writeConsistentSkits($root);
    writeConsistentEnemies($root);
    editTestMapData(
        $root,
        static fn(string $source): string => str_replace(
            "'data' => [\n        'lootType' => 'item',",
            "'cue' => [\n        'symbol' => '!',\n        'conditions' => [['type' => 'weather_magic', 'name' => 'storm']],\n      ],\n      'data' => [\n        'lootType' => 'item',",
            $source,
        ),
    );

    $issues = issuesMentioning(validateProject($root), 'unknown condition type "weather_magic"');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->where)->toBe('test-map event E cue');
});

it('finds the dangling references in the shipped sample project', function () {
    // The fixture's quests came from a bigger project: they ask for a map, an
    // item, and an enemy it does not have. Worth asserting, because it is
    // exactly the kind of drift the check exists to catch.
    $issues = validateProject(fixturePath('sample-project'));

    // The map is named twice: a quest objective goes there, and the skit
    // plays there. The rat is named twice too: a quest objective defeats it,
    // and a troop fields it, as another troop fields the bat.
    expect(issuesMentioning($issues, 'happyville/town-center'))->toHaveCount(2)
        ->and(issuesMentioning($issues, 'S-Mana'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'Sewer Rat'))->toHaveCount(2)
        ->and(issuesMentioning($issues, 'Regular Bat'))->toHaveCount(2)
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

it('validates generic summon availability, policies, identities, and linked actions', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);
    writeConsistentSkits($root);
    writeConsistentEnemies($root);
    writeValidatorSummon($root, 'unsafe-summon', [
        'id' => 'unsafe-summon',
        'name' => 'Unsafe Summon',
        'linkedActionId' => 'Missing Action',
        'availability' => [
            'conditions' => [['type' => 'lunar_phase', 'name' => 'full']],
        ],
        'wielders' => [
            'mode' => 'characters',
            'characters' => ['No Such Actor'],
            'tenancy' => 'forever',
        ],
    ]);

    $issues = validateProject($root);

    expect(issuesMentioning($issues, 'unknown condition type "lunar_phase"'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'invalid tenancy "forever"'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'eligible character "No Such Actor"'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'links to action "Missing Action"'))->toHaveCount(1);
});

it('rejects invalid summon starting assignments and duplicate exclusive holders', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);
    writeConsistentSkits($root);
    writeConsistentEnemies($root);
    writeValidatorSummon($root, 'bound-summon', [
        'id' => 'bound-summon',
        'name' => 'Bound Summon',
        'linkedActionId' => 'Missing Action',
        'availability' => ['conditions' => [['type' => 'event', 'name' => 'summon_unlocked']]],
        'wielders' => [
            'mode' => 'characters',
            'characters' => ['Kaelion'],
            'tenancy' => 'exclusive',
        ],
    ]);
    writeValidatorActor($root, 'Kaelion', 'Kaelion', 'Vanguard', ['bound-summon', 'missing-summon']);
    writeValidatorActor($root, 'Other', 'Other', 'Vanguard', ['bound-summon']);

    $issues = validateProject($root);

    expect(issuesMentioning($issues, 'starts with story-locked summon "bound-summon"'))->toHaveCount(2)
        ->and(issuesMentioning($issues, 'references missing summon "missing-summon"'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'not eligible to hold summon "bound-summon"'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'Exclusive starting ownership is duplicated'))->toHaveCount(1);
});

it('reports malformed availability and actor assignment payloads', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);
    writeConsistentSkits($root);
    writeConsistentEnemies($root);
    writeValidatorSummon($root, 'malformed-summon', [
        'id' => 'malformed-summon',
        'name' => 'Malformed Summon',
        'linkedActionId' => 'Missing Action',
        'availability' => 'always',
        'wielders' => ['mode' => 'all', 'tenancy' => 'exclusive'],
    ]);
    writeValidatorActor($root, 'Kaelion', 'Kaelion', 'Vanguard', 'malformed-summon');

    $issues = validateProject($root);

    expect(issuesMentioning($issues, 'availability block is malformed'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'summon assignments are malformed'))->toHaveCount(1);
});

it('reports malformed declared summon policy and condition fields without coercing them', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);
    writeConsistentSkits($root);
    writeConsistentEnemies($root);
    writeValidatorSummon($root, 'malformed-fields', [
        'id' => 'malformed-fields',
        'name' => 'Malformed Fields',
        'linkedActionId' => 'Missing Action',
        'availability' => ['conditions' => [['type' => ['event'], 'name' => ['unlock']]]],
        'wielders' => ['mode' => ['all'], 'tenancy' => ['shared']],
    ]);

    $issues = validateProject($root);

    expect(issuesMentioning($issues, 'malformed type'))->not->toBe([])
        ->and(issuesMentioning($issues, 'has no name'))->not->toBe([])
        ->and(issuesMentioning($issues, 'invalid wielder mode'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'invalid tenancy'))->toHaveCount(1);
});

it('matches actor summon assignments to definition ids case-insensitively like runtime', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);
    writeConsistentSkits($root);
    writeConsistentEnemies($root);
    writeValidatorSummon($root, 'signature-summon', [
        'id' => 'Signature-Summon',
        'name' => 'Signature Summon',
        'linkedActionId' => 'Missing Action',
        'wielders' => ['mode' => 'characters', 'characters' => ['One'], 'tenancy' => 'exclusive'],
    ]);
    writeValidatorActor($root, 'One', 'One', 'Hero', ['signature-summon']);

    expect(issuesMentioning(validateProject($root), 'references missing summon'))->toBe([]);
});

it('rejects non-list and duplicate actor summon assignments', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);
    writeConsistentSkits($root);
    writeConsistentEnemies($root);
    writeValidatorSummon($root, 'shared-summon', [
        'id' => 'shared-summon',
        'name' => 'Shared Summon',
        'linkedActionId' => 'Missing Action',
        'wielders' => ['mode' => 'all', 'tenancy' => 'shared'],
    ]);
    writeValidatorActor($root, 'One', 'One', 'Hero', ['shared-summon', 'shared-summon']);
    writeValidatorActor($root, 'Two', 'Two', 'Hero', ['primary' => 'shared-summon']);

    $issues = validateProject($root);

    expect(issuesMentioning($issues, 'summon assignments contain duplicate ids'))->toHaveCount(1)
        ->and(issuesMentioning($issues, 'summon assignments are malformed'))->toHaveCount(1);
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

it('accepts a marker painted in separate places, as the runtime triggers its exact cells', function () {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Maps/test-map/test-map.event.php';

    file_put_contents($path, "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n A  \nAAA \n    \n    \nICHILOTO_EVENT_MAP;\n");

    editTestMapData($root, static fn(string $source): string => str_replace(
        "'events' => [",
        "'events' => [\n    'A' => ['class' => 'Ichiloto\\\\Engine\\\\Events\\\\Triggers\\\\DialogueEventTrigger', 'data' => []],",
        $source
    ));

    $issues = validateProject($root);

    expect(issuesMentioning($issues, 'rectangle'))->toBe([])
        ->and(issuesMentioning($issues, 'could not be read'))->toBe([]);
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
    writeConsistentEnemies($root);
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

it('warns that a tiles2d crop table is no longer read', function () {
    $root = makeTemporaryProject();
    editTestMapData($root, static fn(string $source): string => str_replace(
        "'triggers' => [],",
        "'triggers' => [],\n  'tiles2d' => ['asset' => 'Graphics/Tilesets/shared.png', 'symbols' => []],",
        $source,
    ));

    $issues = issuesMentioning(validateProject($root), 'tiles2d');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Severity::WARNING)
        ->and($issues[0]->message)->toBe('Its tiles2d crop table is no longer read.');
});

/** Writes a catalogue of every kind of skill, one record each. */
function writeSpreadSkillCatalog(string $root, Ichiloto\Engine\Entities\Skills\Skill ...$extra): void
{
    writeSkillRecords(
        $root,
        new Ichiloto\Engine\Entities\Skills\BasicSkill('Attack', 'Strikes.', '', 0, 0),
        new Ichiloto\Engine\Entities\Skills\MagicSkill('Cleanse', 'Lifts a poison.', '', 3, 0),
        new Ichiloto\Engine\Entities\Skills\SpecialSkill('Ward', 'Guards.', '', 2, 0),
        new Ichiloto\Engine\Entities\Skills\MagicSkill('Burn I', 'Burns.', '', 5, 0),
        ...$extra,
    );
}

it('checks ability and spell alias targets against their own kind across every skill file', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);
    writeConsistentSkits($root);
    writeConsistentEnemies($root);
    writeSpreadSkillCatalog($root);
    writeSaveCompatibilityManifest($root, <<<'PHP'
    <?php

    return [
      'contentVersion' => 0,
      'migrations' => [],
      'aliases' => [
        'spells' => [
          ['from' => 'Fire', 'to' => 'Burn I'],
          ['from' => 'Purify', 'to' => 'Cleanse'],
          ['from' => 'Guard', 'to' => 'Ward'],
        ],
        'abilities' => [
          ['from' => 'Shield', 'to' => 'Ward'],
          ['from' => 'Blaze', 'to' => 'Burn I'],
        ],
      ],
      'tombstones' => [],
    ];
    PHP);

    $missing = array_map(
        static fn(Issue $issue): string => $issue->message,
        issuesMentioning(validateProject($root), 'is not defined in the current'),
    );

    expect($missing)->toEqualCanonicalizing([
        'Alias target "Ward" is not defined in the current spells catalog.',
        'Alias target "Burn I" is not defined in the current abilities catalog.',
    ]);
});

it('reports a skill name the catalogue defines twice', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);
    writeConsistentSkits($root);
    writeConsistentEnemies($root);
    writeSpreadSkillCatalog($root, new Ichiloto\Engine\Entities\Skills\MagicSkill('Cleanse', 'Again.', '', 3, 0));

    $issues = issuesMentioning(validateProject($root), '"Cleanse" is already defined by Skills/0002-cleanse.php');

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Severity::ERROR);
});

it('offers skills from every skill file as references', function () {
    $root = makeTemporaryProject();
    writeSpreadSkillCatalog($root);

    expect(ProjectWorkspace::fromProject($root)->getSkillNames())->toBe(['Attack', 'Cleanse', 'Ward', 'Burn I']);
});

/** Writes a transitions catalogue whose one treatment draws the given image. */
function writeTransitionCatalog(string $root, string $asset, string $battle = 'sweep'): void
{
    @mkdir($root . '/assets/Data/Presentation', 0777, true);
    file_put_contents($root . '/assets/Data/Presentation/transitions.php', <<<PHP
    <?php
    use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
    use Ichiloto\Engine\Rendering\ScreenTransitionCatalog;
    use Ichiloto\Engine\Rendering\ScreenTransitionTreatment;

    \$bounds = ['x' => 0, 'y' => 0, 'width' => 160, 'height' => 90];
    \$brush = ['type' => 'solid', 'color' => PresentationColor::rgb(0, 0, 0)->toArray()];
    \$sweep = new ScreenTransitionTreatment(['id' => 'sweep', 'width' => 160, 'height' => 90,
      'timings' => ['gather' => 0, 'cover' => 100, 'hold' => 0, 'reveal' => 100],
      'coverBrush' => \$brush, 'phases' => [
        'gather' => [],
        'cover' => [['operation' => ['type' => 'image', 'asset' => '$asset', 'destination' => \$bounds]]],
        'reveal' => [['operation' => ['type' => 'fill', 'destination' => \$bounds, 'brush' => \$brush]]],
      ]]);

    return new ScreenTransitionCatalog(['sweep' => \$sweep], battle: '$battle');
    PHP);
}

it('accepts transitions the Engine can play', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);
    writeConsistentSkits($root);
    writeConsistentEnemies($root);
    @mkdir($root . '/assets/Graphics/System', 0777, true);
    $image = imagecreatetruecolor(4, 4);
    imagepng($image, $root . '/assets/Graphics/System/Sweep.png');
    writeTransitionCatalog($root, 'Graphics/System/Sweep.png');

    expect(validateProject($root))->toBe([]);
});

it('reports transitions the Engine refuses, naming the direct cut it falls back to', function (string $asset, string $battle, ?string $source, string $expected) {
    $root = makeTemporaryProject();
    writeTransitionCatalog($root, $asset, $battle);

    if ($source !== null) {
        file_put_contents($root . '/assets/Data/Presentation/transitions.php', $source);
    }

    $issues = array_values(array_filter(
        validateProject($root),
        static fn(Issue $issue): bool => $issue->where === 'assets/Data/Presentation/transitions.php',
    ));

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Severity::ERROR)
        ->and($issues[0]->message)->toContain($expected)
        ->and($issues[0]->hint)->toContain('direct cut');
})->with([
    'a missing image' => ['Graphics/System/Missing.png', 'sweep', null, 'Transition sweep:'],
    'an unknown battle choice' => ['Graphics/System/Missing.png', 'gilded', null, 'Unknown battle transition treatment.'],
    'the wrong return value' => ['Graphics/System/Missing.png', 'sweep', "<?php\nreturn [];\n", 'must return a ScreenTransitionCatalog'],
]);

/** Gives the disposable project what the game needs to walk its maps. */
function writeReachableProject(string $root, string $grid, string $events, array $startAt = [1, 2]): void
{
    file_put_contents($root . '/assets/Maps/collisions.php', "<?php\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\n"
        . "return ['#' => CollisionType::SOLID, ' ' => CollisionType::NONE, '~' => CollisionType::NONE];\n");
    file_put_contents($root . '/assets/Data/system.php', '<?php return ' . var_export(['startingPositions' => ['player' => [
        'destinationMap' => 'test-map', 'spawnPoint' => ['x' => $startAt[0], 'y' => $startAt[1]], 'spawnSprite' => ['South'],
    ]]], true) . ';');
    file_put_contents($root . '/assets/Maps/test-map/test-map.map.php', "<?php\n\nreturn <<<'ICHILOTO_MAP'\n{$grid}\nICHILOTO_MAP;\n");
    file_put_contents($root . '/assets/Maps/test-map/test-map.event.php', "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n{$events}\nICHILOTO_EVENT_MAP;\n");
}

/** @return list<string> */
function reachabilityIssueLines(string $root): array
{
    return array_map(
        static fn(Issue $issue): string => $issue->severity->value . ': ' . $issue->where . ': ' . $issue->message,
        array_values(array_filter(validateProject($root), static fn(Issue $issue): bool =>
            str_contains($issue->message, 'reach') || str_contains($issue->message, 'arrives')
            || str_contains($issue->message, 'brings them onto'))),
    );
}

it('reports an event the player can never reach, not where content stands', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);
    writeConsistentSkits($root);
    writeConsistentEnemies($root);
    // The chest E sits in a sealed pocket.
    writeReachableProject($root,
        "############\n#  ~~~ ### #\n#      #E# #\n#      ### #\n############",
        "            \n            \n        E   \n            \n            ");

    expect(reachabilityIssueLines($root))->toBe([
        'error: test-map: No cell of event E (ChestEventTrigger) at (8, 2) can be reached, so it never fires.',
    ]);

    // Moving the chest anywhere open is simply fine.
    writeReachableProject($root,
        "############\n#  ~~~ ### #\n#      # # #\n#      ### #\n############",
        "            \n     E      \n            \n            \n            ");
    expect(reachabilityIssueLines($root))->toBe([]);
});

it('notes a map nothing reaches yet as a warning, and checks edge trigger destinations', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);
    writeConsistentSkits($root);
    writeConsistentEnemies($root);
    writeReachableProject($root,
        "############\n#  ~~~     #\n#          #\n#          #\n############",
        "            \n     E      \n            \n            \n            ");
    mkdir($root . '/assets/Maps/vestige', 0777, true);
    file_put_contents($root . '/assets/Maps/vestige/vestige.map.php', "<?php\n\nreturn <<<'ICHILOTO_MAP'\n   \nICHILOTO_MAP;\n");
    file_put_contents($root . '/assets/Maps/vestige/vestige.event.php', "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n   \nICHILOTO_EVENT_MAP;\n");
    file_put_contents($root . '/assets/Maps/vestige/vestige.data.php', '<?php return ' . var_export(['name' => 'Vestige', 'triggers' => [
        ['destinationMap' => 'nowhere', 'trigger_area' => ['x' => 0, 'y' => 0, 'width' => 1, 'height' => 1],
            'spawn_point' => ['x' => 0, 'y' => 0]],
    ]], true) . ';');

    $issues = validateProject($root);

    expect(reachabilityIssueLines($root))->toBe([
        'warning: vestige: Nothing the player can reach from the start brings them onto it yet.',
    ])->and(issuesMentioning($issues, 'Edge trigger 1 leads to "nowhere", which is not a map in this project.'))->toHaveCount(1);
});

/** Writes a field effect drawing one image cell from a 3 x 4 sheet. */
function writeFieldEffect(string $root, string $id, string $asset): void
{
    @mkdir($root . "/assets/Animations/{$id}", 0777, true);
    file_put_contents($root . "/assets/Animations/{$id}/{$id}.timeline.php", '<?php return ' . var_export([
        'fps' => 5, 'lengthFrames' => 2, 'playback' => 'loop', 'restFrame' => 0,
        'tracks' => [[
            'id' => 'cue', 'type' => 'image', 'asset' => $asset, 'sheet' => ['columns' => 3, 'rows' => 4],
            'cells' => ['width' => 1, 'height' => 1], 'depth' => 'front',
            'keyframes' => [['frame' => 0, 'sourceFrame' => 0], ['frame' => 1, 'sourceFrame' => 1]],
        ]],
    ], true) . ';');
}

/** @return list<string> */
function effectIssueLines(string $root): array
{
    return array_map(
        static fn(Issue $issue): string => $issue->where . ': ' . $issue->message,
        array_values(array_filter(validateProject($root), static fn(Issue $issue): bool =>
            str_contains($issue->message, 'Effect ') || str_contains($issue->message, 'fieldEffects')
            || str_contains($issue->message, 'field presentation'))),
    );
}

it('checks every effect a consumer uses, as that consumer plays it, in both presentations', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);
    writeConsistentSkits($root);
    writeConsistentEnemies($root);
    @mkdir($root . '/assets/Graphics/System', 0777, true);
    $image = imagecreatetruecolor(9, 8);
    imagepng($image, $root . '/assets/Graphics/System/Cue.png');
    writeFieldEffect($root, 'cue-ok', 'Graphics/System/Cue.png');
    writeFieldEffect($root, 'cue-missing-art', 'Graphics/System/Missing.png');
    @mkdir($root . '/assets/Data/Presentation', 0777, true);
    file_put_contents($root . '/assets/Data/Presentation/field.php', '<?php return ' . var_export([
        'cues' => ['blue' => ['effect' => 'cue-ok'], 'yellow' => ['effect' => 'cue-missing-art']],
        'actionPrompt' => ['effect' => 'no-such-effect'],
    ], true) . ';');
    $animations = require $root . '/assets/Data/animations.php';
    $animations[0]['targetEffect'] = 'cue-ok';
    file_put_contents($root . '/assets/Data/animations.php', '<?php return ' . var_export($animations, true) . ';');

    $lines = effectIssueLines($root);

    expect(array_filter($lines, static fn(string $line): bool => str_contains($line, 'cue-ok')))->toBe([])
        ->and(implode("\n", $lines))
        ->toContain('assets/Data/Presentation/field.php: yellow cue: Effect cue-missing-art cannot be played in field for the graphical presentation')
        ->toContain('assets/Data/Presentation/field.php: action prompt: Effect no-such-effect cannot be played in field for the terminal presentation')
        // A field image effect without terminal tracks still plays in the terminal: it keeps its glyph.
        ->not->toContain('cue-missing-art cannot be played in field for the terminal presentation');
});

it('reports map field effects it cannot read', function () {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $path;
    $data['fieldEffects'] = 'not a list';
    file_put_contents($path, '<?php return ' . var_export($data, true) . ';');

    expect(effectIssueLines($root))->toBe([
        'test-map: Its fieldEffects cannot be read: fieldEffects must be a list of at most 256 map-owned effects.',
    ]);
});

it('reports a record list entry that names nothing, or a definition the project lacks', function () {
    $root = makeTemporaryProject();
    writeConsistentQuests($root);
    writeConsistentSkits($root);
    writeConsistentEnemies($root);
    $path = $root . '/assets/Data/Enemies/sewer-rat.php';
    file_put_contents($path, str_replace(
        "'rewards' => ['experience' => 1, 'gold' => 1],",
        "'rewards' => ['experience' => 1, 'gold' => 1, 'items' => [['item' => '', 'rate' => 0.5], ['item' => 'Moon Tonic', 'rate' => 0.1], ['item' => 'S-Potion', 'rate' => 0.2]]],",
        (string) file_get_contents($path),
    ));

    $issues = array_map(static fn(Issue $issue): string => $issue->where . ': ' . $issue->message, validateProject($root));

    expect($issues)->toBe([
        'enemy Sewer Rat, drop 1: Its item names no item.',
        'enemy Sewer Rat, drop 2: Its item names the item "Moon Tonic", which the project does not define.',
    ]);
});
