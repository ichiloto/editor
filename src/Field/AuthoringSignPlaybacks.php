<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Engine\Field\WorldObjectDefinition;
use Ichiloto\Engine\Field\WorldSignPlayback;

/**
 * The editing canvas's sign playbacks, one per placed sign, kept while its
 * placement is unchanged so its prepared label survives other edits. A
 * changed or removed placement releases its playback; closing the canvas
 * releases them all. The Engine's playback itself reconciles locale, font,
 * board and session changes.
 */
final class AuthoringSignPlaybacks
{
    /** @var array<string, array{key: string, playback: WorldSignPlayback}> */
    private array $playbacks = [];

    public function __construct(private readonly string $assetRoot)
    {
    }

    /**
     * The playback for a sign placement, the existing one while the placement is as it was.
     *
     * @param array<string, mixed> $entry The placement as authored, which identifies what it shows.
     */
    public function getPlayback(WorldObjectDefinition $definition, array $entry): WorldSignPlayback
    {
        $key = json_encode($entry, JSON_THROW_ON_ERROR);
        $kept = $this->playbacks[$definition->id] ?? null;
        if ($kept !== null && $kept['key'] === $key) {
            return $kept['playback'];
        }
        if ($kept !== null) {
            $kept['playback']->release();
        }
        $playback = new WorldSignPlayback($definition, $this->assetRoot);
        $this->playbacks[$definition->id] = ['key' => $key, 'playback' => $playback];

        return $playback;
    }

    /**
     * Releases the playbacks of signs the map no longer places.
     *
     * @param list<string> $ids The ids of the signs the map places now.
     */
    public function retain(array $ids): void
    {
        foreach (array_diff(array_keys($this->playbacks), $ids) as $id) {
            $this->playbacks[$id]['playback']->release();
            unset($this->playbacks[$id]);
        }
    }

    /** Releases every playback, as closing the canvas does. */
    public function releaseAll(): void
    {
        $this->retain([]);
    }
}
