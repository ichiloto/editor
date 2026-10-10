<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Session;

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Field\NpcAuthoring;
use Ichiloto\Editor\Field\NpcInspector;
use Ichiloto\Editor\History\CommandGroup;
use Ichiloto\Editor\Maps\MapPlacement;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;

/** A map gesture edits only the rows the shared schema currently exposes. */
trait MapPlacementSession
{
    public function applyMapPlacement(array $context, array $key, array $expected, string $previewMap, int $point, int $x, int $y, ?array $origin, int $previewRevision): array
    {
        $kind = $context['kind'] ?? '';
        $frame = self::requireFrame($key['frame'] ?? [], 'map placement');
        $index = $context['index'] ?? 0;
        if (!is_int($index)) {
            throw new SessionRefusal('The record index must be an integer.');
        }
        $map = null;
        if (in_array($kind, ['event', 'npc'], true)) {
            if (!is_string($context['map'] ?? null) || !is_int($context['revision'] ?? null)) {
                throw new SessionRefusal('A map edit needs its identity and revision.');
            }
            $map = $this->requireCurrentMap($context['map'], $context['revision']);
        }
        $rows = match ($kind) {
            'database' => $this->readDatabaseRecord(strval($context['category'] ?? ''), $index, $frame)['rows'],
            'npc' => $this->readNpc($map->mapId, $index, $frame)['rows'],
            'event' => $this->readInspector($map->mapId, strval($key['marker'] ?? ''))['rows'],
            default => throw new SessionRefusal('Unknown map-placement owner.'),
        };
        $row = array_find($rows, static fn(array $row): bool => MapPlacement::areValuesEqual($row['key'] ?? null, $key));
        $placement = $row['mapPlacement'] ?? null;
        if ($placement === null || !MapPlacement::areValuesEqual($placement, $expected) || ($row['kind'] ?? 'info') === 'info') {
            throw new SessionRefusal('The authored placement changed or is read-only; open it again.');
        }
        $preview = $this->requireCurrentMap($previewMap, $previewRevision);
        try {
            $preview->assertSourcesUnchanged();
        } catch (\RuntimeException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }
        if (($placement['issue'] ?? null) !== null) {
            throw new SessionRefusal($placement['issue']);
        }
        if (!isset($placement['points'][$point]) || $point < 0) {
            throw new SessionRefusal('Choose an authored point first.');
        }
        if ($x < 0 || $y < 0 || $x >= $preview->getWidth() || $y >= $preview->getHeight()) {
            throw new SessionRefusal('Choose a cell inside the displayed map.');
        }
        if ($origin !== null && (!array_is_list($origin) || count($origin) !== 2 || !is_int($origin[0]) || !is_int($origin[1])
            || $origin[0] < 0 || $origin[1] < 0 || $origin[0] >= $preview->getWidth() || $origin[1] >= $preview->getHeight())) {
            throw new SessionRefusal('Choose a preview origin inside the displayed map.');
        }
        if ($placement['kind'] === 'event-transfer') {
            return $this->setEventDestination($map->mapId, $context['revision'], $key['marker'], $previewMap, $x, $y);
        }
        try {
            $values = MapPlacement::getChanges($placement, $point, $x, $y, $origin, $previewMap);
            if ($placement['kind'] === 'route' && $origin !== null) {
                $selected = $placement['points'][$point];
                $shift = $selected['faceOnly'] ? [0, 0] : [$x - $origin[0] - $selected['point'][0], $y - $origin[1] - $selected['point'][1]];
                foreach ($placement['points'] as $i => $step) {
                    $px = $origin[0] + $step['point'][0] + ($i >= $point ? $shift[0] : 0);
                    $py = $origin[1] + $step['point'][1] + ($i >= $point ? $shift[1] : 0);
                    if ($px < 0 || $py < 0 || $px >= $preview->getWidth() || $py >= $preview->getHeight()) {
                        throw new \InvalidArgumentException(sprintf('Step %d would leave the preview map; adjust its steps or preview origin first.', $i + 1));
                    }
                }
            }
        } catch (\InvalidArgumentException $error) {
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }
        $changes = [];
        foreach ($values as $field => $value) {
            $found = array_find($rows, static fn(array $candidate): bool => $kind === 'event'
                ? json_encode($candidate['key']['path'] ?? null, JSON_THROW_ON_ERROR) === $field
                : ($candidate['key']['field'] ?? null) === $field);
            if ($found === null || ($found['kind'] ?? 'info') === 'info') {
                throw new SessionRefusal('That coordinate is not editable through the shared inspector.');
            }
            if (($found['raw'] ?? $found['value'] ?? null) === $value) {
                continue;
            }
            $changes[] = ['key' => $found['key'], 'value' => $value];
        }
        if ($kind === 'database') {
            return $this->applyDatabaseRecordValues($context['category'], $index, $changes, 'Place on map');
        }
        $commands = [];
        try {
            foreach ($changes as $change) {
                if ($kind === 'npc') {
                    $inspector = new NpcInspector($map, graphical: true, references: new ReferenceCatalog($this->workspace, $map));
                    $field = array_find($this->collectNpcFields($inspector, $index, $frame),
                        static fn(array $field): bool => ($field['field'] ?? null) === $change['key']['field']);
                    $command = (new NpcAuthoring($this->workspace))->applyField($inspector, $index, $frame, $field, $change['value'])->command;
                } else {
                    $field = $this->requireInspectorField($map, $change['key']);
                    $command = $this->createMapInspector($map)->apply($map, $field, $change['value']);
                }
                if ($command !== null) {
                    $commands[] = $command;
                }
            }
        } catch (\Throwable $error) {
            foreach (array_reverse($commands) as $command) {
                $command->undo();
            }
            throw new SessionRefusal($error->getMessage(), previous: $error);
        }
        if ($commands !== []) {
            $this->history->record(new CommandGroup('Place on map', $commands));
        }
        return ['changed' => $commands !== [], 'revision' => $this->getMapRevision($map)];
    }

