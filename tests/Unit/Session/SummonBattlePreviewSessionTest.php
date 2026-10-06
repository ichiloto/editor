<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/** A summon previewed as the battle test plays it, through the Engine's command preview. Synthetic fixtures only. */

/** The battle test project holding the lantern summon, with sheet art that rests on its second cell. */
function summonBattlePreviewProject(array $wielders = []): string
{
    $root = battleTestProject();
    $summon = $root . '/assets/Cutscenes/Summons/lantern-wisp';
    mkdir($summon, 0o777, true);
    $data = lanternSummonData();
    if ($wielders !== []) {
        $data = str_replace("  'linkedActionId' => 'Fireball',", "  'linkedActionId' => 'Fireball',\n  'wielders' => " . var_export($wielders, true) . ',', $data);
    }
    file_put_contents($summon . '/lantern-wisp.data.php', $data);
    file_put_contents($summon . '/lantern-wisp.timeline.php', str_replace(["  'lengthFrames' => 24,", "    ['type' => 'text', 'id' => 'name',"], [
        "  'lengthFrames' => 24,\n  'restFrame' => 12,",
        "    ['type' => 'image', 'id' => 'wisp-art', 'presentation' => 'graphical', 'asset' => 'Graphics/Summons/Wisp.png',\n"
        . "      'sheet' => ['columns' => 2, 'rows' => 1], 'anchor' => 'target', 'attachment' => 'ground', 'pivot' => ['x' => 0.5, 'y' => 1],\n"
        . "      'keyframes' => [['frame' => 0, 'duration' => 12, 'sourceFrame' => 0], ['frame' => 12, 'duration' => 12, 'sourceFrame' => 1]]],\n"
        . "    ['type' => 'text', 'id' => 'name',"], lanternSummonTimeline()));
    mkdir($root . '/assets/Graphics/Summons', 0o777, true);
    writeTilesetTestPng($root . '/assets/Graphics/Summons/Wisp.png', 16, 8);
    writeFireballSkill($root);
    // The battle reads enemies the way the game loads them, one file each through the project's barrel.
    writeRegularBatEnemy($root);
    // A graphical battle places every enemy it fights.
    file_put_contents($root . '/assets/Data/troops.php', str_replace("['enemy' => 'Regular Bat', 'position' => [15, 20]]",
        "['enemy' => 'Regular Bat', 'position' => [15, 20], 'graphicalPlacement' => ['x' => 420, 'y' => 380, 'width' => 275, 'height' => 190]]",
        (string) file_get_contents($root . '/assets/Data/troops.php')));
    file_put_contents($root . '/assets/Data/enemies.php', "<?php\n\nreturn Ichiloto\\Engine\\Entities\\Enemies\\EnemyCatalog::loadProjectEnemies(dirname(__DIR__));\n");

    return $root;
}

/** @return array<string, mixed>|null The summon's art as the frame's canvas draws it. */
function findSummonArt(array $frame): ?array
{
    return array_find($frame['canvas']['images'] ?? [], static fn(array $image): bool => $image['asset'] === 'Graphics/Summons/Wisp.png');
}

it('previews a summon cast by the battle test party at its troop, for both renderers, seeking freely', function () {
    $session = EditorSession::open(summonBattlePreviewProject());
    $first = $session->readSummonBattlePreview(0, 0);
    $target = $first['phases']['target']['start'] + 1;
    $atTarget = $session->readSummonBattlePreview(0, $target);

    expect($first)->toMatchArray(['caster' => 'Kaelion', 'troop' => 'Pair', 'fps' => 120])
        ->and($first['targets'])->not->toBe([])
        ->and($first['totalFrames'])->toBeGreaterThan($target)
        ->and($atTarget['phase'])->toBe('target')
        ->and(findSummonArt($atTarget))->not->toBeNull()
        ->and($atTarget['terminalLines'])->not->toBe([])
        ->and($atTarget['diagnostics'])->toBe([])
        // A seek past the end holds the last frame; seeking back draws the same frame again.
        ->and($session->readSummonBattlePreview(0, PHP_INT_MAX)['frame'])->toBe($first['totalFrames'] - 1)
        ->and($session->readSummonBattlePreview(0, $target))->toBe($atTarget)
        ->and($session->listUnsavedChanges())->toBe([]);
});

it('previews the summon as it stands, unsaved edits included', function () {
    $session = EditorSession::open(summonBattlePreviewProject());
    $target = $session->readSummonBattlePreview(0, 0)['phases']['target']['start'] + 1;
    $resting = findSummonArt($session->readSummonBattlePreview(0, $target, reducedMotion: true));
    $rest = array_find($session->readDatabaseRecord('cutscenes/summon', 0)['rows'], static fn(array $row): bool => trim($row['label']) === 'Rest Frame');

    $session->applyDatabaseRecord('cutscenes/summon', 0, $rest['key'], '0');

    expect($resting['sourceRect']['x'])->toBe(8)
        ->and(findSummonArt($session->readSummonBattlePreview(0, $target, reducedMotion: true))['sourceRect']['x'])->toBe(0);
});

it('refuses a summon no one in the battle test party may call, naming why', function () {
    $session = EditorSession::open(summonBattlePreviewProject(['mode' => 'characters', 'characters' => ['Liora'], 'tenancy' => 'exclusive']));

    expect(fn() => $session->readSummonBattlePreview(0, 0))->toThrow(SessionRefusal::class, 'No one in the battle test party may call Lantern Wisp')
        ->and(fn() => $session->readSummonBattlePreview(4, 0))->toThrow(SessionRefusal::class);
});
