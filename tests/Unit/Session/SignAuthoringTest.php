<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Field\FieldResourceFields;
use Ichiloto\Editor\Field\TextRoleFields;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Field\WorldObjectDefinition;
use Ichiloto\Engine\Rendering\Presentation\Text\ProjectTextCatalog;

/**
 * Signs are authored as the Engine reads them: a sign-board resource in the
 * field catalogue, a text role in the text catalogue, and map placements whose
 * label is a translatable message with the text shown where none is given.
 * Synthetic project, board image and font bytes only.
 */

/** @return array{string, EditorSession} A project with a board image, a font file, an image resource and a sign resource. */
function createSignProject(): array
{
    $root = mapGraphicsProject();
    writeTilesetTestPng($root . '/assets/Graphics/Boards/Wood.png', 36, 12);
    writeTilesetTestPng($root . '/assets/Graphics/Props/Lamp.png', 12, 16);
    is_dir($root . '/assets/Fonts') || mkdir($root . '/assets/Fonts', 0o777, true);
    file_put_contents($root . '/assets/Fonts/Open.ttf', 'synthetic font');
    is_dir($root . '/assets/Data/Presentation') || mkdir($root . '/assets/Data/Presentation', 0o777, true);
    file_put_contents($root . '/assets/Data/Presentation/field.php', "<?php\n\nreturn ['resources' => [\n"
        . "  'lamp' => ['name' => 'Lamp', 'pivot' => ['x' => 0.5, 'y' => 1.0], 'sprites2d' => ['asset' => 'Graphics/Props/Lamp.png']],\n"
        . "  'wood-sign' => ['name' => 'Wood Sign', 'pivot' => ['x' => 0.5, 'y' => 1.0], 'sign' => ['asset' => 'Graphics/Boards/Wood.png',"
        . " 'fontRole' => 'sign', 'left' => 8, 'right' => 8, 'density' => 1, 'padding' => [4, 2, 4, 2], 'minimumWidth' => 24, 'maximumWidth' => 160]],\n"
        . "]];\n");

    return [$root, EditorSession::open($root)];
}

function findSignDatabaseRow(EditorSession $session, string $category, int $index, string $field): ?array
{
    return array_find($session->readDatabaseRecord($category, $index)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === $field);
}

function applySignDatabaseRow(EditorSession $session, string $category, int $index, string $field, string $value): array
{
    $row = findSignDatabaseRow($session, $category, $index, $field) ?? throw new RuntimeException('Missing row ' . $field);

    return $session->applyDatabaseRecord($category, $index, $row['key'], $value);
}

function findSignPlacementRow(EditorSession $session, string $id, string $field): ?array
{
    return array_find($session->readWorldObject('test-map', $id)['rows'], static fn($row): bool => ($row['key']['field'] ?? null) === $field);
}

it('authors a sign board resource with the Engine\'s board, cut, padding and width fields', function () {
    [$root, $session] = createSignProject();
    $index = array_search('Wood Sign', $session->listDatabaseRecords(FieldResourceFields::CATEGORY)['records'], true);

    expect(findSignDatabaseRow($session, FieldResourceFields::CATEGORY, $index, 'presentation')['value'])->toBe(FieldResourceFields::SIGN)
        ->and(findSignDatabaseRow($session, FieldResourceFields::CATEGORY, $index, 'sign.fontRole'))->toMatchArray(['reference' => 'text_roles'])
        ->and(findSignDatabaseRow($session, FieldResourceFields::CATEGORY, $index, 'sprites2d.asset'))->toBeNull();
    applySignDatabaseRow($session, FieldResourceFields::CATEGORY, $index, 'sign.padding', '6, 2, 6, 3');
    $session->saveDatabase(FieldResourceFields::CATEGORY);

    expect(FieldPresentationCatalog::load($root . '/assets')->getResource('wood-sign')->sign->padding)->toBe([6, 2, 6, 3]);
});

