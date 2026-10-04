<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;

/** The troop formation project with one enemy record and two terminal sprites. */
function enemyPreviewProject(): string
{
    $root = troopFormationProject();
    @mkdir($root . '/assets/Data/Enemies', 0o777, true);
    @mkdir($root . '/assets/Graphics/Enemies', 0o777, true);
    file_put_contents($root . '/assets/Graphics/Enemies/bat.txt', "/\\o/\\\n ' '\n");
    file_put_contents($root . '/assets/Graphics/Enemies/wisp.txt', "~*~\n");
    file_put_contents($root . '/assets/secret.txt', "not a sprite\n");
    file_put_contents($root . '/assets/Data/Enemies/regular-bat.php', "<?php\n\nuse Ichiloto\\Engine\\Entities\\Enemies\\Enemy;\n\nreturn ['class' => Enemy::class, 'data' => "
        . var_export(['name' => 'Regular Bat', 'level' => 2, 'imagePath' => 'bat', 'rewards' => ['experience' => 1, 'gold' => 1], 'stats' => [
            'maxHp' => 10, 'maxMp' => 0, 'attack' => 3, 'defence' => 2, 'magicAttack' => 1, 'magicDefence' => 1, 'speed' => 2, 'grace' => 1, 'evasion' => 1,
        ]], true) . "];\n");

    return $root;
}

/** Sets the open enemy's sprite through its row, as an author would, without saving. */
function chooseEnemySprite(EditorSession $session, string $sprite): void
{
    $row = array_find($session->readDatabaseRecord('enemies', 0)['rows'], static fn(array $row): bool => ($row['key']['field'] ?? null) === 'imagePath');
    $session->applyDatabaseRecord('enemies', 0, $row['key'], $sprite);
}

it('previews an enemy as its terminal sprite and at battle scale opposite the lead party member', function () {
    $session = EditorSession::open(enemyPreviewProject());
    $preview = $session->readEnemyPreview(0);
    $bat = $preview['formation']['members'][0];

    expect($preview['name'])->toBe('Regular Bat')
        ->and($preview['sprite'])->toBe(['lines' => ['/\\o/\\', " ' '"]])
        ->and($preview['formationIssue'])->toBeNull()
        ->and($preview['formation']['arena'])->toBe('arena.cave')
        ->and($preview['formation']['party'][0])->toMatchArray(['name' => 'Kaelion', 'ground' => ['x' => 1050.0, 'y' => 420.0]])
        // The lead slot mirrored across the 1350 wide canvas: a size comparison, not a placement.
        ->and($bat)->toMatchArray(['enemy' => 'Regular Bat', 'placement' => null])
        ->and($bat['battler']['ground'])->toBe(['x' => 300.0, 'y' => 420.0])
        ->and($bat['battler']['diagnostics'])->toBe(['Base battler artwork unavailable: Regular Bat'])
        ->and($session->readEnemyPreview(0, 'arena.road')['formation']['arena'])->toBe('arena.road')
        ->and($session->listUnsavedChanges())->toBe([]);
});

it('shows the sprite the record holds now, and never a file outside the enemy sprites', function () {
    $session = EditorSession::open(enemyPreviewProject());

    chooseEnemySprite($session, 'wisp');
    expect($session->readEnemyPreview(0)['sprite'])->toBe(['lines' => ['~*~']]);

    chooseEnemySprite($session, '../../secret');
    expect($session->readEnemyPreview(0)['sprite'])->toBe(['issue' => 'Graphics/Enemies/../../secret.txt is not a sprite in this project.']);
});

it('still shows the sprite when the project has no graphical battle, saying why there is no art', function () {
    $root = enemyPreviewProject();
    unlink($root . '/assets/Data/Presentation/battle.php');
    $preview = EditorSession::open($root)->readEnemyPreview(0);

    expect($preview['sprite'])->toBe(['lines' => ['/\\o/\\', " ' '"]])
        ->and($preview['formation'])->toBeNull()
        ->and($preview['formationIssue'])->toBe('This project has no graphical battle layout to preview enemies at battle scale on.');
});
