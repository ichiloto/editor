<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourceNode;
use Ichiloto\Editor\Database\ProjectRecord;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Throwable;

/** Proves map-local subject deletion safe without rewriting any script owner. */
final readonly class WorldObjectReferences
{
    public function __construct(private ProjectWorkspace $workspace) {}

    public function describeReferences(ProjectMap $map, string $id): array
    {
        $found = MapLandmarks::describeAnchorReferences($map, ['object' => $id]);
        $scripts = [];
        $cinematics = $this->workspace->cutscenes?->assets(CutsceneType::CINEMATIC) ?? [];
        $knownFiles = array_merge([], ...array_map(static fn($asset): array => $asset->paths(), $cinematics));
        foreach (glob($this->workspace->projectRoot . '/' . CutsceneType::CINEMATIC->relativeRoot() . '/*/*.php') ?: [] as $path) {
            if (!in_array($path, $knownFiles, true)) { $found[] = $path . ': authored source is unavailable in the current catalog; reload before deleting a subject'; }
        }
        $document = PhpArraySourceDocument::parse($map->captureLayerSnapshot()['source']);
        $loaded = ['events' => $map->getEventDefinitions(),
            'npcs' => array_map(static fn($npc): array => $npc->toArray(), array_values($map->getNpcs()->all()))];
        foreach (['events', 'npcs'] as $key) {
            self::describeOpaque($document->root()->entryFor($key)?->value, $map->dataPath . ':' . $key, $found, ['value' => $loaded[$key]]);
        }
        foreach ($map->getEventDefinitions() as $marker => $event) {
            self::describeTree($event, $id, $map->mapId . ' event ' . $marker, $found, $scripts);
        }
        foreach ($map->getNpcs()->all() as $npc) {
            self::describeTree($npc->toArray(), $id, $map->mapId . ' NPC ' . ($npc->getId() ?? $npc->getName()), $found, $scripts);
        }
        foreach ($cinematics as $asset) {
            if ($asset->isDeleted()) { continue; }
            $label = 'cinematic ' . $asset->id;
            try {
                $asset->assertSourcesUnchanged();
                $state = $asset->captureEditState();
                $dataSource = $state['sourceTemplates']['data']['source'] ?? file_get_contents($asset->dataPath());
                $data = PhpArraySourceDocument::parse((string) $dataSource);
                $start = $data->root()->entryFor('startMap')?->value;
                if ($start === null || $start->kind !== SourceNode::SCALAR) {
                    $found[] = $label . ': startMap ownership is not safely provable';
                    continue;
                }
                $commands = $asset->commands();
                if (($asset->data()['startMap'] ?? null) !== $map->mapId && !self::hasTransferTo($commands, $map->mapId)) { continue; }
                foreach (['cast', 'finalizer'] as $key) {
                    self::describeOpaque($data->root()->entryFor($key)?->value, $asset->dataPath() . ':' . $key, $found,
                        array_key_exists($key, $asset->data()) ? ['value' => $asset->data()[$key]] : null);
                    self::describeTree($asset->data()[$key] ?? [], $id, $label . '.' . $key, $found, $scripts);
                }
                $source = $state['sourceTemplates'][$asset->type->partnerNoun()]['source'] ?? file_get_contents($asset->partnerPath());
                self::describeOpaque(PhpArraySourceDocument::parse((string) $source)->root(), $asset->partnerPath(), $found, ['value' => $commands]);
                self::describeTree($commands, $id, $label . '.commands', $found, $scripts);
            } catch (Throwable $error) {
                $found[] = $label . ': opaque authored source; references cannot be proved (' . $error->getMessage() . ')';
            }
        }
        $database = $this->workspace->getRecordDatabase('common_events');
        $visited = [];
        while (($pending = array_diff_key($scripts, $visited)) !== []) {
            foreach ($pending as $scriptId => $via) {
                $visited[$scriptId] = true;
                $record = array_find($database?->getRecords() ?? [], static fn($record): bool => ($record->toArray()['__scriptId'] ?? null) === $scriptId);
                $label = 'event script ' . $scriptId . ' (via ' . $via . ')';
                if ($record === null) { $found[] = $label . ': references are unavailable'; continue; }
                try {
                    $database->assertUnchangedSource();
                    $source = file_get_contents($record->sourcePath ?? $database->backingFilePath());
                    self::describeOpaque($root = PhpArraySourceDocument::parse((string) $source)->root(), $label, $found,
                        self::readLoadedRecord($record, $database->schema, $root));
                    self::describeTree((array) $record->toArray(), $id, $label, $found, $scripts);
                } catch (Throwable $error) {
                    $found[] = $label . ': authored references cannot be proved (' . $error->getMessage() . ')';
                }
            }
        }
        return array_values(array_unique($found));
    }

    private static function describeTree(mixed $tree, string $id, string $path, array &$found, array &$scripts): void
    {
        if (!is_array($tree)) { return; }
        if (($tree['kind'] ?? null) === 'world_object' && ($tree['id'] ?? null) === $id) { $found[] = $path; }
        $script = ($tree['type'] ?? null) === 'common_event' ? ($tree['id'] ?? null) : ($tree['scriptId'] ?? null);
        if (is_string($script) && $script !== '') { $scripts[$script] = $path; }
        foreach ($tree as $key => $value) { self::describeTree($value, $id, $path . '.' . $key, $found, $scripts); }
    }

    /**
     * Reports authored source a reference cannot be proved absent from.
     * Literal arrays and scalars are read as written. An expression or a
     * variable (an enum case's value, a script included with `require`, a
     * helper the file defines) is proved by what it loaded as: when that
     * value is plain data, scalars and arrays alone, the loaded definitions
     * the references are read from hold all of it. A loaded value holding a
     * closure or another object may yield more when the game runs it, and a
     * node whose loaded value cannot be found is never proved.
     *
     * @param array{value: mixed}|null $loaded What the node loaded as, when known.
     */
    private static function describeOpaque(?SourceNode $node, string $path, array &$found, ?array $loaded): void
    {
        if ($node === null || $node->kind === SourceNode::SCALAR) { return; }
        if ($node->kind !== SourceNode::ARRAY && $loaded !== null && self::isPlainData($loaded['value'])) { return; }
        if ($node->kind !== SourceNode::ARRAY || $node->hasOpaqueKey) {
            $found[] = $path . ': opaque authored source; subject references cannot be proved';
            return;
        }
        $value = $loaded['value'] ?? null;
        foreach ($node->entries as $index => $entry) {
            $key = $entry->key ?? $index;
            self::describeOpaque($entry->value, $path . '.' . $key, $found,
                is_array($value) && array_key_exists($key, $value) ? ['value' => $value[$key]] : null);
        }
    }

    /**
     * What a record's own file loaded as, to set beside that file's source:
     * an event script's bare command list, which the record wraps under its
     * list key, or the record itself. A record read from a file shared with
     * others has no source of its own to set it beside.
     *
     * @return array{value: mixed}|null
     */
    private static function readLoadedRecord(ProjectRecord $record, RecordSchema $schema, SourceNode $root): ?array
    {
        if ($record->sourcePath === null) { return null; }
        if ($schema->listPayloadKey === null) { return ['value' => $record->toArray()]; }
        // The wrapped list is re-indexed, so only a list written without keys lines up with its source.
        return $root->kind === SourceNode::ARRAY && $root->isList && !$root->hasOpaqueKey
            ? ['value' => $record->toArray()[$schema->listPayloadKey] ?? null] : null;
    }

    private static function isPlainData(mixed $value): bool
    {
        return is_array($value) ? array_all($value, self::isPlainData(...)) : !is_object($value) && !is_resource($value);
    }

    private static function hasTransferTo(array $tree, string $mapId): bool
    {
        return (($tree['type'] ?? null) === 'transfer' && ($tree['map'] ?? null) === $mapId)
            || array_any($tree, static fn($value): bool => is_array($value) && self::hasTransferTo($value, $mapId));
    }
}
