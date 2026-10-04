<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Engine\Entities\Enemies\EnemyCatalog;
use Ichiloto\Engine\Entities\Enumerations\ActionConditionType;

/**
 * A synthetic project with one skill, two enemy sprites and the given enemy
 * record files, in the form the engine's EnemyRecord reads.
 *
 * @param array<string, string> $records File contents keyed by file name.
 */
function enemyRecordProject(array $records = []): string
{
    $root = rememberTemporaryProject(sys_get_temp_dir() . '/ichiloto-enemy-records-' . bin2hex(random_bytes(4)));
    mkdir($root . '/assets/Data/Enemies', 0777, true);
    mkdir($root . '/assets/Graphics/Enemies', 0777, true);
    file_put_contents($root . '/assets/Graphics/Enemies/blob.txt', "(o)\n");
    file_put_contents($root . '/assets/Graphics/Enemies/wisp.txt', "~*~\n");
    file_put_contents($root . '/assets/Data/skills.php', "<?php\nuse Ichiloto\\Engine\\Entities\\Skills\\BasicSkill;\n"
        . "return [new BasicSkill('Nip', 'A small bite.', '', 0, 0), new BasicSkill('Brace', 'Holds firm.', '', 0, 0)];\n");

    foreach ($records as $file => $contents) {
        file_put_contents($root . '/assets/Data/Enemies/' . $file, $contents);
    }

    return $root;
}

/** A record file as the conversion and an author write it: the class named through its import. */
function blobRecordFile(): string
{
    return <<<'PHP'
    <?php

    use Ichiloto\Engine\Entities\Enemies\Enemy;

    // Lives in the sewers.

    return [
      'class' => Enemy::class,
      'data' => [
        'name' => 'Blob',
        'level' => 2,
        'imagePath' => 'blob',
        'stats' => [
          'maxHp' => 40,
          'maxMp' => 0,
          'attack' => 9,
          'defence' => 4,
          'magicAttack' => 3,
          'magicDefence' => 5,
          'speed' => 7,
          'grace' => 2,
          'evasion' => 1,
        ],
        'rewards' => [
          'experience' => 12,
          'gold' => 30,
        ],
        'actionPatterns' => [
          [
            'skill' => 'Nip',
            'rating' => 5,
          ],
        ],
        'elementAffinities' => [
          'Fire' => 2.0,
        ],
      ],
    ];

    PHP;
}

/** Reads the project's enemies the way the game does, from its own directory. */
function loadEnemyCatalog(string $root): EnemyCatalog
{
    $previous = (string) getcwd();
    chdir($root);

    try {
        return EnemyCatalog::load($root . '/assets');
    } finally {
        chdir($previous);
    }
}

function enemiesDatabase(string $root): ProjectRecordDatabase
{
    return ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('enemies'));
}

it('lists each enemy record file as an editable record of its data', function () {
    $database = enemiesDatabase(enemyRecordProject(['blob.php' => blobRecordFile()]));
    $record = $database->getRecords()[0];

    expect($database->getEntryLabels())->toBe(['Blob'])
        ->and($database->isEditable())->toBeTrue()
        ->and($record->isEditable())->toBeTrue()
        ->and($record->get('stats.maxHp'))->toBe(40)
        ->and($record->get('actionPatterns'))->toBe([['skill' => 'Nip', 'rating' => 5]]);
});

it('edits a value in its own source, keeping the envelope, import and comment', function () {
    $root = enemyRecordProject(['blob.php' => blobRecordFile()]);
    $database = enemiesDatabase($root);

    $database->setField(0, 'stats.maxHp', '55');
    $database->setField(0, 'stats.speed', '9');
    $database->save();

    expect(file_get_contents($root . '/assets/Data/Enemies/blob.php'))->toBe(str_replace(
        ["'maxHp' => 40,", "'speed' => 7,"],
        ["'maxHp' => 55,", "'speed' => 9,"],
        blobRecordFile(),
    ))
        ->and(loadEnemyCatalog($root)->findEnemy('Blob')?->stats->totalHp)->toBe(55);
});

it('authors action patterns by naming catalogue skills and conditions as data', function () {
    $root = enemyRecordProject(['blob.php' => blobRecordFile()]);
    $database = enemiesDatabase($root);

    $entry = $database->addSubItem(0);
    $database->setField(0, ProjectRecordDatabase::subFieldId('pattern', $entry, 'skill'), 'Brace');
    $database->setField(0, ProjectRecordDatabase::subFieldId('pattern', $entry, 'rating'), '7');
    $database->setField(0, ProjectRecordDatabase::subFieldId('pattern', $entry, 'condition.type'), 'HP');
    $database->setField(0, ProjectRecordDatabase::subFieldId('pattern', $entry, 'condition.range'), '0, 50');
    $database->save();

    $pattern = loadEnemyCatalog($root)->findEnemy('Blob')?->actionPatterns[1];

    expect($pattern?->skill->name)->toBe('Brace')
        ->and($pattern?->rating)->toBe(7)
        ->and($pattern?->condition->type)->toBe(ActionConditionType::HP)
        ->and([$pattern?->condition->range->min, $pattern?->condition->range->max])->toBe([0, 50]);
});

it('creates a new enemy in a file of its own that the game loads', function () {
    $root = enemyRecordProject(['blob.php' => blobRecordFile()]);
    $database = enemiesDatabase($root);

    $index = $database->addRecord();
    $database->save();
    $catalog = loadEnemyCatalog($root);

    expect($index)->toBe(1)
        ->and(is_file($root . '/assets/Data/Enemies/new-enemy.php'))->toBeTrue()
        ->and($catalog->getProblems())->toBe([])
        ->and($catalog->getSourceFile('New Enemy'))->toBe('Enemies/new-enemy.php')
        ->and($catalog->findEnemy('New Enemy')?->imagePath)->toBe('blob');
});

it('refuses to create an enemy while the project has no enemy sprite to give it', function () {
    $root = enemyRecordProject();
    array_map(unlink(...), glob($root . '/assets/Graphics/Enemies/*') ?: []);

    expect(enemiesDatabase($root)->addRecord())->toBeNull();
});

it('duplicates an enemy into its own file under a name of its own', function () {
    $root = enemyRecordProject(['blob.php' => blobRecordFile()]);
    $database = enemiesDatabase($root);

    $copy = $database->duplicateRecord(0);
    $database->save();
    $catalog = loadEnemyCatalog($root);

    expect($copy)->toBe(1)
        ->and($catalog->getProblems())->toBe([])
        ->and($catalog->getSourceFile('Blob-2'))->toBe('Enemies/blob-2.php')
        ->and($catalog->findEnemy('Blob-2')?->stats->totalHp)->toBe(40)
        ->and(file_get_contents($root . '/assets/Data/Enemies/blob.php'))->toBe(blobRecordFile());
});

it('keeps a file that is not an enemy record listed, read-only with the reason', function () {
    $database = enemiesDatabase(enemyRecordProject([
        'blob.php' => blobRecordFile(),
        'stray.php' => "<?php\n\nreturn ['name' => 'Stray'];\n",
    ]));
    [$blob, $stray] = $database->getRecords();

    expect($database->getEntryLabels())->toBe(['Blob', 'stray'])
        ->and($blob->isEditable())->toBeTrue()
        ->and($stray->isEditable())->toBeFalse()
        ->and($stray->getReadOnlyReason())->toBe("stray.php does not return ['class' => Enemy::class, 'data' => [...]]")
        ->and($database->duplicateRecord(1))->toBeNull();
});
