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
    expect(ActorIdentityMigration::migrateProject($root))->toBe([$path]);
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
