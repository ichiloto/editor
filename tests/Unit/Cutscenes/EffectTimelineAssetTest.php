<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;

/**
 * An effect is its timeline alone, under assets/Animations, read and written
 * exactly as the Engine's effect library reads it.
 */
function effectPath(string $root, string $id): string
{
    return $root . '/assets/Animations/' . $id . '/' . $id . '.timeline.php';
}

it('reads each effect as one timeline file, named by its folder', function () {
    $root = effectProject();
    $library = CutsceneLibrary::fromProject($root);
    $ember = $library->find(CutsceneType::EFFECT, 'ember-spark');

    expect($library->ids(CutsceneType::EFFECT))->toBe(['dusk-slash', 'ember-spark'])
        ->and($ember?->paths())->toBe([effectPath($root, 'ember-spark')])
        ->and($ember?->isEditable())->toBeTrue()
        ->and($ember?->name())->toBe('ember-spark')
        ->and($ember?->payload()['id'])->toBe('ember-spark')
        ->and($ember?->payload()['tracks'][0]['id'])->toBe('spark')
        ->and($ember?->hasPresentations())->toBeFalse()
        ->and(array_column($library->issues(CutsceneType::EFFECT), 'folder'))->not->toContain('ember-spark');
});

it('edits a flat effect in place, keeping its comment and never writing an id', function () {
    $root = effectProject();
    $library = CutsceneLibrary::fromProject($root);
    $ember = $library->find(CutsceneType::EFFECT, 'ember-spark');
    $payload = $ember->payload();
    $payload['fps'] = 8;
    $ember->apply($payload);

    expect($ember->save())->toBeTrue();

    $source = (string) file_get_contents(effectPath($root, 'ember-spark'));
    $saved = require effectPath($root, 'ember-spark');

    expect($source)->toContain('// Ember Spark: a small flicker over its target.')
        ->and($saved['fps'])->toBe(8)
        ->and($saved)->not->toHaveKey('id')
        ->and($saved['cues'][0]['payload'])->toBe(['sound' => 'crackle'])
        ->and(is_file($root . '/assets/Animations/ember-spark/ember-spark.data.php'))->toBeFalse()
        ->and(new EffectTimelineLibrary($root . '/assets')->load('ember-spark', false, EffectPresentation::TERMINAL)->fps)->toBe(8);
});

it('edits one sequence of a terminal-and-graphical effect and leaves the other exactly as written', function () {
    $root = effectProject();
    $dusk = CutsceneLibrary::fromProject($root)->find(CutsceneType::EFFECT, 'dusk-slash');

    expect($dusk->hasPresentations())->toBeTrue()
        ->and($dusk->getPresentationView())->toBe(EffectPresentation::TERMINAL)
        ->and($dusk->payload()['lengthFrames'])->toBe(4);

    $dusk->selectPresentation(EffectPresentation::GRAPHICAL);
    $payload = $dusk->payload();

    expect($payload['tracks'][0]['type'])->toBe('image');

    $payload['tracks'][0]['keyframes'][1]['flipX'] = true;
    $dusk->apply($payload);
    $dusk->save();
    $saved = require effectPath($root, 'dusk-slash');
    $original = eval('?>' . duskSlashTimeline());

    expect($saved['presentations']['terminal'])->toBe($original['presentations']['terminal'])
        ->and($saved['presentations']['graphical']['tracks'][0]['keyframes'][1])->toBe(['frame' => 1, 'sourceFrame' => 1, 'flipX' => true])
        ->and(array_keys($saved['presentations']['graphical']))->toBe(array_keys($original['presentations']['graphical']));
});

it('splits a flat effect into terminal and graphical sequences only when asked', function () {
    $root = effectProject();
    $ember = CutsceneLibrary::fromProject($root)->find(CutsceneType::EFFECT, 'ember-spark');
    $flat = require effectPath($root, 'ember-spark');

    expect($ember->splitIntoPresentations())->toBeTrue()
        ->and($ember->splitIntoPresentations())->toBeFalse();

    $ember->save();
    $saved = require effectPath($root, 'ember-spark');

    expect($saved)->toBe(['presentations' => ['terminal' => $flat, 'graphical' => $flat]]);
});

it('creates a new effect as one valid timeline, and the Engine refuses an unplayable save', function () {
    $root = effectProject();
    $library = CutsceneLibrary::fromProject($root);
    $id = $library->freeId(CutsceneType::EFFECT, 'Smoke.Puff');
    $blank = Ichiloto\Editor\Database\RecordSchemaCatalog::effects()->blank;
    $created = CutsceneAsset::create(CutsceneType::EFFECT, $id, $library->rootFor(CutsceneType::EFFECT), [...$blank, 'id' => $id], $root);

    expect($id)->toBe('smoke-puff')
        ->and($created->save())->toBeTrue()
        ->and(require effectPath($root, 'smoke-puff'))->not->toHaveKey('id')
        ->and(new EffectTimelineLibrary($root . '/assets')->findTimelineIds())->toContain('smoke-puff');

    $broken = CutsceneLibrary::fromProject($root)->find(CutsceneType::EFFECT, 'smoke-puff');
    $payload = $broken->payload();
    $payload['tracks'] = [];
    $broken->apply($payload);

    expect(fn() => $broken->save())->toThrow(RuntimeException::class, 'Effect smoke-puff cannot be played')
        ->and(require effectPath($root, 'smoke-puff'))->not->toBe([])
        ->and((require effectPath($root, 'smoke-puff'))['tracks'])->not->toBe([]);
});

it('deletes only the effect timeline, and reports a folder without one or with a dotted id', function () {
    $root = effectProject();
    file_put_contents($root . '/assets/Animations/ember-spark/notes.txt', 'keep me');
    $library = CutsceneLibrary::fromProject($root);
    $ember = $library->find(CutsceneType::EFFECT, 'ember-spark');
    $ember->markDeleted(true);
    $ember->save();

    expect(is_file(effectPath($root, 'ember-spark')))->toBeFalse()
        ->and(is_file($root . '/assets/Animations/ember-spark/notes.txt'))->toBeTrue();

    @mkdir($root . '/assets/Animations/empty-effect', 0o777, true);
    @mkdir($root . '/assets/Animations/old.style', 0o777, true);
    file_put_contents(effectPath($root, 'old.style'), emberSparkTimeline());
    $messages = array_column(CutsceneLibrary::fromProject($root)->issues(CutsceneType::EFFECT), 'message');

    expect(implode("\n", $messages))->toContain('Folder "empty-effect" is missing empty-effect.timeline.php; an effect is its timeline file.')
        ->and(implode("\n", $messages))->toContain('The folder name "old.style" is not a stable id (lowercase letters, digits, "_" and "-").');
});
