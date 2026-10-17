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
    // The public migration supplies file context without discarding the source parser cause.
    try {
        ActorIdentityMigration::migrateProject($root);
        $this->fail('Unsupported actor source must be refused.');
    } catch (RuntimeException $failure) {
        expect($failure->getPrevious())->toBeInstanceOf(SourceUnreadable::class)
            ->and(substr_count($failure->getMessage(), $other))->toBe(1);
    }
    expect(sourceHashTree($root))->toBe($before);
});

it('refuses a variable backed data block without flattening it', function () {
    [$root, $path] = createLegacyActorProject();
    file_put_contents($path, "<?php \$data = ['name' => 'Kaelion']; return ['data' => \$data];");
    $before = sourceHashTree($root);
    try {
        ActorIdentityMigration::migrateProject($root);
        $this->fail('Variable-backed actor data must be refused.');
    } catch (RuntimeException $failure) {
        expect($failure->getPrevious())->toBeInstanceOf(\Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal::class)
            ->and($failure->getMessage())->toContain('refusing to flatten')
            ->and(substr_count($failure->getMessage(), $path))->toBe(1);
    }
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
    expect($issues)->toHaveCount(1)
        ->and($issues[0]->where)->toBe('assets/Data/Skits/case.php:beats.0.actor')
        ->and($issues[0]->code)->toBe(\Ichiloto\Editor\Validation\ActorReferenceValidator::UNRESOLVED_ACTOR_REFERENCE);
    file_put_contents($root . '/assets/Data/system.php', "<?php return ['startingParty' => ['Kaelion']];");
    $issues = $validator->validate(\Ichiloto\Editor\ProjectWorkspace::fromProject($root));
    expect($issues)->toHaveCount(2);
});

it('keeps skit diagnostics attached to their files when sorted record positions change', function () {
    [$root, $path] = createLegacyActorProject();
    file_put_contents($path, "<?php return ['data' => ['id' => 'Kaelion', 'name' => 'Kaelion']];");
    $skits = $root . '/assets/Data/Skits';
    file_put_contents($skits . '/z-last-scene.php', "<?php return ['beats' => [['actor' => 'missing', 'text' => 'Hello']]];");
    $validator = new \Ichiloto\Editor\Validation\ActorReferenceValidator();
    $before = $validator->validate(\Ichiloto\Editor\ProjectWorkspace::fromProject($root));
    file_put_contents($skits . '/a-first-scene.php', "<?php return ['beats' => [['actor' => 'Kaelion', 'text' => 'Hello']]];");
    $after = $validator->validate(\Ichiloto\Editor\ProjectWorkspace::fromProject($root));
    expect($before)->toHaveCount(1)->and($after)->toEqual($before)
        ->and($after[0]->where)->toBe('assets/Data/Skits/z-last-scene.php:beats.0.actor')
        ->and(\Ichiloto\Editor\Validation\Issue::error('elsewhere', 'Other failure')->code)->toBeNull();
});

it('repairs every actor keyed presentation reference without touching enemies or artwork resources', function () {
    [$root, $actorPath] = createLegacyActorProject();
    file_put_contents($actorPath, "<?php return ['data' => ['id' => 'hero', 'name' => 'Kaelion']];");
    mkdir($root . '/assets/Data/Presentation');
    $battle = <<<'PHP'
<?php
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlePoseSet;
use Ichiloto\Engine\Battle\Presentation\BattlerArtwork;
return new BattlePresentationCatalog(
    enemyPoses: ['Kaelion' => new BattlePoseSet([], displayWidth: 90)],
    // Party poses follow the same stable identity as their artwork.
    actorPoses: [/* lead */ 'Kaelion' => new BattlePoseSet([], displayWidth: 120)],
    arenas: [],
    enemies: ['Kaelion' => new BattlerArtwork('shade.png', 1, 1, 0, 0)],
    actors: ['Kaelion' => new BattlerArtwork('hero.png', 1, 1, 0, 0)],
);
PHP;
    $dialogue = <<<'PHP'
<?php
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;
return new DialoguePresentationCatalog(
    actors: ['Kaelion' => ['emotions' => []]],
    resources: ['Fira' => ['emotions' => []]],
    speakers: ['Kael' => 'Kaelion', 'Fira Nel' => 'Fira'],
);
PHP;
    file_put_contents($root . '/assets/Data/Presentation/battle.php', $battle);
    file_put_contents($root . '/assets/Data/Presentation/dialogue.php', $dialogue);
    ActorIdentityMigration::migrateProject($root);
    expect(file_get_contents($root . '/assets/Data/Presentation/battle.php'))->toBe(str_replace(
        ["/* lead */ 'Kaelion'", "actors: ['Kaelion'"], ["/* lead */ 'hero'", "actors: ['hero'"], $battle))
        ->and(file_get_contents($root . '/assets/Data/Presentation/dialogue.php'))->toBe(str_replace(
            ["actors: ['Kaelion'", "'Kael' => 'Kaelion'"], ["actors: ['hero'", "'Kael' => 'hero'"], $dialogue))
        ->and(ActorIdentityMigration::planProject($root)->getChangedPaths())->toBe([])
        ->and(new \Ichiloto\Editor\Validation\ActorReferenceValidator()->validate(\Ichiloto\Editor\ProjectWorkspace::fromProject($root)))->toBe([]);
});