    /** Attach a review of the actual owner, never a second editable model. */
    private function describePlacementRows(array $rows, array $owner): array
    {
        $hasPlacement = array_any($rows, static fn(array $row): bool => isset($row['mapPlacement']));
        if (!$hasPlacement && $owner['kind'] === 'database' && CutsceneType::findByRecordCategory($owner['category']) === null) {
            return $rows;
        }
        $prefix = [];
        $stripRoot = null;
        $version = null;
        $paths = [];
        $document = null;
        $sourceIssue = null;
        try {
            if ($owner['kind'] === 'database') {
                $database = $this->requireRecordDatabase($owner['category']);
                $record = $database->getRecordByIndex($owner['index'])
                    ?? throw new SessionRefusal('That placement record is gone.');
                $type = CutsceneType::findByRecordCategory($owner['category']);
                if ($type !== null) {
                    $id = $this->workspace->cutscenes?->ids($type)[$owner['index']] ?? null;
                    $asset = ($id === null ? null : $this->workspace->cutscenes?->find($type, $id))
                        ?? throw new SessionRefusal('That placement asset is gone.');
                    $asset->assertSourcesUnchanged();
                    $paths = $asset->paths();
                    $sourcePath = $paths[array_key_last($paths)];
                    $stripRoot = 'commands';
                    $version = [spl_object_id($asset), $asset->stateVersion()];
                } else {
                    $sourcePath = $record->sourcePath ?? throw new SessionRefusal('This placement has no supported source-file owner.');
                    $paths = [$sourcePath];
                    $stripRoot = $database->schema->listPayloadKey;
                    $version = [spl_object_id($database), $database->getContentVersion(), $record->recordId];
                    // A late external rewrite is not part of the loaded record.
                    $record->file?->composeContents($record->file->payload, checkDisk: true);
                }
            } else {
                $map = $this->requireMap($owner['map']);
                $map->assertSourcesUnchanged();
                $sourcePath = $map->dataPath;
                $paths = [$sourcePath];
                $prefix = $owner['kind'] === 'npc' ? ['npcs', $owner['index']] : ['events', $owner['marker']];
            }
            if (!$hasPlacement) {
                return $rows;
            }
            $document = PhpArraySourceDocument::parse((string) file_get_contents($sourcePath));
        } catch (\Throwable $error) {
            if (!$hasPlacement) {
                throw new SessionRefusal('Authoring cannot safely read its current source: ' . $error->getMessage(), previous: $error);
            }
            $sourceIssue = 'Map placement cannot safely read its current source: ' . $error->getMessage();
        }
        $sources = [];
        foreach ($paths as $path) {
            $sources[$path] = is_file($path) ? hash_file('sha256', $path) : null;
        }
        foreach ($rows as &$row) {
            if (!isset($row['mapPlacement'])) {
                continue;
            }
            $placement = &$row['mapPlacement'];
            $placement['review'] = ['version' => $version, 'sources' => $sources];
            $sourcePaths = array_map(static function (array $path) use ($prefix, $stripRoot): array {
                if ($stripRoot !== null && ($path[0] ?? null) === $stripRoot) {
                    array_shift($path);
                }
                return [...$prefix, ...$path];
            }, $placement['sourcePaths'] ?? []);
            $issue = $sourceIssue ?? ($document === null ? null : MapPlacement::getSourceIssue($document, $sourcePaths));
            $placement['issue'] ??= $issue;
            unset($placement);
        }
        unset($row);
        return $rows;
    }
}
