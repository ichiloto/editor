<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Field\PlayerPresentationConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * Runtime player movement and presentation on an isolated preview field.
 * PreviewMapManager omits ordinary map events and encounters; notifications
 * stay local instead of reaching the game's movement observers.
 */
final class PreviewPlayer extends Player
{
    public ?string $facing {
        get => match ($this->heading) {
            MovementHeading::NORTH => 'up',
            MovementHeading::EAST => 'right',
            MovementHeading::SOUTH => 'down',
            MovementHeading::WEST => 'left',
            default => null,
        };
    }

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
        $presentation = PlayerPresentationConfig::load();
        parent::__construct($scene, 'Player', $position, new Rect(0, 0, 1, 1), $sprite,
            directionalSprites: $presentation->terminal->toArray(),
            graphicalSprites: $presentation->graphical);
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
