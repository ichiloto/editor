<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\InnPresentationFields;
use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\RecordAuthoring;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Field\NpcInspector;
use Ichiloto\Editor\Inspector\InspectorRefusal;
use Ichiloto\Editor\Inspector\MapInspector;
use Ichiloto\Editor\ProjectConfig;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\Validation\EffectValidator;

function innDescriptorProject(): string
{
    $root = makeTemporaryProject('editor-inn-descriptor-');
    mkdir($root . '/assets/Graphics/Rest', 0777, true);
    writeTilesetTestPng($root . '/assets/Graphics/Rest/guest.png', 16, 16);
    foreach (['rest-a', 'rest-b'] as $id) {
        mkdir($root . '/assets/Animations/' . $id, 0777, true);
        file_put_contents($root . '/assets/Animations/' . $id . '/' . $id . '.timeline.php', '<?php return ' . var_export([
            'fps' => 10, 'lengthFrames' => 4,
            'stage' => ['canvas' => ['width' => 320, 'height' => 180], 'startFrame' => 0, 'restoreFrame' => 3,
                'camera' => [['id' => 'hold', 'frame' => 0, 'focus' => ['x' => 160, 'y' => 90], 'zoom' => 1, 'easing' => 'hold']],
                'subjects' => [['id' => 'guest', 'position' => ['x' => 160, 'y' => 150], 'size' => ['width' => 32, 'height' => 32]]]],
            'tracks' => [['id' => 'guest', 'type' => 'image', 'asset' => 'Graphics/Rest/guest.png', 'anchor' => 'stage',
                'placement' => ['subject' => 'guest'], 'pivot' => ['x' => .5, 'y' => 1],
                'keyframes' => [['frame' => 0, 'duration' => 4]]]],
        ], true) . ';');
    }
    $actor = (string) file_get_contents($root . '/assets/Data/Actors/Kaelion.php');
    file_put_contents($root . '/assets/Data/Actors/Guest.php', str_replace("'Kaelion'", "'guest-b'", $actor));
    $data = $root . '/assets/Maps/test-map/test-map.data.php';
    file_put_contents($data, str_replace("  'events' => [\n", "  'events' => [\n    'S' => [\n      'class' => 'Ichiloto\\Engine\\Events\\Triggers\\SleepEventTrigger',\n      'data' => ['cost' => 5, 'confirmDialogue' => ['text' => 'Rest?']],\n    ],\n", (string) file_get_contents($data)));
    return $root;
}

function innDescriptorConfig(string $root, string $descriptor): string
{
    $source = "<?php\n// authored configuration\nreturn [\n  'vocab' => ['title' => 'Original'],\n  'untouched' => strtoupper('kept'),\n  'opaque' => new stdClass(),\n  'graphics' => ['inn' => ['presentation' => $descriptor]],\n];\n";
    file_put_contents($root . '/config.php', $source);
    return $source;
}

function innDescriptorMap(ProjectWorkspace $workspace): ProjectMap
{
    return array_find($workspace->maps, static fn(ProjectMap $map): bool => $map->mapId === 'test-map');
}

function innDescriptorRow(MapInspector $inspector, ProjectMap $map, callable $matches): array
{
    return array_find($inspector->buildEventDataFields('S', $map->getEventDefinition('S')), $matches) ?? [];
}

function innDescriptorSessionRow(EditorSession $session, string $category, int $index, string $field, array $frame = []): array
{
    return array_find($session->readDatabaseRecord($category, $index, $frame)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field)
        ?? throw new RuntimeException('Missing Inn row ' . $field);
}

function innDescriptorCinematic(string $root, string $presentation = ''): string
{
    $folder = $root . '/assets/Cutscenes/Cinematics/rest-scene';
    mkdir($folder, 0777, true);
    file_put_contents($folder . '/rest-scene.data.php', "<?php return ['id' => 'rest-scene', 'name' => 'Rest', 'startMap' => 'test-map', 'skip' => ['policy' => 'forbidden']];\n");
    $file = $folder . '/rest-scene.script.php';
    file_put_contents($file, "<?php\n// preserve cinematic annotation\nreturn [['type' => 'sequence', 'commands' => [['type' => 'inn', 'confirmDialogue' => ['text' => 'Rest?'], 'cost' => 5$presentation]]]];\n");
    return $file;
}

