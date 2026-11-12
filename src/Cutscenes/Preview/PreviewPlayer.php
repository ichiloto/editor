<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Preview;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Field\PlayerGraphicalSubject;
use Ichiloto\Engine\Field\PlayerPresentationConfig;
use Ichiloto\Engine\IO\Console\Console;
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
     * A preview showing a particular character in the player's place gives
     * their graphical art; the Terminal sprites stay the player's own. A
     * preview of a restored campaign gives its party: where the project shows
     * the party's leader as the player, the leader's art is the player's, as
     * in the game.
     *
     * @param string[] $sprite
     */
    public function __construct(GameScene $scene, Vector2 $position, array $sprite = ['@'],
        MovementHeading $heading = MovementHeading::NONE, ?CharacterSheet $appearance = null, ?Party $party = null)
    {
        $presentation = PlayerPresentationConfig::load();
        $showsLeader = $party !== null && $appearance === null
            && $presentation->graphicalSubject === PlayerGraphicalSubject::PARTY_LEADER;
        parent::__construct($scene, 'Player', $position, new Rect(0, 0, 1, 1), $sprite, $heading,
            directionalSprites: $presentation->terminal->toArray(),
            graphicalSprites: $showsLeader ? null : ($appearance ?? $presentation->graphical),
            party: $showsLeader ? $party : null);
    }

    /**
     * Draws the player's Terminal glyph on their own presentation layer, as
     * the game's player does, so a graphical frame that shows them by their
     * sprite leaves that glyph cell out instead of painting it beneath them.
     */
    public function render(): void
    {
        $scene = $this->scene ?? null;

        if ($scene instanceof GameScene) {
            Console::withLayer($this->getGraphicalSpriteId(),
                fn() => $scene->camera->renderOnScreen($this->sprite, $this->position));
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
