<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\SuperMoveFields;
use Ichiloto\Editor\Database\SuperMoveMigration;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Engine\Battle\CrisisAbilityCatalog;
use Ichiloto\Engine\Entities\Actors\ActorDefinition;

/** A synthetic catalogue in the shape both Super Move files hold, with a comment the move must keep. */
function superMoveCatalogueSource(string $id = 'synthetic-surge'): string
{
    return <<<PHP
<?php

// Synthetic finishing techniques.
return [
  [
    'id' => '{$id}',
    'name' => 'Surge',
    'label' => 'SRG',
    'hpBelowPercent' => 25,
    'states' => ['focus'],
    'variants' => [
      ['skill' => 'Test technique', 'conditions' => [['type' => 'switch', 'name' => 'first-form']]],
      ['skill' => 'Test technique', 'conditions' => [['type' => 'switch', 'name' => 'second-form']]],
    ],
  ],
];

PHP;
}

/**
 * Writes a synthetic actor whose data names its Super Move under the given keys, between two other fields.
 *
 * @param array<string, mixed> $keys
 */
function writeSuperMoveActor(string $root, string $id, array $keys): string
{
    $lines = '';
    foreach ($keys as $key => $value) {
        $lines .= sprintf("    '%s' => %s,\n", $key, var_export($value, true));
    }
    $path = $root . '/assets/Data/Actors/' . $id . '.php';
    file_put_contents($path, <<<PHP
<?php

return [
  'class' => \\Ichiloto\\Engine\\Entities\\Character::class,
  'data' => [
    'id' => '{$id}',
    'name' => '{$id}',
{$lines}    'level' => 1,
    'currentExp' => 0,
  ],
];

PHP);

    return $path;
}

/** Every file under assets/Data by relative path, with its bytes. */
function snapshotProjectData(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/assets/Data', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $files[substr($file->getPathname(), strlen($root) + 1)] = file_get_contents($file->getPathname());
    }
    ksort($files);

    return $files;
}