it('keeps each descriptor one config record and preserves source through edit save reopen and byte-restoring undo', function (string $descriptor, string $fieldId, array $expected) {
    $root = innDescriptorProject();
    $source = innDescriptorConfig($root, $descriptor);
    $hashes = sourceHashTree($root);
    $workspace = ProjectWorkspace::fromProject($root, graphical: true);
    $db = $workspace->getRecordDatabase('configuration');
    $index = array_search(ProjectConfig::INN_PRESENTATION, $db->getEntryLabels(), true);
    expect(array_filter($db->getEntryLabels(), static fn(string $path): bool => str_starts_with($path, ProjectConfig::INN_PRESENTATION)))->toHaveCount(1);
    $row = array_find($db->getSettingsFields($index), static fn(array $row): bool => ($row['field'] ?? null) === $fieldId);
    expect($row['reference'])->toBe('stage_timelines')->and($row)->not->toHaveKey('control');
    $change = new RecordAuthoring()->applyField($db, $index, [], $fieldId, 'rest-b', 'Stage');
    $change->command->undo();
    expect($workspace->config->getSource())->toBe($source)->and(sourceHashTree($root))->toBe($hashes);
    $change->command->execute();
    $workspace->config->save();
    expect(file_get_contents($root . '/config.php'))->toContain('// selected shot', "strtoupper('kept')", 'new stdClass()');
    $reopened = ProjectWorkspace::fromProject($root, graphical: true);
    expect($reopened->config->getRecord(ProjectConfig::INN_PRESENTATION)->get('value'))->toBe($expected);
    $change->command->undo();
    $workspace->config->save();
    expect(file_get_contents($root . '/config.php'))->toBe($source)->and(sourceHashTree($root))->toBe($hashes);
})->with([
    'leader' => ["['treatment' => 'leader', 'leaders' => [\n    // selected shot\n    'Kaelion' => 'rest-a',\n  ]]", 'innLeader0Timeline', ['treatment' => 'leader', 'leaders' => ['Kaelion' => 'rest-b']]],
    'party' => ["['treatment' => 'party', 'parties' => [\n    // selected shot\n    ['actors' => ['Kaelion', 'guest-b'], 'timeline' => 'rest-a'],\n  ]]", 'innParty0Timeline', ['treatment' => 'party', 'parties' => [['actors' => ['Kaelion', 'guest-b'], 'timeline' => 'rest-b']]]],
]);

it('creates bindings with stable constrained pickers not invented ids and refuses incomplete saves or mixed treatment before mutation', function (string $treatment) {
    $root = innDescriptorProject();
    $workspace = ProjectWorkspace::fromProject($root, graphical: true);
    $db = $workspace->getRecordDatabase('configuration');
    $index = array_search(ProjectConfig::INN_PRESENTATION, $db->getEntryLabels(), true);
    $before = sourceHashTree($root);
    $author = new RecordAuthoring();
    $author->applyField($db, $index, [], 'value.treatment', $treatment, 'Treatment');
    $prefix = $treatment === 'leader' ? 'innLeader' : 'innParty';
    $add = $author->addItem($db, $index, [], $prefix . 'List');
    expect($add->command)->not->toBeNull()
        ->and(fn() => $workspace->config->save())->toThrow(InvalidArgumentException::class)
        ->and(sourceHashTree($root))->toBe($before);
    $actorField = $treatment === 'leader' ? $prefix . '0Actor' : $prefix . '0Actors';
    $actorRow = array_find($db->getSettingsFields($index), static fn(array $row): bool => ($row['field'] ?? null) === $actorField);
    expect($actorRow['reference'])->toBe('actor_ids')->and($actorRow)->not->toHaveKey('control');
    $snapshot = $db->getRecordByIndex($index)->toArray();
    expect(fn() => $author->applyField($db, $index, [], $actorField, 'missing-actor', 'Guest'))->toThrow(RecordRefusal::class)
        ->and($db->getRecordByIndex($index)->toArray())->toBe($snapshot);
    $author->applyField($db, $index, [], $actorField, $treatment === 'leader' ? 'Kaelion' : 'Kaelion, guest-b', 'Guests');
    $stage = $author->applyField($db, $index, [], $prefix . '0Timeline', 'rest-a', 'Stage');
    $snapshot = $db->getRecordByIndex($index)->toArray();
    expect(fn() => $author->applyField($db, $index, [], 'value.treatment', $treatment === 'leader' ? 'party' : 'leader', 'Treatment'))->toThrow(RecordRefusal::class)
        ->and($db->getRecordByIndex($index)->toArray())->toBe($snapshot);
    $workspace->config->save();
    expect(ProjectWorkspace::fromProject($root)->config->getRecord(ProjectConfig::INN_PRESENTATION)->get('value')['treatment'])->toBe($treatment);
    $stage->command->undo();
    expect(fn() => $workspace->config->save())->toThrow(InvalidArgumentException::class);
    $stage->command->execute();
    expect($db->getRecordByIndex($index)->toArray())->toBe($snapshot);
})->with(['leader', 'party']);

