<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\CutsceneSchemas;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Database\SkitPlaybackFields;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;

/**
 * Authoring the Engine's Skit playback contract: a `play_skit` command that
 * selects a current Skit by stable id, and a Skit's trigger and lifecycle
 * sound cues, edited in place and validated as the Engine reads them. Every
 * project here is a synthetic fixture.
 */

/** Writes a synthetic Skit file, with a comment the editor must keep. */
function writePlaybackSkit(string $root, string $id, string $body): string
{
    $path = $root . '/assets/Data/Skits/' . $id . '.php';
    file_put_contents($path, "<?php\n\n// Written by hand.\nreturn [\n  'id' => '{$id}',\n{$body}  'beats' => [\n    ['speaker' => 'Caller', 'text' => 'Hello?'],\n  ],\n];\n");

    return $path;
}

/** Gives the project the SFX its Skits name. */
function writePlaybackSounds(string $root, string ...$names): void
{
    @mkdir($root . '/assets/Audio/SFX', 0o777, true);
    foreach ($names as $name) {
        file_put_contents($root . '/assets/Audio/SFX/' . $name . '.ogg', 'synthetic');
    }
}

it('reads the command, its reference, the triggers and the cues from the Engine contract', function (): void {
    $contract = CinematicCommandSchema::export()['skitPlayback'];
    $skits = RecordSchemaCatalog::forKey('skits');

    expect(SkitPlaybackFields::getCommandType())->toBe($contract['command'])
        ->and(SkitPlaybackFields::getReferenceField())->toBe($contract['referenceField'])
        ->and(SkitPlaybackFields::getTriggers())->toBe($contract['triggers'])
        ->and(SkitPlaybackFields::getSoundCues())->toBe($contract['soundCues'])
        // The picker lists what the runtime resolves the reference from.
        ->and($contract['referenceSource'])->toBe($skits->relativePath . '/*.php:' . $skits->identityKey)
        ->and(RecordSchemaCatalog::getEventCommandTypes())->toContain($contract['command']);

    foreach ([RecordSchemaCatalog::eventCommandVariants(), CutsceneSchemas::cinematicCommandVariants(true)] as $variants) {
        $field = $variants[$contract['command']][0];
        expect([$field->key, $field->reference])->toBe([$contract['referenceField'], SkitPlaybackFields::REFERENCE_CATEGORY]);
    }
});

it('chooses the played Skit by its stable id and saves the command', function (): void {
    $root = makeTemporaryProject();
    writePlaybackSkit($root, 'device-call', "  'title' => 'A Call',\n  'trigger' => 'script',\n");
    $workspace = ProjectWorkspace::fromProject($root);
    $references = new ReferenceCatalog($workspace);

    expect($references->valuesFor('skits'))->toBe(['breakfast-banter', 'device-call'])
        ->and($references->labelsFor('skits'))->toBe(['breakfast-banter' => 'Breakfast Banter (breakfast-banter)', 'device-call' => 'A Call (device-call)']);

    $database = loadRecordDatabase($root, 'common_events');
    $database->setField(0, 'command0Type', 'play_skit');
    $row = array_column($database->getSettingsFields(0), null, 'field')['command0Skit'];
    expect($row['reference'])->toBe('skits');
    $database->setField(0, 'command0Skit', 'device-call');
    $database->save();

    expect((require $root . '/assets/Events/dresser-note.php')[0])->toBe(['type' => 'play_skit', 'skit' => 'device-call']);
});

it('edits a Skit trigger and its sound cues in place, with undo and redo byte for byte', function (): void {
    $root = makeTemporaryProject();
    writePlaybackSounds($root, 'DeviceOn', 'DeviceOff');
    $path = writePlaybackSkit($root, 'device-call', "  'title' => 'A Call',\n");
    $original = (string) file_get_contents($path);
    $editor = deletionEditor($root);
    openDatabaseCategory($editor, 'skits');
    $database = getEditorProperty($editor, 'workspace')->getRecordDatabase('skits');
    $index = array_search('A Call', $database->getEntryLabels(), true);
    callEditorMethod($editor, 'setSelectedRecordIndex', $index);
    $fields = static fn(): array => array_column($database->getSettingsFields($index), null, 'field');

    expect($fields()['trigger'])->toMatchArray(['value' => 'prompt', 'options' => SkitPlaybackFields::getTriggers()])
        ->and($fields()['sounds.open'])->toMatchArray(['value' => '(none)', 'reference' => 'sfx'])
        ->and($fields()['sounds.close'])->toMatchArray(['value' => '(none)', 'reference' => 'sfx']);

    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $fields()['trigger'], 'script');
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $fields()['sounds.open'], 'DeviceOn');
    callEditorMethod($editor, 'applyDatabaseFieldValueRecorded', $fields()['sounds.close'], 'DeviceOff');
    $database->save();
    $edited = (string) file_get_contents($path);
    $skit = require $path;

    expect($edited)->toContain('// Written by hand.')
        ->and($skit['trigger'])->toBe('script')
        ->and($skit['sounds'])->toBe(['open' => 'DeviceOn', 'close' => 'DeviceOff'])
        ->and(SkitPlaybackFields::readSkitPlayback($skit, 'Skit device-call')['sounds'])->toBe($skit['sounds']);

    callEditorMethod($editor, 'performUndo');
    callEditorMethod($editor, 'performUndo');
    callEditorMethod($editor, 'performUndo');
    $database->save();
    expect(file_get_contents($path))->toBe($original);

    callEditorMethod($editor, 'performRedo');
    callEditorMethod($editor, 'performRedo');
    callEditorMethod($editor, 'performRedo');
    $database->save();
    expect(file_get_contents($path))->toBe($edited);

    // Clearing both cues drops the sounds key as well.
    $database->setField($index, 'sounds.open', '');
    $database->setField($index, 'sounds.close', '');
    $database->save();
    expect(require $path)->not->toHaveKey('sounds');
});

