<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Events;

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * The maps a reusable event script runs on, as its callers run it: a map's own events and NPCs (a `common_event`
 * command or a `scriptId`), a cinematic from its Start Map, and another script on the maps that one runs on. Like
 * relative placement previews, a transfer earlier in a caller is not followed; validation, which follows them,
 * judges the result.
 */
final readonly class CommonEventMaps
{
    /** @param array<string, list<string>> $maps Map ids by script id. */
    private function __construct(private array $maps) {}

    /** Reads every caller as the workspace holds it now, unsaved edits included. */
    public static function read(ProjectWorkspace $workspace): self
    {
        $direct = $calls = [];
        foreach ($workspace->maps as $map) {
            foreach (self::findCalls($map->getEditableData()) as $id) { $direct[$id][$map->mapId] = true; }
        }
        foreach ($workspace->cutscenes?->assets(CutsceneType::CINEMATIC) ?? [] as $cinematic) {
            $start = $cinematic->data()['startMap'] ?? null;
            if (!is_string($start) || $start === '') { continue; }
            foreach (self::findCalls($cinematic->commands()) as $id) { $direct[$id][$start] = true; }
        }
        foreach ($workspace->getRecordDatabase('common_events')?->getRecords() ?? [] as $record) {
            foreach (self::findCalls($record->getSubList('commands')) as $id) { $calls[$record->recordId][$id] = true; }
        }
        // A script runs on every map a script calling it runs on.
        $maps = $direct;
        do {
            $changed = false;
            foreach ($calls as $caller => $callees) {
                foreach (array_keys($callees) as $callee) {
                    $added = array_diff_key($maps[$caller] ?? [], $maps[$callee] ?? []);
                    if ($added !== []) {
                        $maps[$callee] = ($maps[$callee] ?? []) + $added;
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        return new self(array_map(static fn(array $ids): array => array_keys($ids), $maps));
    }

    /** @return list<string> The maps the script runs on. */
    public function findMaps(string $scriptId): array
    {
        return $this->maps[$scriptId] ?? [];
    }

    /** @return list<string> The scripts a value calls anywhere inside it. */
    private static function findCalls(mixed $value): array
    {
        if (!is_array($value)) { return []; }
        $ids = [];
        if (($value['type'] ?? null) === 'common_event' && is_string($value['id'] ?? null)) { $ids[] = $value['id']; }
        if (is_string($value['scriptId'] ?? null)) { $ids[] = $value['scriptId']; }
        foreach ($value as $child) { array_push($ids, ...self::findCalls($child)); }
        return $ids;
    }
}
