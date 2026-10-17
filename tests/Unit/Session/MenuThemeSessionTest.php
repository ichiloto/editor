<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Database\MenuThemeFields;
use Ichiloto\Editor\MenuPresentationSettings;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\UI\Presentation\MapAtlasTheme;

/** A project whose synthetic menu theme authors only the atlas margin, beside settings the Editor does not own. */
function createMenuThemeProject(?string $catalog = null): array
{
    $root = makeTemporaryProject('editor-menu-theme-');
    $file = $root . '/' . MenuPresentationSettings::RELATIVE_PATH;
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, $catalog ?? <<<'PHP'
<?php
// Theme header stays authored.
return [
  'schema' => 'ichiloto.menu/1',
  'showInputHints' => true, // A setting another page owns.
  'atlas' => [
    'margin' => 24, // Breathing room.
  ],
];
PHP);
    return [$root, $file, EditorSession::open($root)];
}

/** The index of a theme setting by its path. */
function findThemeSetting(EditorSession $session, string $path): int
{
    $labels = $session->listDatabaseRecords(MenuThemeFields::CATEGORY)['records'];
    $index = array_search($path, $labels, true);
    return is_int($index) ? $index : throw new RuntimeException('No theme setting ' . $path);
}

function setThemeSetting(EditorSession $session, string $path, string $value): array
{
    $index = findThemeSetting($session, $path);
    $row = array_find($session->readDatabaseRecord(MenuThemeFields::CATEGORY, $index)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'value');
    return $session->applyDatabaseRecord(MenuThemeFields::CATEGORY, $index, $row['key'], $value);
}