it('refuses expression edits and unknown stages before record mutation or history creation', function () {
    $root = innDescriptorProject();
    $source = innDescriptorConfig($root, "['treatment' => 'leader', 'leaders' => ['Kaelion' => strtolower('REST-A')]]");
    $workspace = ProjectWorkspace::fromProject($root, graphical: true);
    $db = $workspace->getRecordDatabase('configuration');
    $index = array_search(ProjectConfig::INN_PRESENTATION, $db->getEntryLabels(), true);
    $before = $db->getRecordByIndex($index)->toArray();
    $version = $db->getContentVersion();
    expect(fn() => new RecordAuthoring()->applyField($db, $index, [], 'innLeader0Timeline', 'rest-b', 'Stage'))->toThrow(RecordRefusal::class)
        ->and(fn() => $db->setField($index, 'innLeader0Timeline', 'missing-stage'))->toThrow(InvalidArgumentException::class)
        ->and($db->getRecordByIndex($index)->toArray())->toBe($before)
        ->and($db->getContentVersion())->toBe($version)
        ->and($workspace->config->isDirty())->toBeFalse()
        ->and(file_get_contents($root . '/config.php'))->toBe($source);
});

it('preserves graphical descriptors in Terminal while retaining scalar config and Sleep workflow', function () {
    $root = innDescriptorProject();
    innDescriptorConfig($root, "['treatment' => 'leader', 'leaders' => ['Kaelion' => 'rest-a']]");
    $workspace = ProjectWorkspace::fromProject($root);
    $db = $workspace->getRecordDatabase('configuration');
    $index = array_search(ProjectConfig::INN_PRESENTATION, $db->getEntryLabels(), true);
    $fields = $db->getSettingsFields($index);
    expect($fields)->toHaveCount(2)->and($fields[1]['editable'])->toBeFalse();
    $old = $db->getRecordByIndex($index)->toArray();
    $db->setField($index, 'value', 'rest-b');
    expect($db->getRecordByIndex($index)->toArray())->toBe($old);
    $map = innDescriptorMap($workspace);
    $map->setEventField('S', ['data', 'presentation'], $old['value']);
    $tui = new MapInspector(new ReferenceCatalog($workspace));
    $row = innDescriptorRow($tui, $map, static fn(array $row): bool => ($row['innPresentation'] ?? false) === true);
    expect($row['editable'])->toBeFalse()
        ->and(fn() => $tui->apply($map, $row, 'rest-b'))->toThrow(InspectorRefusal::class);
    $map->setEventField('S', ['data', 'cost'], 7);
    $map->save();
    expect(ProjectWorkspace::fromProject($root)->maps[0]->data)->not->toBeNull();
});

it('authors Sleep leader and exact-party bindings with undo redo save reopen and refusal without mutation', function (string $treatment) {
    $root = innDescriptorProject();
    $workspace = ProjectWorkspace::fromProject($root, graphical: true);
    $map = innDescriptorMap($workspace);
    $before = sourceHashTree($root);
    $inspector = new MapInspector(new ReferenceCatalog($workspace), graphical: true);
    $treatmentRow = innDescriptorRow($inspector, $map, static fn(array $row): bool => ($row['path'] ?? null) === ['data', 'presentation', 'treatment']);
    $mode = $inspector->apply($map, $treatmentRow, $treatment);
    $heading = innDescriptorRow($inspector, $map, static fn(array $row): bool => isset($row['innPresentationList']) && ($row['innPresentationList']['index'] ?? null) === -1);
    $added = $inspector->addListEntry($map, $heading);
    expect(fn() => $map->save())->toThrow(InvalidArgumentException::class)->and(sourceHashTree($root))->toBe($before);
    $actorRow = innDescriptorRow($inspector, $map, static fn(array $row): bool => ($row['reference'] ?? null) === 'actor_ids');
    $inspector->apply($map, $actorRow, $treatment === 'leader' ? 'Kaelion' : 'Kaelion, guest-b');
    $stageRow = innDescriptorRow($inspector, $map, static fn(array $row): bool => isset($row['innEntryField']) && ($row['reference'] ?? null) === 'stage_timelines');
    $stage = $inspector->apply($map, $stageRow, 'rest-a');
    $snapshot = $map->getEventDefinition('S');
    expect(fn() => $inspector->apply($map, $stageRow, 'absent-stage'))->toThrow(InspectorRefusal::class)
        ->and($map->getEventDefinition('S'))->toBe($snapshot);
    $stage->undo();
    $stage->execute();
    $map->save();
    expect(innDescriptorMap(ProjectWorkspace::fromProject($root))->getEventDefinition('S'))->toBe($snapshot);
    $removed = $inspector->removeListEntry($map, $stageRow);
    $removed->command->undo();
    expect($map->getEventDefinition('S'))->toBe($snapshot);
    $removed->command->execute();
    $added->command->undo();
    $mode->undo();
    $map->save();
    expect(sourceHashTree($root))->toBe($before);
})->with(['leader', 'party']);

