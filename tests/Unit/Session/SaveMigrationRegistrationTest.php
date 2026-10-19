<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * Registering a project's own save migration class through the session: the
 * next manifest step names the class alone, written source-preserved once the
 * staged manifest reads back as the game reads it, through the project's own
 * autoloader. The fixture is the cutscene project, given an autoloader that
 * loads the Engine and a `Synthetic\` namespace of its own.
 */

/** The project root with its own autoloader, a migration class and a class that is not one. */
function migrationProject(): string
{
    $root = cutsceneProject();
    $editorAutoload = var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true);
    mkdir($root . '/vendor', 0o777, true);
    file_put_contents($root . '/vendor/autoload.php', <<<PHP
    <?php
    \$loader = require {$editorAutoload};
    if (is_string(getenv('ICHILOTO_ENGINE_SRC')) && getenv('ICHILOTO_ENGINE_SRC') !== '') {
        \$loader->setPsr4('Ichiloto\\\\Engine\\\\', [getenv('ICHILOTO_ENGINE_SRC') . '/src']);
    }
    \$loader->setPsr4('Synthetic\\\\', [__DIR__ . '/../assets/Migrations']);
    return \$loader;
    PHP);
    mkdir($root . '/assets/Migrations', 0o777, true);
    file_put_contents($root . '/assets/Migrations/RepairArrival.php', "<?php\n\nnamespace Synthetic;\n\nfinal class RepairArrival implements \\Ichiloto\\Engine\\IO\\SaveCompatibility\\ContentMigrationInterface\n{\n    public function migrate(array \$payload): array\n    {\n        return \$payload;\n    }\n}\n");
    file_put_contents($root . '/assets/Migrations/NotAMigration.php', "<?php\n\nnamespace Synthetic;\n\nfinal class NotAMigration\n{\n}\n");
    $manifest = $root . '/assets/Data/save-compatibility.php';
    file_put_contents($manifest, str_replace("return [", "// Authored by the release team.\nreturn [", (string) file_get_contents($manifest)));

    return $root;
}

function readMigrationManifest(string $root): array
{
    return require $root . '/assets/Data/save-compatibility.php';
}

it('registers a migration class as the next step alone, keeping the manifest as authored, with undo', function () {
    $root = migrationProject();
    $path = $root . '/assets/Data/save-compatibility.php';
    $before = (string) file_get_contents($path);
    $session = EditorSession::open($root);

    expect($session->registerSaveMigration('\\Synthetic\\RepairArrival'))
        ->toBe(['from' => 0, 'to' => 1, 'class' => 'Synthetic\\RepairArrival', 'contentVersion' => 1])
        ->and(readMigrationManifest($root))->toMatchArray(['contentVersion' => 1, 'migrations' => [['from' => 0, 'to' => 1, 'class' => 'Synthetic\\RepairArrival']]])
        ->and((string) file_get_contents($path))->toContain('// Authored by the release team.')
        ->and(describeSaveCompatibilityIssues($root))->toBe([]);

    expect($session->undo()['label'])->toBe('Register save migration')
        ->and((string) file_get_contents($path))->toBe($before);
    $session->redo();
    expect(readMigrationManifest($root)['contentVersion'])->toBe(1);
});

it('refuses a class the game would not run as a migration, writing nothing', function (string $class, string $reason) {
    $root = migrationProject();
    $before = sourceHashTree($root . '/assets');
    $session = EditorSession::open($root);

    expect(fn() => $session->registerSaveMigration($class))->toThrow(SessionRefusal::class, $reason)
        ->and(sourceHashTree($root . '/assets'))->toBe($before)
        ->and($session->undo()['label'])->toBeNull();
})->with([
    'not a migration' => ['Synthetic\\NotAMigration', 'class "Synthetic\\NotAMigration" must implement Ichiloto\\Engine\\IO\\SaveCompatibility\\ContentMigrationInterface'],
    'a class that does not load' => ['Synthetic\\Missing', 'class "Synthetic\\Missing" must implement'],
    'no class' => ['  ', 'Name the migration class to register.'],
]);

it('refuses when the project has no autoloader or a manifest it cannot rewrite', function () {
    $root = migrationProject();
    unlink($root . '/vendor/autoload.php');
    $session = EditorSession::open($root);
    expect(fn() => $session->registerSaveMigration('Synthetic\\RepairArrival'))->toThrow(SessionRefusal::class, 'the project has no vendor/autoload.php');

    $root = migrationProject();
    $path = $root . '/assets/Data/save-compatibility.php';
    file_put_contents($path, str_replace("'migrations' => []", "'migrations' => array_values([])", (string) file_get_contents($path)));
    $before = (string) file_get_contents($path);
    $session = EditorSession::open($root);
    expect(fn() => $session->registerSaveMigration('Synthetic\\RepairArrival'))->toThrow(SessionRefusal::class, 'cannot take the migration step')
        ->and((string) file_get_contents($path))->toBe($before);
});

it('writes a change set that registers a migration, and refuses one that also moves saves off map cells', function () {
    $root = migrationProject();
    // The quay is where the game starts, so saves may stand on its floor.
    file_put_contents($root . '/assets/Maps/collisions.php', "<?php\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\n"
        . "return ['#' => CollisionType::SOLID, '=' => CollisionType::SOLID, ' ' => CollisionType::NONE];\n");
    file_put_contents($root . '/assets/Data/system.php', "<?php\n\nreturn ['startingPositions' => ['player' => "
        . "['destinationMap' => 'harbour', 'spawnPoint' => ['x' => 5, 'y' => 3], 'spawnSprite' => ['South']]]];\n");
    $before = sourceHashTree($root . '/assets');
    $session = EditorSession::open($root);

    $session->beginChanges();
    $session->registerSaveMigration('Synthetic\\RepairArrival');
    $map = $session->readMap('harbour');
    $session->paint('harbour', $map['revision'], $map['baseLayer'], [[6, 4]], '#');
    $refused = $session->commitChanges(relocateSaves: true);

    expect($refused['status'])->toBe('refused')
        ->and($refused['reason'])->toContain('The Engine has no save migration step that does both')
        ->and(sourceHashTree($root . '/assets'))->toBe($before)
        ->and($session->listUnsavedChanges())->toBe([]);

    $session->beginChanges();
    $session->registerSaveMigration('Synthetic\\RepairArrival');
    expect($session->commitChanges()['status'])->toBe('saved')
        ->and(readMigrationManifest($root)['migrations'])->toBe([['from' => 0, 'to' => 1, 'class' => 'Synthetic\\RepairArrival']]);
});
