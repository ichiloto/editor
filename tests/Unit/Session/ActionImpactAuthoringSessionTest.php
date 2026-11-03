<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Battle\Presentation\BattlerBindings;

/**
 * Aligning an action to its impact: a battle pose's impact entry, chosen among its own playback entries, and
 * an animation's caster effect anchored to the target phase, saved as the Engine reads them and previewed on
 * the command's own target boundary. Synthetic fixtures only.
 */

/** The effect battle preview project, with a caster effect on its attack animation and an attack sheet for Kaelion. */
function actionImpactProject(?array $attackPose = null): string
{
    $root = effectBattlePreviewProject();
    // Kaelion's attack: four 40 px cells, cell 1 held, played once.
    writeTilesetTestPng($root . '/assets/Graphics/Actors/KaelionAttack.png', 160, 80);
    file_put_contents($root . '/assets/' . BattlerBindings::FILE, "<?php\n\nreturn " . var_export(['actors' => ['Kaelion' => ['poses' => [
        'attack' => $attackPose ?? ['image' => 'Graphics/Actors/KaelionAttack.png', 'columns' => 4, 'frames' => [0, 1, 1, 2, 3], 'fps' => 20, 'loop' => false],
    ]]]], true) . ";\n");
    file_put_contents($root . '/assets/Data/animations.php', "<?php\n\n// Attacks.\nreturn " . var_export([
        ['id' => 1, 'name' => 'Dusk Slash', 'roles' => ['attack', 'attack-unarmed'], 'sourceEffect' => 'ember-spark', 'targetEffect' => 'dusk-slash'],
    ], true) . ";\n");

    return $root;
}

/** An animation record's row, by its field. */
function readAnimationRow(EditorSession $session, string $field): ?array
{
    return array_find($session->readDatabaseRecord('animations', 0)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === $field);
}

/** Kaelion's attack pose row, by its field. */
function readAttackPoseRow(EditorSession $session, string $field): array
{
    return array_find($session->readDatabaseRecord('battler_actors', 0)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === $field)
        ?? throw new RuntimeException("No row {$field}.");
}

/** The source rectangle Kaelion's attack sheet is drawn from at one command frame. */
function readAttackCell(EditorSession $session, int $effect, int $frame): ?int
{
    $preview = $session->readEffectBattlePreview($effect, $frame);
    $image = array_find($preview['canvas']['images'] ?? [], static fn(array $image): bool => $image['asset'] === 'Graphics/Actors/KaelionAttack.png');

    return isset($image['sourceRect']) ? intdiv($image['sourceRect']['x'], 40) : null;
}

it('chooses a pose\'s impact among its own playback entries, each with its cell, and saves the index the battle reads', function () {
    $root = actionImpactProject();
    $session = EditorSession::open($root);
    $path = $root . '/assets/' . BattlerBindings::FILE;
    $before = (string) file_get_contents($path);
    $row = readAttackPoseRow($session, 'pose0ImpactFrame');

    // Indexes into frames, repeats included: entry 2 is the held cell 1.
    expect($row['value'])->toBe('(none: its own clock)')
        ->and($row['options'])->toBe(['', 'Entry 0 · cell 0', 'Entry 1 · cell 1', 'Entry 2 · cell 1', 'Entry 3 · cell 2', 'Entry 4 · cell 3']);

    $session->applyDatabaseRecord('battler_actors', 0, $row['key'], 'Entry 3 · cell 2');
    expect(readAttackPoseRow($session, 'pose0ImpactFrame')['value'])->toBe('Entry 3 · cell 2');

    $session->saveDatabase('battler_actors');
    $written = require $path;
    expect($written['actors']['Kaelion']['poses']['attack']['impactFrame'])->toBe(3)
        ->and(BattlerBindings::load($root . '/assets')->actorPoses['Kaelion']->roles['attack']->impactFrame)->toBe(3);

    // Undone and saved, the file is the bytes it was.
    $session->undo();
    $session->saveDatabase('battler_actors');
    expect((string) file_get_contents($path))->toBe($before);
    $session->redo();
    expect(readAttackPoseRow($session, 'pose0ImpactFrame')['value'])->toBe('Entry 3 · cell 2');
});

it('offers a looping pose no impact, refuses one outside its entries, and refuses a save the battle could not read', function () {
    $root = actionImpactProject(['image' => 'Graphics/Actors/KaelionAttack.png', 'columns' => 4, 'frames' => [0, 1, 2]]);
    $session = EditorSession::open($root);
    $path = $root . '/assets/' . BattlerBindings::FILE;
    $before = (string) file_get_contents($path);
    $impact = readAttackPoseRow($session, 'pose0ImpactFrame')['key'];

    expect(readAttackPoseRow($session, 'pose0ImpactFrame')['options'])->toBe([''])
        ->and(fn() => $session->applyDatabaseRecord('battler_actors', 0, $impact, 'Entry 1 · cell 1'))->toThrow(SessionRefusal::class, 'the pose must play once');

    $session->applyDatabaseRecord('battler_actors', 0, readAttackPoseRow($session, 'pose0Loop')['key'], 'false');
    expect(fn() => $session->applyDatabaseRecord('battler_actors', 0, $impact, 'Entry 3'))->toThrow(SessionRefusal::class, 'playback entries');
    $session->applyDatabaseRecord('battler_actors', 0, $impact, 'Entry 2 · cell 2');
    $session->saveDatabase('battler_actors');
    // Playing once is written as false, since an absent loop loops.
    expect((require $path)['actors']['Kaelion']['poses']['attack'])->toMatchArray(['loop' => false, 'impactFrame' => 2]);
    $before = (string) file_get_contents($path);

    // Looping again leaves an impact the Engine refuses; nothing is written.
    $session->applyDatabaseRecord('battler_actors', 0, readAttackPoseRow($session, 'pose0Loop')['key'], 'true');
    expect(readAttackPoseRow($session, 'pose0ImpactFrame')['value'])->toBe('Entry 2 (the pose loops, so it has no impact)')
        ->and(fn() => $session->saveDatabase('battler_actors'))->toThrow(SessionRefusal::class, 'An impact frame requires a non-looping pose')
        ->and((string) file_get_contents($path))->toBe($before);
});

