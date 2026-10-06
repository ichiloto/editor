<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;

/**
 * A summon may have one timeline for every renderer or a terminal and a
 * graphical sequence of its own, as an effect may. Its definition is shared;
 * the record edits one sequence at a time. Synthetic fixtures only.
 */

/** A record row by its label. */
function summonRow(EditorSession $session, string $label): array
{
    return array_find($session->readDatabaseRecord('cutscenes/summon', 0)['rows'], static fn(array $row): bool => trim($row['label']) === $label)
        ?? throw new RuntimeException(sprintf('No %s row.', $label));
}

it('separates a summon\'s timeline into terminal and graphical sequences as one undo step, keeping its definition shared', function () {
    $root = cutsceneProject();
    $folder = $root . '/assets/Cutscenes/Summons/lantern-wisp';
    $dataBefore = (string) file_get_contents($folder . '/lantern-wisp.data.php');
    $session = EditorSession::open($root);

    expect($session->describeCutsceneTimeline('cutscenes/summon', 0)['presentation'])->toBeNull()
        ->and(fn() => $session->selectCutscenePresentation('cutscenes/summon', 0, 'graphical'))->toThrow(SessionRefusal::class, 'one sequence');

    expect($session->separateCutsceneSequences('cutscenes/summon', 0))->toBe(['changed' => true, 'presentation' => 'terminal'])
        ->and($session->describeCutsceneTimeline('cutscenes/summon', 0)['presentation'])->toBe('terminal');
    $session->undo();
    expect($session->describeCutsceneTimeline('cutscenes/summon', 0)['presentation'])->toBeNull();
    $session->redo();

    // The graphical sequence is edited on its own; the name stays the definition's.
    $session->selectCutscenePresentation('cutscenes/summon', 0, 'graphical');
    $session->applyDatabaseRecord('cutscenes/summon', 0, summonRow($session, 'FPS')['key'], '24');
    $session->applyDatabaseRecord('cutscenes/summon', 0, summonRow($session, 'Name')['key'], 'Lantern Wraith');
    $session->selectCutscenePresentation('cutscenes/summon', 0, 'terminal');
    expect(summonRow($session, 'FPS')['value'])->toBe('12');
    expect($session->saveAll()['failures'])->toBe([]);

    $timeline = require $folder . '/lantern-wisp.timeline.php';
    expect(array_keys($timeline))->toBe(['formatVersion', 'presentations'])
        ->and(array_keys($timeline['presentations']))->toBe(['terminal', 'graphical'])
        ->and($timeline['presentations']['terminal']['fps'])->toBe(12)
        ->and($timeline['presentations']['graphical']['fps'])->toBe(24)
        ->and(array_keys($timeline['presentations']['graphical']))->toBe(['fps', 'lengthFrames', 'tracks', 'cues'])
        ->and((string) file_get_contents($folder . '/lantern-wisp.data.php'))->toBe(str_replace("'Lantern Wisp'", "'Lantern Wraith'", $dataBefore));

    // The Engine plays each renderer its own sequence.
    $asset = CutsceneLibrary::fromProject($root)->find(CutsceneType::SUMMON, 'lantern-wisp');
    expect($asset->compiledSummon(EffectPresentation::TERMINAL)->fps)->toBe(12)
        ->and($asset->compiledSummon(EffectPresentation::GRAPHICAL)->fps)->toBe(24);
});

it('adds a sequence key a summon sequence did not hold to that sequence, never to the definition', function () {
    $root = cutsceneProject();
    $folder = $root . '/assets/Cutscenes/Summons/lantern-wisp';
    $session = EditorSession::open($root);
    $session->separateCutsceneSequences('cutscenes/summon', 0);
    $session->selectCutscenePresentation('cutscenes/summon', 0, 'graphical');

    $session->applyDatabaseRecord('cutscenes/summon', 0, summonRow($session, 'Rest Frame')['key'], '12');
    $session->saveAll();

    $timeline = require $folder . '/lantern-wisp.timeline.php';
    expect($timeline['presentations']['graphical']['restFrame'])->toBe(12)
        ->and($timeline['presentations']['terminal'])->not->toHaveKey('restFrame')
        ->and((string) file_get_contents($folder . '/lantern-wisp.data.php'))->not->toContain('restFrame');
});

it('duplicates a summon or effect with separate sequences whole, whichever sequence is shown', function () {
    $root = cutsceneProject();
    $session = EditorSession::open($root);
    $session->separateCutsceneSequences('cutscenes/summon', 0);
    $session->selectCutscenePresentation('cutscenes/summon', 0, 'graphical');
    $session->applyDatabaseRecord('cutscenes/summon', 0, summonRow($session, 'FPS')['key'], '24');

    $copy = $session->duplicateDatabaseRecord('cutscenes/summon', 0);
    $session->saveAll();

    $timeline = require $root . '/assets/Cutscenes/Summons/lantern-wisp-copy/lantern-wisp-copy.timeline.php';
    $data = require $root . '/assets/Cutscenes/Summons/lantern-wisp-copy/lantern-wisp-copy.data.php';
    expect($copy['index'])->toBe(1)
        ->and(array_keys($timeline['presentations']))->toBe(['terminal', 'graphical'])
        ->and($timeline['presentations']['terminal']['fps'])->toBe(12)
        ->and($timeline['presentations']['graphical']['fps'])->toBe(24)
        ->and($data['id'])->toBe('lantern-wisp-copy');
});

it('opens a summon read-only when a sequence declares a key its definition holds', function () {
    $root = cutsceneProject();
    $folder = $root . '/assets/Cutscenes/Summons/lantern-wisp';
    file_put_contents($folder . '/lantern-wisp.timeline.php', "<?php\n\nreturn ['formatVersion' => 1, 'presentations' => [\n"
        . "  'terminal' => ['fps' => 12, 'lengthFrames' => 2, 'tracks' => [], 'cues' => [], 'name' => 'Shadow'],\n"
        . "  'graphical' => ['fps' => 12, 'lengthFrames' => 2, 'tracks' => [], 'cues' => []],\n]];\n");

    $asset = CutsceneLibrary::fromProject($root)->find(CutsceneType::SUMMON, 'lantern-wisp');

    expect($asset->isEditable())->toBeFalse()
        ->and($asset->readOnlyReason())->toContain('the terminal sequence also declares "name"');
});
