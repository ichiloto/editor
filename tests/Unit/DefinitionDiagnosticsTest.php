<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Editor\Validation\Severity;

/**
 * Returns every issue a project reports, as "where: message" lines.
 *
 * @param string $root The project root.
 * @param Severity|null $severity Only this severity, or all of them.
 * @return string[] The lines.
 */
function diagnosticsFor(string $root, ?Severity $severity = null): array
{
    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));

    return array_values(array_map(
        static fn($issue): string => $issue->where . ': ' . $issue->message,
        array_filter($issues, static fn($issue): bool => $severity === null || $issue->severity === $severity),
    ));
}

/**
 * Writes an actor file into a project.
 *
 * @param array<string, mixed> $data The actor's data block.
 */
function writeActorFile(string $root, string $name, array $data): void
{
    file_put_contents(
        $root . '/assets/Data/Actors/' . $name . '.php',
        "<?php\n\nreturn " . var_export(['class' => \Ichiloto\Engine\Entities\Character::class, 'data' => $data], true) . ";\n",
    );
}

it('reports two actors that resolve to one identity', function () {
    $root = makeTemporaryProject('ichiloto-diag-');
    writeActorFile($root, 'Twin', ['id' => 'actor.kaelion', 'name' => 'Twin', 'stats' => ['attack' => 1]]);
    writeActorFile($root, 'Kaelion', ['id' => 'actor.kaelion', 'name' => 'Kaelion', 'stats' => ['attack' => 1]]);

    expect(implode("\n", diagnosticsFor($root, Severity::ERROR)))
        ->toContain('Two actors resolve to the identity "actor.kaelion"');
});

it('reports a default natural variant the actor does not declare', function () {
    $root = makeTemporaryProject('ichiloto-diag-');
    writeActorFile($root, 'Kaelion', [
        'name' => 'Kaelion',
        'stats' => ['attack' => 1],
        'naturalVariants' => ['awakened' => ['attack' => 2]],
        'defaultNaturalVariantId' => 'dormant',
    ]);

    expect(implode("\n", diagnosticsFor($root, Severity::ERROR)))
        ->toContain('Its default natural variant "dormant" is not one it declares');

    // And a default named without any variants at all.
    writeActorFile($root, 'Kaelion', [
        'name' => 'Kaelion',
        'stats' => ['attack' => 1],
        'defaultNaturalVariantId' => 'awakened',
    ]);

    expect(implode("\n", diagnosticsFor($root, Severity::ERROR)))
        ->toContain('names the default natural variant "awakened" but declares no variants');
});

it('reports an adjustment to something the runtime does not resolve as a stat', function () {
    $root = makeTemporaryProject('ichiloto-diag-');
    writeActorFile($root, 'Kaelion', [
        'name' => 'Kaelion',
        'stats' => ['attack' => 1],
        // Accuracy and Critical are deliberately not resolved layers.
        'actorNaturalAdjustments' => ['attack' => 2, 'accuracy' => 5],
        'naturalVariants' => ['awakened' => ['luck' => 3]],
        'defaultNaturalVariantId' => 'awakened',
    ]);

    $errors = implode("\n", diagnosticsFor($root, Severity::ERROR));

    expect($errors)->toContain('adjusts "accuracy", which is not a stat the runtime resolves')
        ->toContain('variant awakened adjusts "luck"');
});

it('leaves the runtime to enforce its own bounds, and says so when a project cannot open', function () {
    $root = makeTemporaryProject('ichiloto-diag-');
    // The engine's own constructor refuses a sell rate outside 0 through
    // 10000. It does that by throwing, which takes the whole file with it,
    // so the project cannot be opened at all rather than opening with a
    // quietly clamped value. The editor does not re-check the bound; it
    // surfaces the engine's own words.
    file_put_contents($root . '/assets/Data/items.php', <<<'PHP'
    <?php

    use Ichiloto\Engine\Entities\Inventory\Items\Item;

    return [
      new Item(
        id: 'item.odd',
        name: 'Odd Potion',
        description: 'Priced strangely.',
        icon: 'i',
        price: 10,
        sellRateBasisPoints: 25000,
      ),
    ];
    PHP);

    // Validation reports it once, accurately, rather than as a page of
    // "does not exist" for every reference to an item.
    $errors = diagnosticsFor($root, Severity::ERROR);

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('The inventory could not be read')
        ->and($errors[0])->toContain('sell rate must be between 0 and 10000');
});

