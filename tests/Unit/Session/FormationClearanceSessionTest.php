<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\TroopFormationValidator;

/** Formation previews and validation report the Engine's clearance check, never move anything. Synthetic fixtures only. */

/** The battle art project with authored battler and enemy areas, its bat bound to art and placed with its feet at $y. */
function formationClearanceProject(int $y): string
{
    $root = battlerArtProject();
    $layout = $root . '/assets/Data/Presentation/battle.php';
    file_put_contents($layout, str_replace(
        'partySlots: [new BattlerSlot(1050, 420, 180, 260), new BattlerSlot(1180, 560, 180, 260)])',
        'partySlots: [new BattlerSlot(1050, 420, 180, 260), new BattlerSlot(1180, 560, 180, 260)], '
            . 'battlerArea: new CanvasRectangle(0, 80, 1350, 452), enemyArea: new CanvasRectangle(0, 80, 675, 452))',
        (string) file_get_contents($layout),
    ));
    $troops = $root . '/assets/Data/troops.php';
    file_put_contents($troops, str_replace("'y' => 300,", "'y' => {$y},", (string) file_get_contents($troops)));
    $session = EditorSession::open($root);
    $index = $session->createDatabaseRecord('battler_enemies', 'Regular Bat')['index'];
    setBattlerRow($session, $index, 'artwork.image', 'Graphics/Enemies/Bat.png');
    setBattlerRow($session, $index, 'artwork.pivot', '0.5, 1');
    $session->saveDatabase('battler_enemies');

    return $root;
}

/** @return list<Issue> The validator's issues about troops. */
function readTroopClearanceIssues(string $root): array
{
    return array_values(array_filter(new TroopFormationValidator()->validate(ProjectWorkspace::fromProject($root)),
        static fn(Issue $issue): bool => str_starts_with($issue->where, 'assets/Data/troops.php')));
}

it('reports nothing for a troop member standing clear of the UI and the party', function () {
    $root = formationClearanceProject(300);
    $member = EditorSession::open($root)->readTroopFormation(0)['members'][0]['battler'];

    expect($member['diagnostics'])->toBe([])
        ->and(readTroopClearanceIssues($root))->toBe([]);
});

it('reports a troop member whose art reaches above the battler area, and leaves it where it stands', function () {
    $root = formationClearanceProject(200);
    $troops = (string) file_get_contents($root . '/assets/Data/troops.php');
    $member = EditorSession::open($root)->readTroopFormation(0)['members'][0];
    $issues = readTroopClearanceIssues($root);

    expect($member['placement'])->toMatchArray(['x' => 260, 'y' => 200])
        ->and($member['battler']['ground'])->toEqual(['x' => 260, 'y' => 200])
        ->and(implode(' ', $member['battler']['diagnostics']))->toContain('outside the authored battler area')
        ->and($issues)->not->toBe([])
        ->and($issues[0]->where)->toBe('assets/Data/troops.php: Pair')
        ->and($issues[0]->message)->toContain('Regular Bat (member 1)')
        ->and(file_get_contents($root . '/assets/Data/troops.php'))->toBe($troops);
});

it('shows an enemy preview beside a party member it stands clear of, with what it does not clear', function () {
    $root = formationClearanceProject(300);
    $battler = EditorSession::open($root)->readEnemyPreview(0)['formation']['members'][0]['battler'];

    expect($battler['diagnostics'])->toBe([])
        ->and($battler['image']['asset'])->toBe('Graphics/Enemies/Bat.png');
});