it('authors nested registered inn descriptors and retains Terminal command scalar compatibility', function (string $treatment) {
    $root = innDescriptorProject();
    file_put_contents($root . '/assets/Events/rest.php', "<?php\n// keep script annotation\nreturn [['type' => 'if', 'condition' => ['switch' => 'late'], 'then' => [['type' => 'inn', 'cost' => 5]]]];\n");
    $workspace = ProjectWorkspace::fromProject($root, graphical: true);
    $db = $workspace->getRecordDatabase('common_events');
    $index = array_search('rest', $db->getEntryLabels(), true);
    $author = new RecordAuthoring();
    $frame = [0, 'then'];
    $author->applyField($db, $index, $frame, 'command0Presentationtreatment', $treatment, 'Treatment');
    $beforeAdd = $db->getFrameCommands($index, $frame);
    $added = $author->addNestedItem($db, $index, $frame, 0);
    expect($added->command)->not->toBeNull()->and(fn() => $db->save())->toThrow(InvalidArgumentException::class);
    $draft = $db->getFrameCommands($index, $frame);
    $added->command->undo();
    expect($db->getFrameCommands($index, $frame))->toBe($beforeAdd);
    $added->command->execute();
    expect($db->getFrameCommands($index, $frame))->toBe($draft);
    $prefix = $treatment === 'leader' ? 'command0InnLeader0' : 'command0InnParty0';
    $author->applyField($db, $index, $frame, $prefix . ($treatment === 'leader' ? 'Actor' : 'Actors'), $treatment === 'leader' ? 'Kaelion' : 'Kaelion, guest-b', 'Guests');
    $change = $author->applyField($db, $index, $frame, $prefix . 'Timeline', 'rest-a', 'Stage');
    $expected = $db->getFrameCommands($index, $frame)[0]['presentation'];
    $db->save();
    $change->command->undo();
    $change->command->execute();
    expect(ProjectWorkspace::fromProject($root)->getRecordDatabase('common_events')->getFrameCommands($index, $frame)[0]['presentation'])->toBe($expected)
        ->and(file_get_contents($root . '/assets/Events/rest.php'))->toContain('// keep script annotation');
    $tui = ProjectWorkspace::fromProject($root)->getRecordDatabase('common_events');
    $row = array_find($tui->getFrameSettingsFields($index, $frame), static fn(array $row): bool => ($row['field'] ?? null) === 'command0Presentation');
    expect($row['editable'])->toBeFalse();
})->with(['leader', 'party']);

it('traverses every bound stage independent of the live party and reports malformed descriptors without guessing fallback', function () {
    $root = innDescriptorProject();
    innDescriptorConfig($root, "['treatment' => 'leader', 'leaders' => ['Kaelion' => 'rest-a', 'guest-b' => 'missing-rest']]");
    file_put_contents($root . '/assets/Events/rest.php', '<?php return ' . var_export([
        ['type' => 'if', 'then' => [['type' => 'inn', 'presentation' => ['treatment' => 'party', 'parties' => [
            ['actors' => ['Kaelion', 'guest-b'], 'timeline' => 'rest-b'],
        ]]]]],
    ], true) . ';');
    $workspace = ProjectWorkspace::fromProject($root);
    $issues = [];
    $uses = EffectValidator::findUses($workspace, $issues);
    expect($uses['rest-a']['stage'])->toBe(['config.php: ' . ProjectConfig::INN_PRESENTATION])
        ->and($uses['missing-rest']['stage'])->toBe(['config.php: ' . ProjectConfig::INN_PRESENTATION])
        ->and($uses['rest-b']['stage'])->toBe(['event script rest'])
        ->and(array_filter(new EffectValidator()->validate($workspace), static fn($issue): bool => str_contains($issue->message, 'missing-rest')))->toHaveCount(1);
    $workspace->config->getRecord(ProjectConfig::INN_PRESENTATION)->set('value', ['leaders' => ['Kaelion' => 'rest-a']]);
    $issues = [];
    $uses = EffectValidator::findUses($workspace, $issues);
    expect($uses)->not->toHaveKey('rest-a')
        ->and(array_filter($issues, static fn($issue): bool => str_contains($issue->message, 'rest presentation is invalid')))->toHaveCount(1);
});

it('rejects mixed bindings duplicate parties and unknown keys through the authoritative descriptor', function (array $value) {
    expect(fn() => InnPresentationFields::assertValid($value, complete: false))->toThrow(InvalidArgumentException::class);
})->with([
    [['treatment' => 'leader', 'leaders' => ['Kaelion' => 'rest-a'], 'parties' => [['actors' => ['guest-b'], 'timeline' => 'rest-b']]]],
    [['treatment' => 'party', 'parties' => [['actors' => ['Kaelion', 'guest-b'], 'timeline' => 'rest-a'], ['actors' => ['guest-b', 'Kaelion'], 'timeline' => 'rest-b']]]],
    [['treatment' => 'leader', 'leaders' => [], 'fallback' => 'rest-a']],
]);

