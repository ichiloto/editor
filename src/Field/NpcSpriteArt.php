<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourceNode;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\MapSourceRefusal;

/** Optional NPC art uses the map's existing source writer, transaction and history. */
trait NpcSpriteArt
{
    public function setNpcGraphicalSprites(int $index, ?array $data): void
    {
        $npc = $this->getNpcs()->get($index);
        if ($npc === null) { throw new MapSourceRefusal('Select an existing NPC before editing artwork.'); }
        $document = PhpArraySourceDocument::parse($this->proposedDataSource());
        $node = $document->root();
        foreach (['npcs', $index, 'sprites2d'] as $key) {
            $this->assertNpcArtLiteral($node);
            $node = $node->entryFor($key)?->value;
            if ($node === null) { break; }
        }
        if ($node !== null) { $this->assertNpcArtLiteral($node, true); }
        if ($data !== null) {
            $set = (new DirectionalSpriteDraft($data))->validate(dirname($this->getMapsRoot()));
            $assets = ReferenceCatalog::getPngAssets(dirname($this->getMapsRoot(), 2));
            foreach (ProjectNpc::DIRECTIONS as $direction) {
                $definition = $set->$direction;
                if (! in_array($definition->asset, $assets, true)) {
                    throw new MapSourceRefusal('Choose each directional PNG through the project asset picker.');
                }
            }
        }
        $entries = $this->getNpcs()->toMapData();
        $entries[$index] = $npc->with('sprites2d', $data)->toArray();
        $this->setNpcs(NpcCollection::fromMapData($entries));
    }

    private function assertNpcArtLiteral(SourceNode $node, bool $recursive = false): void
    {
        $keys = array_map(static fn($entry, $index) => $entry->key ?? $index, $node->entries, array_keys($node->entries));
        if (in_array($node->kind, [SourceNode::VARIABLE, SourceNode::EXPRESSION], true) || $node->hasOpaqueKey
            || count(array_unique($keys, SORT_REGULAR)) !== count($keys)) {
            throw new MapSourceRefusal('NPC sprites2d requires literal, unambiguous source. Opaque expressions and variables are preserved, not rewritten.');
        }
        if ($recursive) {
            foreach ($node->entries as $entry) { $this->assertNpcArtLiteral($entry->value, true); }
        }
    }
}