it('switches a resource between an image and a sign board, keeping the image chosen', function () {
    [$root, $session] = createSignProject();
    $index = array_search('Lamp', $session->listDatabaseRecords(FieldResourceFields::CATEGORY)['records'], true);
    applySignDatabaseRow($session, FieldResourceFields::CATEGORY, $index, 'presentation', FieldResourceFields::SIGN);

    expect(findSignDatabaseRow($session, FieldResourceFields::CATEGORY, $index, 'sign.asset')['value'])->toBe('Graphics/Props/Lamp.png')
        ->and(findSignDatabaseRow($session, FieldResourceFields::CATEGORY, $index, 'presentation')['value'])->toBe(FieldResourceFields::SIGN);
    applySignDatabaseRow($session, FieldResourceFields::CATEGORY, $index, 'presentation', FieldResourceFields::IMAGE);
    $session->saveDatabase(FieldResourceFields::CATEGORY);

    $lamp = FieldPresentationCatalog::load($root . '/assets')->getResource('lamp');
    expect($lamp->sign)->toBeNull()->and($lamp->sprites)->not->toBeNull();
});

it('creates the text catalogue with its schema and a role set in the project\'s own font', function () {
    [$root, $session] = createSignProject();
    $session->createDatabaseRecord(TextRoleFields::CATEGORY);
    applySignDatabaseRow($session, TextRoleFields::CATEGORY, 0, 'id', 'sign');
    applySignDatabaseRow($session, TextRoleFields::CATEGORY, 0, 'fontSize', '14');
    $session->saveDatabase(TextRoleFields::CATEGORY);

    $role = ProjectTextCatalog::load($root . '/assets')->getRole('sign');
    expect((require $root . '/assets/Data/Presentation/text.php')['schema'])->toBe(ProjectTextCatalog::SCHEMA)
        ->and($role->fonts[0]->asset)->toBe('Fonts/Open.ttf')
        ->and($role->fontSize)->toBe(14.0)
        ->and(new ReferenceCatalog(ProjectWorkspace::fromProject($root))->valuesFor('text_roles'))->toBe(['sign'])
        ->and(fn() => applySignDatabaseRow($session, TextRoleFields::CATEGORY, 0, 'id', 'Sign Font'))->toThrow(SessionRefusal::class);
});

it('places a sign with the text it shows under a translation key of its own id', function () {
    [$root, $session] = createSignProject();
    $session->createWorldObject('test-map', $session->readMap('test-map')['revision'], 'inn-sign', 1, 1, 'wood-sign', 'Inn');

    expect($session->readMap('test-map')['worldObjects'][0])->toBe(['id' => 'inn-sign', 'resource' => 'wood-sign', 'anchor' => ['x' => 1, 'y' => 1],
        'label' => ['message' => 'signs.inn-sign', 'default' => 'Inn']])
        ->and(findSignPlacementRow($session, 'inn-sign', 'label.default')['value'])->toBe('Inn');
    $row = findSignPlacementRow($session, 'inn-sign', 'label.default');
    $session->applyWorldObject('test-map', $session->readWorldObject('test-map', 'inn-sign')['revision'], 'inn-sign', $row['key'], 'Weapons');
    $session->saveMap('test-map');

    $data = require $root . '/assets/Maps/test-map/test-map.data.php';
    $placement = WorldObjectDefinition::readMap($data, loadLayeredMap($root)->getLayerSet(),
        catalog: FieldPresentationCatalog::load($root . '/assets'))[0];
    expect($placement->label->default)->toBe('Weapons');
});

it('refuses a sign without text, and text on what is not a sign, writing nothing', function () {
    [, $session] = createSignProject();
    $before = $session->readMap('test-map');

    expect(fn() => $session->createWorldObject('test-map', $before['revision'], 'blank-sign', 1, 1, 'wood-sign', ' '))->toThrow(SessionRefusal::class)
        ->and(fn() => $session->createWorldObject('test-map', $before['revision'], 'lamp-1', 1, 1, 'lamp', 'Lamp'))->toThrow(SessionRefusal::class)
        ->and($session->readMap('test-map'))->toBe($before);
});

it('gives a placement a label when its resource becomes a sign, and takes it away when it stops being one', function () {
    [, $session] = createSignProject();
    $session->createWorldObject('test-map', $session->readMap('test-map')['revision'], 'lamp-1', 1, 1, 'lamp');
    $apply = static function (string $field, string $value) use ($session): void {
        $row = findSignPlacementRow($session, 'lamp-1', $field);
        $session->applyWorldObject('test-map', $session->readWorldObject('test-map', 'lamp-1')['revision'], 'lamp-1', $row['key'], $value);
    };

    $apply('resource', 'wood-sign');
    expect($session->readMap('test-map')['worldObjects'][0]['label'])->toBe(['message' => 'signs.lamp-1', 'default' => 'Wood Sign']);
    $apply('resource', 'lamp');
    expect($session->readMap('test-map')['worldObjects'][0])->not->toHaveKey('label');
});