it('refuses a Skit the Engine would skip before anything is written', function (): void {
    $root = makeTemporaryProject();
    $path = writePlaybackSkit($root, 'device-call', "  'title' => 'A Call',\n  'sounds' => ['ring' => 'Bell'],\n");
    $original = (string) file_get_contents($path);
    $database = loadRecordDatabase($root, 'skits');
    $index = array_search('A Call', $database->getEntryLabels(), true);

    $database->setField($index, 'title', 'A Ring');
    expect(fn() => $database->save())->toThrow(RuntimeException::class, 'sounds accepts named open and close SFX references')
        ->and(file_get_contents($path))->toBe($original);

    $database->setField($index, 'trigger', 'whenever');
    expect(fn() => $database->save())->toThrow(RuntimeException::class, 'trigger must be one of prompt, script')
        ->and(file_get_contents($path))->toBe($original);
});

it('refuses a cue its source cannot hold rather than flattening the Skit', function (): void {
    $root = makeTemporaryProject();
    $path = writePlaybackSkit($root, 'device-call', "  'title' => 'A Call',\n  'sounds' => array_merge(['open' => 'DeviceOn'], []),\n");
    $original = (string) file_get_contents($path);
    $database = loadRecordDatabase($root, 'skits');
    $index = array_search('A Call', $database->getEntryLabels(), true);

    $refused = null;
    try {
        $database->setField($index, 'sounds.close', 'DeviceOff');
        $database->save();
    } catch (RuntimeException $error) {
        $refused = $error;
    }

    expect($refused)->toBeInstanceOf(RuntimeException::class)
        ->and($refused?->getMessage())->toBe('The value at sounds is written as an expression the editor cannot rewrite.')
        ->and(file_get_contents($path))->toBe($original);
});

it('validates what a play_skit command selects and what a Skit plays with', function (): void {
    $root = cutsceneProject();
    writePlaybackSounds($root, 'DeviceOn');
    writePlaybackSkit($root, 'device-call', "  'title' => 'A Call',\n  'trigger' => 'script',\n  'sounds' => ['open' => 'DeviceOn', 'close' => 'Gone'],\n");
    writePlaybackSkit($root, 'odd-trigger', "  'title' => 'Odd',\n  'trigger' => 'whenever',\n");
    file_put_contents($root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php', "<?php\n\nreturn "
        . var_export([
            ['type' => 'play_skit', 'skit' => 'device-call'],
            ['type' => 'play_skit', 'skit' => 'breakfast-banter'],
            ['type' => 'play_skit', 'skit' => 'missing'],
        ], true) . ";\n");

    $messages = array_map(static fn($issue): string => $issue->message, validateProject($root));

    expect($messages)->toContain('It names the skit "missing", which does not exist.')
        ->and($messages)->toContain('It plays the skit "breakfast-banter", which plays only on the map "happyville/town-center", from harbour.')
        ->and($messages)->toContain('It plays the close sound "Gone", which the project has no file for.')
        ->and($messages)->toContain('Skit odd-trigger trigger must be one of prompt, script.')
        ->and(array_filter($messages, static fn(string $message): bool => str_contains($message, '"device-call"')))->toBe([]);
});

it('offers the Skit picker on a cinematic play_skit command through the editor session', function (): void {
    $root = cutsceneProject();
    file_put_contents($root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php', "<?php\n\nreturn "
        . var_export([['type' => 'play_skit', 'skit' => 'breakfast-banter']], true) . ";\n");
    $session = EditorSession::open($root);

    $row = array_find($session->readDatabaseRecord('cutscenes/cinematic', 0, ['commands'])['rows'],
        static fn(array $row): bool => ($row['entry'] ?? []) === [0] && ($row['name'] ?? '') === 'Skit');

    expect($row)->toMatchArray(['value' => 'breakfast-banter', 'reference' => 'skits']);
});
