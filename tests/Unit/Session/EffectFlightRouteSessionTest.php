<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Battle\Formation\BattleTargeting;
use Ichiloto\Engine\Battle\Formation\BattleTargetShape;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Skills\BasicSkill;

/**
 * The effect battle preview project with a straight caster flight on Dusk Slash, battle art for its caster and
 * enemies, and a line-shot attack, so the troop's formation decides whether one straight line reaches every target.
 * Synthetic fixtures only.
 *
 * @param array<string, mixed> $flight The track's flight, merged over a straight route.
 * @param list<array{x: int, depth: int}> $placements Where the troop's members stand.
 */
function straightFlightProject(array $flight, array $placements): string
{
    $root = effectBattlePreviewProject();
    writeTilesetTestPng($root . '/assets/Graphics/FixturePose.png', 8, 8);
    writeTilesetTestPng($root . '/assets/Graphics/FixtureEnemy.png', 8, 8);
    file_put_contents($root . '/assets/Data/Presentation/battlers.php', '<?php return ' . var_export(['actors' => ['Kaelion' => [
        'artwork' => ['image' => 'Graphics/FixturePose.png'],
        'poses' => ['attack' => ['image' => 'Graphics/FixturePose.png',
            'attachmentPoints' => [['cell' => 0, 'name' => 'release', 'point' => ['x' => .5, 'y' => .4]]]]],
    ]], 'enemies' => ['Regular Bat' => ['artwork' => ['image' => 'Graphics/FixtureEnemy.png']]]], true) . ';');
    writeSkillRecords($root, new BasicSkill('Piercing shot', '', '', 0, 0, occasion: Occasion::BATTLE_SCREEN, animationId: 1,
        battleTargeting: new BattleTargeting(BattleTargetShape::LINE, ignoreCover: true)));
    $actorPath = $root . '/assets/Data/Actors/Kaelion.php';
    $actor = require $actorPath;
    $actor['data']['attackSkill'] = 'Piercing shot';
    file_put_contents($actorPath, '<?php return ' . var_export($actor, true) . ';');
    $troopPath = $root . '/assets/Data/troops.php';
    $troops = require $troopPath;
    $troops[0]['enemies'] = array_map(static fn(array $place, int $index): array => ['enemy' => 'Regular Bat', 'position' => [15, 7 + $index],
        'battlePosition' => ['row' => $index === 0 ? 'front' : 'back', 'lane' => 0],
        'graphicalPlacement' => ['x' => $place['x'], 'depth' => $place['depth'], 'width' => 275, 'height' => 190]], $placements, array_keys($placements));
    file_put_contents($troopPath, '<?php return ' . var_export($troops, true) . ';');
    $path = $root . '/assets/Animations/dusk-slash/dusk-slash.timeline.php';
    $timeline = require $path;
    $timeline['presentations']['graphical']['tracks'][0] = [...$timeline['presentations']['graphical']['tracks'][0],
        'anchor' => 'caster', 'attachmentPoint' => 'release', 'detachFrame' => 0,
        'flight' => ['arrivalFrame' => 1, 'route' => 'straight', ...$flight]];
    $timeline['presentations']['graphical']['poseSyncFrame'] = 0;
    file_put_contents($path, '<?php return ' . var_export($timeline, true) . ';');

    return $root;
}

/** One formation a single straight line reaches, and one it cannot. */
const STRAIGHT_FLIGHT_REACHABLE = [['x' => 260, 'depth' => 300], ['x' => 420, 'depth' => 300]];
const STRAIGHT_FLIGHT_UNREACHABLE = [['x' => 260, 'depth' => 400], ['x' => 420, 'depth' => 20]];

function checkDuskSlashRoutes(string $root): array
{
    $session = EditorSession::open($root);

    return $session->checkEffectFlightRoutes(findEffectIndex($session, 'dusk-slash'))['issues'];
}

it('finds nothing to report where one straight line reaches every target of the troop', function () {
    expect(checkDuskSlashRoutes(straightFlightProject([], STRAIGHT_FLIGHT_REACHABLE)))->toBe([]);
});

it('reports a straight flight that cannot reach a troop\'s line as an error without a fallback, and a warning with one', function () {
    $error = checkDuskSlashRoutes(straightFlightProject([], STRAIGHT_FLIGHT_UNREACHABLE));
    expect($error)->toHaveCount(1)
        ->and($error[0])->toMatchArray(['severity' => 'error', 'where' => 'effect dusk-slash', 'code' => EditorSession::FLIGHT_ROUTE_UNREACHABLE,
            'message' => 'Track stroke-art cannot reach every target in one straight line for Kaelion\'s Piercing shot against Regular Bat, Regular Bat in troop Pair, and it has no fallback.']);

    $warning = checkDuskSlashRoutes(straightFlightProject(['fallback' => 'horizontal-exit'], STRAIGHT_FLIGHT_UNREACHABLE));
    expect($warning)->toHaveCount(1)
        ->and($warning[0])->toMatchArray(['severity' => 'warning', 'code' => EditorSession::FLIGHT_ROUTE_FALLBACK_USED,
            'message' => 'Track stroke-art cannot reach every target directly for Kaelion\'s Piercing shot against Regular Bat, Regular Bat in troop Pair, so it uses its horizontal-exit fallback.'])
        ->and(checkDuskSlashRoutes(straightFlightProject(['fallback' => 'horizontal-exit'], STRAIGHT_FLIGHT_REACHABLE)))->toBe([]);
});