it('refuses a variable backed presentation argument rather than stranding or flattening its actor keys', function () {
    [$root, $actorPath] = createLegacyActorProject();
    file_put_contents($actorPath, "<?php return ['data' => ['id' => 'hero', 'name' => 'Kaelion']];");
    mkdir($root . '/assets/Data/Presentation');
    file_put_contents($root . '/assets/Data/Presentation/battle.php', <<<'PHP'
<?php
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlePoseSet;
$poses = ['Kaelion' => new BattlePoseSet([], displayWidth: 120)];
return new BattlePresentationCatalog([], [], [], actorPoses: $poses);
PHP);
    $before = sourceHashTree($root);
    try {
        ActorIdentityMigration::planProject($root);
        $this->fail('A variable-backed pose catalog must be refused.');
    } catch (RuntimeException $failure) {
        expect($failure->getPrevious())->toBeInstanceOf(\Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal::class)
            ->and($failure->getMessage())->toContain('battle.php', 'actorPoses', 'refusing to flatten');
    }
    expect(sourceHashTree($root))->toBe($before);
});

it('repairs the battle scale reference actor and actor profiles while preserving enemies and calibration', function () {
    [$root, $actorPath] = createLegacyActorProject();
    file_put_contents($actorPath, "<?php return ['data' => ['id' => 'hero', 'name' => 'Kaelion']];");
    mkdir($root . '/assets/Data/Presentation');
    $battle = <<<'PHP'
<?php
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlerArtwork;
use Ichiloto\Engine\Battle\Presentation\BattleScale;
use Ichiloto\Engine\Battle\Presentation\BattlerScale;
return new BattlePresentationCatalog(
    arenas: [],
    actors: ['Kaelion' => new BattlerArtwork('hero.png', 1, 1, 0, 0)],
    enemies: ['Kaelion' => new BattlerArtwork('shade.png', 1, 1, 0, 0)],
    scale: new BattleScale(
        // The party lead is the body every battler is sized against.
        referenceActorId: 'Kaelion',
        referenceHeight: 210.0,
        actors: ['Kaelion' => new BattlerScale(1.0, 0.82)],
        enemies: ['Kaelion' => new BattlerScale(0.4, 0.6, horizontal: true)],
    ),
);
PHP;
    file_put_contents($root . '/assets/Data/Presentation/battle.php', $battle);
    ActorIdentityMigration::migrateProject($root);
    expect(file_get_contents($root . '/assets/Data/Presentation/battle.php'))->toBe(str_replace(
        ["actors: ['Kaelion' => new BattlerArtwork", "referenceActorId: 'Kaelion'", "actors: ['Kaelion' => new BattlerScale"],
        ["actors: ['hero' => new BattlerArtwork", "referenceActorId: 'hero'", "actors: ['hero' => new BattlerScale"], $battle))
        ->and(ActorIdentityMigration::planProject($root)->getChangedPaths())->toBe([])
        ->and(new \Ichiloto\Editor\Validation\ActorReferenceValidator()->validate(\Ichiloto\Editor\ProjectWorkspace::fromProject($root)))->toBe([]);
});

it('repairs battle art bound as data: actor keys and the scale reference, never enemies or image paths', function () {
    [$root, $actorPath] = createLegacyActorProject();
    file_put_contents($actorPath, "<?php return ['data' => ['id' => 'hero', 'name' => 'Kaelion']];");
    mkdir($root . '/assets/Data/Presentation');
    $battlers = <<<'SOURCE'
<?php
return [
    // The party lead is the body every battler is sized against.
    'reference' => ['actor' => 'Kaelion', 'height' => 150],
    'actors' => [
        'Kaelion' => ['artwork' => ['image' => 'Graphics/Kaelion/Idle.png'], 'scale' => ['relativeSize' => 1, 'sourceSpan' => 0.7]],
    ],
    'enemies' => [
        'Kaelion' => ['artwork' => ['image' => 'Graphics/Kaelion/Shade.png']],
    ],
];
SOURCE;
    file_put_contents($root . '/assets/Data/Presentation/battlers.php', $battlers);
    ActorIdentityMigration::migrateProject($root);

    expect(file_get_contents($root . '/assets/Data/Presentation/battlers.php'))->toBe(str_replace(
        ["'actor' => 'Kaelion'", "        'Kaelion' => ['artwork' => ['image' => 'Graphics/Kaelion/Idle.png']"],
        ["'actor' => 'hero'", "        'hero' => ['artwork' => ['image' => 'Graphics/Kaelion/Idle.png']"], $battlers))
        ->and(ActorIdentityMigration::planProject($root)->getChangedPaths())->toBe([]);
});

