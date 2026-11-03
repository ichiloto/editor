<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Engine\Battle\Presentation\BattlerBindings;
use Ichiloto\Engine\Battle\Presentation\BattlerPose;

/**
 * The entries a battle pose's impact is chosen among ({@see RecordFieldCodec::POSE_IMPACT_ENTRY}): each
 * index of the pose's `frames` playback list, repeated holds included, with the sheet cell it plays. Only a
 * pose that plays once has any, as {@see BattlerPose} requires. The pose is read as {@see BattlerBindings}
 * reads it: absent `frames` play cell 0 and an absent `loop` loops. The Engine's reader remains the authority
 * on a whole pose, and refuses a save it could not read.
 */
final class PoseImpactEntries
{
    /** How a chosen entry reads, and is typed: `Entry 3 · cell 2`. */
    private const string LABEL = 'Entry %d · cell %s';

    /**
     * The pose's playback entries an impact may name, by index, each with the cell it plays; none while the
     * pose loops or its frames are not a list.
     *
     * @param array<array-key, mixed> $pose The pose entry as authored.
     * @return array<int, string> Index to its label.
     */
    public static function listEntries(array $pose): array
    {
        $frames = $pose['frames'] ?? [0];
        if (($pose['loop'] ?? true) !== false || ! is_array($frames) || ! array_is_list($frames)) {
            return [];
        }
        $entries = [];
        foreach ($frames as $index => $cell) {
            $entries[$index] = self::describeEntry($index, $cell);
        }

        return $entries;
    }

    /**
     * How a stored impact reads on the pose's row: the entry with its cell, or why the pose cannot play it.
     *
     * @param array<array-key, mixed> $pose The pose entry as authored.
     */
    public static function describeValue(int $index, array $pose): string
    {
        $entries = self::listEntries($pose);
        if (isset($entries[$index])) {
            return $entries[$index];
        }

        return ($pose['loop'] ?? true) !== false
            ? sprintf('Entry %d (the pose loops, so it has no impact)', $index)
            : sprintf('Entry %d (not in its frames)', $index);
    }

    /** The entry index a chosen or typed line names (`Entry 3 · cell 2`, or `3`), or null when it names none. */
    public static function parse(string $line): ?int
    {
        return preg_match('/\A(?:Entry\s+)?(\d+)(?:\s*·.*)?\z/u', trim($line), $match) === 1 ? intval($match[1]) : null;
    }

    private static function describeEntry(int $index, mixed $cell): string
    {
        return sprintf(self::LABEL, $index, is_scalar($cell) ? strval($cell) : '?');
    }
}