it('renames a selected leader identity in place without dropping its source annotation or expression value', function () {
    $root = innDescriptorProject();
    $source = innDescriptorConfig($root, "['treatment' => 'leader', 'leaders' => [\n    // selected shot\n    'Kaelion' => strtolower('REST-A'),\n  ]]");
    $workspace = ProjectWorkspace::fromProject($root, graphical: true);
    $db = $workspace->getRecordDatabase('configuration');
    $index = array_search(ProjectConfig::INN_PRESENTATION, $db->getEntryLabels(), true);
    $change = new RecordAuthoring()->applyField($db, $index, [], 'innLeader0Actor', 'guest-b', 'Leader');
    $workspace->config->save();
    expect(file_get_contents($root . '/config.php'))->toContain('// selected shot', "'guest-b' => strtolower('REST-A')")
        ->and(ProjectWorkspace::fromProject($root)->config->getRecord(ProjectConfig::INN_PRESENTATION)->get('value')['leaders'])->toBe(['guest-b' => 'rest-a']);
    $change->command->undo();
    $workspace->config->save();
    expect(file_get_contents($root . '/config.php'))->toBe($source);
});

it('exposes constrained Inn controls through the graphical session with history and disk round trips', function () {
    $root = innDescriptorProject();
    $session = EditorSession::open($root);
    $index = array_search(ProjectConfig::INN_PRESENTATION, ProjectWorkspace::fromProject($root)->getRecordDatabase('configuration')->getEntryLabels(), true);
    $row = innDescriptorSessionRow($session, 'configuration', $index, 'value.treatment');
    expect($row['kind'])->toBe('options');
    $session->applyDatabaseRecord('configuration', $index, $row['key'], 'party');
    $session->addDatabaseItem('configuration', $index, innDescriptorSessionRow($session, 'configuration', $index, 'innPartyList')['key']);
    $actors = innDescriptorSessionRow($session, 'configuration', $index, 'innParty0Actors');
    expect($actors)->toMatchArray(['kind' => 'reference', 'reference' => 'actor_ids', 'multi' => true])
        ->and(array_column($session->listReferences(null, 'actor_ids'), 'value'))->toContain('Kaelion', 'guest-b');
    $session->applyDatabaseRecord('configuration', $index, $actors['key'], 'Kaelion, guest-b');
    $stage = innDescriptorSessionRow($session, 'configuration', $index, 'innParty0Timeline');
    expect($stage)->toMatchArray(['kind' => 'reference', 'reference' => 'stage_timelines']);
    $session->applyDatabaseRecord('configuration', $index, $stage['key'], 'rest-b');
    $session->undo();
    expect(innDescriptorSessionRow($session, 'configuration', $index, 'innParty0Timeline')['value'])->toBe('');
    $session->redo();
    expect(fn() => $session->applyDatabaseRecord('configuration', $index, $stage['key'], 'missing-stage'))->toThrow(SessionRefusal::class);
    $session->saveDatabase('configuration');
    $reopenedIndex = array_search(ProjectConfig::INN_PRESENTATION, ProjectWorkspace::fromProject($root)->getRecordDatabase('configuration')->getEntryLabels(), true);
    expect(innDescriptorSessionRow(EditorSession::open($root), 'configuration', $reopenedIndex, 'innParty0Timeline')['value'])->toBe('rest-b');
});

it('keeps cinematic owned-list drafts editable and reference-constrained across refresh then saves reopens and undoes', function () {
    $root = innDescriptorProject();
    $file = innDescriptorCinematic($root);
    $before = file_get_contents($file);
    $session = EditorSession::open($root);
    $frame = [CutsceneAsset::COMMANDS_KEY, 0, 'commands'];
    $row = static fn(string $field): array => innDescriptorSessionRow($session, 'cutscenes/cinematic', 0, $field, $frame);
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, $row('command0Presentationtreatment')['key'], 'party');
    expect($session->addDatabaseItem('cutscenes/cinematic', 0, $row('command0Cost')['key'], child: true)['changed'])->toBeTrue();
    expect(fn() => $session->saveDatabase('cutscenes/cinematic'))->toThrow(InvalidArgumentException::class)
        ->and(file_get_contents($file))->toBe($before);
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, $row('command0InnParty0Actors')['key'], 'Kaelion, guest-b');
    $session->applyDatabaseRecord('cutscenes/cinematic', 0, $row('command0InnParty0Timeline')['key'], 'rest-a');
    $snapshot = $session->readDatabaseRecord('cutscenes/cinematic', 0, $frame);
    expect(fn() => $session->applyDatabaseRecord('cutscenes/cinematic', 0, $row('command0InnParty0Actors')['key'], 'unknown-actor'))->toThrow(SessionRefusal::class)
        ->and($session->readDatabaseRecord('cutscenes/cinematic', 0, $frame))->toBe($snapshot);
    $session->saveDatabase('cutscenes/cinematic');
    expect(innDescriptorSessionRow(EditorSession::open($root), 'cutscenes/cinematic', 0, 'command0InnParty0Timeline', $frame)['value'])->toBe('rest-a')
        ->and(file_get_contents($file))->toContain('// preserve cinematic annotation');
    $session->undo();
    $session->redo();
    expect($row('command0InnParty0Timeline')['value'])->toBe('rest-a');
});

