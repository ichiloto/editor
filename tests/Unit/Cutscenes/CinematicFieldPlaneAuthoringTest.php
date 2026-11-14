<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Database\CutsceneSchemas;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicFieldPlane;

/**
 * A cinematic's field effects, staged actor commands and staged cast choose the plane their graphical art draws on,
 * from the Engine's cinematic schema, through the shared session rows, saves and undo. Synthetic fixtures only.
 */
it('offers the Engine\'s planes to field effects and staged actors in both editors', function () {
    $plane = CinematicCommandSchema::export()['fieldPlane'];
    $field = CutsceneSchemas::getFieldPlaneField();
    expect($field->key)->toBe(CinematicFieldPlane::FIELD_NAME)
        ->and($field->options)->toBe(array_column(CinematicFieldPlane::cases(), 'value'))
        ->and($field->displayDefault)->toBe($plane['default']);
    foreach ([false, true] as $graphical) {
        $keys = static fn(array $fields): array => array_map(static fn($field): string => $field->key, $fields);
        $variants = CutsceneSchemas::cinematicCommandVariants($graphical);
        expect($keys(CutsceneSchemas::stagedActorFields($graphical)))->toContain('plane')
            ->and($keys($variants['field_animation'](['type' => 'field_animation', 'effect' => 'glow'])))->toContain('plane')
            ->and($keys($variants['field_animation'](['type' => 'field_animation', 'animation' => 'spark'])))->toContain('plane');
    }
});

it('authors a field effect\'s plane, keeping the source, undoing to the original bytes and clearing to the default', function () {
    $root = createHarbourCommandProject(['type' => 'field_animation', 'animation' => 'spark', 'target' => ['kind' => 'player']]);
    [, $scriptPath] = getHarbourPaths($root);
    $before = file_get_contents($scriptPath);
    $session = EditorSession::open($root);
    $row = getCinematicRow($session, 'command4Plane');
    expect($row['options'])->toBe(array_column(CinematicFieldPlane::cases(), 'value'));
    setCinematicRow($session, 'command4Plane', CinematicFieldPlane::ABOVE_COVER->value);
    $session->saveDatabase('cutscenes/cinematic');
    $script = CutsceneLibrary::fromProject($root)->find(CutsceneType::CINEMATIC, 'harbour-lanterns')->partner();
    expect($script[4]['plane'])->toBe('aboveCover')
        ->and(CinematicFieldPlane::getFromCommand($script[4]))->toBe(CinematicFieldPlane::ABOVE_COVER)
        ->and(file_get_contents($scriptPath))->toContain("'type' => 'transition'");
    $session->undo();
    $session->saveDatabase('cutscenes/cinematic');
    expect(file_get_contents($scriptPath))->toBe($before);

    setCinematicRow($session, 'command4Plane', CinematicFieldPlane::ABOVE_COVER->value);
    setCinematicRow($session, 'command4Plane', '');
    $session->saveDatabase('cutscenes/cinematic');
    expect(file_get_contents($scriptPath))->not->toContain("'plane'");
});

it('lifts a staged cast member and a wrapped stage command above the cover, preserving the authored data file', function () {
    $root = createHarbourCommandProject(['type' => 'stage_actor', 'actor' => ['id' => 'lantern', 'sprite' => ['*'], 'x' => 2, 'y' => 2]]);
    [$dataPath] = getHarbourPaths($root);
    $session = EditorSession::open($root);
    setCinematicRow($session, 'cast0Plane', CinematicFieldPlane::ABOVE_COVER->value);
    setCinematicRow($session, 'command4Actorplane', CinematicFieldPlane::ABOVE_COVER->value);
    $session->saveDatabase('cutscenes/cinematic');
    $asset = CutsceneLibrary::fromProject($root)->find(CutsceneType::CINEMATIC, 'harbour-lanterns');
    expect($asset->data()['cast'][0]['plane'])->toBe('aboveCover')
        ->and($asset->partner()[4]['actor']['plane'])->toBe('aboveCover')
        ->and($asset->partner()[4])->not->toHaveKey('plane')
        ->and(file_get_contents($dataPath))->toContain('// Harbour Lanterns: an original test cinematic.');
    $session->undo();
    expect(getCinematicRow($session, 'command4Actorplane')['value'] ?? '')->not->toBe('aboveCover');
});

it('refuses lifting a world object takeover above the cover, in the Engine\'s words, before anything changes', function () {
    $root = createHarbourCommandProject(['type' => 'wait', 'seconds' => 0.2]);
    [$dataPath] = getHarbourPaths($root);
    $session = EditorSession::open($root);
    setCinematicRow($session, 'cast0Subjectkind', 'world_object');
    setCinematicRow($session, 'cast0Subjectid', 'beacon');
    expect(fn() => setCinematicRow($session, 'cast0Plane', CinematicFieldPlane::ABOVE_COVER->value))
        ->toThrow(SessionRefusal::class, 'A world object takeover draws in the field');
    // The field plane stays available to the takeover.
    setCinematicRow($session, 'cast0Plane', CinematicFieldPlane::FIELD->value);
    $session->saveDatabase('cutscenes/cinematic');
    expect((require $dataPath)['cast'][0])->toMatchArray(['plane' => 'field', 'subject' => ['kind' => 'world_object', 'id' => 'beacon']]);

    // Binding a lifted visual to a world object is refused when the binding is complete.
    $other = EditorSession::open(createHarbourCommandProject(['type' => 'wait', 'seconds' => 0.2]));
    setCinematicRow($other, 'cast0Plane', CinematicFieldPlane::ABOVE_COVER->value);
    setCinematicRow($other, 'cast0Subjectkind', 'world_object');
    expect(fn() => setCinematicRow($other, 'cast0Subjectid', 'beacon'))
        ->toThrow(SessionRefusal::class, 'A world object takeover draws in the field');
});

it('reports an unknown plane and a lifted world object takeover in a reusable script, in the Engine\'s words', function () {
    $root = cutsceneProject();
    file_put_contents($root . '/assets/Events/lifted.php', "<?php\n\nreturn " . var_export([
        ['type' => 'field_animation', 'animation' => 'spark', 'target' => ['kind' => 'player'], 'plane' => 'sideways'],
        ['type' => 'stage_actor', 'id' => 'beacon-flare', 'subject' => ['kind' => 'world_object', 'id' => 'beacon'], 'plane' => 'aboveCover'],
    ], true) . ";\n");
    $messages = array_values(array_map(static fn(Issue $issue): string => $issue->message, array_filter(
        new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)),
        static fn(Issue $issue): bool => str_contains($issue->message, 'plane'))));
    expect(implode("\n", $messages))->toContain('plane must be one of field, aboveCover')
        ->toContain('A world object takeover draws in the field; plane aboveCover lifts staged characters and images.');
});
