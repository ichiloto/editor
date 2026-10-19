<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;

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

it('stands an enemy too tall for the lead party member\'s ground line opposite the next member it fits beside', function () {
    $root = enemyPreviewProject();
    writeTilesetTestPng($root . '/assets/Graphics/Actors/Kaelion.png', 10, 10);
    writeTilesetTestPng($root . '/assets/Graphics/Enemies/Bat.png', 4, 10);
    $battle = (string) file_get_contents($root . '/assets/Data/Presentation/battle.php');
    $battle = str_replace(['use Ichiloto\Engine\Battle\Presentation\BattlerSlot;', "    actors: [],\n    enemies: [],"], [
        "use Ichiloto\\Engine\\Battle\\Presentation\\BattlerSlot;\nuse Ichiloto\\Engine\\Battle\\Presentation\\{BattlerArtwork, BattleScale, BattlerScale};",
        // At three times the party's height the bat stands 450 tall: above the canvas from the lead's ground line at 420, whole from the next at 560.
        "    actors: ['Kaelion' => new BattlerArtwork('Graphics/Actors/Kaelion.png', 10, 10, 5, 10)],\n"
        . "    enemies: ['Regular Bat' => new BattlerArtwork('Graphics/Enemies/Bat.png', 4, 10, 2, 10)],\n"
        . "    scale: new BattleScale('Kaelion', 150, actors: ['Kaelion' => new BattlerScale(1, 1)], enemies: ['Regular Bat' => new BattlerScale(3, 1)]),",
    ], $battle);
    file_put_contents($root . '/assets/Data/Presentation/battle.php', $battle);

    $preview = EditorSession::open($root)->readEnemyPreview(0);

    expect($preview['formationIssue'])->toBeNull()
        ->and($preview['formation']['members'][0]['battler']['ground'])->toBe(['x' => 170.0, 'y' => 560.0]);
});
