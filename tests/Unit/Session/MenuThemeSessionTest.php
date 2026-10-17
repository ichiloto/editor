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
        ->and($labels)->toEqualCanonicalizing($engine);
    $frame = array_find($session->readDatabaseRecord(MenuThemeFields::CATEGORY, findThemeSetting($session, 'atlas.frameRole'))['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'value');
    expect($frame['options'])->toBe(MapAtlasTheme::FRAME_ROLES);
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
