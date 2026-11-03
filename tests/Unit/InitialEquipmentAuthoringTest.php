<?php

declare(strict_types=1);

use Ichiloto\Editor\Actors\ActorAuthoring;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\InitialEquipmentValidator;

it('offers slot-specific item identities and saves initial equipment with undo and source preservation', function () {
    $root = makeTemporaryProject();
    $workspace = ProjectWorkspace::fromProject($root);
    $actor = $workspace->actorDatabase->getActorByIndex(0);
    $authoring = new ActorAuthoring();
    $row = array_find($authoring->describeFields($workspace, $actor), static fn($field) => ($field['field'] ?? '') === 'initialEquipment.weapon');
    expect($row['reference'])->toBe('initial_weapon')->and($row['allowsNone'])->toBeTrue();
    $references = new ReferenceCatalog($workspace);
    expect($references->valuesFor('initial_weapon'))->toBe(['legacy.wooden-sword'])
        ->and($references->valuesFor('initial_head'))->not->toContain('legacy.wooden-sword')
        ->and($references->labelsFor('initial_weapon')['legacy.wooden-sword'])->toContain('Wooden Sword');
    $before = $actor->getData();
    $change = $authoring->applyField($workspace, 0, $row['field'], 'legacy.wooden-sword', $row['label']);
    expect($actor->getData()['initialEquipment'])->toBe(['weapon' => 'legacy.wooden-sword']);
    $change->command->undo();
    expect($actor->getData())->toBe($before);
    $change->command->execute();
    $workspace->actorDatabase->save();
    $payload = require $root . '/assets/Data/Actors/Kaelion.php';
    expect($payload['data']['initialEquipment'])->toBe(['weapon' => 'legacy.wooden-sword'])
        ->and(array_diff_key($payload['data'], ['initialEquipment' => true]))->toBe($before)
        ->and((new InitialEquipmentValidator())->validate($workspace))->toBeEmpty();
    $reopened = ProjectWorkspace::fromProject($root);
    expect($reopened->actorDatabase->getActorByIndex(0)->getData()['initialEquipment'])->toBe(['weapon' => 'legacy.wooden-sword']);
    $authoring->applyField($reopened, 0, 'initialEquipment.weapon', '(None)', 'Starting Weapon');
    expect($reopened->actorDatabase->getActorByIndex(0)->getData())->not->toHaveKey('initialEquipment');
});

it('reports unresolved and wrong-slot initial equipment before gameplay', function () {
    $root = makeTemporaryProject();
    $workspace = ProjectWorkspace::fromProject($root);
    $actor = $workspace->actorDatabase->getActorByIndex(0);
    foreach ([['weapon' => 'missing'], ['head' => 'legacy.wooden-sword'], ['weapon' => 'S-Potion']] as $loadout) {
        $actor->restoreData([...$actor->getData(), 'initialEquipment' => $loadout]);
        expect((new InitialEquipmentValidator())->validate($workspace))->toHaveCount(1);
    }
});

it('checks initial equipment against the current class equipment permissions', function () {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/classes.php';
    $classes = require $path;
    $classes[0]['equipment'] = ['weapons' => ['Staff']];
    file_put_contents($path, '<?php return ' . var_export($classes, true) . ';');
    $workspace = ProjectWorkspace::fromProject($root);
    $actor = $workspace->actorDatabase->getActorByIndex(0);
    $actor->restoreData([...$actor->getData(), 'class' => $classes[0]['name'], 'initialEquipment' => ['weapon' => 'legacy.wooden-sword']]);
    $issues = (new InitialEquipmentValidator())->validate($workspace);
    expect($issues)->toHaveCount(1)->and($issues[0]->message)->toContain("not allowed by the actor's class");
});
