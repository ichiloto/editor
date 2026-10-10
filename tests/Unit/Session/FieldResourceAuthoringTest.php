<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Field\FieldResourceFields;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Events\Enumerations\CollisionType;

/**
 * The graphical editor authors the field presentation catalogue's named
 * resources as their own Database category, keyed by stable id, leaving the
 * catalogue's cues and action prompt exactly as written. Synthetic project only.
 */

/** @return array{string, string, EditorSession} A project whose field catalogue has a cue binding and one resource. */
function createFieldResourceProject(): array
{
    $root = makeTemporaryProject();
    $directory = $root . '/assets/Data/Presentation';
    is_dir($directory) || mkdir($directory, 0o777, true);
    writeTilesetTestPng($root . '/assets/Graphics/Props/Oak.png', 12, 16);
    writeTilesetTestPng($root . '/assets/Graphics/Props/Pine.png', 12, 16);
    $path = $directory . '/field.php';
    file_put_contents($path, <<<'PHP_SOURCE'
<?php

// Authored cue bindings stay as written.
return [
  'cues' => [],
  'resources' => [
    'oak' => [
      'name' => 'Oak',
      'pivot' => ['x' => 0.5, 'y' => 0.9],
      'sprites2d' => ['asset' => 'Graphics/Props/Oak.png'],
      'occupancyStamp' => [['x' => 0, 'y' => 0, 'type' => \Ichiloto\Engine\Events\Enumerations\CollisionType::SOLID]],
    ],
  ],
];
PHP_SOURCE);

    return [$root, $path, EditorSession::open($root)];
}

function findResourceRow(EditorSession $session, int $index, string $field): ?array
{
    return array_find($session->readDatabaseRecord(FieldResourceFields::CATEGORY, $index)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field);
}

function applyResourceRow(EditorSession $session, int $index, string $field, string $value): array
{
    $row = findResourceRow($session, $index, $field) ?? throw new RuntimeException('Missing row ' . $field);

    return $session->applyDatabaseRecord(FieldResourceFields::CATEGORY, $index, $row['key'], $value);
}

it('offers field resources only to the graphical editor', function () {
    [, , $session] = createFieldResourceProject();

    expect(array_column(DatabaseCatalog::all(), 'key'))->not->toContain(FieldResourceFields::CATEGORY)
        ->and(array_column($session->describeProject()['databases'], 'key'))->toContain(FieldResourceFields::CATEGORY)
        ->and($session->listDatabaseRecords(FieldResourceFields::CATEGORY)['records'])->toBe(['Oak']);
});

it('reads a resource with its key as its id, its art, ground contact and stamp', function () {
    [, , $session] = createFieldResourceProject();

    expect(findResourceRow($session, 0, 'id')['value'])->toBe('oak')
        ->and(findResourceRow($session, 0, 'pivot.y')['value'])->toBe('0.9')
        ->and(findResourceRow($session, 0, 'sprites2d.asset')['value'])->toBe('Graphics/Props/Oak.png')
        ->and(findResourceRow($session, 0, 'stamp0Type')['value'] ?? null)->toBe('SOLID');
});

it('adds and edits a resource, saving the catalogue the Engine admits and keeping what it does not own', function () {
    [$root, $path, $session] = createFieldResourceProject();
    $session->createDatabaseRecord(FieldResourceFields::CATEGORY);
    $index = count($session->listDatabaseRecords(FieldResourceFields::CATEGORY)['records']) - 1;
    applyResourceRow($session, $index, 'id', 'pine');
    applyResourceRow($session, $index, 'name', 'Pine');
    applyResourceRow($session, $index, 'sprites2d.asset', 'Graphics/Props/Pine.png');
    $session->saveDatabase(FieldResourceFields::CATEGORY);

    $catalog = FieldPresentationCatalog::load($root . '/assets');
    expect(array_keys($catalog->resources))->toBe(['oak', 'pine'])
        ->and($catalog->getResource('pine')->name)->toBe('Pine')
        ->and($catalog->getResource('oak')->occupancyStamp)->toBe([['x' => 0, 'y' => 0, 'type' => CollisionType::SOLID]])
        ->and((string) file_get_contents($path))->toContain('// Authored cue bindings stay as written.')
        ->and((require $path)['cues'])->toBe([]);
});

it('refuses an id the Engine would refuse as it is typed', function () {
    [, , $session] = createFieldResourceProject();

    expect(fn() => applyResourceRow($session, 0, 'id', 'Oak Tree'))->toThrow(SessionRefusal::class)
        ->and(findResourceRow($session, 0, 'id')['value'])->toBe('oak');
});

it('refuses to save a resource the Engine would refuse, writing nothing', function () {
    [, $path, $session] = createFieldResourceProject();
    $before = (string) file_get_contents($path);
    applyResourceRow($session, 0, 'pivot.x', '2');

    expect(fn() => $session->saveDatabase(FieldResourceFields::CATEGORY))->toThrow(SessionRefusal::class)
        ->and((string) file_get_contents($path))->toBe($before);
});
