<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;

/** A summon previewed as the battle test plays it, through the Engine's command preview. Synthetic fixtures only. */

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

it('previews the summon in the terminal arena alone, reading no graphical image', function () {
    $root = summonBattlePreviewProject();
    // Every graphical image gone: the terminal arena never needs one.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/assets/Graphics', FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), '.png')) {
            unlink($file->getPathname());
        }
    }
    $session = EditorSession::open($root);
    $first = $session->readSummonBattlePreview(0, 0, presentation: EffectPresentation::TERMINAL);
    $atTarget = $session->readSummonBattlePreview(0, $first['phases']['target']['start'] + 1, presentation: EffectPresentation::TERMINAL);

    expect($first['caster'])->toBe('Kaelion')
        ->and($atTarget['phase'])->toBe('target')
        ->and($atTarget['canvas'])->toBeNull()
        ->and($atTarget['terminalLines'])->not->toBe([])
        ->and(fn() => $session->readSummonBattlePreview(0, 0))->toThrow(SessionRefusal::class);
});

it('draws a frame of the summon\'s own timeline where the battle plays it, in either presentation', function () {
    $session = EditorSession::open(summonBattlePreviewProject());
    foreach ([EffectPresentation::GRAPHICAL, EffectPresentation::TERMINAL] as $presentation) {
        $twelfth = $session->readSummonBattlePreview(0, 0, presentation: $presentation, authoredFrame: 12);
        $first = $session->readSummonBattlePreview(0, 0, presentation: $presentation, authoredFrame: -5);
        $last = $session->readSummonBattlePreview(0, 0, presentation: $presentation, authoredFrame: 999);

        expect($twelfth['authoredFrames']['target'])->toBe(12)
            ->and($twelfth['phase'])->toBe('target')
            ->and($first['frame'])->toBe($first['phases']['target']['start'])
            ->and($last['frame'])->toBe($last['phases']['target']['start'] + $last['phases']['target']['length'] - 1);
    }
});