it('keeps a flight the battle cannot plan apart from the geometric case', function () {
    $root = straightFlightProject([], STRAIGHT_FLIGHT_REACHABLE);
    $path = $root . '/assets/Animations/dusk-slash/dusk-slash.timeline.php';
    $timeline = require $path;
    $timeline['presentations']['graphical']['tracks'][0]['attachmentPoint'] = 'missing-point';
    file_put_contents($path, '<?php return ' . var_export($timeline, true) . ';');

    $issues = checkDuskSlashRoutes($root);
    expect(array_column($issues, 'code'))->toBe([EditorSession::FLIGHT_ROUTE_UNPLANNED])
        ->and($issues[0]['message'])->toContain('missing-point');
});

it('authors the straight flight fallback through the shared rows with undo, and the Engine refuses it off a straight route', function () {
    $root = straightFlightProject([], STRAIGHT_FLIGHT_UNREACHABLE);
    $path = $root . '/assets/Animations/dusk-slash/dusk-slash.timeline.php';
    $session = EditorSession::open($root);
    $index = findEffectIndex($session, 'dusk-slash');
    $category = 'cutscenes/effect';
    $session->selectCutscenePresentation($category, $index, 'graphical');
    $frame = $session->describeCutsceneTimeline($category, $index)['tracks'][0]['art']['pivotKey']['frame'];
    $row = static fn(string $field): array => array_find($session->readDatabaseRecord($category, $index, $frame)['rows'],
        static fn(array $entry): bool => ($entry['key']['field'] ?? null) === $field);
    $fallback = $row('track0Flightfallback');
    expect($fallback)->toMatchArray(['label' => 'Track 1 Straight Flight Fallback', 'value' => '(none)'])
        ->and(json_encode($fallback))->toContain('horizontal-exit');

    expect($session->applyDatabaseRecord($category, $index, $fallback['key'], 'horizontal-exit')['changed'])->toBeTrue();
    $session->undo();
    expect($row('track0Flightfallback')['value'])->toBe('(none)');
    $session->redo();
    expect($session->saveAll()['failures'])->toBe([])
        ->and((require $path)['presentations']['graphical']['tracks'][0]['flight'])->toMatchArray(['route' => 'straight', 'fallback' => 'horizontal-exit'])
        ->and(checkDuskSlashRoutes($root)[0]['code'])->toBe(EditorSession::FLIGHT_ROUTE_FALLBACK_USED);

    $before = file_get_contents($path);
    try {
        $session->applyDatabaseRecord($category, $index, $row('track0Flightroute')['key'], 'waypoints');
        $refusal = implode(' ', array_map('json_encode', $session->saveAll()['failures']));
    } catch (\Ichiloto\Editor\Session\SessionRefusal $failure) {
        $refusal = $failure->getMessage();
    }
    expect($refusal)->toContain('flight fallback requires a straight route')
        ->and(file_get_contents($path))->toBe($before);
});

it('reserves spaced fallback hit time for each previewed target as the battle does', function () {
    $reaction = static function (array $placements, bool $spaced): array {
        $root = straightFlightProject(['fallback' => 'horizontal-exit'], $placements);
        $path = $root . '/assets/Animations/dusk-slash/dusk-slash.timeline.php';
        $timeline = require $path;
        // The impact lands as the flight arrives, so each target beyond the first gets its own spaced hit.
        $timeline['presentations']['graphical']['cues'] = [['id' => 'hit', 'type' => 'applyEffect', 'frame' => 1]];
        if ($spaced) { $timeline['presentations']['graphical']['fallbackHitSpacingFrames'] = 1; }
        file_put_contents($path, '<?php return ' . var_export($timeline, true) . ';');
        $session = EditorSession::open($root);
        $preview = $session->readEffectBattlePreview(findEffectIndex($session, 'dusk-slash'), 0);

        return [count($preview['targets']), $preview['phases']['reaction']['length']];
    };

    [$targets, $spaced] = $reaction(STRAIGHT_FLIGHT_UNREACHABLE, true);
    [, $unspaced] = $reaction(STRAIGHT_FLIGHT_UNREACHABLE, false);
    expect($targets)->toBe(2)->and($spaced)->toBeGreaterThan($unspaced);

    $alone = [STRAIGHT_FLIGHT_UNREACHABLE[0]];
    [$targets, $spaced] = $reaction($alone, true);
    [, $unspaced] = $reaction($alone, false);
    expect($targets)->toBe(1)->and($spaced)->toBe($unspaced);
});
