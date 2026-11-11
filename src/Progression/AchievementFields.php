<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Progression;

use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordFieldCodec;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordStorage;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Progress\Achievement;
use InvalidArgumentException;

/**
 * Database > Achievements: the project's achievements, the list
 * `assets/Data/achievements.php` returns and the Engine's
 * {@see \Ichiloto\Engine\Progress\AchievementManager} reads. Each has a
 * stable id that saves keep its unlock under, a name and description players
 * read, an icon, whether it stays hidden until unlocked, its points, and the
 * world conditions that unlock it, authored through the shared condition
 * editor.
 *
 * The Engine's own reader and world-condition validation judge every save,
 * so an achievement the runtime would skip, or a condition it would refuse,
 * is refused before a byte is written. Two achievements under one id are
 * refused too: the Engine keeps only the later one.
 *
 * @package Ichiloto\Editor\Progression
 */
final class AchievementFields
{
    /** The Database category. */
    public const string CATEGORY = 'achievements';

    /** The project file the Engine reads achievements from. */
    public const string RELATIVE_PATH = 'assets/Data/achievements.php';

    private function __construct()
    {
    }

    public static function getSchema(): RecordSchema
    {
        return new RecordSchema(
            key: self::CATEGORY,
            entryNoun: 'achievement',
            storage: RecordStorage::LIST_FILE,
            relativePath: self::RELATIVE_PATH,
            fields: [
                // Saves keep each unlock under this id, so it is chosen, not derived from the name.
                new RecordField('id', 'Id'),
                new RecordField('name', 'Name'),
                new RecordField('description', 'Description', removeWhenEmpty: true),
                new RecordField('icon', 'Icon', removeWhenEmpty: true, displayDefault: '🏆'),
                RecordField::boolean('secret', 'Secret Until Unlocked'),
                new RecordField('points', 'Points', InputControlType::INTEGER, removeWhenEmpty: true, displayDefault: '0'),
                // Every condition must hold; the achievement unlocks once they all do.
                new RecordField('conditions', 'Unlocks When', removeWhenEmpty: true, codec: RecordFieldCodec::CONDITIONS),
            ],
            labelKey: 'name',
            identityKey: 'id',
            blank: ['id' => 'new-achievement', 'name' => 'New Achievement'],
            saveCheck: self::checkSource(...),
        );
    }

    /**
     * Why the Engine could not read the achievements as a save would write them, or null when it can.
     *
     * @param array<array-key, mixed> $whole Every achievement, as it would be saved.
     */
    public static function checkSource(array $whole, string $projectRoot = ''): ?string
    {
        $ids = [];
        foreach (array_values($whole) as $index => $entry) {
            $where = sprintf('Achievement %d', $index + 1);
            if (! is_array($entry)) {
                return sprintf('%s is %s, not an achievement.', $where, get_debug_type($entry));
            }
            $conditions = $entry['conditions'] ?? [];
            if (! is_array($conditions) || ! array_is_list($conditions)) {
                return sprintf('%s conditions must be a list of world conditions.', $where);
            }
            try {
                $achievement = Achievement::fromArray($entry);
                WorldConditionEvaluator::validateAll($conditions, sprintf('achievement %s conditions', $achievement->id));
            } catch (InvalidArgumentException $error) {
                return sprintf('%s could not be read as it would be saved: %s', $where, $error->getMessage());
            }
            if (isset($ids[$achievement->id])) {
                return sprintf('%s has the id "%s", which achievement %d already has; the game would keep only one.',
                    $where, $achievement->id, $ids[$achievement->id]);
            }
            $ids[$achievement->id] = $index + 1;
        }

        return null;
    }
}
