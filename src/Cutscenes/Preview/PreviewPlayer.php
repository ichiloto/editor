<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Field\PlayerPresentationConfig;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * The player as a cinematic sees them: a position, a facing and a sprite that
 * the interpreter's player commands move, with the field's own input, HUD and
 * trigger machinery left out.
 */
final class PreviewPlayer extends Player
{
    public ?string $facing = null;

    /** @var string[] */
    public array $blockedMessages = [];

    /**
     * The player of a preview scene, with the field art the project gives the
     * player ({@see PlayerPresentationConfig}), as the game's field builds it.
     *
     * @param string[] $sprite
     */
    public function __construct(GameScene $scene, Vector2 $position, array $sprite = ['@'])
    {
        parent::__construct($scene, 'Player', $position, new Rect(0, 0, 1, 1), $sprite,
            graphicalSprites: PlayerPresentationConfig::load()->graphical);
    }

    public function tryMove(Vector2 $direction, Camera $camera): bool
    {
        $scene = $this->scene ?? null;
        $destinationX = intval($this->position->x + $direction->x);
        $destinationY = intval($this->position->y + $direction->y);

        if ($scene instanceof GameScene && ! $scene->mapManager?->canMoveTo($destinationX, $destinationY)) {
            $this->face($direction, $camera);

            return false;
        }

        $this->position->x = $destinationX;
        $this->position->y = $destinationY;
        $this->face($direction, $camera);

        if ($camera->followsPlayer) {
            $camera->resetPosition($this);
        }

        return true;
    }

    public function face(Vector2 $direction, Camera $camera): void
    {
        $this->facing = match (true) {
            $direction->y < 0 => 'up',
            $direction->y > 0 => 'down',
            $direction->x < 0 => 'left',
            default => 'right',
        };
    }

    public function render(): void
    {
        $scene = $this->scene ?? null;

        if ($scene instanceof GameScene) {
            $scene->camera->renderOnScreen($this->sprite, $this->position);
        }
    }

    public function erase(): void
    {
    }

    protected function announceBlockedEvent(string $message): void
    {
        $this->blockedMessages[] = $message;
    }

    public function notify(object $entity, EventInterface $event): void
    {
    }
}