it('keeps NPC commands graphical only while their sole map owner supplies save and history', function () {
    $root = innDescriptorProject();
    $file = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $file;
    $data['npcs'] = [['id' => 'keeper', 'name' => 'Keeper', 'x' => 1, 'y' => 1, 'sprite' => 'K',
        'script' => [['type' => 'inn', 'cost' => 5, 'presentation' => ['treatment' => 'leader', 'leaders' => ['Kaelion' => 'rest-a']]]]]];
    file_put_contents($file, '<?php return ' . var_export($data, true) . ';');
    $workspace = ProjectWorkspace::fromProject($root);
    $tui = new NpcInspector(innDescriptorMap($workspace));
    $frame = ['script'];
    $tuiRow = array_find($tui->getFields(0, $frame), static fn(array $row): bool => ($row['field'] ?? '') === 'command0Presentation');
    expect($tuiRow['editable'])->toBeFalse();
    $session = EditorSession::open($root);
    $read = $session->readNpc('test-map', 0, $frame);
    $row = array_find($read['rows'], static fn(array $row): bool => ($row['key']['field'] ?? '') === 'command0InnLeader0Timeline');
    expect($row['reference'])->toBe('stage_timelines');
    $session->applyNpc('test-map', $read['revision'], 0, $row['key'], 'rest-b');
    $session->undo();
    $session->redo();
    $session->saveMap('test-map');
    $reopened = $session->readNpc('test-map', 0, $frame);
    expect(array_find(EditorSession::open($root)->readNpc('test-map', 0, $frame)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? '') === 'command0InnLeader0Timeline')['value'])->toBe('rest-b');
    expect(fn() => $session->applyNpc('test-map', $reopened['revision'], 0, $row['key'], 'missing-stage'))->toThrow(SessionRefusal::class);
});

it('preserves untouched graphical references while a Terminal cinematic edits gameplay cost', function (bool $saveAll) {
    $root = innDescriptorProject();
    $file = innDescriptorCinematic($root, ", 'presentation' => ['treatment' => 'leader', 'leaders' => ['unknown-actor' => 'rest-a']]");
    $before = sourceHashTree($root);
    $workspace = ProjectWorkspace::fromProject($root);
    $library = $workspace->cutscenes;
    $frame = [CutsceneAsset::COMMANDS_KEY, 0, 'commands'];
    $change = $library->changeAsset(CutsceneType::CINEMATIC, 0, 'Cost', static fn($db, $index) => new RecordAuthoring()->applyField($db, $index, $frame, 'command0Cost', '7', 'Cost'));
    if ($saveAll) {
        expect($library->saveAll())->toBe(['saved' => ['cinematic rest-scene'], 'failed' => []]);
    } else {
        expect($library->save(CutsceneType::CINEMATIC, 'rest-scene'))->toBeTrue();
    }
    expect((require $file)[0]['commands'][0])->toMatchArray(['cost' => 7, 'presentation' => ['treatment' => 'leader', 'leaders' => ['unknown-actor' => 'rest-a']]])
        ->and(ProjectWorkspace::fromProject($root)->cutscenes->find(CutsceneType::CINEMATIC, 'rest-scene')->payload()['commands'][0]['commands'][0]['cost'])->toBe(7)
        ->and(file_get_contents($file))->toContain('// preserve cinematic annotation');
    $change['command']->undo();
    $library->save(CutsceneType::CINEMATIC, 'rest-scene');
    expect(sourceHashTree($root))->toBe($before);
})->with(['single save' => [false], 'save all' => [true]]);

