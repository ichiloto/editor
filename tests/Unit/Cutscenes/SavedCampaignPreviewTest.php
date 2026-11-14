<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession;
use Ichiloto\Editor\Cutscenes\Preview\PreviewField;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\IO\SaveCompatibility\ContentMigrationResolution;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;
use Ichiloto\Engine\IO\SaveManager;

/**
 * A preview started from a save plays in that save's campaign: its world
 * state on its map, with the player where the save left them, restored
 * through the Engine's save pipeline and campaign session, and released when
 * the preview ends. Everything here is synthetic: a quay and one switch.
 */

/** A save of the harbour with a switch set and the player on a quay cell, written by the Engine's own save pipeline. */
function writeHarbourSave(string $root, int $x, int $y): string
{
    $field = PreviewField::open($root, 'harbour', new Vector2($x, $y), 40, 12, static fn(): float => 0.0);
    $path = null;
    try {
        $field->run(function () use ($field, $root, &$path): void {
            $field->scene->gameState->setSwitch('lanterns_lit', true);
            $manager = new SaveManager($field->scene->getGame(), compatibilityManifest: SaveCompatibilityManifest::fromProjectRoot(
                $root, migrationResolution: ContentMigrationResolution::WHEN_REQUIRED));
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

/**
 * Declares the project's save history: one migration class per content step, none of which the Editor can load,
 * as a real project's classes never are.
 */
function writeHarbourSaveHistory(string $root, int $contentVersion): void
{
    $migrations = [];
    for ($from = 0; $from < $contentVersion; $from++) {
        $migrations[] = ['from' => $from, 'to' => $from + 1, 'class' => sprintf('Synthetic\\Harbour\\Save\\Step%dTo%d', $from, $from + 1)];
    }
    file_put_contents($root . '/assets/Data/save-compatibility.php',
        "<?php\n\nreturn " . var_export(['contentVersion' => $contentVersion, 'migrations' => $migrations], true) . ";\n");
}

it('restores a save at the project\'s current content version without the project\'s migration classes', function () {
    $root = cutsceneProject();
    writeHarbourSaveHistory($root, 2);
    $save = writeHarbourSave($root, 3, 1);

    $field = PreviewField::openSavedCampaign($root, $save, 40, 12, static fn(): float => 0.0);
    try {
        expect($field->scene->gameState->getSwitch('lanterns_lit'))->toBeTrue()
            ->and(class_exists('Synthetic\\Harbour\\Save\\Step1To2', false))->toBeFalse();
    } finally {
        $field->dispose();
    }
});

it('refuses a save that needs a migration class the Editor cannot load, naming the class', function () {
    $root = cutsceneProject();
    writeHarbourSaveHistory($root, 1);
    $save = writeHarbourSave($root, 3, 1);
    writeHarbourSaveHistory($root, 2);
    $stores = readInstalledStores();

    expect(fn() => PreviewField::openSavedCampaign($root, $save, 40, 12, static fn(): float => 0.0))
        ->toThrow('Step1To2", which is unavailable in this host')
        ->and(readInstalledStores())->toBe($stores);
});

it('names the project\'s keys in the Engine\'s bindings while a field is open, and gives them back', function () {
    $root = cutsceneProject();
    file_put_contents($root . '/input.php', "<?php\n\nuse Ichiloto\\Engine\\IO\\Enumerations\\KeyCode;\n\nreturn ['confirm' => ['description' => 'Confirm', 'keys' => [KeyCode::F]]];\n");
    $before = \Ichiloto\Engine\IO\InputManager::getBindings();

    $field = PreviewField::open($root, 'harbour', new Vector2(3, 1), 40, 12, static fn(): float => 0.0);
    try {
        expect(\Ichiloto\Engine\IO\InputManager::getBindings()['confirm']['keys'] ?? null)->toBe([\Ichiloto\Engine\IO\Enumerations\KeyCode::F])
            ->and($field->getDiagnostics())->toBe([]);
    } finally {
        $field->dispose();
    }

    expect(\Ichiloto\Engine\IO\InputManager::getBindings()['confirm']['keys'] ?? null)->toBe($before['confirm']['keys'] ?? null);
});
