<?php

declare(strict_types=1);

use Ichiloto\Editor\ActorStatPreview;
use Ichiloto\Editor\Editor;
use Ichiloto\Editor\ProjectActor;
use Ichiloto\Editor\ProjectActorDatabase;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Returns the fixture project's first actor, ready to author.
 *
 * @return array{0: string, 1: ProjectActorDatabase, 2: ProjectActor}
 */
function actorUnderTest(): array
{
    $root = makeTemporaryProject('ichiloto-actor-');
    $database = ProjectActorDatabase::fromProject($root);
    $actor = $database->getActors()[0];

    return [$root, $database, $actor];
}

/**
 * Opens the fixture project's actors in the Database screen.
 */
function actorEditorOn(string $root): Editor
{
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 140, 'height' => 40]);
    callEditorMethod($editor, 'openDatabaseAtCategory', 'actors');

    return $editor;
}

/**
 * Returns one Inspector row of the selected actor by its field id.
 *
 * @return array<string, mixed>
 */
function actorRow(Editor $editor, string $fieldId): array
{
    foreach (callEditorMethod($editor, 'getDatabaseActorSettingsFields') as $field) {
        if (($field['field'] ?? null) === $fieldId) {
            return $field;
        }
    }

    throw new RuntimeException(sprintf('No actor row "%s".', $fieldId));
}

it('keeps explicit actor identity read-only while renaming display text', function () {
    [$root, $database, $actor] = actorUnderTest();

    expect($actor->hasDefinitionId())->toBeTrue()
        ->and($actor->getDefinitionId())->toBe('Kaelion');

    $editor = actorEditorOn($root);
    expect(actorRow($editor, 'id')['editable'])->toBeFalse();
    expect(fn() => $database->setField(0, 'id', 'actor.kaelion'))->toThrow(RuntimeException::class, 'permanent');
    expect(fn() => $database->setField(0, 'id', ''))->toThrow(RuntimeException::class, 'permanent');

    // Renaming now leaves the identity where it was.
    $database->setField(0, 'name', 'Kaelion the Elder');
    expect($database->getActors()[0]->getDefinitionId())->toBe('Kaelion');
    $database->save();
    $reloaded = ProjectActorDatabase::fromProject($root)->getActors()[0];
    expect($reloaded->getName())->toBe('Kaelion the Elder')->and($reloaded->getDefinitionId())->toBe('Kaelion');
});

it('assigns an explicit immutable id to a new actor before its first save', function () {
    [$root, $database] = actorUnderTest();
    $index = $database->addActor('Liora');
    $actor = $database->getActorByIndex($index);
    expect($actor->hasDefinitionId())->toBeTrue()->and($actor->getDefinitionId())->toBe('Liora');
    $database->setField($index, 'name', 'Liora Vey');
    expect($actor->getDefinitionId())->toBe('Liora');
    $database->save();
    expect((require $actor->path)['data'])->toMatchArray(['id' => 'Liora', 'name' => 'Liora Vey']);
});

it('flags missing explicit ids and requires repairing identity before a legacy rename', function () {
    [$root] = actorUnderTest();
    $path = $root . '/assets/Data/Actors/Kaelion.php';
    $payload = require $path;
    unset($payload['data']['id']);
    file_put_contents($path, '<?php return ' . var_export($payload, true) . ';');
    $workspace = ProjectWorkspace::fromProject($root);
    $actor = $workspace->actorDatabase->getActors()[0];
    $validator = new \Ichiloto\Editor\Validation\ProjectValidator();
    $issues = new ReflectionMethod($validator, 'checkActorDefinitions')->invoke($validator, $workspace);
    expect(array_column($issues, 'message'))->toContain('Actor has no explicit stable id.');
    expect(fn() => $actor->setField('name', 'Renamed'))->toThrow(RuntimeException::class, 'before renaming');
    $actor->setField('id', 'Kaelion');
    $actor->setField('name', 'Renamed');
    expect($actor->getDefinitionId())->toBe('Kaelion');
});