it('offers every atlas setting with the Engine default, authored ones first, only in the graphical editor', function () {
    [, , $session] = createMenuThemeProject();
    $labels = $session->listDatabaseRecords(MenuThemeFields::CATEGORY)['records'];
    $engine = array_map(static fn(string $key): string => 'atlas.' . $key, array_keys(get_object_vars(new MapAtlasTheme())));
    expect($labels[0])->toBe('atlas.margin')
        ->and(array_values(array_filter($labels, static fn(string $label): bool => str_starts_with($label, 'atlas.'))))->toEqualCanonicalizing($engine);
    $frame = array_find($session->readDatabaseRecord(MenuThemeFields::CATEGORY, findThemeSetting($session, 'atlas.frameRole'))['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'value');
    expect($frame['options'])->toBe(MapAtlasTheme::FRAME_ROLES);
    $margin = array_find($session->readDatabaseRecord(MenuThemeFields::CATEGORY, findThemeSetting($session, 'atlas.margin'))['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'value');
    expect($margin['label'])->toBe(sprintf('Pixels (0..%d)', MapAtlasTheme::MAX_MARGIN));
    // The Terminal edits no graphical presentation.
    expect(array_map(static fn($category): string => $category->key, DatabaseCatalog::all()))->not->toContain(MenuThemeFields::CATEGORY)
        ->and(array_map(static fn($category): string => $category->key, DatabaseCatalog::getGraphicalCategories()))->toContain(MenuThemeFields::CATEGORY);
});

it('validates each value with its neighbours through the Engine, saves only changed leaves and undoes', function () {
    [$root, $file, $session] = createMenuThemeProject();
    $default = (new MapAtlasTheme())->largeWidth;
    expect(fn() => setThemeSetting($session, 'atlas.compactWidth', (string) ($default + 1)))->toThrow(SessionRefusal::class);
    expect(fn() => setThemeSetting($session, 'atlas.markerSize', '99'))->toThrow(SessionRefusal::class);

    setThemeSetting($session, 'atlas.largeWidth', '600');
    setThemeSetting($session, 'atlas.compactWidth', '500');
    setThemeSetting($session, 'atlas.margin', '20');
    setThemeSetting($session, 'atlas.frameRole', 'panel');
    $session->undo();
    $session->saveDatabase(MenuThemeFields::CATEGORY);
    $source = (string) file_get_contents($file);
    expect($source)->toContain('// Theme header stays authored.', '// A setting another page owns.', '// Breathing room.')
        ->and($source)->not->toContain('frameRole');
    $saved = (require $file)['atlas'];
    ksort($saved);
    expect($saved)->toBe(['compactWidth' => 500, 'largeWidth' => 600, 'margin' => 20]);
    expect(MapAtlasTheme::fromArray($saved)->compactWidth)->toBe(500);

    // Choosing the default for a setting the theme leaves out writes nothing.
    setThemeSetting($session, 'atlas.markerSize', (string) (new MapAtlasTheme())->markerSize);
    expect($session->listDatabaseRecords(MenuThemeFields::CATEGORY)['dirty'])->toBeFalse();
    expect(EditorSession::open($root)->listDatabaseRecords(MenuThemeFields::CATEGORY)['records'][0])->toBe('atlas.margin');
});

it('is read-only, saying why, in a project without a menu theme', function () {
    $root = makeTemporaryProject('editor-menu-theme-none-');
    $listing = EditorSession::open($root)->listDatabaseRecords(MenuThemeFields::CATEGORY);
    expect($listing['editable'])->toBeFalse()
        ->and($listing['readOnly'])->toContain(MenuPresentationSettings::RELATIVE_PATH)
        ->and($listing['records'])->toBe([]);
});

it('binds atlas marker images by role, the project\'s own categories included, and unbinds them by removing the key', function () {
    [$root, $file, $session] = createMenuThemeProject(<<<'PHP'
<?php
return [
  'schema' => 'ichiloto.menu/1',
  'icons' => [
    'notification.save' => 'Graphics/Icons/Save.png', // Bound by another page.
  ],
];
PHP);
    writeTilesetTestPng($root . '/assets/Graphics/Icons/Save.png', 8, 8);
    writeTilesetTestPng($root . '/assets/Graphics/Icons/School.png', 8, 8);
    // A project category, unsaved, already has a marker role to bind.
    $index = $session->createDatabaseRecord(Ichiloto\Editor\Field\MapCategoryFields::CATEGORY)['index'];
    $id = array_find($session->readDatabaseRecord(Ichiloto\Editor\Field\MapCategoryFields::CATEGORY, $index)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'id');
    $session->applyDatabaseRecord(Ichiloto\Editor\Field\MapCategoryFields::CATEGORY, $index, $id['key'], 'bard-school');

    $labels = $session->listDatabaseRecords(MenuThemeFields::CATEGORY)['records'];
    $roles = Ichiloto\Engine\UI\Presentation\MapAtlasPresentation::listIconRoles(Ichiloto\Engine\Field\MapCategoryCatalog::fromArray([
        'schema' => Ichiloto\Engine\Field\MapCategoryCatalog::SCHEMA,
        'categories' => ['bard-school' => ['name' => ['message' => 'm', 'default' => 'Bard School'], 'base' => 'inn']]]));
    expect($labels)->toContain('icons.notification.save')
        ->and(array_values(array_filter($labels, static fn(string $label): bool => str_starts_with($label, 'icons.map.'))))
        ->toEqualCanonicalizing(array_map(static fn(string $role): string => 'icons.' . $role, $roles))
        ->and($labels)->toContain('icons.map.category.bard-school');
    $marker = array_find($session->readDatabaseRecord(MenuThemeFields::CATEGORY, findThemeSetting($session, 'icons.map.category.bard-school'))['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'value');
    expect($marker['kind'])->toBe('reference')->and($marker['reference'])->toBe('png_assets')
        ->and($marker['noneLabel'])->toBe('(Engine pictogram)');

    expect(fn() => setThemeSetting($session, 'icons.map.category.bard-school', 'Graphics/Icons/Missing.png'))->toThrow(SessionRefusal::class);
    setThemeSetting($session, 'icons.map.category.bard-school', 'Graphics/Icons/School.png');
    $session->saveDatabase(MenuThemeFields::CATEGORY);
    expect((require $file)['icons'])->toBe(['notification.save' => 'Graphics/Icons/Save.png', 'map.category.bard-school' => 'Graphics/Icons/School.png'])
        ->and(file_get_contents($file))->toContain('// Bound by another page.');

    // Choosing none again removes the binding: the Engine's pictogram shows.
    setThemeSetting($session, 'icons.map.category.bard-school', '');
    $session->saveDatabase(MenuThemeFields::CATEGORY);
    expect((require $file)['icons'])->toBe(['notification.save' => 'Graphics/Icons/Save.png'])
        ->and(file_get_contents($file))->toContain('// Bound by another page.');
});

it('sets the corner map\'s opacity as a number the Engine checks, and writes it as one', function () {
    [, $file, $session] = createMenuThemeProject();
    $row = array_find($session->readDatabaseRecord(MenuThemeFields::CATEGORY, findThemeSetting($session, 'atlas.cornerOpacity'))['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'value');
    expect($row['kind'])->toBe('float')->and($row['label'])->toBe('Opacity (0 clear, 1 solid)');
    expect(fn() => setThemeSetting($session, 'atlas.cornerOpacity', '1.5'))->toThrow(SessionRefusal::class);
    setThemeSetting($session, 'atlas.cornerOpacity', '0.5');
    $session->saveDatabase(MenuThemeFields::CATEGORY);
    expect((require $file)['atlas']['cornerOpacity'])->toBe(0.5)
        ->and(MapAtlasTheme::fromArray((require $file)['atlas'])->cornerOpacity)->toBe(0.5)
        ->and(file_get_contents($file))->toContain('// Breathing room.');
});
