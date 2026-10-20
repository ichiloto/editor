<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Events\Triggers\CinematicEventTrigger;
use Ichiloto\Engine\Events\Triggers\DialogueEventTrigger;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;
use Ichiloto\Engine\Field\NpcPages;
use Ichiloto\Engine\Field\Validation\MapNpcInteractionRule;
use Ichiloto\Engine\Messaging\Dialogue\ConditionalDialogue;
use InvalidArgumentException;

/**
 * Plans moving a story event an NPC delivers onto that NPC as its first
 * page, which is what the Engine's `event.npc-interaction` rule asks for:
 * talking to the NPC then runs the beat, and the NPC shows its cue and
 * carries its waymark.
 *
 * The page keeps the event's conditions, writes, cue and action (its event
 * script, inline script, Cinematic or lines) and whether it runs once; the
 * event's other speakers become the page's staged speakers. Where the player
 * stood, which way they faced and the blocked message belonged to the
 * event's cells and are named as dropped. A one-shot event's saved
 * completion is carried to the page by a one-shot event alias
 * (`{map}:{marker}` to `{map}:{npcId}/{pageId}`), so old saves keep it.
 *
 * Planning changes nothing; {@see \Ichiloto\Editor\Session\EditorSession::adoptEventOnNpc()}
 * applies the plan as one undo step.
 *
 * @package Ichiloto\Editor\Field
 */
final class NpcEventAdoption
{
    /** Event fields that described its cells, not the beat. */
    private const array CELL_FIELDS = ['whenBlocked' => 'its blocked message', 'facing' => 'the facings it answered',
        'engagement' => 'how the player stood to it', 'area' => 'its cells'];

    /**
     * @param int $npcIndex The NPC's position in the map's list.
     * @param array<string, mixed> $entry The NPC entry with the new page first.
     * @param array<string, mixed> $page The new page.
     * @param list<string> $dropped What the event had that a page does not.
     * @param array{from: string, to: string}|null $alias The one-shot completion to carry, when the event ran once.
     */
    private function __construct(
        public readonly int $npcIndex,
        public readonly string $npcId,
        public readonly array $entry,
        public readonly array $page,
        public readonly array $dropped,
        public readonly ?array $alias,
    ) {
    }