it('undoes and redoes a legacy identity repair and display rename without retargeting the actor', function () {
    [$root] = actorUnderTest();
    $path = $root . '/assets/Data/Actors/Kaelion.php';
    $payload = require $path;
    unset($payload['data']['id']);
    file_put_contents($path, '<?php return ' . var_export($payload, true) . ';');
    $editor = actorEditorOn($root);
    $actor = getEditorProperty($editor, 'workspace')->actorDatabase->getActors()[0];
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', actorRow($editor, 'id'), 'Kaelion');
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', actorRow($editor, 'name'), 'Kaelion the Elder');
    callEditorMethod($editor, 'performUndo');
    expect($actor->getName())->toBe('Kaelion')->and($actor->getDefinitionId())->toBe('Kaelion');
    callEditorMethod($editor, 'performUndo');
    expect($actor->hasDefinitionId())->toBeFalse()->and($actor->isDirty())->toBeFalse();
    callEditorMethod($editor, 'performRedo');
    callEditorMethod($editor, 'performRedo');
    expect($actor->getName())->toBe('Kaelion the Elder')->and($actor->getDefinitionId())->toBe('Kaelion');
});

it('authors actor-natural adjustments, keeping their sign and dropping zeroes', function () {
    [$root, $database] = actorUnderTest();

    $database->setField(0, 'actorNaturalAdjustments.attack', 4);
    // Being slower than the class baseline is a legitimate nature.
    $database->setField(0, 'actorNaturalAdjustments.speed', -2);

    expect($database->getActors()[0]->getActorNaturalAdjustments())->toBe(['attack' => 4, 'speed' => -2]);

    // Zero is the absence of an adjustment, so the key goes rather than
    // being written as a value the runtime would add to everything.
    $database->setField(0, 'actorNaturalAdjustments.attack', 0);
    expect($database->getActors()[0]->getActorNaturalAdjustments())->toBe(['speed' => -2]);

    $database->save();
    $payload = require $root . '/assets/Data/Actors/Kaelion.php';

    expect($payload['data']['actorNaturalAdjustments'])->toBe(['speed' => -2]);
});

it('authors named variants, and reads the one in force rather than the fixed set', function () {
    [$root, $database] = actorUnderTest();

    $database->setField(0, 'actorNaturalAdjustments.attack', 4);
    $database->setField(0, 'naturalVariants.awakened.attack', 12);
    $database->setField(0, 'naturalVariants.awakened.speed', 3);
    $database->setField(0, 'naturalVariants.dormant.attack', 1);
    $database->setField(0, 'defaultNaturalVariantId', 'awakened');

    $actor = $database->getActors()[0];

    expect(array_keys($actor->getNaturalVariants()))->toBe(['awakened', 'dormant'])
        ->and($actor->getDefaultNaturalVariantId())->toBe('awakened')
        // The runtime composes: the fixed adjustments always apply and the
        // selected variant is added on top, summing where both name one
        // stat. Fixed attack 4 plus awakened attack 12 is 16, not 12.
        ->and($actor->getNaturalAdjustmentsFor(null))->toBe(['attack' => 16, 'speed' => 3])
        ->and($actor->getNaturalAdjustmentsFor('dormant'))->toBe(['attack' => 5])
        // The fixed set is preserved, not overwritten by the variants.
        ->and($actor->getActorNaturalAdjustments())->toBe(['attack' => 4])
        // A variant nobody declared contributes nothing of its own, which
        // leaves the fixed layer standing rather than nothing at all.
        ->and($actor->getNaturalAdjustmentsFor('missing'))->toBe(['attack' => 4]);

    // Emptying a variant of every adjustment stops it being a variant.
    $database->setField(0, 'naturalVariants.dormant.attack', 0);
    expect(array_keys($database->getActors()[0]->getNaturalVariants()))->toBe(['awakened']);
});

