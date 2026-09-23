<?php

declare(strict_types=1);

use Ichiloto\Editor\Actors\ActorIdentityMigration;
use Ichiloto\Editor\ProjectActorDatabase;
use Ichiloto\Editor\Cutscenes\Source\SourceUnreadable;

function createLegacyActorProject(): array
{
    $root = makeTemporaryProject('ichiloto-actor-migration-');
    $path = $root . '/assets/Data/Actors/Kaelion.php';
    $source = <<<'PHP'
<?php
// Keep this authored header.
use Ichiloto\Engine\Entities\Character;
$profile = 'A familiar traveller';
return [
    'class' => Character::class,
    'data' => [
        // Keep this name comment.
        'name' => 'Kaelion',
        'description' => $profile,
        'level' => 1 + 2,
    ],
];
PHP;
    file_put_contents($path, $source);
    return [$root, $path, $source];
}

it('shares an explicit source preserving batch repair and keeps ids stable after later rename', function () {
    [$root, $path, $source] = createLegacyActorProject();
    $before = sourceHashTree($root);
    $database = ProjectActorDatabase::fromProject($root);
    expect(ActorIdentityMigration::getPendingActors($database))->toHaveCount(1)
        ->and(sourceHashTree($root))->toBe($before);
    expect(ActorIdentityMigration::migrateProject($root))->toBe([$path, $root . '/assets/Data/Skits/breakfast-banter.php']);
    $written = file_get_contents($path);
    expect($written)->toContain("'id' => 'Kaelion'", '// Keep this authored header.', '// Keep this name comment.', "'level' => 1 + 2", "'description' => \$profile")
        ->and(ActorIdentityMigration::migrateProject($root))->toBe([]);
    $database = ProjectActorDatabase::fromProject($root);
    $actor = $database->getActors()[0];
    $actor->setField('name', 'Kaelion Renamed');
    $database->save();
    expect(file_get_contents($path))->toBe(str_replace("'name' => 'Kaelion'", "'name' => 'Kaelion Renamed'", $written));
    $store = $database->createActorStore();
    expect($store->require('Kaelion', 'restoring identity')->id)->toBe('Kaelion')
        ->and($store->has('Kaelion Renamed'))->toBeFalse();
});

it('supports source exact repair undo and redo across saves without allowing another identity', function () {
    [$root, $path, $source] = createLegacyActorProject();
    $database = ProjectActorDatabase::fromProject($root);
    $actor = $database->getActors()[0];
    $before = $actor->getData();
    ActorIdentityMigration::freezeCurrentName($database, $actor);
    $after = $actor->getData();
    expect(file_get_contents($path))->toBe($source);
    $database->save();
    $repaired = file_get_contents($path);
    $actor->restoreData($before);
    $database->save();
    expect(file_get_contents($path))->toBe($source);
    $actor->restoreData($after);
    $database->save();
    expect(file_get_contents($path))->toBe($repaired);
    expect(fn() => $actor->setField('id', 'another'))->toThrow(RuntimeException::class, 'permanent');
});

it('refuses unsupported source anywhere in the batch before writing any actor', function () {
    [$root, $path] = createLegacyActorProject();
    $other = dirname($path) . '/Other.php';
    file_put_contents($other, "<?php \$actor = ['data' => ['name' => 'Other']]; return \$actor;");
    $before = sourceHashTree($root);
    expect(fn() => ActorIdentityMigration::migrateProject($root))->toThrow(SourceUnreadable::class);
    expect(sourceHashTree($root))->toBe($before);
});

it('refuses a variable backed data block without flattening it', function () {
    [$root, $path] = createLegacyActorProject();
    file_put_contents($path, "<?php \$data = ['name' => 'Kaelion']; return ['data' => \$data];");
    $before = sourceHashTree($root);
    expect(fn() => ActorIdentityMigration::migrateProject($root))->toThrow(RuntimeException::class, 'refusing to flatten');
    expect(sourceHashTree($root))->toBe($before);
});

