<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\CutsceneSchemas;
use Ichiloto\Editor\Database\StagedActorFields;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;

/**
 * A staged actor's image can be withheld while its Terminal glyph, placement and collision stay, from the Engine's
 * cinematic schema (`stagedActorGraphicalVisibility`), through the shared session rows, saves and undo. Synthetic
 * fixtures only.
 */
it('offers Draw Image from the Engine\'s contract to the graphical editor, beside the visual it replaces', function () {
    $visibility = CinematicCommandSchema::export()['stagedActorGraphicalVisibility'];
    $field = StagedActorFields::getGraphicalVisibilityField([]);
    expect($field->key)->toBe($visibility['field'])
        ->and($field->options)->toBe(['false', 'true'])
        ->and($field->displayDefault)->toBe('true')
        ->and($visibility['default'])->toBeTrue();
    $replacement = StagedActorFields::getGraphicalVisibilityField(['replace' => true]);
    expect($replacement->options)->toBe(['', 'false', 'true'])
        ->and($replacement->displayDefault)->toBe('(keep current)');

    $keys = static fn(array $fields): array => array_map(static fn($field): string => $field->key, $fields);
    expect($keys(CutsceneSchemas::stagedActorFields(true)))->toContain($visibility['field'])
        ->and($keys(CutsceneSchemas::stagedActorFields(true, ['appearance' => ['kind' => 'npc', 'id' => 'keeper']])))->toContain($visibility['field'])
        ->and($keys(CutsceneSchemas::stagedActorFields(true, ['subject' => ['kind' => 'player']])))->toContain($visibility['field'])
        // The Terminal editor authors no graphical presentation of a staged actor.
        ->and($keys(CutsceneSchemas::stagedActorFields(false)))->not->toContain($visibility['field']);
});

it('withholds a cast member\'s and a wrapped stage command\'s image, storing only false and undoing to the original bytes', function () {
    $root = createHarbourCommandProject(['type' => 'stage_actor', 'actor' => ['id' => 'lantern', 'sprite' => ['*'], 'x' => 2, 'y' => 2]]);
    [$dataPath, $scriptPath] = getHarbourPaths($root);
    $before = [file_get_contents($dataPath), file_get_contents($scriptPath)];
    $session = EditorSession::open($root);
    expect(getCinematicRow($session, 'cast0GraphicalVisible')['value'] ?? null)->toBe('true');
    setCinematicRow($session, 'cast0GraphicalVisible', 'false');
    $session->saveDatabase('cutscenes/cinematic');
    expect((require $dataPath)['cast'][0]['graphicalVisible'])->toBeFalse();
    $session->undo();
    $session->saveDatabase('cutscenes/cinematic');
    expect([file_get_contents($dataPath), file_get_contents($scriptPath)])->toBe($before);

    setCinematicRow($session, 'cast0GraphicalVisible', 'false');
    setCinematicRow($session, 'command4ActorgraphicalVisible', 'false');
    $session->saveDatabase('cutscenes/cinematic');
    $asset = CutsceneLibrary::fromProject($root)->find(CutsceneType::CINEMATIC, 'harbour-lanterns');
    expect($asset->data()['cast'][0]['graphicalVisible'])->toBeFalse()
        ->and($asset->partner()[4]['actor']['graphicalVisible'])->toBeFalse()
        ->and($asset->partner()[4])->not->toHaveKey('graphicalVisible')
        ->and(file_get_contents($dataPath))->toContain('// Harbour Lanterns: an original test cinematic.');
    CinematicStageManager::validateBinding($asset->partner()[4]['actor']);

    // Drawing the image again is the Engine's default for a new visual: the key goes.
    setCinematicRow($session, 'cast0GraphicalVisible', 'true');
    setCinematicRow($session, 'command4ActorgraphicalVisible', 'true');
    $session->saveDatabase('cutscenes/cinematic');
    expect(file_get_contents($dataPath))->not->toContain('graphicalVisible')
        ->and(file_get_contents($scriptPath))->not->toContain('graphicalVisible');
});

