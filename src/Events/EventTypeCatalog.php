<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Events;

use Ichiloto\Engine\Events\Triggers\ChestEventTrigger;
use Ichiloto\Engine\Events\Triggers\CinematicEventTrigger;
use Ichiloto\Engine\Events\Triggers\DialogueEventTrigger;
use Ichiloto\Engine\Events\Triggers\ShopEventTrigger;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;
use Ichiloto\Engine\Events\Triggers\SleepEventTrigger;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;

/**
 * Lists valid event types that can be assigned in the editor.
 */
final class EventTypeCatalog
{
    /**
     * @var EventTypeDefinition[]|null
     */
    private static ?array $definitions = null;

    /**
     * @return EventTypeDefinition[]
     */
    public static function all(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        self::$definitions = [
            new EventTypeDefinition(
                label: 'Story Script',
                className: ScriptEventTrigger::class,
                description: 'Runs a resumable event script by stable script id.',
                defaultData: [
                    'scriptId' => '',
                    'mode' => 'action',
                    'reusable' => false,
                ],
                defaultDefinitionFields: [
                    'conditions' => [],
                    'sets' => [],
                    'whenBlocked' => '',
                    'cue' => ['symbol' => '', 'color' => 'bright-yellow'],
                ],
            ),
            new EventTypeDefinition(
                label: 'Cinematic',
                className: CinematicEventTrigger::class,
                description: 'Launches a first-class Cinematic by stable id, automatically on arrival or as the field action.',
                defaultData: [
                    'cinematicId' => '',
                    'mode' => 'auto',
                    'reusable' => false,
                ],
                defaultDefinitionFields: [
                    'conditions' => [],
                    'sets' => [],
                    'whenBlocked' => '',
                    'cue' => ['symbol' => '', 'color' => 'bright-yellow'],
                ],
            ),
            new EventTypeDefinition(
                label: 'Dialogue',
                className: DialogueEventTrigger::class,
                description: 'Displays dialogue when the player interacts with the event.',
                defaultData: [
                    'dialogue' => [
                        [
                            'name' => '',
                            'text' => '',
                        ],
                    ],
                ],
                defaultDefinitionFields: ['cue' => ['symbol' => '', 'color' => 'bright-yellow']],
            ),
            new EventTypeDefinition(
                label: 'Transfer Player',
                className: TransferPlayerTrigger::class,
                description: 'Moves the player to another map and spawn point.',
                defaultData: [
                    'destinationMap' => '',
                    'spawnPoint' => [
                        'x' => 0,
                        'y' => 0,
                    ],
                    'spawnSprite' => [
                        '🧍',
                    ],
                ],
                defaultDefinitionFields: ['cue' => ['symbol' => '', 'color' => 'bright-yellow']],
            ),
            new EventTypeDefinition(
                label: 'Shop',
                className: ShopEventTrigger::class,
                description: 'Opens a shop with inventory and optional merchant dialogue.',
                defaultData: [
                    'items' => [],
                    'dialogue' => [],
                    'buyRate' => 1.0,
                    'sellRate' => 0.5,
                ],
                defaultDefinitionFields: ['cue' => ['symbol' => '', 'color' => 'bright-yellow']],
            ),
            new EventTypeDefinition(
                label: 'Sleep',
                className: SleepEventTrigger::class,
                description: 'Offers rest, restores the party, and can charge a fee.',
                defaultData: [
                    'spawnPoint' => [
                        'x' => 0,
                        'y' => 0,
                    ],
                    'spawnSprite' => [
                        '🧍',
                    ],
                    'confirmDialogue' => [
                        'name' => '',
                        'text' => 'Rest here?',
                    ],
                    'cost' => 0,
                ],
                defaultDefinitionFields: ['cue' => ['symbol' => '', 'color' => 'bright-yellow']],
                // InnOffer's own optional data: the rest music, else the
                // project's sleep theme; the rest stage timeline, else
                // config's graphics.inn.presentation.
                optionalData: ['bgm' => '', 'presentation' => ''],
            ),
            new EventTypeDefinition(
                label: 'Chest',
                className: ChestEventTrigger::class,
                description: 'Gives loot when the player opens the chest event.',
                defaultData: [
                    'loot' => '',
                    'quantity' => 1,
                    'reusable' => false,
                    'chestType' => 'common',
                    'lootType' => 'item',
                ],
                defaultDefinitionFields: ['cue' => ['symbol' => '', 'color' => 'bright-yellow']],
            ),
        ];

        return self::$definitions;
    }

    /**
     * Returns the definition at the given index.
     *
     * @param int $index The selected index.
     * @return EventTypeDefinition
     */
    public static function at(int $index): EventTypeDefinition
    {
        $definitions = self::all();

        return $definitions[max(0, min(count($definitions) - 1, $index))];
    }

    /**
     * Returns the type an author names by its label, or null when no type
     * has that label.
     */
    public static function findByLabel(string $label): ?EventTypeDefinition
    {
        return array_find(self::all(), static fn(EventTypeDefinition $definition): bool => $definition->label === $label);
    }

    /** Returns the type of a trigger class, or null when the catalog does not list it. */
    public static function findByClass(?string $className): ?EventTypeDefinition
    {
        return array_find(self::all(), static fn(EventTypeDefinition $definition): bool => $definition->className === $className);
    }

    /**
     * Returns the index for the given trigger class.
     *
     * @param string|null $className The stored trigger class name.
     * @return int
     */
    public static function indexOfClass(?string $className): int
    {
        foreach (self::all() as $index => $definition) {
            if ($definition->className === $className) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * Returns the label an event of this trigger class is shown by: Unset for
     * none, its catalog label, or the class's short name for one the catalog
     * does not list.
     */
    public static function describeClass(?string $className): string
    {
        if ($className === null || $className === '') {
            return 'Unset';
        }

        foreach (self::all() as $definition) {
            if ($definition->className === $className) {
                return $definition->label;
            }
        }

        return basename(str_replace('\\', '/', $className));
    }
}
