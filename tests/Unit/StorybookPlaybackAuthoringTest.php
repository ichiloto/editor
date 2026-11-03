<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\CutsceneSchemas;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Database\StorybookPlaybackFields;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Events\Interpreter\EventExecutionScope;
use Ichiloto\Engine\Messaging\Storybook\StorybookLibrary;

/**
 * Authoring the Engine's storybook playback contract: a `play_storybook`
 * command in event scripts and cinematics that selects one of the project's
 * storybooks by stable id, edited in its own source and validated as the
 * Engine reads it. Every project and storybook here is a synthetic fixture.
 */

/**
 * Writes a synthetic storybook source.
 *
 * @param array<string, mixed> $book
 */
function writePlaybackStorybook(string $root, string $id, array $book): void
{
    $directory = $root . '/assets/' . StorybookLibrary::DIRECTORY;
    if (! is_dir($directory)) {
        mkdir($directory, 0o777, true);
    }
    file_put_contents($directory . '/' . $id . '.php', "<?php\n\nreturn " . var_export(['id' => $id, ...$book], true) . ";\n");
}

/** Gives a project two readable synthetic storybooks. */
function writePlaybackStorybooks(string $root): void
{
    writePlaybackStorybook($root, 'river-tale', ['title' => 'The River', 'narrator' => 'Reader', 'pages' => [
        ['id' => 'spring', 'text' => 'Water found its way down.'],
    ]]);
    writePlaybackStorybook($root, 'moon-tale', ['title' => 'The Moon', 'narrator' => 'Reader', 'pages' => [
        ['id' => 'rise', 'text' => 'A light came up over the hill.'],
    ]]);
}

/** Writes a synthetic event script with a comment the editor must keep. */
function writeStorybookEventScript(string $root, string $storybook): string
{
    $path = $root . '/assets/Events/reading-hour.php';
    file_put_contents($path, "<?php\n\n// Written by hand.\nreturn [\n  ['type' => 'text', 'name' => '', 'text' => 'Sit and listen.'],\n"
        . "  ['type' => 'play_storybook', 'storybook' => {$storybook}],\n];\n");

    return $path;
}

it('reads the command and its reference from the Engine contract in every script scope', function (): void {
    $contract = CinematicCommandSchema::export()['storybookPlayback'];

    expect(StorybookPlaybackFields::getCommandType())->toBe($contract['command'])
        ->and(StorybookPlaybackFields::getReferenceField())->toBe($contract['referenceField'])
        // The picker lists what the runtime resolves the reference from.
        ->and($contract['referenceSource'])->toBe('assets/' . StorybookLibrary::DIRECTORY . '/*.php:id');

    foreach ([EventExecutionScope::FIELD, EventExecutionScope::BATTLE] as $scope) {
        $list = RecordSchemaCatalog::eventCommandList('commands', scope: $scope);
        expect($list->fields[0]->options)->toContain($contract['command']);
        $field = $list->variants[$contract['command']][0];
        expect([$field->key, $field->reference])->toBe([$contract['referenceField'], StorybookPlaybackFields::REFERENCE_CATEGORY]);
    }

    $field = CutsceneSchemas::cinematicCommandVariants(true)[$contract['command']][0];
    expect([$field->key, $field->reference])->toBe([$contract['referenceField'], StorybookPlaybackFields::REFERENCE_CATEGORY]);
});

it('lists the storybooks the Engine discovers, by stable id with their titles', function (): void {
    $root = makeTemporaryProject();
    writePlaybackStorybooks($root);
    // Refused by the Engine (no narrator), yet still a selectable source; validation says why.
    writePlaybackStorybook($root, 'torn-tale', ['title' => 'Torn', 'pages' => [['id' => 'one', 'text' => 'Gone.']]]);
    $references = new ReferenceCatalog(ProjectWorkspace::fromProject($root));

    expect(ReferenceCatalog::knows(StorybookPlaybackFields::REFERENCE_CATEGORY))->toBeTrue()
        ->and($references->valuesFor('storybooks'))->toBe(['moon-tale', 'river-tale', 'torn-tale'])
        ->and($references->labelsFor('storybooks'))->toBe([
            'moon-tale' => 'The Moon (moon-tale)',
            'river-tale' => 'The River (river-tale)',
            'torn-tale' => 'torn-tale',
        ])
        ->and(new ReferenceCatalog(ProjectWorkspace::fromProject(makeTemporaryProject()))->valuesFor('storybooks'))->toBe([]);
});

it('adds a play_storybook command by choosing its storybook and saves the stable id', function (): void {
    $root = makeTemporaryProject();
    writePlaybackStorybooks($root);
    $database = loadRecordDatabase($root, 'common_events');

    $database->setField(0, 'command0Type', 'play_storybook');
    $row = array_column($database->getSettingsFields(0), null, 'field')['command0Storybook'];
    expect($row['reference'])->toBe('storybooks');
    $database->setField(0, 'command0Storybook', 'river-tale');
    $database->save();

    expect((require $root . '/assets/Events/dresser-note.php')[0])->toBe(['type' => 'play_storybook', 'storybook' => 'river-tale']);
});

