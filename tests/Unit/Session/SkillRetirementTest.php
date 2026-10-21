<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;

/**
 * Retiring a skill: nothing that names it may lose it silently, everything
 * that names it can move onto a replacement at once, and saves that knew it
 * read the replacement. Synthetic fixtures only.
 */
function skillRetirementProject(): string
{
    $root = makeTemporaryProject();
    writeSkillRecords(
        $root,
        new SpecialSkill('Old Cut', 'Cuts.', '', 2, 0, effects: [new HPDamageSkillEffect('$user->stats->attack * 6')]),
        new SpecialSkill('New Cut', 'Cuts well.', '', 4, 0, effects: [new HPDamageSkillEffect('$user->stats->attack * 2')]),
        new SpecialSkill('Guard Up', 'Guards.', '', 3, 0),
        new MagicSkill('Spark', 'Shocks.', '', 5, 0),
    );
    $actor = $root . '/assets/Data/Actors/Kaelion.php';
    file_put_contents($actor, str_replace("    'images' => [", <<<'PHP'
    // What Kaelion knows and can still learn.
    'abilities' => [
      'learned' => ['Old Cut', 'Guard Up'],
      'learnables' => [
        ['skill' => 'New Cut', 'requirement' => ['goldCost' => 10]],
        ['skill' => 'Old Cut', 'requirement' => ['goldCost' => 20]],
      ],
    ],
    'images' => [
PHP, (string) file_get_contents($actor)));
    $classes = $root . '/assets/Data/classes.php';
    file_put_contents($classes, preg_replace("/'name' => 'Vanguard',\n/",
        "'name' => 'Vanguard',\n    'skillsToLearn' => [\n      ['level' => 2, 'skill' => 'Old Cut'],\n    ],\n", (string) file_get_contents($classes), 1));

    return $root;
}

it('keeps a skill something names from being deleted or renamed, saying what names it', function () {
    $session = EditorSession::open(skillRetirementProject());
    $nameRow = array_find($session->readDatabaseRecord('skills', 0)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === 'name');

    expect(fn() => $session->deleteDatabaseRecord('skills', 0))->toThrow(SessionRefusal::class, 'Actor Kaelion (abilities.learned.0)')
        ->and(fn() => $session->applyDatabaseRecord('skills', 0, $nameRow['key'], 'Renamed Cut'))->toThrow(SessionRefusal::class, 'Class Vanguard')
        ->and($session->deleteDatabaseRecord('skills', 3)['records'])->toBe(['Old Cut', 'New Cut', 'Guard Up']);
});

it('moves everything that names a skill onto its replacement, keeping comments, and lets saves read the replacement', function () {
    $root = skillRetirementProject();
    $session = EditorSession::open($root);

    $question = $session->retireSkill(0, 'New Cut');
    $written = $session->retireSkill(0, 'New Cut', 'write', $question['confirm']);
    $actor = (string) file_get_contents($root . '/assets/Data/Actors/Kaelion.php');
    $data = (require $root . '/assets/Data/Actors/Kaelion.php')['data'];
    $classes = require $root . '/assets/Data/classes.php';
    $manifest = require $root . '/assets/Data/save-compatibility.php';

    expect($question['status'])->toBe('question')
        ->and($written['changed'])->toBeTrue()
        ->and($data['abilities']['learned'])->toBe(['New Cut', 'Guard Up'])
        // The actor could already learn the replacement, so the old learnable goes.
        ->and(array_column($data['abilities']['learnables'], 'skill'))->toBe(['New Cut'])
        ->and($classes[0]['skillsToLearn'])->toBe([['level' => 2, 'skill' => 'New Cut']])
        ->and($actor)->toContain('// What Kaelion knows and can still learn.')
        ->and($manifest['aliases']['abilities'])->toBe([['from' => 'Old Cut', 'to' => 'New Cut']])
        ->and(EditorSession::open($root)->deleteDatabaseRecord('skills', 0)['records'])->toBe(['New Cut', 'Guard Up', 'Spark']);
});

it('refuses moving a skill onto itself, an unknown skill, or across spells and abilities', function () {
    $session = EditorSession::open(skillRetirementProject());

    expect(fn() => $session->retireSkill(0, 'Old Cut'))->toThrow(SessionRefusal::class, 'its own place')
        ->and(fn() => $session->retireSkill(0, 'Missing'))->toThrow(SessionRefusal::class, 'no skill named Missing')
        ->and(fn() => $session->retireSkill(0, 'Spark'))->toThrow(SessionRefusal::class, 'both be spells or both abilities');
});

it('removes a skill retired without a replacement from everything that names it, and saves simply drop it', function () {
    $root = skillRetirementProject();
    $session = EditorSession::open($root);

    $question = $session->retireSkill(0);
    $session->retireSkill(0, null, 'write', $question['confirm']);
    $data = (require $root . '/assets/Data/Actors/Kaelion.php')['data'];
    $classes = require $root . '/assets/Data/classes.php';

    expect($question['question'])->toContain('Remove Old Cut from everything that names it')
        ->and($data['abilities']['learned'])->toBe(['Guard Up'])
        ->and(array_column($data['abilities']['learnables'], 'skill'))->toBe(['New Cut'])
        ->and($classes[0]['skillsToLearn'])->toBe([])
        ->and((require $root . '/assets/Data/save-compatibility.php')['aliases'])->toBe([])
        ->and(EditorSession::open($root)->deleteDatabaseRecord('skills', 0)['records'])->toBe(['New Cut', 'Guard Up', 'Spark']);
});

it('has nothing to write when nothing names a skill and saves already follow it', function () {
    $root = skillRetirementProject();
    $session = EditorSession::open($root);
    $question = $session->retireSkill(0, 'New Cut');
    $session->retireSkill(0, 'New Cut', 'write', $question['confirm']);

    expect(EditorSession::open($root)->retireSkill(0, 'New Cut'))->toBe(['changed' => false, 'records' => ['Old Cut', 'New Cut', 'Guard Up', 'Spark']])
        ->and(EditorSession::open($root)->retireSkill(3))->toBe(['changed' => false, 'records' => ['Old Cut', 'New Cut', 'Guard Up', 'Spark']]);
});