function makeLegacySuperMoveProject(): string
{
    $root = makeTemporaryProject();
    writeSkillRecords($root, new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Test technique', 'Strikes.', '', 2, 0));
    file_put_contents($root . '/assets/Data/states.php', '<?php return ' . var_export([['id' => 'focus', 'name' => 'Focus']], true) . ';');
    file_put_contents($root . '/' . SuperMoveFields::LEGACY_CATALOGUE, superMoveCatalogueSource());

    return $root;
}

it('reads a legacy catalogue and actor key, read-only until the project migrates', function () {
    $root = makeLegacySuperMoveProject();
    writeSuperMoveActor($root, 'synthetic-hero', ['crisisAbility' => 'synthetic-surge']);
    $workspace = ProjectWorkspace::fromProject($root);
    $database = $workspace->getRecordDatabase(SuperMoveFields::CATEGORY);

    expect($database->getEntryLabels())->toBe(['synthetic-surge'])
        ->and($database->isEditable())->toBeFalse()
        ->and($database->getReadOnlyReason())->toContain('Migrate to Super Moves')
        ->and($database->path)->toEndWith(SuperMoveFields::LEGACY_CATALOGUE)
        ->and(array_find($workspace->actorDatabase->getActors(), static fn($actor): bool => $actor->getDefinitionId() === 'synthetic-hero')
            ->getSuperMove())->toBe('synthetic-surge')
        ->and(array_filter(new ProjectValidator()->validate($workspace),
            static fn($issue): bool => str_contains($issue->message, 'Super Move')))->toBe([]);
});

it('reports both catalogues and disagreeing actor keys in plain words', function () {
    $root = makeLegacySuperMoveProject();
    file_put_contents($root . '/' . SuperMoveFields::CATALOGUE, superMoveCatalogueSource());
    writeSuperMoveActor($root, 'synthetic-hero', ['superMove' => 'synthetic-surge', 'crisisAbility' => 'other-surge']);
    writeSuperMoveActor($root, 'synthetic-cleared', ['superMove' => null, 'crisisAbility' => 'synthetic-surge']);
    $workspace = ProjectWorkspace::fromProject($root);
    $issues = new ProjectValidator()->validate($workspace);
    $find = static fn(string $code): array => array_values(array_filter($issues, static fn($issue): bool => $issue->code === $code));

    $ambiguous = $find(ProjectValidator::SUPER_MOVE_CATALOGUES_AMBIGUOUS);
    expect($ambiguous)->toHaveCount(1)
        ->and($ambiguous[0]->message)->toBe('Both assets/Data/super-moves.php and assets/Data/crisis-abilities.php declare Super Moves. The game refuses a project with both.')
        ->and($workspace->getRecordDatabase(SuperMoveFields::CATEGORY)->getReadOnlyReason())->toContain('holds both');

    $disagree = $find(ProjectValidator::ACTOR_SUPER_MOVE_KEYS_DISAGREE);
    expect(array_map(static fn($issue): string => $issue->message, $disagree))->toEqualCanonicalizing([
        'Its superMove and crisisAbility keys name different Super Moves (synthetic-surge and other-surge).',
        'Its superMove and crisisAbility keys name different Super Moves (none and synthetic-surge).',
    ]);
});

it('migrates the catalogue and actor keys in one transaction that one undo restores', function () {
    $root = makeLegacySuperMoveProject();
    $renamed = writeSuperMoveActor($root, 'synthetic-hero', ['crisisAbility' => 'synthetic-surge']);
    $agreeing = writeSuperMoveActor($root, 'synthetic-twin', ['crisisAbility' => 'synthetic-surge', 'superMove' => 'synthetic-surge']);
    $cleared = writeSuperMoveActor($root, 'synthetic-plain', ['crisisAbility' => null]);
    $before = snapshotProjectData($root);
    $legacyEntries = CrisisAbilityCatalog::fromProject($root . '/assets')->getAbilities();

    $session = EditorSession::open($root);
    $question = $session->migrateSuperMoves();
    expect($question['status'])->toBe('question')
        ->and(array_column($question['answers'], 'key'))->toBe(['cancel', 'write'])
        ->and(snapshotProjectData($root))->toBe($before);

    $result = $session->migrateSuperMoves('write', $question['confirm']);
    expect($result)->toMatchArray(['changed' => true, 'reloaded' => true, 'records' => ['synthetic-surge']])
        ->and(is_file($root . '/' . SuperMoveFields::LEGACY_CATALOGUE))->toBeFalse()
        // The catalogue moves byte for byte, so every id, label, condition and variant stays as authored.
        ->and(file_get_contents($root . '/' . SuperMoveFields::CATALOGUE))->toBe($before[SuperMoveFields::LEGACY_CATALOGUE])
        ->and(CrisisAbilityCatalog::fromProject($root . '/assets')->getAbilities())->toEqual($legacyEntries)
        // The key is renamed where it stood; nothing else in the actor changes.
        ->and(file_get_contents($renamed))->toBe(str_replace("'crisisAbility' =>", "'superMove' =>", $before['assets/Data/Actors/synthetic-hero.php']))
        ->and(file_get_contents($agreeing))->toBe(str_replace("    'crisisAbility' => 'synthetic-surge',\n", '', $before['assets/Data/Actors/synthetic-twin.php']))
        ->and(file_get_contents($cleared))->toBe(str_replace("'crisisAbility' =>", "'superMove' =>", $before['assets/Data/Actors/synthetic-plain.php']));

    foreach (['synthetic-hero' => 'synthetic-surge', 'synthetic-twin' => 'synthetic-surge', 'synthetic-plain' => null] as $id => $expected) {
        $data = (require $root . '/assets/Data/Actors/' . $id . '.php')['data'];
        expect(CrisisAbilityCatalog::getActorReference($data))->toBe($expected);
        ActorDefinition::fromArray($data);
    }
    expect($session->readDatabaseRecord(SuperMoveFields::CATEGORY, 0)['rows'])->not->toBe([])
        ->and(ProjectWorkspace::fromProject($root)->getRecordDatabase(SuperMoveFields::CATEGORY)->isEditable())->toBeTrue();

    $session->undo();
    expect(snapshotProjectData($root))->toBe($before);
    $session->redo();
    expect(is_file($root . '/' . SuperMoveFields::CATALOGUE))->toBeTrue()
        ->and(is_file($root . '/' . SuperMoveFields::LEGACY_CATALOGUE))->toBeFalse();
    $session->undo();
    expect(snapshotProjectData($root))->toBe($before);
});

it('migrates from the Terminal command palette as one undo step', function () {
    $root = makeLegacySuperMoveProject();
    writeSuperMoveActor($root, 'synthetic-hero', ['crisisAbility' => 'synthetic-surge']);
    $before = snapshotProjectData($root);
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));

    callEditorMethod($editor, 'openSuperMoveMigration');
    $entries = getEditorProperty($editor, 'eventOptionDialogEntries');
    expect(array_column($entries, 'value'))->toBe(['cancel', 'migrate', 'preview', 'preview', 'preview'])
        ->and(array_slice(array_column($entries, 'label'), 2))->toEqualCanonicalizing([
            'assets/Data/crisis-abilities.php', 'assets/Data/super-moves.php', 'assets/Data/Actors/synthetic-hero.php',
        ]);
    callEditorMethod($editor, 'confirmSuperMoveMigration', getEditorProperty($editor, 'optionDialogField')['superMoveMigration'], true);
    expect(is_file($root . '/' . SuperMoveFields::CATALOGUE))->toBeTrue()
        ->and(getEditorProperty($editor, 'workspace')->getRecordDatabase(SuperMoveFields::CATEGORY)->isEditable())->toBeTrue();

    callEditorMethod($editor, 'performUndo');
    expect(snapshotProjectData($root))->toBe($before);
});

