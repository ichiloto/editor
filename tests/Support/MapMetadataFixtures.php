<?php

declare(strict_types=1);

use Ichiloto\Editor\Editor;

/**
 * Shared fixtures for Map Inspector runtime-metadata tests: the music a map
 * plays, its conditional variants, and its encounters.
 */

/**
 * A project whose fixture map can carry music and encounters.
 */
function metadataProject(array $tracks = ['harbour-theme', 'crypt-theme']): string
{
    $root = makeTemporaryProject('ichiloto-map-metadata-');
    mkdir($root . '/assets/Audio/BGM', 0o777, true);

    foreach ($tracks as $track) {
        file_put_contents($root . '/assets/Audio/BGM/' . $track . '.ogg', '');
    }

    return $root;
}

/**
 * An editor over the project with the fixture map selected and the Inspector
 * focused, exactly as an author has it.
 */
function metadataEditor(string $root): Editor
{
    $editor = deletionEditor($root);
    setEditorProperty($editor, 'focusedPane', 'inspector');

    return $editor;
}

/**
 * The inspector field with the given label, and its index.
 *
 * @return array{0: array<string, mixed>, 1: int}
 */
function inspectorFieldNamed(Editor $editor, string $label): array
{
    foreach (callEditorMethod($editor, 'getInspectorFields') as $index => $field) {
        if (trim((string) ($field['label'] ?? '')) === $label) {
            return [$field, $index];
        }
    }

    throw new RuntimeException("No inspector field \"{$label}\": " . implode(', ', array_map(
        static fn(array $f): string => trim((string) ($f['label'] ?? '')),
        callEditorMethod($editor, 'getInspectorFields'),
    )));
}

/**
 * The value shown on the inspector row with the given label.
 */
function inspectorValueOf(Editor $editor, string $label): string
{
    [$field] = inspectorFieldNamed($editor, $label);

    return (string) ($field['value'] ?? '');
}

/**
 * Puts the inspector cursor on a row and returns its descriptor.
 *
 * @return array<string, mixed>
 */
function selectInspectorField(Editor $editor, string $label): array
{
    [$field, $index] = inspectorFieldNamed($editor, $label);
    setEditorProperty($editor, 'selectedInspectorFieldIndex', $index);

    return $field;
}

/**
 * Chooses a value from the picker the row opens.
 */
function pickInspectorReference(Editor $editor, string $label, string $value): void
{
    selectInspectorField($editor, $label);
    callEditorMethod($editor, 'activateInspectorField');
    $entries = getEditorProperty($editor, 'eventOptionDialogEntries');
    $index = null;

    foreach ($entries as $position => $entry) {
        if ((string) $entry['value'] === $value) {
            $index = $position;
        }
    }

    expect($index)->not->toBeNull("the picker offers {$value}");
    setEditorProperty($editor, 'selectedEventOptionIndex', $index);
    callEditorMethod($editor, 'applySelectedEventOption');
}

/**
 * The map's data as it now stands in the editor.
 */
function mapDataOf(Editor $editor): array
{
    return callEditorMethod($editor, 'getSelectedMap')->getEditableData();
}

/**
 * Every file of a project with its bytes and modification time.
 *
 * @return array<string, array{0: string, 1: int}>
 */
function projectFileState(string $root): array
{
    $state = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $state[substr($file->getPathname(), strlen($root) + 1)] = [
                (string) file_get_contents($file->getPathname()),
                (int) filemtime($file->getPathname()),
            ];
        }
    }

    ksort($state);

    return $state;
}

/**
 * Backdates every file so a rewrite is visible in its modification time.
 */
function backdateProject(string $root): void
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            touch($file->getPathname(), time() - 3600);
        }
    }
}
