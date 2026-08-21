<?php

declare(strict_types=1);

use Atatusoft\Termutil\IO\Enumerations\Color;
use Ichiloto\Editor\EditorWindow;
use Ichiloto\Editor\Theme\EditorTheme;
use Ichiloto\Engine\UI\Windows\BorderPacks\FancyBorderPack;

afterEach(function (): void {
    EditorWindow::useBorderPack(null);
});

it('falls back to the editor look when a project declares no theme', function (): void {
    $root = makeTemporaryProject();
    @unlink($root . '/config.php');

    $theme = EditorTheme::fromProject($root);

    expect($theme->isFromProject)->toBeFalse();
    expect($theme->selectionColor)->toBe(Color::LIGHT_BLUE);
    expect($theme->borderPack->topLeft)->toBe('┌');
    expect($theme->describe())->toContain('editor default');

    removeDirectoryRecursively($root);
});

it('adopts the project menu selection color', function (): void {
    $root = makeTemporaryProject();

    $theme = EditorTheme::fromProject($root);

    expect($theme->isFromProject)->toBeTrue();
    expect($theme->selectionColor)->toBe(Color::YELLOW);
    expect($theme->describe())->toContain('from config.php');
    expect($theme->colorName())->toBe('Yellow');

    removeDirectoryRecursively($root);
});

it('translates an engine border pack into editor border characters', function (): void {
    $root = makeTemporaryProject();
    $contents = (string) file_get_contents($root . '/config.php');
    file_put_contents($root . '/config.php', str_replace(
        "'selection_color' =>",
        "'border' => new \\" . FancyBorderPack::class . "(),\n      'selection_color' =>",
        $contents,
    ));

    $theme = EditorTheme::fromProject($root);

    expect($theme->borderPackName)->toBe('FancyBorderPack');
    expect($theme->borderPack->topLeft)->toBe(FancyBorderPack::getTopLeftCorner());
    expect($theme->borderPack->horizontal)->toBe(FancyBorderPack::getHorizontalBorder());
    expect($theme->borderPack->vertical)->toBe(FancyBorderPack::getVerticalBorder());
    expect($theme->describe())->toContain('Fancy');

    removeDirectoryRecursively($root);
});

it('accepts a border pack named by class string', function (): void {
    $root = makeTemporaryProject();
    $contents = (string) file_get_contents($root . '/config.php');
    file_put_contents($root . '/config.php', str_replace(
        "'selection_color' =>",
        "'border' => '" . addslashes(FancyBorderPack::class) . "',\n      'selection_color' =>",
        $contents,
    ));

    expect(EditorTheme::fromProject($root)->borderPackName)->toBe('FancyBorderPack');

    removeDirectoryRecursively($root);
});

it('ignores a nonsense theme instead of failing to open the project', function (): void {
    $root = makeTemporaryProject();
    file_put_contents($root . '/config.php', "<?php\n\nreturn ['ui' => ['menu' => ['border' => 'Not\\\\A\\\\Class', 'selection_color' => 'chartreuse']]];\n");

    $theme = EditorTheme::fromProject($root);

    expect($theme->borderPack->topLeft)->toBe('┌');
    expect($theme->selectionColor)->toBe(Color::LIGHT_BLUE);

    removeDirectoryRecursively($root);
});

it('draws new windows with the themed border pack', function (): void {
    $theme = new EditorTheme(
        new Atatusoft\Termutil\UI\Windows\BorderPack(topLeft: '╔', topRight: '╗', bottomLeft: '╚', bottomRight: '╝', horizontal: '═', vertical: '║'),
        Color::YELLOW,
        'DefaultBorderPack',
        true,
    );

    EditorWindow::useBorderPack($theme->borderPack);

    $window = new EditorWindow(title: 'Themed', width: 20, height: 4, content: ['hi']);

    expect($window->borderPack->topLeft)->toBe('╔');
    expect($window->borderPack->horizontal)->toBe('═');

    // A caller that passes its own pack still wins.
    $explicit = new EditorWindow(title: 'Explicit', width: 20, height: 4, borderPack: new Atatusoft\Termutil\UI\Windows\BorderPack());
    expect($explicit->borderPack->topLeft)->toBe('┌');
});

it('paints the focused pane in the project selection color', function (): void {
    $root = makeTemporaryProject();

    try {
        $editor = createEditorForTesting($root);
        callEditorMethod($editor, 'applyProjectTheme');

        setEditorProperty($editor, 'focusedPane', 'assets');

        expect(callEditorMethod($editor, 'resolvePaneColor', 'assets'))->toBe(Color::YELLOW);
        expect(callEditorMethod($editor, 'resolvePaneColor', 'canvas'))->toBe(Color::WHITE);
    } finally {
        removeDirectoryRecursively($root);
    }
});
