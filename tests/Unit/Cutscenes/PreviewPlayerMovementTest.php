<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\Preview\PreviewField;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Triggers\CinematicEventTrigger;
use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

function createPreviewWalkingProject(): string
{
    $root = cutsceneProject();
    file_put_contents($root . '/assets/Maps/collisions.php', <<<'PHP_SOURCE'
<?php
use Ichiloto\Engine\Events\Enumerations\CollisionType;
return [' ' => CollisionType::NONE, '#' => CollisionType::SOLID, '=' => CollisionType::SOLID];
PHP_SOURCE);
    writeTilesetTestPng($root . '/assets/Graphics/Characters/$Walker.png', 6, 8);
    file_put_contents($root . '/assets/Data/Entities/player.php', '<?php return ' . var_export([
        'sprites' => ['north' => ['N'], 'east' => ['E'], 'south' => ['S'], 'west' => ['W']],
        'sprites2d' => ['sheet' => 'Graphics/Characters/$Walker.png'],
    ], true) . ';');

    return $root;
}

it('uses the project directional sprites and shared heading for both preview presentations',
    function (Vector2 $direction, MovementHeading $heading, string $glyph, string $facing, int $row): void {
        $field = PreviewField::open(createPreviewWalkingProject(), 'harbour', new Vector2(4, 4), 20, 8, fn(): float => 0.0);

        try {
            expect($field->mapFailure)->toBeNull();
            $field->run(function () use ($field, $direction, $heading, $glyph, $facing, $row): void {
                $player = $field->scene->player;
                $player->face($direction, $field->scene->camera);
                expect($player->heading)->toBe($heading)
                    ->and($player->facing)->toBe($facing)
                    ->and($player->sprite)->toBe([$glyph])
                    ->and([$player->position->x, $player->position->y])->toBe([4.0, 4.0])
                    ->and($player->getGraphicalSpriteDefinition()->sourceRect->toArray())
                    ->toBe(['x' => 2, 'y' => $row * 2, 'width' => 2, 'height' => 2]);
            });
            expect(implode("\n", array_map(TerminalText::stripAnsi(...), $field->frame())))->toContain($glyph);
        } finally {
            $field->dispose();
        }
    })->with([
        [Vector2::up(), MovementHeading::NORTH, 'N', 'up', 3],
        [Vector2::right(), MovementHeading::EAST, 'E', 'right', 2],
        [Vector2::down(), MovementHeading::SOUTH, 'S', 'down', 0],
        [Vector2::left(), MovementHeading::WEST, 'W', 'left', 1],
    ]);

it('uses the runtime step at the callers pace and ends the walking presentation without moving again', function (): void {
    $field = PreviewField::open(createPreviewWalkingProject(), 'harbour', new Vector2(4, 4), 20, 8, fn(): float => 0.0);

    try {
        expect($field->mapFailure)->toBeNull();
        $field->run(function () use ($field): void {
            $player = $field->scene->player;
            expect($field->scene->moveAtPace(0.4, fn(): bool => $player->tryMove(Vector2::right(), $field->scene->camera)))
                ->toBeTrue()
                ->and([$player->position->x, $player->position->y])->toBe([5.0, 4.0])
                ->and($player->heading)->toBe(MovementHeading::EAST)
                ->and($player->getGraphicalSpriteMotion()?->seconds)->toBe(0.4)
                ->and($player->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(4);
        });
        $field->advance(0.7);
        $field->run(function () use ($field): void {
            $player = $field->scene->player;
            expect([$player->position->x, $player->position->y])->toBe([5.0, 4.0])
                ->and($player->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(2);
            $player->face(Vector2::up(), $field->scene->camera);
            expect($player->getGraphicalSpriteMotion())->toBeNull()
                ->and($player->heading)->toBe(MovementHeading::NORTH);
        });
    } finally {
        $field->dispose();
    }
});

it('faces a blocked cell without moving or leaving a stale walking slide', function (): void {
    $field = PreviewField::open(createPreviewWalkingProject(), 'harbour', new Vector2(1, 1), 20, 8, fn(): float => 0.0);

    try {
        expect($field->mapFailure)->toBeNull();
        $field->run(function () use ($field): void {
            $player = $field->scene->player;
            expect($player->tryMove(Vector2::left(), $field->scene->camera))->toBeFalse()
                ->and([$player->position->x, $player->position->y])->toBe([1.0, 1.0])
                ->and($player->heading)->toBe(MovementHeading::WEST)
                ->and($player->sprite)->toBe(['W'])
                ->and($player->getGraphicalSpriteMotion())->toBeNull();
        });
    } finally {
        $field->dispose();
    }
});

it('scrolls following previews through the shared map camera and leaves detached cameras alone', function (): void {
    $root = createPreviewWalkingProject();
    writeOpeningMaps($root);
    $field = PreviewField::open($root, 'skyfield-night', new Vector2(30, 8), 20, 8, fn(): float => 0.0);

    try {
        expect($field->mapFailure)->toBeNull();
        $field->run(function () use ($field): void {
            $player = $field->scene->player;
            $camera = $field->scene->camera;
            $before = clone $camera->position;
            expect($player->tryMove(Vector2::right(), $camera))->toBeTrue()
                ->and($camera->position->x)->toBe($before->x + 1);
            $camera->detach();
            $detached = clone $camera->position;
            expect($player->tryMove(Vector2::down(), $camera))->toBeTrue()
                ->and([$camera->position->x, $camera->position->y])->toBe([$detached->x, $detached->y]);
        });
    } finally {
        $field->dispose();
    }
});

it('keeps ordinary map events and encounter bookkeeping out of shared preview movement', function (): void {
    $root = createPreviewWalkingProject();
    $directory = $root . '/assets/Maps/harbour';
    $map = require $directory . '/harbour.data.php';
    $map['events']['g'] = ['class' => CinematicEventTrigger::class,
        'data' => ['cinematicId' => 'harbour-lanterns', 'mode' => 'auto', 'reusable' => false]];
    file_put_contents($directory . '/harbour.data.php', '<?php return ' . var_export($map, true) . ';');
    $rows = explode("\n", require $directory . '/harbour.event.php');
    $rows[4][5] = 'g';
    file_put_contents($directory . '/harbour.event.php', MapGridSource::buildSource(implode("\n", $rows), 'EVENTS'));
    $before = sourceHashTree($root);
    $field = PreviewField::open($root, 'harbour', new Vector2(4, 4), 20, 8, fn(): float => 0.0);

    try {
        expect($field->mapFailure)->toBeNull();
        $field->run(function () use ($field): void {
            expect($field->scene->player->findEventMarkerPosition('g'))->toBeNull()
                ->and($field->scene->encounterManager)->toBeNull()
                ->and($field->scene->player->tryMove(Vector2::right(), $field->scene->camera))->toBeTrue();
        });
        $field->advance(1.0);
        expect($field->scene->finishedSessions)->toBe([])
            ->and($field->scene->cinematicController->active())->toBeNull()
            ->and(ConfigStore::get(ProjectConfig::class)->get('save.autosave'))->toBeFalse()
            ->and(ConfigStore::get(ProjectConfig::class)->get('audio.music'))->toBeFalse()
            ->and(ConfigStore::get(ProjectConfig::class)->get('audio.sfx'))->toBeFalse()
            ->and(ConfigStore::get(ProjectConfig::class)->get('audio.voice'))->toBeFalse()
            ->and(sourceHashTree($root))->toBe($before);
    } finally {
        $field->dispose();
    }
});