it('reports a knowledge catalogue that points at what it does not declare', function () {
    $root = makeTemporaryProject('ichiloto-diag-');
    file_put_contents($root . '/assets/Data/knowledge.php', <<<'PHP'
    <?php

    return [
      'recordTypes' => ['creature'],
      'subjects' => [
        ['id' => 'creature.rat', 'recordType' => 'creature', 'displayName' => 'Rat', 'quickCard' => 'A rat.'],
        ['id' => 'creature.rat', 'recordType' => 'creature', 'displayName' => 'Rat again', 'quickCard' => 'The same id.'],
        ['id' => 'person.ghost', 'recordType' => 'apparition', 'displayName' => 'Ghost', 'quickCard' => ''],
        [
          'id' => 'creature.bat',
          'recordType' => 'creature',
          'displayName' => 'Bat',
          'quickCard' => 'A bat.',
          'relationships' => [['type' => 'preys-on', 'subject' => 'creature.moth']],
        ],
      ],
      'enemyMappings' => ['Sewer Rat' => 'creature.nothing'],
      'reports' => [
        ['id' => 'report.one', 'subject' => 'creature.unknown', 'title' => 'One', 'summary' => 'About nothing.'],
        ['id' => 'report.one', 'subject' => 'creature.rat', 'title' => 'Two', 'summary' => 'Same id.', 'disagreesWith' => ['report.absent']],
      ],
    ];
    PHP);

    $errors = implode("\n", diagnosticsFor($root, Severity::ERROR));
    $warnings = implode("\n", diagnosticsFor($root, Severity::WARNING));

    expect($errors)->toContain('Subject id "creature.rat" is declared more than once')
        ->toContain('record type "apparition", which the catalogue does not declare')
        ->toContain('person.ghost has no quick card')
        ->toContain('creature.bat is related to "creature.moth"')
        ->toContain('The enemy "Sewer Rat" maps to the subject "creature.nothing"')
        ->toContain('report.one concerns the subject "creature.unknown"')
        ->toContain('Report id "report.one" is declared more than once')
        ->and($warnings)->toContain('disagrees with "report.absent"');
});

it('refuses a catalogue that would ship what only the author should know', function () {
    $root = makeTemporaryProject('ichiloto-diag-');
    file_put_contents($root . '/assets/Data/knowledge.php', <<<'PHP'
    <?php

    return [
      'recordTypes' => ['creature'],
      'subjects' => [
        ['id' => 'creature.rat', 'recordType' => 'creature', 'displayName' => 'Rat', 'quickCard' => 'A rat.'],
      ],
      'authorTruth' => ['creature.rat' => 'It is not really a rat.'],
    ];
    PHP);

    expect(implode("\n", diagnosticsFor($root, Severity::ERROR)))
        ->toContain('It carries a "authorTruth" section');
});

it('reports a name two definitions both claim', function () {
    $root = makeTemporaryProject('ichiloto-diag-');
    file_put_contents($root . '/assets/Data/items.php', <<<'PHP'
    <?php

    use Ichiloto\Engine\Entities\Inventory\Items\Item;

    return [
      new Item(id: 'item.one', name: 'Elixir', description: 'One.', icon: 'i', price: 1),
      new Item(id: 'item.two', name: 'Tonic', description: 'Two.', icon: 'i', price: 1, aliases: ['Elixir']),
    ];
    PHP);

    expect(implode("\n", diagnosticsFor($root, Severity::ERROR)))
        ->toContain('"elixir" is claimed by');
});

it('adds nothing to a project that has none of these faults', function () {
    // The sample fixture has known unrelated quest and skit findings of its
    // own; what matters is that none of the new diagnostics fire on it.
    $reported = implode("\n", diagnosticsFor(makeTemporaryProject('ichiloto-diag-')));

    foreach ([
        'resolve to the identity',
        'natural variant',
        'is not a stat the runtime resolves',
        'is claimed by',
        'catalogue does not declare',
        'carries a "',
    ] as $newDiagnostic) {
        expect($reported)->not->toContain($newDiagnostic);
    }
});