it('rolls back all staged actors when readback cannot reproduce the authored value', function () {
    [$root, $path] = createLegacyActorProject();
    file_put_contents(dirname($path) . '/Other.php', "<?php return ['data' => ['name' => 'Other', 'description' => __FILE__]];");
    $before = sourceHashTree($root);
    expect(fn() => ActorIdentityMigration::migrateProject($root))->toThrow(RuntimeException::class, 'would not read back');
    expect(sourceHashTree($root))->toBe($before);
});

it('refuses legacy identity collisions and never repairs malformed explicit ids', function () {
    [$root, $path] = createLegacyActorProject();
    $other = dirname($path) . '/Other.php';
    file_put_contents($other, "<?php return ['data' => ['id' => 'Kaelion', 'name' => 'Different']];");
    $before = sourceHashTree($root);
    expect(fn() => ActorIdentityMigration::migrateProject($root))->toThrow(RuntimeException::class, 'conflicts');
    expect(sourceHashTree($root))->toBe($before);
    foreach (['', null, 42] as $invalidId) {
        file_put_contents($path, '<?php return ' . var_export(['data' => ['id' => $invalidId, 'name' => 'Kaelion']], true) . ';');
        $database = ProjectActorDatabase::fromProject($root);
        expect(ActorIdentityMigration::getPendingActors($database))->toBe([]);
        expect(fn() => $database->getActors()[0]->setField('id', 'Kaelion'))->toThrow(RuntimeException::class, 'freeze');
    }
});

it('allows duplicate display names and a display name matching another id in the authoring registry', function () {
    [$root, $path] = createLegacyActorProject();
    file_put_contents($path, "<?php return ['data' => ['id' => 'one', 'name' => 'two']];");
    file_put_contents(dirname($path) . '/Second.php', "<?php return ['data' => ['id' => 'two', 'name' => 'Shared']];");
    file_put_contents(dirname($path) . '/Third.php', "<?php return ['data' => ['id' => 'three', 'name' => 'Shared']];");
    $store = ProjectActorDatabase::fromProject($root)->createActorStore();
    expect($store->get('two')->id)->toBe('two')->and($store->has('Shared'))->toBeFalse()->and($store->has('Kaelion'))->toBeFalse();
});

it('refuses outside source changes and changed expressions rather than flattening the actor', function () {
    [$root, $path, $source] = createLegacyActorProject();
    ActorIdentityMigration::migrateProject($root);
    $database = ProjectActorDatabase::fromProject($root);
    $actor = $database->getActors()[0];
    $before = file_get_contents($path);
    $actor->setField('level', 9);
    expect(fn() => $database->save())->toThrow(RuntimeException::class, 'expression');
    expect(file_get_contents($path))->toBe($before);
    $actor->setField('level', 3);
    $actor->setField('name', 'Renamed');
    file_put_contents($path, $before . "\n// Author's later edit\n");
    expect(fn() => $database->save())->toThrow(RuntimeException::class, 'changed outside');
    expect(file_get_contents($path))->toBe($before . "\n// Author's later edit\n");
});