it('anchors an animation\'s caster effect to the target phase as the Engine names it, and omits the default', function () {
    $root = actionImpactProject();
    $session = EditorSession::open($root);
    $path = $root . '/assets/Data/animations.php';
    $before = (string) file_get_contents($path);
    $row = readAnimationRow($session, 'sourceEffectAnchor');

    expect($row)->toMatchArray(['value' => 'source', 'options' => ['', 'target']]);

    $session->applyDatabaseRecord('animations', 0, $row['key'], 'target');
    $session->saveDatabase('animations');
    expect((require $path)[0]['sourceEffectAnchor'])->toBe('target')
        ->and((string) file_get_contents($path))->toContain('// Attacks.');

    $session->applyDatabaseRecord('animations', 0, $row['key'], '');
    $session->saveDatabase('animations');
    expect((string) file_get_contents($path))->toBe($before);

    // Undo and redo step through the same bytes.
    $session->undo();
    $session->saveDatabase('animations');
    expect((require $path)[0]['sourceEffectAnchor'])->toBe('target');
    $session->redo();
    $session->saveDatabase('animations');
    expect((string) file_get_contents($path))->toBe($before);
});

it('offers the caster effect\'s start only with a caster effect, and refuses an anchor the Engine cannot read', function () {
    $root = actionImpactProject();
    $path = $root . '/assets/Data/animations.php';
    file_put_contents($path, "<?php\n\nreturn " . var_export([
        ['id' => 1, 'name' => 'Dusk Slash', 'roles' => ['attack'], 'targetEffect' => 'dusk-slash'],
        ['id' => 2, 'name' => 'Odd', 'sourceEffect' => 'ember-spark', 'sourceEffectAnchor' => 'sideways'],
    ], true) . ";\n");
    $before = (string) file_get_contents($path);
    $session = EditorSession::open($root);

    expect(readAnimationRow($session, 'sourceEffectAnchor'))->toBeNull();

    $session->applyDatabaseRecord('animations', 0, readAnimationRow($session, 'name')['key'], 'Dusk Cut');
    expect(fn() => $session->saveDatabase('animations'))->toThrow(SessionRefusal::class, 'Animation 2 could not be read as it would be saved')
        ->and((string) file_get_contents($path))->toBe($before);
});

it('previews the impact entry and a target-anchored caster effect on the command\'s own target boundary', function () {
    $root = actionImpactProject(['image' => 'Graphics/Actors/KaelionAttack.png', 'columns' => 4, 'frames' => [0, 1, 1, 2, 3], 'fps' => 20,
        'loop' => false, 'impactFrame' => 3]);
    $session = EditorSession::open($root);
    $effect = findEffectIndex($session, 'ember-spark');
    $source = $session->readEffectBattlePreview($effect, 0);
    $boundary = $source['phases']['target']['start'];

    // Anchored at its own phase, the caster effect starts there; the impact entry still lands on the boundary.
    expect($source)->toMatchArray(['lane' => 'source', 'sourceEffectAnchor' => 'source'])
        ->and($session->readEffectBattlePreview($effect, 0, authoredFrame: 0)['frame'])->toBe($source['phases']['source']['start'])
        ->and(readAttackCell($session, $effect, $boundary))->toBe(2)
        ->and(readAttackCell($session, $effect, $boundary - 1))->toBe(1);

    $session->applyDatabaseRecord('animations', 0, readAnimationRow($session, 'sourceEffectAnchor')['key'], 'target');
    $target = $session->readEffectBattlePreview($effect, 0, authoredFrame: 0);

    expect($target['sourceEffectAnchor'])->toBe('target')
        ->and($target['frame'])->toBe($target['phases']['target']['start'])
        ->and($target['authoredFrames']['source'])->toBe(0)
        ->and(readAttackCell($session, $effect, $target['phases']['target']['start']))->toBe(2)
        // The terminal keeps its own source phase.
        ->and($session->readEffectBattlePreview($effect, 0, presentation: EffectPresentation::TERMINAL, authoredFrame: 0)['frame'])
            ->toBe($session->readEffectBattlePreview($effect, 0, presentation: EffectPresentation::TERMINAL)['phases']['source']['start']);
});

it('keeps a pose without an impact entry on its own clock in the command preview', function () {
    $session = EditorSession::open(actionImpactProject());
    $effect = findEffectIndex($session, 'ember-spark');
    $preview = $session->readEffectBattlePreview($effect, 0);
    $announce = $preview['phases']['announce']['start'];

    // 20 fps against the command's 120: one entry every six command frames from the announcement, cell 1 held.
    expect(readAttackCell($session, $effect, $announce))->toBe(0)
        ->and(readAttackCell($session, $effect, $announce + 6))->toBe(1)
        ->and(readAttackCell($session, $effect, $announce + 18))->toBe(2);
});
