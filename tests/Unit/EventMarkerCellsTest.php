<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * An event marker painted in separate places: the inspector shows its cells
 * rather than a rectangle's size, and moving it keeps its shape.
 */
function scatteredMarkerEditor(): array
{
    $root = makeTemporaryProject();
    file_put_contents($root . '/assets/Maps/test-map/test-map.event.php',
        "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n            \n E          \n            \n        E   \n            \nICHILOTO_EVENT_MAP;\n");
    $editor = createEditorForTesting($root);
    setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
    setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
    setEditorProperty($editor, 'isRunning', true);
    callEditorMethod($editor, 'setEditingMode', 'event');
    setEditorProperty($editor, 'cursorX', 1);
    setEditorProperty($editor, 'cursorY', 1);

    return [$editor, getEditorProperty($editor, 'workspace')->maps[0]];
}

/** @return array<string, array<string, mixed>> The event's inspector rows keyed "Section/label". */
function scatteredMarkerRows(Ichiloto\Editor\Editor $editor): array
{
    $rows = [];
    $section = '';
    $fields = callEditorMethod($editor, 'getInspectorFields');
    // The map's own rows come first; the event's begin at its Event row.
    $fields = array_slice($fields, (int) array_search('Event', array_column($fields, 'label'), true));

    foreach ($fields as $field) {
        if (! str_starts_with((string) $field['label'], '  ')) {
            $section = (string) $field['label'];
        }

        $rows[$section . '/' . trim((string) $field['label'])] = $field;
    }

    return $rows;
}

it('shows the cells of a marker painted in separate places instead of a rectangle to resize', function () {
    [$editor] = scatteredMarkerEditor();
    $rows = scatteredMarkerRows($editor);

    expect($rows['Cells/Cells']['value'] ?? null)->toBe('2 in 2 places')
        ->and($rows)->toHaveKeys(['Position/X', 'Position/Y'])
        ->and($rows)->not->toHaveKey('Size/X');
});

it('moves every cell of a scattered marker from the inspector, and undoes it whole', function () {
    /** @var ProjectMap $map */
    [$editor, $map] = scatteredMarkerEditor();
    $rows = scatteredMarkerRows($editor);

    callEditorMethod($editor, 'applyInspectorFieldValue', $rows['Position/X'], '2');

    expect($map->getEventArea('E')?->cells)->toBe([[2, 1], [9, 3]]);

    callEditorMethod($editor, 'performUndo');

    expect($map->getEventArea('E')?->cells)->toBe([[1, 1], [8, 3]]);
});

it('refuses to move a scattered marker onto another marker and leaves it where it was', function () {
    /** @var ProjectMap $map */
    [$editor, $map] = scatteredMarkerEditor();
    $map->setEventSymbol(3, 1, 'F');
    $rows = scatteredMarkerRows($editor);

    callEditorMethod($editor, 'applyInspectorFieldValue', $rows['Position/X'], '3');

    expect($map->getEventArea('E')?->cells)->toBe([[1, 1], [8, 3]])
        ->and(getEditorProperty($editor, 'statusMessage'))->toContain('would cover marker F at (3, 1)');
});
