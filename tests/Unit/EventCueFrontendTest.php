<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\SessionHost;

function createCueFrontendProject(): string
{
    $root = makeTemporaryProject('editor-cue-frontend-');
    file_put_contents($root . '/assets/Maps/test-map/test-map.data.php', <<<'PHP'
    <?php
    // Keep this authored description expression.
    return [
      'name' => 'Cue frontend fixture',
      'description' => strtoupper('kept'),
      'events' => [
        'E' => [
          'class' => 'Ichiloto\Engine\Events\Triggers\ChestEventTrigger',
          'data' => ['lootType' => 'gold', 'loot' => 2],
          'cue' => ['symbol' => '!', 'color' => 'bright-blue', 'conditions' => []],
        ],
      ],
    ];
    PHP);

    return $root;
}

it('cycles and clears cue kind through the real Terminal inspector without typed or graphical controls', function () {
    $root = createCueFrontendProject();
    $editor = deletionEditor($root);
    $map = callEditorMethod($editor, 'getSelectedMap');
    $bounds = $map->getEventBounds('E');
    setEditorProperty($editor, 'cursorX', $bounds['x']);
    setEditorProperty($editor, 'cursorY', $bounds['y']);
    setEditorProperty($editor, 'editingMode', 'event');
    setEditorProperty($editor, 'focusedPane', 'inspector');
    $fields = callEditorMethod($editor, 'getInspectorFields');
    $index = array_find_key($fields, static fn(array $field): bool => ($field['path'] ?? null) === ['cue', 'kind']);
    expect($index)->not->toBeNull();
    setEditorProperty($editor, 'selectedInspectorFieldIndex', $index);
    $before = $map->getEventDefinition('E');
    $hashes = sourceHashTree($root);

    expect(implode("\n", callEditorMethod($editor, 'inspectorPaneLayout')->lines))->toContain('Unclassified')
        ->and($fields[$index])->not->toHaveKey('control');
    callEditorMethod($editor, 'activateInspectorField');
    expect($map->getEventField('E', ['cue', 'kind']))->toBe('story')
        ->and(getEditorProperty($editor, 'isInspectorEditing'))->toBeFalse();
    callEditorMethod($editor, 'adjustInspectorOptionField', 1);
    expect($map->getEventField('E', ['cue', 'kind']))->toBe('route');
    callEditorMethod($editor, 'adjustInspectorOptionField', -1);
    callEditorMethod($editor, 'adjustInspectorOptionField', -1);
    expect($map->getEventDefinition('E'))->toBe($before)
        ->and(sourceHashTree($root))->toBe($hashes);
    callEditorMethod($editor, 'performUndo');
    expect($map->getEventField('E', ['cue', 'kind']))->toBe('story');
    $map->save();
    expect(ProjectWorkspace::fromProject($root)->getMapByIndex(0)->getEventField('E', ['cue', 'kind']))->toBe('story')
        ->and(file_get_contents($map->dataPath))->toContain("strtoupper('kept')");
});

it('transports cue choices and clear through the GUI session protocol with undo save and reload', function () {
    $root = createCueFrontendProject();
    $hashes = sourceHashTree($root);
    $input = fopen('php://memory', 'r');
    $output = fopen('php://memory', 'w+');
    $diagnostics = fopen('php://memory', 'w+');
    $host = new SessionHost($input, $output, $diagnostics);
    $request = static fn(string $method, array $params = []): array =>
        $host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR));
    $read = static fn(): array => $request('inspector.read', ['map' => 'test-map', 'event' => 'E'])['result'];
    $findRow = static fn(array $inspector): array => array_find($inspector['rows'],
        static fn(array $row): bool => ($row['key']['path'] ?? null) === ['cue', 'kind']);

    try {
        expect($request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $root]))->toHaveKey('result');
        $inspector = $read();
        $row = $findRow($inspector);
        expect($row)->toMatchArray(['kind' => 'options', 'value' => '', 'options' => ['', 'story', 'route']])
            ->and($row['optionLabels'])->toBe(['Unclassified (clear kind)', 'Story', 'Route'])
            ->and(sourceHashTree($root))->toBe($hashes);

        $params = ['map' => 'test-map', 'revision' => $inspector['revision'], 'key' => $row['key']];
        expect($request('inspector.apply', $params + ['value' => 'unknown'])['error']['kind'])->toBe('refusal')
            ->and($findRow($read())['value'])->toBe('')
            ->and(sourceHashTree($root))->toBe($hashes);
        expect($request('inspector.apply', $params + ['value' => 'route'])['result']['changed'])->toBeTrue()
            ->and($findRow($read())['value'])->toBe('route');
        expect($request('project.saveAll')['result']['failures'])->toBe([])
            ->and(ProjectWorkspace::fromProject($root)->getMapByIndex(0)->getEventField('E', ['cue', 'kind']))->toBe('route');
        $inspector = $read();
        expect($request('inspector.apply', ['map' => 'test-map', 'revision' => $inspector['revision'],
            'key' => $findRow($inspector)['key'], 'value' => ''])['result']['changed'])->toBeTrue()
            ->and($findRow($read())['value'])->toBe('');
        expect($request('project.saveAll')['result']['failures'])->toBe([])
            ->and(sourceHashTree($root))->toBe($hashes);
        expect($request('history.undo')['result']['label'])->toBe('Cue kind edit')
            ->and($findRow($read())['value'])->toBe('route');
        expect($request('history.redo')['result']['label'])->toBe('Cue kind edit')
            ->and($findRow($read())['value'])->toBe('');
        rewind($diagnostics);
        expect(stream_get_contents($diagnostics))->toBe('');
    } finally {
        $host->run();
        fclose($input);
        fclose($output);
        fclose($diagnostics);
    }
});