it('plans every actor reference without writes and atomically applies undoes and redoes exact source', function () {
    [$root, $actorPath] = createLegacyActorProject();
    // Already-frozen IDs still need repair of the original name and file references.
    file_put_contents($actorPath, "<?php return ['data' => ['id' => 'hero', 'name' => 'Kaelion']];");
    $sources = [
        'assets/Data/system.php' => "<?php // Keep header\nreturn ['startingParty' => [/* member */ 'Kaelion'], 'note' => 'Kaelion'];",
        'assets/Data/Skits/old.php' => "<?php return ['beats' => [['actor' => 'Kaelion', 'text' => 'Kaelion'], ['speaker' => 'Kaelion', 'text' => 'Hello'], ['speaker' => 'Stranger', 'text' => 'Kaelion']]];",
        'assets/Data/battle-entry-rules.php' => "<?php return ['rules' => [['actors' => [['actor' => 'Kaelion']], 'effects' => [['type' => 'stat_stage', 'actor' => 'Kaelion']]]]];",
        'assets/Events/actor-reference.php' => "<?php return [['type' => 'sequence', 'commands' => [['type' => 'stage_actor', 'actor' => ['id' => 'Kaelion']]]], ['type' => 'show_actor', 'actorId' => 'Kaelion'], ['type' => 'text', 'name' => 'Kaelion', 'text' => 'Hello']];",
        'assets/Data/troops.php' => "<?php return [['name' => 'Kaelion', 'enemies' => [['enemy' => 'Kaelion']], 'events' => [['type' => 'text', 'name' => 'Kaelion', 'text' => 'Hello']]]];",
        'assets/Data/Presentation/dialogue.php' => "<?php\nuse Ichiloto\\Engine\\Messaging\\Dialogue\\Presentation\\DialoguePresentationCatalog;\nreturn new DialoguePresentationCatalog(actors: [/* portrait */ 'Kaelion' => ['emotions' => ['Neutral' => 'Kaelion.png']]]);",
        'assets/Data/Presentation/battle.php' => "<?php\nuse Ichiloto\\Engine\\Battle\\Presentation\\BattlePresentationCatalog;\nuse Ichiloto\\Engine\\Battle\\Presentation\\BattlerArtwork;\nreturn new BattlePresentationCatalog([], ['Kaelion' => new BattlerArtwork('hero.png', 1, 1, 0, 0)], []);",
        'assets/Data/Presentation/menus.php' => "<?php return ['portraits' => [/* keep */ 'Kaelion' => 'Kaelion.png']];",
    ];
    foreach ($sources as $relative => $source) {
        if (! is_dir(dirname($root . '/' . $relative))) { mkdir(dirname($root . '/' . $relative), 0777, true); }
        file_put_contents($root . '/' . $relative, $source);
    }
    $before = sourceHashTree($root);
    $plan = ActorIdentityMigration::planProject($root);
    expect($plan->getChangedPaths())->not->toContain($actorPath)->and(sourceHashTree($root))->toBe($before);
    foreach (array_keys($sources) as $relative) {
        if (in_array($relative, ['assets/Events/actor-reference.php', 'assets/Data/troops.php'], true)) {
            expect($plan->getChangedPaths())->not->toContain($root . '/' . $relative);
        } else { expect($plan->getChangedPaths())->toContain($root . '/' . $relative); }
    }
    $plan->apply();
    $after = sourceHashTree($root);
    expect(file_get_contents($root . '/assets/Data/system.php'))->toBe(str_replace("/* member */ 'Kaelion'", "/* member */ 'hero'", $sources['assets/Data/system.php']))
        ->and(file_get_contents($root . '/assets/Events/actor-reference.php'))->toBe($sources['assets/Events/actor-reference.php'])
        ->and(file_get_contents($root . '/assets/Data/troops.php'))->toBe($sources['assets/Data/troops.php'])
        ->and(file_get_contents($root . '/assets/Events/actor-reference.php'))->toContain("'actorId' => 'Kaelion'", "'id' => 'Kaelion'", "'name' => 'Kaelion'")
        ->and(file_get_contents($root . '/assets/Data/Presentation/dialogue.php'))->toContain("/* portrait */ 'hero'", "'Neutral' => 'Kaelion.png'")
        ->and(ActorIdentityMigration::planProject($root)->getChangedPaths())->toBe([]);
    $plan->revert();
    expect(sourceHashTree($root))->toBe($before);
    $plan->apply();
    expect(sourceHashTree($root))->toBe($after);
    file_put_contents($root . '/assets/Data/system.php', $sources['assets/Data/system.php'] . '// outside edit');
    $outside = sourceHashTree($root);
    expect(fn() => $plan->revert())->toThrow(RuntimeException::class, 'changed outside');
    expect(sourceHashTree($root))->toBe($outside);
});

