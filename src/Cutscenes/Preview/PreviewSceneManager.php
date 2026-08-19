<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Scenes\Game\GameScene;
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

    public function __construct()
    {
        $this->game = new PreviewGame();
        $this->saveManager = new PreviewSaveManager();
    }

    public function loadBattleScene(Party $party, Troop $troop, array $events = [], array $extraSettings = []): void
    {
        $this->battles[] = ['troop' => $troop->name, 'settings' => $extraSettings];
    }
}

/**
 * A save manager that never touches a slot.
 */
final class PreviewSaveManager extends SaveManager
{
    public int $autoSaves = 0;

    public function __construct()
    {
    }

    public function autoSave(GameScene $scene): SaveSlot
    {
        $this->autoSaves++;

        return SaveSlot::empty(self::AUTO_SAVE_SLOT, sys_get_temp_dir() . '/ichiloto-preview-autosave.iedata');
    }
}
