<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\DatabaseCatalog;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Editor;

/**
 * Returns the manual's text.
 */
function docsPath(string $relativePath = ''): string
{
    // tests/Unit -> tests -> the package root.
    $root = dirname(__DIR__, 2) . '/docs';

    return $relativePath === '' ? $root : $root . '/' . ltrim($relativePath, '/');
}

function manualText(): string
{
    $path = docsPath('manual.md');

    expect(is_file($path))->toBeTrue();

    return (string) file_get_contents($path);
}

/**
 * Returns every key token the editor dispatches on, flattened out of the
 * composite labels the binding table uses (`Ctrl+Z / Ctrl+Y` is two tokens).
 *
 * @return string[]
 */
function registeredKeyTokens(): array
{
    $editor = createEditorForTesting(fixturePath('sample-project'));
    $router = new ReflectionProperty(Editor::class, 'inputRouter')->getValue($editor);
    $tokens = [];

    foreach ($router->describeBindings() as $entry) {
        foreach (explode('/', (string) $entry['key']) as $token) {
            $token = trim($token);

            if ($token !== '') {
                $tokens[$token] = $token;
            }
        }
    }

    return array_values($tokens);
}

it('documents every registered keybinding', function (): void {
    $manual = manualText();
    $missing = [];

    foreach (registeredKeyTokens() as $token) {
        if (! str_contains($manual, '`' . $token . '`')) {
            $missing[] = $token;
        }
    }

    expect($missing)->toBe([], 'docs/manual.md is missing keys: ' . implode(', ', $missing));
});

it('documents a real number of keybindings, so the guard cannot pass vacuously', function (): void {
    expect(count(registeredKeyTokens()))->toBeGreaterThanOrEqual(20);
});

it('documents every Database category', function (): void {
    $manual = manualText();
    $missing = [];

    foreach (DatabaseCatalog::all() as $category) {
        if (! str_contains($manual, $category->label)) {
            $missing[] = $category->label;
        }
    }

    expect($missing)->toBe([], 'docs/manual.md is missing categories: ' . implode(', ', $missing));
});

it('names the backing file of every schema-driven category', function (): void {
    $manual = manualText();
    $missing = [];

    foreach (RecordSchemaCatalog::all() as $key => $schema) {
        // Tilesets have no backing file yet; the manual says so in prose.
        if ($key === 'tilesets') {
            continue;
        }

        if (! str_contains($manual, $schema->relativePath)) {
            $missing[] = $schema->relativePath;
        }
    }

    expect($missing)->toBe([], 'docs/manual.md is missing paths: ' . implode(', ', $missing));
});

it('documents every event-script command type', function (): void {
    $manual = manualText();
    $missing = [];

    foreach (RecordSchemaCatalog::EVENT_COMMAND_TYPES as $type) {
        if (! str_contains($manual, '`' . $type . '`')) {
            $missing[] = $type;
        }
    }

    expect($missing)->toBe([], 'docs/manual.md is missing command types: ' . implode(', ', $missing));
});

it('ships the task guides the manual links to', function (): void {
    expect(is_dir(docsPath('guides')))->toBeTrue();

    foreach ([
        'README.md',
        'make-a-new-map.md',
        'add-an-npc.md',
        'wire-a-quest.md',
        'author-a-cutscene.md',
        'write-a-skit.md',
    ] as $guide) {
        expect(is_file(docsPath('guides/' . $guide)))->toBeTrue("missing guide: {$guide}");
    }
});

it('keeps every manual and guide cross-link resolvable', function (): void {
    $broken = [];

    foreach ([docsPath('manual.md'), ...glob(docsPath('guides/*.md'))] as $path) {
        $contents = (string) file_get_contents($path);
        preg_match_all('/\]\(([^)#]+)(?:#[^)]*)?\)/', $contents, $matches);

        foreach ($matches[1] as $link) {
            if (str_starts_with($link, 'http')) {
                continue;
            }

            if (! is_file(dirname($path) . '/' . $link)) {
                $broken[] = basename($path) . ' -> ' . $link;
            }
        }
    }

    expect($broken)->toBe([], 'broken doc links: ' . implode(', ', $broken));
});