it('refuses incomplete or invalid newly authored bindings at the direct cutscene owner before staging or backup', function (array $presentation, bool $withReferences) {
    $root = innDescriptorProject();
    innDescriptorCinematic($root);
    $workspace = ProjectWorkspace::fromProject($root, graphical: true);
    $asset = $workspace->cutscenes->find(CutsceneType::CINEMATIC, 'rest-scene');
    $before = sourceHashTree($root);
    $state = $asset->captureEditState();
    $payload = $asset->payload();
    $payload['commands'][0]['commands'][0]['presentation'] = $presentation;
    $asset->apply($payload);
    $edited = $asset->captureEditState();
    $backedUp = false;
    $backup = static function () use (&$backedUp): void { $backedUp = true; };
    $references = $withReferences ? new ReferenceCatalog($workspace) : null;
    expect(fn() => $asset->save($backup, $references))->toThrow(InvalidArgumentException::class)
        ->and($backedUp)->toBeFalse()
        ->and($asset->captureEditState())->toBe($edited)
        ->and($asset->isDirty())->toBeTrue()
        ->and(sourceHashTree($root))->toBe($before);
    $asset->restoreEditState($state);
    expect($asset->save($backup, $references))->toBeFalse()->and(sourceHashTree($root))->toBe($before);
})->with([
    'missing treatment without catalog' => [['leaders' => ['Kaelion' => 'rest-a']], false],
    'blank leader without catalog' => [['treatment' => 'leader', 'leaders' => ['' => '']], false],
    'blank party without catalog' => [['treatment' => 'party', 'parties' => [['actors' => [], 'timeline' => '']]], false],
    'unknown actor with live catalog' => [['treatment' => 'leader', 'leaders' => ['unknown-actor' => 'rest-a']], true],
    'unknown stage with live catalog' => [['treatment' => 'party', 'parties' => [['actors' => ['Kaelion'], 'timeline' => 'unknown-stage']]], true],
]);

it('composes route draft refusal with unchanged Inn references at the paired owner and restores authored source after save', function (array $draft) {
    $root = innDescriptorProject();
    $file = innDescriptorCinematic($root, ", 'presentation' => ['treatment' => 'leader', 'leaders' => ['unknown-actor' => strtolower('REST-A')]]");
    $workspace = ProjectWorkspace::fromProject($root, graphical: true);
    $asset = $workspace->cutscenes->find(CutsceneType::CINEMATIC, 'rest-scene');
    $state = $asset->captureEditState();
    $before = sourceHashTree($root);
    $payload = $asset->payload();
    $route = ['type' => 'move_route', 'subject' => 'player', 'secondsPerStep' => 0.3];
    $payload['commands'][0]['commands'][] = [...$route, ...$draft];
    $asset->apply($payload);
    $pending = $asset->captureEditState();
    $backedUp = false;
    $backup = static function () use (&$backedUp): void { $backedUp = true; };
    $references = new ReferenceCatalog($workspace);
    expect(fn() => $asset->save($backup, $references))->toThrow(InvalidArgumentException::class)
        ->and($backedUp)->toBeFalse()
        ->and($asset->captureEditState())->toBe($pending)
        ->and(sourceHashTree($root))->toBe($before);
    $complete = [...$route, 'waypoints' => [['x' => 0], ['y' => 0]]];
    $payload['commands'][0]['commands'][1] = $complete;
    $asset->apply($payload);
    expect($asset->save(references: $references))->toBeTrue()
        ->and((require $file)[0]['commands'][1])->toBe($complete)
        ->and(file_get_contents($file))->toContain('// preserve cinematic annotation', "strtolower('REST-A')")
        ->and(ProjectWorkspace::fromProject($root)->cutscenes->find(CutsceneType::CINEMATIC, 'rest-scene')->payload()['commands'][0]['commands'][1])->toBe($complete);
    $asset->restoreEditState($state);
    $asset->save(references: $references);
    expect(sourceHashTree($root))->toBe($before);
})->with([
    'no waypoints' => [['waypoints' => []]],
    'no authored axes' => [['waypoints' => [[]]]],
    'no retrace reference' => [['retrace' => '']],
]);

it('refuses unsupported nested binding source changes before mutation while preserving independent authored expressions', function () {
    $root = innDescriptorProject();
    $file = $root . '/assets/Events/rest.php';
    $source = "<?php\n// binding comment\nreturn [['type' => 'inn', 'confirmDialogue' => ['text' => 'Rest?'], 'cost' => 5, 'presentation' => ['treatment' => 'leader', 'leaders' => ['Kaelion' => strtolower('REST-A')]]]];\n";
    file_put_contents($file, $source);
    $workspace = ProjectWorkspace::fromProject($root, graphical: true);
    $db = $workspace->getRecordDatabase('common_events');
    $index = array_search('rest', $db->getEntryLabels(), true);
    $before = $db->getRecordByIndex($index)->toArray();
    $version = $db->getContentVersion();
    expect(fn() => new RecordAuthoring()->applyField($db, $index, [], 'command0InnLeader0Timeline', 'rest-b', 'Stage'))->toThrow(RecordRefusal::class)
        ->and($db->getRecordByIndex($index)->toArray())->toBe($before)
        ->and($db->getContentVersion())->toBe($version)
        ->and(file_get_contents($file))->toBe($source);
    new RecordAuthoring()->applyField($db, $index, [], 'command0Cost', '7', 'Cost');
    $db->save();
    expect(file_get_contents($file))->toContain('// binding comment', "strtolower('REST-A')")
        ->and((require $file)[0]['cost'])->toBe(7);
});