/** A summon whose wielder policy names actors, beside the other policy fields a migration must leave alone. */
function writeWielderSummon(string $root, string $characters): string
{
    $directory = $root . '/assets/Cutscenes/Summons/ember';
    @mkdir($directory, 0777, true);
    file_put_contents($directory . '/ember.timeline.php', "<?php return ['fps' => 12, 'lengthFrames' => 1, 'tracks' => [], 'cues' => []];");
    $path = $directory . '/ember.data.php';
    file_put_contents($path, "<?php\n// Keep this summon header.\nreturn ['id' => 'ember', 'name' => 'Ember', 'linkedActionId' => 'Fireball',\n    'wielders' => ['mode' => 'characters', 'characters' => [{$characters}], 'tenancy' => 'exclusive']];\n");

    return $path;
}

it('repairs summon wielders to stable ids, source preserving, leaving mode and tenancy as they are', function () {
    [$root, $actorPath] = createLegacyActorProject();
    file_put_contents($actorPath, "<?php return ['data' => ['id' => 'hero', 'name' => 'Kaelion']];");
    $summon = writeWielderSummon($root, "/* lead */ 'Kaelion'");
    $before = sourceHashTree($root);

    $plan = ActorIdentityMigration::planProject($root);
    expect($plan->getChangedPaths())->toContain($summon)->and(sourceHashTree($root))->toBe($before);
    $plan->apply();

    $written = (string) file_get_contents($summon);
    expect($written)->toContain('// Keep this summon header.', "/* lead */ 'hero'", "'mode' => 'characters'", "'tenancy' => 'exclusive'")
        ->and(ActorIdentityMigration::planProject($root)->getChangedPaths())->toBe([])
        ->and(new \Ichiloto\Editor\Validation\ActorReferenceValidator()->validate(\Ichiloto\Editor\ProjectWorkspace::fromProject($root)))->toBe([]);
    $plan->revert();
    expect(sourceHashTree($root))->toBe($before);
});

it('refuses a summon wielder two actors are displayed as, and never hands one actor\'s id to another', function () {
    [$root, $actorPath] = createLegacyActorProject();
    file_put_contents($actorPath, "<?php return ['data' => ['id' => 'first', 'name' => 'Twin']];");
    file_put_contents(dirname($actorPath) . '/Second.php', "<?php return ['data' => ['id' => 'second', 'name' => 'Twin']];");
    $summon = writeWielderSummon($root, "'Twin'");
    $before = sourceHashTree($root);
    expect(fn() => ActorIdentityMigration::planProject($root))->toThrow(RuntimeException::class, 'ambiguous')
        ->and(sourceHashTree($root))->toBe($before);

    // An explicit id wins over another actor displayed under the same text.
    file_put_contents(dirname($actorPath) . '/Third.php', "<?php return ['data' => ['id' => 'Twin', 'name' => 'Modern']];");
    expect(ActorIdentityMigration::planProject($root)->getChangedPaths())->not->toContain($summon);
});

it('judges summon eligibility by stable id, so a display rename keeps a holder and a shared name grants nothing', function () {
    $diagnostics = new \Ichiloto\Editor\Database\SummonAssignmentDiagnostics([
        'ember' => ['id' => 'ember', 'wielders' => ['mode' => 'characters', 'characters' => ['Hero'], 'tenancy' => 'exclusive']],
    ]);
    [$root, $actorPath] = createLegacyActorProject();
    file_put_contents($actorPath, "<?php return ['data' => ['id' => 'hero', 'name' => 'Kaelion Renamed']];");
    file_put_contents(dirname($actorPath) . '/Impostor.php', "<?php return ['data' => ['id' => 'impostor', 'name' => 'Hero']];");
    file_put_contents(dirname($actorPath) . '/Legacy.php', "<?php return ['data' => ['name' => 'Hero']];");
    $actors = [];
    foreach (ProjectActorDatabase::fromProject($root)->getActors() as $actor) {
        $actors[$actor->getName() . '/' . ($actor->getDefinitionId() ?: 'legacy')] = $actor->getRuntimeId();
    }

    expect($diagnostics->forActor($actors['Kaelion Renamed/hero'], 'Vanguard', ['ember'])[0]['problems'])->toBe([])
        ->and($diagnostics->forActor($actors['Hero/impostor'], 'Vanguard', ['ember'])[0]['problems'])->not->toBe([])
        // A legacy actor declaring no id is known by its authored name until the migration writes one.
        ->and($actors['Hero/legacy'])->toBe('Hero')
        ->and($diagnostics->forActor($actors['Hero/legacy'], 'Vanguard', ['ember'])[0]['problems'])->toBe([]);
});
