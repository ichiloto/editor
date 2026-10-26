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
        'partySlots: [new BattleStageSlot(1050, 180, 180, 260), new BattleStageSlot(1180, 40, 180, 260)])',
        'partySlots: [new BattleStageSlot(1050, 180, 180, 260), new BattleStageSlot(1180, 40, 180, 260)], '
            . 'battlerArea: new CanvasRectangle(0, 80, 1350, 520), enemyArea: new CanvasRectangle(0, 80, 675, 520))',
        (string) file_get_contents($layout),
    ));
    $troops = $root . '/assets/Data/troops.php';
    // Every member placed: the clearance check is what these tests are about.
    // The ground runs from the battler area's top to the battle UI's top at 600, so feet at $y stand 600 - $y back.
    $depth = 600 - $y;
    file_put_contents($troops, str_replace(["'depth' => 300,", "            ['enemy' => 'Regular Bat', 'position' => [15, 20]],\n"], ["'depth' => {$depth},", ""], (string) file_get_contents($troops)));
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

    expect($member['placement'])->toMatchArray(['x' => 260, 'depth' => 400])
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

it('arranges a member with no battle placement instead of refusing the troop, and reports an unreadable one', function () {
    $root = troopFormationProject();
    $issues = array_values(array_filter(new TroopFormationValidator()->validate(ProjectWorkspace::fromProject($root)),
        static fn(Issue $issue): bool => $issue->severity === \Ichiloto\Editor\Validation\Severity::ERROR));
    expect($issues)->toBe([]);

    $troops = $root . '/assets/Data/troops.php';
    file_put_contents($troops, str_replace("'depth' => 300", "'y' => 300", (string) file_get_contents($troops)));
    $issues = array_values(array_filter(new TroopFormationValidator()->validate(ProjectWorkspace::fromProject($root)),
        static fn(Issue $issue): bool => str_starts_with($issue->where, 'assets/Data/troops.php')));
    expect($issues)->toHaveCount(1)
        ->and($issues[0]->message)->toContain('Regular Bat (member 1)', 'y is a fixed canvas height');
});

it('warns of a terminal position that leaves out a member\'s target badge, and leaves it where it stands', function () {
    $root = enemyPreviewProject();
    // Only the terminal field's issues: this fixture's bat has no graphical battle art.
    $terminal = static fn(string $root): array => array_values(array_filter(readTroopClearanceIssues($root),
        static fn(Issue $issue): bool => str_contains($issue->message, 'on the terminal field')));
    expect($terminal($root))->toBe([]);

    $troops = $root . '/assets/Data/troops.php';
    file_put_contents($troops, str_replace("'position' => [15, 20]", "'position' => [15, 1]", (string) file_get_contents($troops)));
    $source = (string) file_get_contents($troops);
    $issues = $terminal($root);

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(\Ichiloto\Editor\Validation\Severity::WARNING)
        ->and($issues[0]->where)->toBe('assets/Data/troops.php: Pair')
        ->and($issues[0]->message)->toBe('Regular Bat (member 2) on the terminal field leaves no room above it for its target badge.')
        ->and(file_get_contents($troops))->toBe($source);
});
