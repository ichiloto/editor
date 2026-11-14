<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession;
use Ichiloto\Editor\Cutscenes\Preview\PreviewField;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;

/**
 * A preview started without a save plays in the campaign a new game starts
 * with: the project's starting party, built by the Engine's own new-game
 * loader, with the player shown as a new game shows them. Everything here
 * is synthetic: a quay and one starting member.
 */

/** The harbour project showing the party's leader as the player, its one starting member with field art of their own. */
function leaderArtProject(): string
{
    $root = cutsceneProject();
    mkdir($root . '/assets/Graphics/Characters', 0o777, true);
    writeTilesetTestPng($root . '/assets/Graphics/Characters/$Swordsman.png', 144, 192);
    $actor = $root . '/assets/Data/Actors/Kaelion.php';
    $definition = require $actor;
    $definition['data']['images']['field2d'] = ['sheet' => 'Graphics/Characters/$Swordsman.png', 'layer' => 100];
    file_put_contents($actor, "<?php\n\nreturn " . var_export($definition, true) . ";\n");
    $player = $root . '/assets/Data/Entities/player.php';
    file_put_contents($player, str_replace("return [\n", "return [\n  'graphicalSubject' => 'party-leader',\n", (string) file_get_contents($player)));

    return $root;
}

function newGameSceneDefinition(): CinematicDefinition
{
    return CinematicDefinition::fromArrays([
        'id' => 'first-morning', 'name' => 'First morning', 'startMap' => 'harbour',
        'presentation' => ['initial' => 'field'], 'cast' => [], 'skip' => ['policy' => 'forbidden'], 'checkpoints' => [], 'finalizer' => [],
    ], [['type' => 'wait', 'seconds' => 0.1]]);
}

it('plays a cinematic without a save in the campaign a new game starts with, where the preview places the player', function () {
    $root = cutsceneProject();
    $stores = readInstalledStores();

    $preview = CinematicPreviewSession::start($root, newGameSceneDefinition(), ['mapId' => 'harbour', 'x' => 6, 'y' => 1, 'width' => 40, 'height' => 12]);
    $field = new ReflectionProperty($preview, 'field')->getValue($preview);
    $campaign = $field->scene->campaign;
    try {
        expect($preview->failure())->toBeNull()
            ->and($field->campaignFailure)->toBeNull()
            ->and($field->getDiagnostics())->toBe([])
            ->and(array_column($preview->log(), 'text'))->toContain('New game campaign started.')
            ->and($field->scene->party->leader?->name)->toBe('Kaelion')
            ->and($campaign?->isReleased)->toBeFalse()
            // The preview's origin places the player; a new game gives their sprite and heading.
            ->and(intval($field->scene->player?->position->x))->toBe(6)
            ->and(intval($field->scene->player?->position->y))->toBe(1)
            ->and($field->scene->player?->heading)->toBe(MovementHeading::SOUTH)
            ->and($field->scene->player?->sprite)->toBe(['v'])
            // A save's map is a save's alone.
            ->and($field->getCampaignMapId())->toBeNull();
        $preview->play();
        for ($tick = 0; $tick < 40 && ! $preview->isFinished(); $tick++) {
            $preview->step();
        }
        expect($preview->isFinished())->toBeTrue();
    } finally {
        $preview->dispose();
    }

    expect($campaign?->isReleased)->toBeTrue()
        ->and(readInstalledStores())->toBe($stores);
});

it('shows the party leader\'s art as the player\'s where the project shows the leader as the player', function () {
    $root = leaderArtProject();

    $field = PreviewField::open($root, 'harbour', new Vector2(6, 1), 40, 12, static fn(): float => 0.0);
    try {
        expect($field->campaignFailure)->toBeNull()
            ->and($field->scene->player?->getGraphicalCharacterSheet()?->asset)->toBe('Graphics/Characters/$Swordsman.png');
    } finally {
        $field->dispose();
    }
});

it('opens a field with an empty party and says why where the project cannot start a new game', function (Closure $spoil, string $reason) {
    $root = leaderArtProject();
    $spoil($root);
    $stores = readInstalledStores();

    $field = PreviewField::open($root, 'harbour', new Vector2(6, 1), 40, 12, static fn(): float => 0.0);
    try {
        expect($field->campaignFailure)->toContain('A new game could not be started')->toContain($reason)
            ->and($field->getDiagnostics())->toBe([$field->campaignFailure])
            ->and($field->mapFailure)->toBeNull()
            ->and($field->scene->campaign)->toBeNull()
            ->and($field->scene->party->leader)->toBeNull()
            ->and($field->scene->player?->getGraphicalCharacterSheet())->toBeNull()
            // The actors and items the field found installed stay installed.
            ->and(readInstalledStores())->toBe($stores);
    } finally {
        $field->dispose();
    }

    $preview = CinematicPreviewSession::start($root, newGameSceneDefinition(), ['mapId' => 'harbour', 'x' => 6, 'y' => 1, 'width' => 40, 'height' => 12]);
    try {
        // The scene still plays, and its log and diagnostics say what it is missing.
        expect($preview->failure())->toBeNull()
            ->and(implode("\n", array_column($preview->log(), 'text')))->toContain('A new game could not be started')
            ->and(implode("\n", $preview->getDiagnostics()))->toContain($reason);
    } finally {
        $preview->dispose();
    }

    expect(readInstalledStores())->toBe($stores);
})->with([
    'no system data' => [static fn(string $root) => unlink($root . '/assets/Data/system.php'), 'System data not found'],
    'a starting member the project does not have' => [static function (string $root): void {
        $system = require $root . '/assets/Data/system.php';
        $system['startingParty'] = ['Nobody'];
        file_put_contents($root . '/assets/Data/system.php', "<?php\n\nreturn " . var_export($system, true) . ";\n");
    }, 'startingParty actor "Nobody" cannot be resolved'],
]);