it('validates Sleep cinematic and NPC bindings including actors and all timelines without party-dependent selection', function () {
    $root = innDescriptorProject();
    innDescriptorCinematic($root, ", 'presentation' => ['treatment' => 'party', 'parties' => [['actors' => ['Kaelion', 'guest-b'], 'timeline' => 'rest-b']]]");
    $file = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $file;
    $data['events']['S']['data']['presentation'] = ['treatment' => 'leader', 'leaders' => ['Kaelion' => 'rest-a']];
    $data['npcs'] = [['id' => 'keeper', 'name' => 'Keeper', 'x' => 1, 'y' => 1, 'sprite' => 'K',
        'script' => [['type' => 'if', 'then' => [['type' => 'inn', 'presentation' => ['treatment' => 'leader', 'leaders' => ['unknown-actor' => 'missing-rest']]]]]]]];
    file_put_contents($file, '<?php return ' . var_export($data, true) . ';');
    $issues = [];
    $uses = EffectValidator::findUses(ProjectWorkspace::fromProject($root), $issues);
    expect($uses['rest-a']['stage'])->toBe(['map test-map event S'])
        ->and($uses['rest-b']['stage'])->toBe(['cinematic rest-scene'])
        ->and($uses['missing-rest']['stage'])->toBe(['map test-map NPC keeper'])
        ->and(array_filter($issues, static fn($issue): bool => str_contains($issue->message, 'unknown actor identity unknown-actor')))->toHaveCount(1);
});

it('authors inline map command bindings through the same controls while Terminal preserves them read-only', function (string $treatment) {
    $root = innDescriptorProject();
    $file = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $file;
    $data['events']['I'] = ['class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptedEventTrigger', 'data' => [
        'commands' => [['type' => 'if', 'then' => [['type' => 'inn', 'confirmDialogue' => ['text' => 'Rest?'], 'cost' => 5]]]],
    ]];
    file_put_contents($file, "<?php\n// preserve inline event\nreturn " . var_export($data, true) . ';');
    $workspace = ProjectWorkspace::fromProject($root, graphical: true);
    $map = innDescriptorMap($workspace);
    $before = file_get_contents($file);
    $inspector = new MapInspector(new ReferenceCatalog($workspace, $map), graphical: true);
    $rows = static fn(): array => $inspector->buildEventDataFields('I', $map->getEventDefinition('I'));
    $path = ['data', 'commands', '0', 'then', '0', 'presentation'];
    $mode = $inspector->apply($map, array_find($rows(), static fn(array $row): bool => ($row['path'] ?? []) === [...$path, 'treatment']), $treatment);
    $heading = array_find($rows(), static fn(array $row): bool => ($row['innPresentationList']['index'] ?? null) === -1);
    $added = $inspector->addListEntry($map, $heading);
    expect(fn() => $map->save())->toThrow(InvalidArgumentException::class)->and(file_get_contents($file))->toBe($before);
    $actors = array_find($rows(), static fn(array $row): bool => ($row['reference'] ?? null) === 'actor_ids');
    $inspector->apply($map, $actors, $treatment === 'leader' ? 'Kaelion' : 'Kaelion, guest-b');
    $stageRow = array_find($rows(), static fn(array $row): bool => isset($row['innEntryField']) && ($row['reference'] ?? null) === 'stage_timelines');
    $stage = $inspector->apply($map, $stageRow, 'rest-a');
    $snapshot = $map->getEventDefinition('I');
    expect(fn() => $inspector->apply($map, $stageRow, 'missing-stage'))->toThrow(InspectorRefusal::class)
        ->and($map->getEventDefinition('I'))->toBe($snapshot);
    $stage->undo();
    $stage->execute();
    $map->save();
    $reopened = ProjectWorkspace::fromProject($root);
    $tui = new MapInspector(new ReferenceCatalog($reopened));
    $reopenedMap = innDescriptorMap($reopened);
    $terminalRows = $tui->buildEventDataFields('I', $reopenedMap->getEventDefinition('I'));
    $descriptorRow = array_find($terminalRows, static fn(array $row): bool => ($row['path'] ?? []) === $path);
    expect($descriptorRow['editable'])->toBeFalse()
        ->and(array_any($terminalRows, static fn(array $row): bool => ($row['reference'] ?? '') === 'actor_ids'))->toBeFalse()
        ->and($reopenedMap->getEventDefinition('I'))->toBe($snapshot)
        ->and(file_get_contents($file))->toContain('// preserve inline event');
})->with(['leader', 'party']);