it('refuses to migrate and changes nothing when the project cannot be moved as it is', function (Closure $arrange, string $message) {
    $root = makeLegacySuperMoveProject();
    $arrange($root);
    $before = snapshotProjectData($root);

    expect(fn() => SuperMoveMigration::plan($root))->toThrow(RuntimeException::class, $message)
        ->and(fn() => EditorSession::open($root)->migrateSuperMoves())->toThrow(SessionRefusal::class, $message)
        ->and(snapshotProjectData($root))->toBe($before);
})->with([
    'both catalogues' => [static function (string $root): void {
        file_put_contents($root . '/' . SuperMoveFields::CATALOGUE, superMoveCatalogueSource());
    }, 'Both assets/Data/super-moves.php and assets/Data/crisis-abilities.php declare Super Moves, so nothing was moved.'],
    'disagreeing actor keys' => [static function (string $root): void {
        writeSuperMoveActor($root, 'synthetic-hero', ['crisisAbility' => 'synthetic-surge', 'superMove' => 'other-surge']);
    }, 'reference different definitions'],
    'actor data built in code' => [static function (string $root): void {
        file_put_contents($root . '/assets/Data/Actors/synthetic-built.php', <<<'PHP'
<?php

$data = ['id' => 'synthetic-built', 'name' => 'Built', 'level' => 1, 'currentExp' => 0];
foreach (['crisisAbility' => 'synthetic-surge'] as $key => $value) {
    $data[$key] = $value;
}

return ['class' => \Ichiloto\Engine\Entities\Character::class, 'data' => $data];

PHP);
    }, 'Nothing was moved; write its data as a plain array'],
    'unreadable catalogue' => [static function (string $root): void {
        file_put_contents($root . '/' . SuperMoveFields::LEGACY_CATALOGUE, '<?php return [["name" => "No id"]];');
    }, 'The game cannot read assets/Data/crisis-abilities.php'],
]);

it('has nothing to migrate once a project uses Super Moves throughout', function () {
    $root = makeTemporaryProject();
    file_put_contents($root . '/' . SuperMoveFields::CATALOGUE, superMoveCatalogueSource());
    writeSuperMoveActor($root, 'synthetic-hero', ['superMove' => 'synthetic-surge']);

    expect(SuperMoveMigration::plan($root))->toBeNull()
        ->and(EditorSession::open($root)->migrateSuperMoves())->toMatchArray(['changed' => false]);
});

it('writes a new project\'s Super Moves only to the canonical catalogue', function () {
    $root = makeTemporaryProject();
    writeSkillRecords($root, new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Test technique', 'Strikes.', '', 2, 0));
    $database = ProjectWorkspace::fromProject($root)->getRecordDatabase(SuperMoveFields::CATEGORY);
    $index = $database->addRecord();
    $database->setField($index, 'id', 'synthetic-surge');
    $database->save();

    expect(is_file($root . '/' . SuperMoveFields::CATALOGUE))->toBeTrue()
        ->and(is_file($root . '/' . SuperMoveFields::LEGACY_CATALOGUE))->toBeFalse()
        ->and(array_keys(CrisisAbilityCatalog::fromProject($root . '/assets')->getAbilities()))->toBe(['synthetic-surge']);
});

it('writes superMove in place of a legacy actor key from the actor page', function () {
    $root = makeTemporaryProject();
    file_put_contents($root . '/' . SuperMoveFields::CATALOGUE, superMoveCatalogueSource());
    $path = writeSuperMoveActor($root, 'synthetic-hero', ['crisisAbility' => 'synthetic-surge']);
    $original = file_get_contents($path);
    $session = EditorSession::open($root);
    $index = array_search('synthetic-hero', array_map(static fn($actor): string => $actor->getDefinitionId(),
        ProjectWorkspace::fromProject($root)->actorDatabase->getActors()), true);
    $row = array_find($session->readDatabaseRecord('actors', $index)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'superMove');
    expect($row['value'] ?? null)->toBe('synthetic-surge');

    $session->applyDatabaseRecord('actors', $index, $row['key'], 'synthetic-surge');
    $session->saveDatabase('actors');
    expect(file_get_contents($path))->toBe(str_replace("'crisisAbility' =>", "'superMove' =>", $original));
});