    /**
     * @param string|null $npcId The NPC to own it; the event's first speaker when null.
     * @throws NpcRefusal When the event is not a story event an NPC can own, or the NPC cannot take it.
     */
    public static function plan(ProjectMap $map, string $marker, ?string $npcId = null): self
    {
        $event = $map->getEventDefinition($marker) ?? throw new NpcRefusal("{$map->mapId} has no event {$marker}.");
        $class = ltrim(strval($event['class'] ?? ''), '\\');
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        if (!array_any(MapNpcInteractionRule::PAGE_ACTION_TRIGGERS, static fn(string $type): bool => is_a($class, $type, true))) {
            throw new NpcRefusal("Event {$marker} is not a script, Cinematic or dialogue event, so no NPC page can run it.");
        }
        if (strtolower(trim(strval($data['mode'] ?? ''))) === ScriptEventTrigger::MODE_AUTO) {
            throw new NpcRefusal("Event {$marker} starts when the player arrives; talking to an NPC cannot start it.");
        }
        if (is_array($event['subject'] ?? null)) {
            throw new NpcRefusal("Event {$marker} belongs to the object or place it is bound to.");
        }
        $speakers = array_values(array_filter((array) ($event['speakers'] ?? []), is_string(...)));
        $npcId ??= $speakers[0] ?? throw new NpcRefusal("Event {$marker} names no speaker; choose the NPC who delivers it.");
        $npcs = $map->getNpcs();
        $index = $npcs->indexOfId($npcId) ?? throw new NpcRefusal("{$map->mapId} has no NPC {$npcId}.");
        $npc = $npcs->get($index) ?? throw new NpcRefusal("{$map->mapId} has no NPC {$npcId}.");
        if ($npc->getScript() !== [] && $npc->getDialogue() !== []) {
            throw new NpcRefusal("{$npc->getName()} has a script that replaces their dialogue; make them pages first.");
        }

        $page = [];
        $action = match (true) {
            is_a($class, CinematicEventTrigger::class, true) => ['cinematicId' => strval($data['cinematicId'] ?? '')],
            is_a($class, DialogueEventTrigger::class, true) => ['lines' => self::readLines($data, $marker)],
            is_array($data['script'] ?? null) && $data['script'] !== [] => ['script' => array_values($data['script'])],
            default => ['scriptId' => strval($data['scriptId'] ?? '')],
        };
        $runsOnce = ($data['reusable'] ?? true) === false;
        $existing = array_filter(array_map(static fn(array $page): ?string => is_string($page['id'] ?? null) ? $page['id'] : null,
            $npc->getPages()));
        $base = strval($action['scriptId'] ?? $action['cinematicId'] ?? '') ?: 'event-' . strtolower($marker);
        $id = $base;
        for ($n = 2; in_array($id, $existing, true); $n++) { $id = "{$base}-{$n}"; }
        if ($runsOnce || isset($action['scriptId']) || isset($action['cinematicId'])) { $page['id'] = $id; }
        foreach (['conditions', 'sets'] as $field) {
            if (is_array($event[$field] ?? null) && $event[$field] !== []) { $page[$field] = array_values($event[$field]); }
        }
        $page = [...$page, ...$action];
        if ($runsOnce) { $page['reusable'] = false; }
        $staged = array_values(array_diff($speakers, [$npcId]));
        if ($staged !== []) { $page['speakers'] = $staged; }
        if (is_array($event['cue'] ?? null) && trim(strval($event['cue']['symbol'] ?? '')) !== '') { $page['cue'] = $event['cue']; }

        $cue = is_array($npc->toArray()['cue'] ?? null) ? $npc->toArray()['cue'] : null;
        if ($cue !== null && $npc->hasDialogueVariants()) {
            throw new NpcRefusal("{$npc->getName()} has a cue beside pages; put it on the page it belongs to first.");
        }
        $pages = match (true) {
            // A top-level script replaces dialogue, so it becomes the page after the beat, keeping its cue.
            $npc->getScript() !== [] => [array_filter(['script' => $npc->getScript(), 'cue' => $cue], static fn(mixed $v): bool => $v !== null)],
            // The NPC's own cue moves with its only page, the one it described (getPages() carries it).
            $npc->getPages() === [] && $cue !== null => [['cue' => $cue]],
            default => $npc->getPages(),
        };
        $entry = $npc->toArray();
        unset($entry['script'], $entry['cue']);
        $entry = ProjectNpc::storePages($entry, [$page, ...$pages]);
        try {
            NpcPages::fromEntry($entry);
        } catch (InvalidArgumentException $error) {
            throw new NpcRefusal("{$npc->getName()} cannot take event {$marker} as a page: {$error->getMessage()}", previous: $error);
        }

        $dropped = [];
        foreach (self::CELL_FIELDS as $field => $what) {
            if (array_key_exists($field, $event) && $event[$field] !== [] && $event[$field] !== null) { $dropped[] = $what; }
        }

        return new self($index, $npcId, $entry, $page, $dropped, $runsOnce ? [
            'from' => "{$map->mapId}:{$marker}",
            'to' => "{$map->mapId}:" . NpcPages::getCompletionMarker($npcId, $id),
        ] : null);
    }

    /** @return list<array<string, mixed>> */
    private static function readLines(array $data, string $marker): array
    {
        $dialogue = array_values(array_filter((array) ($data['dialogue'] ?? []), is_array(...)));
        if (ConditionalDialogue::isVariantList($dialogue)) {
            throw new NpcRefusal("Event {$marker} speaks conditional variants; move them onto the NPC as pages by hand.");
        }

        return $dialogue;
    }
}
