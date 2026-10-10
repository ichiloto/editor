<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourceNode;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\ProjectWorkspace;
use Throwable;

/** Proves map-local subject deletion safe without rewriting any script owner. */
final readonly class WorldObjectReferences
{
    public function __construct(private ProjectWorkspace $workspace) {}

    public function describeReferences(ProjectMap $map, string $id): array
    {
        $found = $scripts = [];
        $cinematics = $this->workspace->cutscenes?->assets(CutsceneType::CINEMATIC) ?? [];
        $knownFiles = array_merge([], ...array_map(static fn($asset): array => $asset->paths(), $cinematics));
        foreach (glob($this->workspace->projectRoot . '/' . CutsceneType::CINEMATIC->relativeRoot() . '/*/*.php') ?: [] as $path) {
            if (!in_array($path, $knownFiles, true)) { $found[] = $path . ': authored source is unavailable in the current catalog; reload before deleting a subject'; }
        }
        $document = PhpArraySourceDocument::parse($map->captureLayerSnapshot()['source']);
        foreach (['events', 'npcs'] as $key) {
            self::describeOpaque($document->root()->entryFor($key)?->value, $map->dataPath . ':' . $key, $found);
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
                    self::describeOpaque($data->root()->entryFor($key)?->value, $asset->dataPath() . ':' . $key, $found);
                    self::describeTree($asset->data()[$key] ?? [], $id, $label . '.' . $key, $found, $scripts);
                }
                $source = $state['sourceTemplates'][$asset->type->partnerNoun()]['source'] ?? file_get_contents($asset->partnerPath());
                self::describeOpaque(PhpArraySourceDocument::parse((string) $source)->root(), $asset->partnerPath(), $found);
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
                    self::describeOpaque(PhpArraySourceDocument::parse((string) $source)->root(), $label, $found);
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

    private static function describeOpaque(?SourceNode $node, string $path, array &$found): void
    {
        if ($node === null || $node->kind === SourceNode::SCALAR) { return; }
        if ($node->kind !== SourceNode::ARRAY || $node->hasOpaqueKey) {
            $found[] = $path . ': opaque authored source; subject references cannot be proved';
            return;
        }
        foreach ($node->entries as $index => $entry) { self::describeOpaque($entry->value, $path . '.' . ($entry->key ?? $index), $found); }
    }

    private static function hasTransferTo(array $tree, string $mapId): bool
    {
        return (($tree['type'] ?? null) === 'transfer' && ($tree['map'] ?? null) === $mapId)
            || array_any($tree, static fn($value): bool => is_array($value) && self::hasTransferTo($value, $mapId));
    }
}