it('refuses ambiguous references and key collisions before modifying any file but honours explicit ids first', function () {
    [$root, $path] = createLegacyActorProject();
    file_put_contents($path, "<?php return ['data' => ['id' => 'first', 'name' => 'Shared']];");
    file_put_contents(dirname($path) . '/Other.php', "<?php return ['data' => ['id' => 'second', 'name' => 'Shared']];");
    $system = $root . '/assets/Data/system.php';
    file_put_contents($system, "<?php return ['startingParty' => ['Shared']];");
    $before = sourceHashTree($root);
    expect(fn() => ActorIdentityMigration::planProject($root))->toThrow(RuntimeException::class, 'ambiguous');
    expect(sourceHashTree($root))->toBe($before);
    file_put_contents(dirname($path) . '/Shared.php', "<?php return ['data' => ['id' => 'Shared', 'name' => 'Modern']];");
    expect(ActorIdentityMigration::planProject($root)->getChangedPaths())->not->toContain($system);
    mkdir($root . '/assets/Data/Presentation');
    file_put_contents($root . '/assets/Data/Presentation/menus.php', "<?php return ['portraits' => ['Kaelion' => 'old.png', 'first' => 'new.png']];");
    $before = sourceHashTree($root);
    expect(fn() => ActorIdentityMigration::planProject($root))->toThrow(RuntimeException::class, 'overwrite key');
    expect(sourceHashTree($root))->toBe($before);
});

it('refuses dynamic reference expressions with file-qualified diagnostics and no partial actor migration', function () {
    [$root, $path] = createLegacyActorProject();
    file_put_contents($path, "<?php return ['data' => ['name' => 'Full Name']];");
    $system = $root . '/assets/Data/system.php';
    file_put_contents($system, "<?php \$member = 'Kaelion'; return ['startingParty' => [\$member]];");
    $before = sourceHashTree($root);
    expect(fn() => ActorIdentityMigration::planProject($root))->toThrow(RuntimeException::class, 'system.php:')
        ->and(sourceHashTree($root))->toBe($before);
});

it('validates unresolved starting party and all explicit actor references against stable ids', function () {
    [$root, $path] = createLegacyActorProject();
    file_put_contents($path, "<?php return ['data' => ['id' => 'hero', 'name' => 'Kaelion']];");
    file_put_contents($root . '/assets/Data/system.php', "<?php return ['startingParty' => ['Kaelion']];");
    $workspace = \Ichiloto\Editor\ProjectWorkspace::fromProject($root);
    $issues = new \Ichiloto\Editor\Validation\ProjectValidator()->validate($workspace);
    expect(array_filter($issues, static fn($issue) => str_contains($issue->where, 'startingParty') && str_contains($issue->message, 'Unresolved actor')))->not->toBeEmpty();
    ActorIdentityMigration::migrateProject($root);
    $issues = new \Ichiloto\Editor\Validation\ActorReferenceValidator()->validate(\Ichiloto\Editor\ProjectWorkspace::fromProject($root));
    expect($issues)->toBe([]);
});

it('uses each consumer case semantics without restoring display name aliases', function () {
    [$root, $path] = createLegacyActorProject();
    file_put_contents($path, "<?php return ['data' => ['id' => 'HERO', 'name' => 'Kaelion']];");
    file_put_contents($root . '/assets/Data/system.php', "<?php return ['startingParty' => [' hero ']];");
    $validator = new \Ichiloto\Editor\Validation\ActorReferenceValidator();
    expect($validator->validate(\Ichiloto\Editor\ProjectWorkspace::fromProject($root)))->toBe([]);
    file_put_contents($root . '/assets/Data/Skits/case.php', "<?php return ['beats' => [['actor' => 'hero', 'text' => 'Hello']]];");
    $issues = $validator->validate(\Ichiloto\Editor\ProjectWorkspace::fromProject($root));
    expect($issues)->toHaveCount(1)->and($issues[0]->where)->toContain('beats.0.actor');
    file_put_contents($root . '/assets/Data/system.php', "<?php return ['startingParty' => ['Kaelion']];");
    $issues = $validator->validate(\Ichiloto\Editor\ProjectWorkspace::fromProject($root));
    expect($issues)->toHaveCount(2);
});
