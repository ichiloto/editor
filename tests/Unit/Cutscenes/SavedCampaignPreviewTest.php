<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession;
use Ichiloto\Editor\Cutscenes\Preview\PreviewField;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\Util\Stores\ItemStore;

/**
 * A preview started from a save plays in that save's campaign: its world
 * state on its map, with the player where the save left them, restored
 * through the Engine's save pipeline and campaign session, and released when
 * the preview ends. Everything here is synthetic: a quay and one switch.
 */

/**
 * The project's actors and items as the Engine holds them now, so a test can
 * tell that a preview gave back exactly what it found.
 *
 * @return array{0: object|null, 1: object|null}
 */
function readInstalledStores(): array
{
    return [ConfigStore::has(ActorStore::class) ? ConfigStore::get(ActorStore::class) : null,
        ConfigStore::has(ItemStore::class) ? ConfigStore::get(ItemStore::class) : null];
}

/** A save of the harbour with a switch set and the player on a quay cell, written by the Engine's own save pipeline. */
function writeHarbourSave(string $root, int $x, int $y): string
{
    $field = PreviewField::open($root, 'harbour', new Vector2($x, $y), 40, 12, static fn(): float => 0.0);
    $path = null;
    try {
        $field->run(function () use ($field, &$path): void {
            $field->scene->gameState->setSwitch('lanterns_lit', true);
            $manager = new SaveManager($field->scene->getGame());
            $manager->save($field->scene, 1);
            $path = $manager->getSlotPath(1);
        });
    } finally {
        $field->dispose();
    }

    return realpath((string) $path) ?: throw new RuntimeException('The synthetic save was not written.');
}

function savedCampaignDefinition(): CinematicDefinition
{
    return CinematicDefinition::fromArrays([
        'id' => 'after-the-lanterns', 'name' => 'After the lanterns', 'startMap' => 'harbour',
        'presentation' => ['initial' => 'field'], 'cast' => [], 'skip' => ['policy' => 'forbidden'], 'checkpoints' => [], 'finalizer' => [],
    ], [
        ['type' => 'wait', 'seconds' => 0.1],
        ['type' => 'set_switch', 'name' => 'keeper_thanked', 'value' => true],
    ]);
}

it('plays a cinematic in the campaign a save holds, where the save left the player', function () {
    $root = cutsceneProject();
    $save = writeHarbourSave($root, 3, 1);
    $before = hash_file('sha256', $save);
    $stores = readInstalledStores();

    $preview = CinematicPreviewSession::start($root, savedCampaignDefinition(), ['campaignSave' => $save, 'width' => 40, 'height' => 12]);
    try {
        expect($preview->failure())->toBeNull()
            ->and($preview->snapshot()->values)->toHaveKey('switch lanterns_lit', true)
            ->and($preview->snapshot()->values)->not->toHaveKey('switch keeper_thanked');
        $preview->play();
        for ($tick = 0; $tick < 40 && ! $preview->isFinished(); $tick++) {
            $preview->step();
        }
        expect($preview->isFinished())->toBeTrue()
            ->and($preview->snapshot()->values)->toHaveKey('switch keeper_thanked', true)
            ->and($preview->snapshot()->values)->toHaveKey('switch lanterns_lit', true);
    } finally {
        $preview->dispose();
    }

    expect(hash_file('sha256', $save))->toBe($before)
        ->and(readInstalledStores())->toBe($stores);
});

it('opens the field on the save\'s map and cell and releases its campaign when disposed', function () {
    $root = cutsceneProject();
    $save = writeHarbourSave($root, 4, 1);

    $field = PreviewField::openSavedCampaign($root, $save, 40, 12, static fn(): float => 0.0);
    $campaign = $field->scene->campaign;
    try {
        expect($field->getCampaignMapId())->toBe('harbour')
            ->and($field->mapFailure)->toBeNull()
            ->and(intval($field->scene->player?->position->x))->toBe(4)
            ->and(intval($field->scene->player?->position->y))->toBe(1)
            ->and($field->scene->gameState->getSwitch('lanterns_lit'))->toBeTrue()
            ->and($campaign?->isReleased)->toBeFalse();
    } finally {
        $field->dispose();
    }

    expect($campaign?->isReleased)->toBeTrue();
});

it('refuses a save that cannot be read and leaves nothing installed', function () {
    $root = cutsceneProject();
    $stores = readInstalledStores();

    expect(fn() => PreviewField::openSavedCampaign($root, $root . '/no-such-save.iedata', 40, 12, static fn(): float => 0.0))
        ->toThrow(InvalidArgumentException::class, 'could not be read')
        ->and(readInstalledStores())->toBe($stores);
});
