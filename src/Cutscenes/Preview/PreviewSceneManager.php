<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Scenes\SceneManager;

/**
 * A scene manager that records battle requests instead of loading a battle.
 *
 * A cinematic that starts a battle suspends its session until the battle
 * reports back; the preview resolves that boundary itself (see
 * `CinematicPreviewSession::resolveBattle()`), so this only keeps the
 * request.
 */
final class PreviewSceneManager extends SceneManager
{
    /** @var array<int, array{troop: string, settings: array<string, mixed>}> */
    public array $battles = [];

    public function __construct(PreviewGame $game)
    {
        $this->game = $game;
        $this->saveManager = $game->getSaveManager();
    }

    public function loadBattleScene(Party $party, Troop $troop, array $events = [], array $extraSettings = []): void
    {
        $this->battles[] = ['troop' => $troop->name, 'settings' => $extraSettings];
    }
}