it('resolves every layer through the engine, including cap loss and headroom', function () {
    if (! ActorStatPreview::isAvailable()) {
        $this->markTestSkipped('The engine stat resolver is not reachable from this checkout.');
    }

    [, $database] = actorUnderTest();
    $database->setField(0, 'actorNaturalAdjustments.attack', 4);
    $actor = $database->getActors()[0];
    $natural = $actor->getStat('attack');

    $rows = ActorStatPreview::resolve(
        $actor,
        permanent: ['attack' => 6],
        equipment: ['attack' => 10],
        temporary: ['attack' => 5],
    );
    $byStat = array_column($rows, null, 'stat');

    // Every canonical key, and none the runtime does not resolve.
    expect(array_keys($byStat))->toBe(ActorStatPreview::statKeys())
        ->and(array_keys($byStat))->not->toContain('accuracy')
        ->and(array_keys($byStat))->not->toContain('critical');

    $attack = $byStat['attack'];

    expect($attack['natural'])->toBe($natural)
        ->and($attack['actorNatural'])->toBe(4)
        ->and($attack['permanent'])->toBe(6)
        ->and($attack['equipment'])->toBe(10)
        ->and($attack['temporary'])->toBe(5)
        ->and($attack['uncapped'])->toBe($natural + 25)
        ->and($attack['effective'])->toBe($natural + 25)
        ->and($attack['capLoss'])->toBe(0)
        ->and($attack['headroom'])->toBe($attack['cap'] - $attack['effective'])
        ->and(ActorStatPreview::describeRow($attack))->toContain('+4 nature')
        ->and(ActorStatPreview::describeRow($attack))->toContain('+5 battle');

    // Past the cap the extra is lost, which is the whole reason to look.
    $database->setField(0, 'actorNaturalAdjustments.attack', 2000);
    $capped = array_column(ActorStatPreview::resolve($database->getActors()[0]), null, 'stat')['attack'];

    expect($capped['effective'])->toBe($capped['cap'])
        ->and($capped['capLoss'])->toBeGreaterThan(0)
        ->and($capped['headroom'])->toBe(0)
        ->and(ActorStatPreview::describeRow($capped))->toContain('lost to the');
});

it('caps a player and an enemy differently', function () {
    if (! ActorStatPreview::isAvailable()) {
        $this->markTestSkipped('The engine stat resolver is not reachable from this checkout.');
    }

    [, $database] = actorUnderTest();
    $actor = $database->getActors()[0];

    $player = array_column(ActorStatPreview::resolve($actor), null, 'stat')['maxHp'];
    $enemy = array_column(ActorStatPreview::resolve($actor, isEnemy: true), null, 'stat')['maxHp'];

    expect($player['cap'])->not->toBe($enemy['cap']);
});

it('never writes anything while previewing', function () {
    if (! ActorStatPreview::isAvailable()) {
        $this->markTestSkipped('The engine stat resolver is not reachable from this checkout.');
    }

    [$root, $database] = actorUnderTest();
    $before = sourceHashTree($root);
    $actor = $database->getActors()[0];
    // What the actor reports of itself, before and after: a preview that
    // mutated anything would show up in one of these.
    $snapshot = static fn(): array => [
        $actor->getStats(),
        $actor->getActorNaturalAdjustments(),
        $actor->getNaturalVariants(),
        $actor->getDefinitionId(),
    ];
    $before_state = $snapshot();

    ActorStatPreview::resolve($actor, permanent: ['attack' => 50], equipment: ['attack' => 50]);
    ActorStatPreview::resolve($actor, 'awakened');
    $database->save();

    expect($snapshot())->toBe($before_state)
        ->and($database->isDirty())->toBeFalse()
        ->and(sourceHashTree($root))->toBe($before);
});

it('shows the layers in the Inspector and edits the variant in force', function () {
    if (! ActorStatPreview::isAvailable()) {
        $this->markTestSkipped('The engine stat resolver is not reachable from this checkout.');
    }

    [$root, $database] = actorUnderTest();
    $database->setField(0, 'naturalVariants.awakened.attack', 12);
    $database->setField(0, 'naturalVariants.dormant.attack', 1);
    $database->setField(0, 'defaultNaturalVariantId', 'awakened');
    $database->save();

    $editor = actorEditorOn($root);
    $labels = array_column(callEditorMethod($editor, 'getDatabaseActorSettingsFields'), 'label');

    expect($labels)->toContain('Identity')
        ->toContain('Nature')
        ->toContain('Resolved Stats')
        ->toContain('  attack');

    // The rows edit the default variant until another is chosen.
    expect(actorRow($editor, 'naturalVariants.awakened.attack')['value'])->toBe('12');

    callEditorMethod($editor, 'applyDatabaseFieldValue', '__actor_variant', 'dormant');

    expect(actorRow($editor, 'naturalVariants.dormant.attack')['value'])->toBe('1');

    // Choosing which variant to edit is a view of the pane, not a change to
    // the project.
    /** @var ProjectWorkspace $workspace */
    $workspace = getEditorProperty($editor, 'workspace');
    expect($workspace->actorDatabase->isDirty())->toBeFalse();

    // Editing through the pane writes into that variant.
    callEditorMethod($editor, 'applyDatabaseFieldValue', 'naturalVariants.dormant.attack', '7');
    expect($workspace->actorDatabase->getActors()[0]->getNaturalVariants()['dormant'])->toBe(['attack' => 7]);
});
