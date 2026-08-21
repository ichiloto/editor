<?php

declare(strict_types=1);

use Ichiloto\Editor\ActorStatPreview;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\ProjectActorDatabase;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * What an actor naturally is, composed the way the runtime composes it.
 *
 * The runtime's authority is `ActorDefinition::naturalAdjustmentsFor()`: the
 * fixed adjustments always apply, and the selected variant is added on top,
 * summing where both name one stat. An editor that replaced the fixed layer
 * instead would show, and let an author balance against, numbers the game
 * never uses.
 */

/**
 * Writes an actor and returns it ready to read.
 *
 * @param array<string, mixed> $nature The nature block to author.
 * @return array{0: string, 1: ProjectActorDatabase}
 */
function actorWithNature(array $nature): array
{
    $root = makeTemporaryProject('ichiloto-nature-');
    $path = $root . '/assets/Data/Actors/Kaelion.php';
    $actor = (static fn(): mixed => require $path)();
    $actor['data'] = [...$actor['data'], ...$nature];

    file_put_contents(
        $path,
        "<?php\n\nreturn " . var_export(['class' => \Ichiloto\Engine\Entities\Character::class, 'data' => $actor['data']], true) . ";\n",
    );

    return [$root, ProjectActorDatabase::fromProject($root)];
}

it('adds the selected variant on top of the fixed adjustments', function () {
    [, $database] = actorWithNature([
        'actorNaturalAdjustments' => ['attack' => 4, 'defence' => 2],
        'naturalVariants' => [
            'awakened' => ['attack' => 12, 'speed' => 3],
            'dormant' => ['attack' => -1],
        ],
        'defaultNaturalVariantId' => 'awakened',
    ]);
    $actor = $database->getActors()[0];

    expect($actor->getNaturalAdjustmentsFor('awakened'))
        // attack overlaps and sums; defence is fixed only; speed is variant
        // only. None of the three is dropped.
        ->toBe(['attack' => 16, 'defence' => 2, 'speed' => 3])
        // A variant may take away what the fixed layer gave.
        ->and($actor->getNaturalAdjustmentsFor('dormant'))->toBe(['attack' => 3, 'defence' => 2])
        // No argument means the default the actor declares.
        ->and($actor->getNaturalAdjustmentsFor())->toBe($actor->getNaturalAdjustmentsFor('awakened'));
});

it('matches the engine definition exactly, for every variant', function () {
    if (! class_exists(\Ichiloto\Engine\Entities\Actors\ActorDefinition::class)) {
        $this->markTestSkipped('The engine actor definition is not reachable from this checkout.');
    }

    $nature = [
        'actorNaturalAdjustments' => ['attack' => 4, 'defence' => 2, 'speed' => -3],
        'naturalVariants' => [
            'awakened' => ['attack' => 12, 'speed' => 3, 'grace' => 1],
            'dormant' => ['attack' => -6],
            'hollow' => [],
        ],
        'defaultNaturalVariantId' => 'dormant',
    ];
    [$root, $database] = actorWithNature($nature);
    $actor = $database->getActors()[0];
    $data = (static fn(): mixed => require $root . '/assets/Data/Actors/Kaelion.php')()['data'];
    $definition = \Ichiloto\Engine\Entities\Actors\ActorDefinition::fromArray(['data' => $data]);

    foreach ([null, 'awakened', 'dormant', 'hollow', 'never-declared'] as $variantId) {
        $expected = $definition->naturalAdjustmentsFor(
            $variantId ?? $definition->defaultNaturalVariantId,
        );

        expect($actor->getNaturalAdjustmentsFor($variantId))->toBe(
            $expected,
            sprintf('Variant %s does not match the engine.', $variantId ?? '(default)'),
        );
    }

    // An empty variant contributes nothing, which leaves the fixed layer
    // standing rather than emptying it.
    expect($actor->getNaturalAdjustmentsFor('hollow'))->toBe(['attack' => 4, 'defence' => 2, 'speed' => -3]);
});