it('changes the selected storybook in place, with undo and redo byte for byte', function (): void {
    $root = makeTemporaryProject();
    writePlaybackStorybooks($root);
    $path = writeStorybookEventScript($root, "'river-tale'");
    $original = (string) file_get_contents($path);
    $editor = deletionEditor($root);
    openDatabaseCategory($editor, 'common_events');
    $database = getEditorProperty($editor, 'workspace')->getRecordDatabase('common_events');
    $index = array_search('reading-hour', $database->getEntryLabels(), true);
    expect($index)->toBeInt();
    callEditorMethod($editor, 'setSelectedRecordIndex', $index);
    $field = array_column($database->getSettingsFields($index), null, 'field')['command1Storybook'];

    expect($field)->toMatchArray(['value' => 'river-tale', 'reference' => 'storybooks']);

    // The Terminal picker lists the storybooks by title; typing narrows them and Enter stores the stable id.
    callEditorMethod($editor, 'openReferencePicker', $field);
    $picker = getEditorProperty($editor, 'referencePicker');
    expect($picker->isOpen())->toBeTrue()
        ->and($picker->rows())->toBe(['The Moon (moon-tale)', 'The River (river-tale)']);
    $picker->setFilter('moon');
    callEditorMethod($editor, 'commitReferenceSelection');
    expect($picker->isOpen())->toBeFalse();
    $database->save();
    $edited = (string) file_get_contents($path);

    expect($edited)->toBe(str_replace("'river-tale'", "'moon-tale'", $original))
        ->and((require $path)[1])->toBe(['type' => 'play_storybook', 'storybook' => 'moon-tale']);

    callEditorMethod($editor, 'performUndo');
    $database->save();
    expect(file_get_contents($path))->toBe($original);

    callEditorMethod($editor, 'performRedo');
    $database->save();
    expect(file_get_contents($path))->toBe($edited);
});

it('refuses to rewrite a storybook reference its source computes, before anything is written', function (): void {
    $root = makeTemporaryProject();
    writePlaybackStorybooks($root);
    $path = writeStorybookEventScript($root, "strtolower('RIVER-TALE')");
    $original = (string) file_get_contents($path);
    $database = loadRecordDatabase($root, 'common_events');
    $index = array_search('reading-hour', $database->getEntryLabels(), true);

    $refused = null;
    try {
        $database->setField($index, 'command1Storybook', 'moon-tale');
        $database->save();
    } catch (RuntimeException $error) {
        $refused = $error;
    }

    expect($refused)->toBeInstanceOf(RuntimeException::class)
        ->and($refused?->getMessage())->toContain('written as an expression the editor cannot rewrite')
        ->and(file_get_contents($path))->toBe($original);
});

it('validates what a play_storybook command selects and the storybooks themselves', function (): void {
    $root = cutsceneProject();
    writePlaybackStorybooks($root);
    writePlaybackStorybook($root, 'torn-tale', ['title' => 'Torn', 'pages' => [['id' => 'one', 'text' => 'Gone.']]]);
    writePlaybackStorybook($root, 'faded-tale', ['title' => 'Faded', 'narrator' => 'Reader', 'pages' => [
        ['id' => 'dusk', 'text' => 'The colours went first.', 'picture' => 'Storybooks/faded/dusk.png'],
    ]]);
    file_put_contents($root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php', "<?php\n\nreturn "
        . var_export([
            ['type' => 'play_storybook', 'storybook' => 'river-tale'],
            ['type' => 'play_storybook', 'storybook' => 'torn-tale'],
            ['type' => 'play_storybook', 'storybook' => 'lost-tale'],
        ], true) . ";\n");
    file_put_contents($root . '/assets/Events/dresser-note.php', "<?php\n\nreturn "
        . var_export([['type' => 'play_storybook', 'storybook' => 'moon-tale'], ['type' => 'play_storybook']], true) . ";\n");

    $issues = validateProject($root);
    $messages = array_map(static fn($issue): string => $issue->message, $issues);

    expect($messages)->toContain('It names the storybook "lost-tale", which does not exist.')
        ->and($messages)->toContain('It plays the storybook "torn-tale", which the game refuses.')
        ->and($messages)->toContain('It plays no storybook.')
        ->and($messages)->toContain('Page dusk shows the picture Storybooks/faded/dusk.png, which the game cannot read.')
        ->and(array_filter($issues, static fn($issue): bool => $issue->where === 'assets/Data/Storybooks/torn-tale.php'
            && str_starts_with($issue->message, 'The game refuses this storybook: ')))->not->toBe([])
        ->and(array_filter($messages, static fn(string $message): bool => str_contains($message, '"river-tale"')
            || str_contains($message, '"moon-tale"')))->toBe([]);
});

it('offers the storybook picker on a cinematic play_storybook command through the editor session', function (): void {
    $root = cutsceneProject();
    writePlaybackStorybooks($root);
    file_put_contents($root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php', "<?php\n\nreturn "
        . var_export([['type' => 'play_storybook', 'storybook' => 'moon-tale']], true) . ";\n");
    $session = EditorSession::open($root);

    $row = array_find($session->readDatabaseRecord('cutscenes/cinematic', 0, ['commands'])['rows'],
        static fn(array $row): bool => ($row['entry'] ?? []) === [0] && ($row['name'] ?? '') === 'Storybook');

    expect($row)->toMatchArray(['value' => 'moon-tale', 'reference' => 'storybooks'])
        ->and($session->listReferences(null, 'storybooks'))->toBe([
            ['value' => 'moon-tale', 'label' => 'The Moon (moon-tale)'],
            ['value' => 'river-tale', 'label' => 'The River (river-tale)'],
        ]);
});
