<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;

/**
 * Writes a shop event onto the fixture map and opens the editor on it.
 *
 * @param string $root The project root.
 * @return object The editor, with the shop event under the cursor.
 */
function editorOnShopEvent(string $root): object
{
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $source = (string) file_get_contents($path);

    // The fixture's event layer places E at (5, 1); giving that marker a shop
    // is what puts the list under the inspector cursor.
    $shop = <<<'PHP'
      'events' => [
        'E' => [
          'class' => 'Ichiloto\Engine\Events\Triggers\ShopEventTrigger',
          'data' => [
            'items' => [
              ['item' => 'S-Potion', 'price' => 10],
              ['item' => 'Antidote', 'price' => 20],
            ],
          ],
        ],
      ],
    PHP;

    file_put_contents($path, preg_replace("/'events' => \[.*?\n  \],/s", $shop, $source, 1));

    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'editingMode', 'event');
    setEditorProperty($editor, 'cursorX', 5);
    setEditorProperty($editor, 'cursorY', 1);

    return $editor;
}

/**
 * Returns the inspector fields belonging to the shop's item list.
 *
 * @param object $editor The editor.
 * @return array<int, array<string, mixed>> The fields.
 */
function shopListEntryFields(object $editor): array
{
    return array_values(array_filter(
        callEditorMethod($editor, 'getInspectorFields'),
        static fn(array $field): bool => isset($field['list'], $field['path']),
    ));
}

/**
 * Puts the inspector cursor on a field of the given shop entry.
 *
 * @param object $editor The editor.
 * @param int $entryIndex Which entry.
 * @return void
 */
function selectShopEntry(object $editor, int $entryIndex): void
{
    foreach (callEditorMethod($editor, 'getInspectorFields') as $index => $field) {
        if (isset($field['path']) && ($field['list']['index'] ?? null) === $entryIndex) {
            setEditorProperty($editor, 'selectedInspectorFieldIndex', $index);

            return;
        }
    }
}

/**
 * Returns the shop's stock as stored.
 *
 * @param object $editor The editor.
 * @return array<int, mixed> The entries.
 */
function shopStock(object $editor): array
{
    return (array) callEditorMethod($editor, 'getSelectedMap')->getEventField('E', ['data', 'items']);
}

it('shows a list with its length, and marks what belongs to it', function () {
    $editor = editorOnShopEvent(makeTemporaryProject());
    $labels = array_map(
        static fn(array $field): string => (string) $field['label'],
        callEditorMethod($editor, 'getInspectorFields'),
    );

    expect($labels)->toContain('Items · 2');

    $entries = shopListEntryFields($editor);

    // Every field of every entry knows which list and which entry it is, so
    // adding or removing works from any of them rather than only the first.
    expect($entries)->toHaveCount(4)
        ->and($entries[0]['list'])->toBe(['path' => ['data', 'items'], 'index' => 0])
        ->and($entries[3]['list'])->toBe(['path' => ['data', 'items'], 'index' => 1]);
});

it('adds an entry shaped like the one it follows', function () {
    $root = makeTemporaryProject();
    $editor = editorOnShopEvent($root);

    selectShopEntry($editor, 0);
    callEditorMethod($editor, 'addInspectorListItem');

    $items = shopStock($editor);

    // Same keys, nothing filled in: a second shop line means another line
    // like the first, not a hole to describe.
    expect($items)->toHaveCount(3)
        ->and($items[1])->toBe(['item' => '', 'price' => 0])
        ->and($items[2])->toBe(['item' => 'Antidote', 'price' => 20]);
});

it('removes the entry the cursor is in', function () {
    $editor = editorOnShopEvent(makeTemporaryProject());

    selectShopEntry($editor, 1);
    callEditorMethod($editor, 'removeInspectorListItem');

    $items = shopStock($editor);

    expect($items)->toHaveCount(1)
        ->and($items[0]['item'])->toBe('S-Potion');
});

it('puts back what it removed', function () {
    $editor = editorOnShopEvent(makeTemporaryProject());

    selectShopEntry($editor, 0);
    callEditorMethod($editor, 'removeInspectorListItem');
    callEditorMethod($editor, 'performUndo');

    $items = shopStock($editor);

    expect($items)->toHaveCount(2)
        ->and($items[0]['item'])->toBe('S-Potion');
});

it('says so rather than doing nothing on a field that is not in a list', function () {
    $root = makeTemporaryProject();
    $editor = editorOnShopEvent($root);

    // The map's own Name field, which belongs to no list.
    setEditorProperty($editor, 'editingMode', 'map');
    setEditorProperty($editor, 'selectedInspectorFieldIndex', 0);
    callEditorMethod($editor, 'addInspectorListItem');

    expect((string) getEditorProperty($editor, 'statusMessage'))->toContain('list');
});