it('composes the fixed layer alone when the actor declares no variants', function () {
    [, $database] = actorWithNature([
        'actorNaturalAdjustments' => ['attack' => 4, 'speed' => -2],
    ]);
    $actor = $database->getActors()[0];

    expect($actor->getNaturalAdjustmentsFor())->toBe(['attack' => 4, 'speed' => -2])
        ->and($actor->getNaturalAdjustmentsFor('anything'))->toBe(['attack' => 4, 'speed' => -2]);
});

it('still composes when the engine refuses the definition', function () {
    // A default naming a variant that is not declared is a definition the
    // engine will not build. The editor must still open the project and
    // still show the fixed layer; the validator is what reports the fault.
    [, $database] = actorWithNature([
        'actorNaturalAdjustments' => ['attack' => 4],
        'naturalVariants' => ['awakened' => ['attack' => 5]],
        'defaultNaturalVariantId' => 'nowhere',
    ]);
    $actor = $database->getActors()[0];

    expect($actor->getNaturalAdjustmentsFor('awakened'))->toBe(['attack' => 9])
        ->and($actor->getNaturalAdjustmentsFor('nowhere'))->toBe(['attack' => 4]);
});

it('resolves the preview from the composed layer', function () {
    if (! ActorStatPreview::isAvailable()) {
        $this->markTestSkipped('The engine stat resolver is not reachable from this checkout.');
    }

    [, $database] = actorWithNature([
        'actorNaturalAdjustments' => ['attack' => 4],
        'naturalVariants' => ['awakened' => ['attack' => 12]],
        'defaultNaturalVariantId' => 'awakened',
    ]);
    $actor = $database->getActors()[0];
    $attack = array_column(ActorStatPreview::resolve($actor, 'awakened'), null, 'stat')['attack'];

    expect($attack['actorNatural'])->toBe(16)
        ->and(ActorStatPreview::describeRow($attack))->toContain('+16 nature');
});

it('shows and edits both layers without writing while looking', function () {
    [$root, ] = actorWithNature([
        'actorNaturalAdjustments' => ['attack' => 4],
        'naturalVariants' => ['awakened' => ['attack' => 12], 'dormant' => ['attack' => 1]],
        'defaultNaturalVariantId' => 'awakened',
    ]);
    $before = sourceHashTree($root);

    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 140, 'height' => 40]);
    callEditorMethod($editor, 'openDatabaseAtCategory', 'actors');

    $rowFor = static function (Editor $editor, string $field): array {
        foreach (callEditorMethod($editor, 'getDatabaseActorSettingsFields') as $row) {
            if (($row['field'] ?? null) === $field) {
                return $row;
            }
        }

        return [];
    };
    $labels = array_column(callEditorMethod($editor, 'getDatabaseActorSettingsFields'), 'label');

    // Both layers are on the pane, named for what they do, with what they
    // come to beside them.
    expect($labels)->toContain('  Fixed, always applied')
        ->toContain('  Variant awakened, added on top')
        ->toContain('  In force')
        ->and($rowFor($editor, 'actorNaturalAdjustments.attack')['value'])->toBe('4')
        ->and($rowFor($editor, 'naturalVariants.awakened.attack')['value'])->toBe('12');

    foreach (callEditorMethod($editor, 'getDatabaseActorSettingsFields') as $row) {
        if (($row['label'] ?? null) === '  In force') {
            expect($row['value'])->toContain('+16 attack');
        }
    }

    // Looking at either layer writes nothing.
    expect(sourceHashTree($root))->toBe($before);

    // Editing the fixed layer moves what is in force, without touching the
    // variant that is added to it.
    callEditorMethod($editor, 'applyDatabaseFieldValue', 'actorNaturalAdjustments.attack', '10');

    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    $actor = $workspace->actorDatabase->getActors()[0];

    expect($actor->getActorNaturalAdjustments())->toBe(['attack' => 10])
        ->and($actor->getNaturalVariants()['awakened'])->toBe(['attack' => 12])
        ->and($actor->getNaturalAdjustmentsFor('awakened'))->toBe(['attack' => 22]);
});