it('lets a replacement keep, show or withhold the image of the visual it replaces', function () {
    $root = createHarbourCommandProject(['type' => 'stage_actor', 'id' => 'lantern', 'sprite' => ['*'], 'replace' => true]);
    [, $scriptPath] = getHarbourPaths($root);
    $session = EditorSession::open($root);
    $row = getCinematicRow($session, 'command4GraphicalVisible');
    expect($row['value'] ?? null)->toBe('(keep current)')
        ->and($row['options'])->toBe(['', 'false', 'true']);
    $read = static fn(): array => CutsceneLibrary::fromProject($root)->find(CutsceneType::CINEMATIC, 'harbour-lanterns')->partner()[4];

    // A replacement that names true restores an image an earlier command withheld, so true is stored.
    setCinematicRow($session, 'command4GraphicalVisible', 'true');
    $session->saveDatabase('cutscenes/cinematic');
    expect($read()['graphicalVisible'])->toBeTrue();
    setCinematicRow($session, 'command4GraphicalVisible', 'false');
    $session->saveDatabase('cutscenes/cinematic');
    expect($read()['graphicalVisible'])->toBeFalse();
    setCinematicRow($session, 'command4GraphicalVisible', '');
    $session->saveDatabase('cutscenes/cinematic');
    expect($read())->not->toHaveKey('graphicalVisible')
        ->and(file_get_contents($scriptPath))->toContain("'type' => 'transition'");

    // No longer a replacement, a stored true is the default again and goes; a stored false stays.
    setCinematicRow($session, 'command4GraphicalVisible', 'true');
    setCinematicRow($session, 'command4Replace', 'false');
    $session->saveDatabase('cutscenes/cinematic');
    expect($read())->not->toHaveKey('graphicalVisible')->not->toHaveKey('replace');
    setCinematicRow($session, 'command4Replace', 'true');
    setCinematicRow($session, 'command4GraphicalVisible', 'false');
    setCinematicRow($session, 'command4Replace', 'false');
    $session->saveDatabase('cutscenes/cinematic');
    expect($read()['graphicalVisible'])->toBeFalse();
});

it('keeps a withheld image through the Terminal editor, which does not offer the field', function () {
    $root = createHarbourCommandProject(['type' => 'stage_actor', 'id' => 'lantern', 'sprite' => ['*'], 'x' => 2, 'y' => 2,
        'graphicalVisible' => false]);
    $library = ProjectWorkspace::fromProject($root)->cutscenes ?? throw new RuntimeException('The Terminal workspace has no cutscenes.');
    $rows = array_values(array_filter($library->records(CutsceneType::CINEMATIC)->getFrameSettingsFields(0, ['commands']),
        static fn(array $row): bool => ($row['entry'] ?? []) === [4]));
    expect(array_column($rows, 'name'))->toContain('Sprite')->not->toContain('Draw Image');
    $sprite = array_find($rows, static fn(array $row): bool => ($row['name'] ?? '') === 'Sprite');
    // Every Terminal edit goes through the library's one change path.
    $library->changeAsset(CutsceneType::CINEMATIC, 0, 'Sprite',
        static fn($records, int $index) => $records->setFrameField($index, ['commands'], $sprite['field'], 'H'));
    $library->save(CutsceneType::CINEMATIC, 'harbour-lanterns');
    $command = CutsceneLibrary::fromProject($root)->find(CutsceneType::CINEMATIC, 'harbour-lanterns')->partner()[4];
    expect($command['sprite'])->toBe(['H'])
        ->and($command['graphicalVisible'])->toBeFalse();
});

it('reports a Draw Image value that is not true or false in a reusable script, in the Engine\'s words', function () {
    $root = cutsceneProject();
    file_put_contents($root . '/assets/Events/withheld.php', "<?php\n\nreturn " . var_export([
        ['type' => 'stage_actor', 'id' => 'lantern', 'sprite' => ['*'], 'x' => 2, 'y' => 2, 'graphicalVisible' => 'no'],
    ], true) . ";\n");
    $messages = array_values(array_map(static fn(Issue $issue): string => $issue->message, array_filter(
        new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)),
        static fn(Issue $issue): bool => str_contains($issue->message, 'graphicalVisible'))));
    expect(implode("\n", $messages))->toContain('boolean');
});
