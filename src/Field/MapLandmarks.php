<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Events\EventTypeCatalog;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Field\MapCategoryCatalog;
use Ichiloto\Engine\Field\MapLandmarkAnchors;
use Ichiloto\Engine\Field\MapLandmarkCatalog;
use Ichiloto\Engine\Field\MapLandmarkKind;
use InvalidArgumentException;

/**
 * A map's minimap landmarks, as authored under `landmarks` (Engine docs/maps.md, "Active-map landmarks and
 * discovery"). Each declaration names one source-owned anchor: an event marker, a world-object id or a legacy
 * transfer id, never its cells, so moving the anchor moves the landmark. Every change is checked by the Engine's own
 * catalog against the map as it stands, unsaved edits included; this model holds no rule of its own beyond choosing
 * what an author is offered.
 */
final readonly class MapLandmarks
{
    /** The map key holding the ordered declarations. */
    public const string KEY = 'landmarks';

    /** @param list<array<string, mixed>> $entries */
    private function __construct(public ProjectMap $map, public array $entries) {}

    /** @throws InvalidArgumentException When the authored block is not a list of declarations. */
    public static function readMap(ProjectMap $map): self
    {
        $block = $map->getMapDataField([self::KEY]) ?? [];
        if (!is_array($block) || !array_is_list($block) || array_any($block, static fn($entry): bool => !is_array($entry))) {
            throw new InvalidArgumentException('landmarks must be a list of declarations; it cannot be edited as rows.');
        }

        return new self($map, $block);
    }

    /**
     * The map as the Engine's map readers take it, unsaved edits included: its data with each placed event
     * resolved to its marker and cells (an event without cells has nothing to mark), and its composed logical cell
     * grid. Landmark anchors and atlas parents are both read from this one view.
     *
     * @return array{data: array<string, mixed>, tiles: array<int, array<int, string>>}
     * @throws InvalidArgumentException When world objects cannot be read.
     */
    public static function readResolvedSource(ProjectMap $map): array
    {
        $events = [];
        foreach ($map->getEventDefinitions() as $marker => $definition) {
            $area = $map->getEventArea((string) $marker);
            if ($area === null || !is_array($definition)) { continue; }
            $events[] = [...$definition, 'marker' => (string) $marker, 'area' => $area->toArray()];
        }
        $triggers = $map->getEditableData()['triggers'] ?? [];

        return ['data' => [...$map->getEditableData(), 'id' => $map->mapId, 'events' => $events,
            'worldObjects' => WorldObjectAuthoring::readEntries($map), 'triggers' => is_array($triggers) ? $triggers : []],
            'tiles' => $map->getLayerSet()->getComposedGrid()];
    }

    /**
     * What the map's landmarks may anchor to, read by the Engine's own adapter from the map as it stands. Only a
     * legacy transfer with a stable `id` can be chosen as an anchor.
     *
     * @throws InvalidArgumentException When world objects cannot be read.
     */
    public static function readAnchors(ProjectMap $map): MapLandmarkAnchors
    {
        return MapLandmarkAnchors::readMap(self::readResolvedSource($map)['data']);
    }

    /** @return list<int> The number of real cells on each row, so ragged maps keep their shape. */
    public static function readRowWidths(ProjectMap $map): array
    {
        return array_values(array_map(count(...), $map->getLayerSet()->getComposedGrid()));
    }

    /**
     * Checks declarations against the map as it stands, through the Engine's validator.
     *
     * @param list<array<string, mixed>> $entries
     * @param MapCategoryCatalog|null $categories The project's place categories; null accepts the Engine's generic ones only.
     * @throws InvalidArgumentException With the Engine's reason when the map would not load them.
     */
    public static function validateEntries(ProjectMap $map, array $entries, ?MapCategoryCatalog $categories = null): MapLandmarkCatalog
    {
        return MapLandmarkCatalog::readDeclarations($entries, self::readAnchors($map), self::readRowWidths($map), $categories);
    }

    /** Whether an event definition moves the player to another map: the only kind of event a route marks. */
    public static function isTransfer(array $definition): bool
    {
        return is_string($definition['class'] ?? null) && is_a($definition['class'], TransferPlayerTrigger::class, true);
    }

    public function findIndex(string $id): int
    {
        foreach ($this->entries as $index => $entry) { if (($entry['id'] ?? null) === $id) { return $index; } }
        throw new InvalidArgumentException("This map has no landmark {$id}.");
    }

    /** @param array<string, string> $anchor */
    public function findAnchorIndex(array $anchor): ?int
    {
        foreach ($this->entries as $index => $entry) {
            if (self::readAnchor($entry) === $anchor) { return $index; }
        }
        return null;
    }

    /**
     * An entry's anchor as `[kind => id]`, with an event marker as a string whatever PHP made of its key.
     *
     * @return array<string, string>|null
     */
    public static function readAnchor(array $entry): ?array
    {
        $anchor = $entry['anchor'] ?? null;
        if (!is_array($anchor) || count($anchor) !== 1) { return null; }
        $kind = array_key_first($anchor);
        $id = $anchor[$kind];
        return in_array($kind, ['event', 'object', 'trigger'], true) && (is_string($id) || is_int($id)) ? [$kind => (string) $id] : null;
    }

    /** A selector's value for an anchor: its kind, a colon, then its id (a marker or an id may itself hold one). */
    public static function encodeAnchor(array $anchor): string
    {
        $kind = (string) array_key_first($anchor);
        return $kind . ':' . $anchor[$kind];
    }

    /** @return array<string, string> */
    public static function decodeAnchor(string $value): array
    {
        [$kind, $id] = array_pad(explode(':', $value, 2), 2, '');
        if (!in_array($kind, ['event', 'object', 'trigger'], true) || $id === '') {
            throw new InvalidArgumentException('Choose a listed event, world object or legacy transfer.');
        }
        return [$kind => $id];
    }

    /**
     * Every anchor on the map with what an author reads for it, whether it is a transfer, and the landmark already
     * describing it, if any.
     *
     * @return list<array{anchor: array<string, string>, value: string, label: string, transfer: bool, landmark: ?string}>
     */
    public function describeAnchors(): array
    {
        $anchors = self::readAnchors($this->map);
        $claimed = [];
        foreach ($this->entries as $entry) {
            $anchor = self::readAnchor($entry);
            if ($anchor !== null && is_string($entry['id'] ?? null)) { $claimed[self::encodeAnchor($anchor)] = $entry['id']; }
        }
        $described = [];
        foreach ([['event', $anchors->events], ['object', $anchors->objects], ['trigger', $anchors->triggers]] as [$kind, $table]) {
            foreach ($table as $id => $anchor) {
                // A legacy transfer without an id is keyed by its position, which is no identity to keep.
                if ($kind === 'trigger' && str_starts_with((string) $id, '@')) { continue; }
                $reference = [$kind => (string) $id];
                $value = self::encodeAnchor($reference);
                $described[] = ['anchor' => $reference, 'value' => $value, 'label' => $this->describeAnchor($reference),
                    'transfer' => $anchor->isTransfer, 'landmark' => $claimed[$value] ?? null];
            }
        }
        return $described;
    }

    /** What an author reads for an anchor: an event by marker and type (and where a transfer leads), or an id. */
    public function describeAnchor(array $anchor): string
    {
        $kind = (string) array_key_first($anchor);
        $id = (string) $anchor[$kind];
        if ($kind === 'object') { return 'World object ' . $id; }
        if ($kind === 'trigger') { return 'Legacy transfer ' . $id; }
        $definition = $this->map->getEventDefinition($id);
        $type = EventTypeCatalog::describeClass(is_string($definition['class'] ?? null) ? $definition['class'] : null);
        $destination = $definition['data']['destinationMap'] ?? null;
        return sprintf('Event %s · %s%s', $id, $type, is_string($destination) && $destination !== '' ? ' to ' . $destination : '');
    }

    /**
     * The kinds a landmark on this anchor may be: a transfer is a route (passage, entrance or exit), anything else
     * a building or a landmark.
     *
     * @return list<MapLandmarkKind>
     */
    public function findKindsFor(array $anchor): array
    {
        return array_values(array_filter(MapLandmarkKind::cases(),
            fn(MapLandmarkKind $kind): bool => $kind->isRoute() === $this->isRouteAnchor($anchor)));
    }

    public function isRouteAnchor(array $anchor): bool
    {
        $kind = (string) array_key_first($anchor);
        if ($kind === 'trigger') { return true; }
        if ($kind === 'object') { return false; }
        $definition = $this->map->getEventDefinition((string) $anchor[$kind]);
        return is_array($definition) && self::isTransfer($definition);
    }

    /**
     * Describes a place: a declaration for an anchor no landmark describes yet. A route starts as the passage its
     * automatic marker already is, so describing it changes nothing on the minimap until an author edits it; any
     * other anchor starts as the kind chosen, or a landmark. Its id is the anchor's own stable id where that is a
     * valid landmark id, otherwise the next free `landmark-N`, and never changes afterwards: saves remember
     * discoveries by it.
     *
     * @return array{entries: list<array<string, mixed>>, id: string}
     */
    public function addLandmark(array $anchor, ?MapLandmarkKind $kind = null): array
    {
        if ($this->findAnchorIndex($anchor) !== null) {
            throw new InvalidArgumentException(sprintf('%s already anchors a landmark.', $this->describeAnchor($anchor)));
        }
        $kinds = $this->findKindsFor($anchor);
        $kind ??= $kinds[0] === MapLandmarkKind::PASSAGE ? MapLandmarkKind::PASSAGE : MapLandmarkKind::LANDMARK;
        if (!in_array($kind, $kinds, true)) {
            throw new InvalidArgumentException(sprintf('%s cannot be a %s.', $this->describeAnchor($anchor), $kind->value));
        }
        $id = $this->findUnusedId($anchor);
        return ['entries' => [...$this->entries, ['id' => $id, 'kind' => $kind->value, 'anchor' => $anchor]], 'id' => $id];
    }

    /**
     * Points a landmark at another anchor. Moving between a route and anything else changes what the landmark can
     * be, so its kind becomes what a new landmark there starts as; everything else it says is kept.
     *
     * @return list<array<string, mixed>>
     */
    public function setAnchor(int $index, array $anchor): array
    {
        $entries = $this->entries;
        $current = self::readAnchor($entries[$index]);
        if ($current === $anchor) { return $entries; }
        $other = $this->findAnchorIndex($anchor);
        if ($other !== null) {
            throw new InvalidArgumentException(sprintf('%s already anchors landmark %s.', $this->describeAnchor($anchor), $entries[$other]['id'] ?? $other));
        }
        $entries[$index]['anchor'] = $anchor;
        $kind = MapLandmarkKind::tryFrom((string) ($entries[$index]['kind'] ?? ''));
        if ($kind === null || !in_array($kind, $this->findKindsFor($anchor), true)) {
            $entries[$index]['kind'] = ($this->isRouteAnchor($anchor) ? MapLandmarkKind::PASSAGE : MapLandmarkKind::LANDMARK)->value;
        }
        return $entries;
    }

    /** @return list<array<string, mixed>> */
    public function setKind(int $index, MapLandmarkKind $kind): array
    {
        $entries = $this->entries;
        $entries[$index]['kind'] = $kind->value;
        return $entries;
    }

    /**
     * Says what a place offers (an inn, a shop, the project's own kinds), or removes the category when none is
     * chosen. A category is independent of the kind: an entrance into a shop is a route with the shop category.
     *
     * @return list<array<string, mixed>>
     */
    public function setCategory(int $index, ?string $category): array
    {
        $entries = $this->entries;
        if ($category === null || $category === '') { unset($entries[$index]['category']); }
        else { $entries[$index]['category'] = $category; }
        return $entries;
    }

    /**
     * Names a landmark, or removes its name when the text is blank. A first name takes the Engine's map-scoped
     * translation key; a name already keyed keeps its key.
     *
     * @return list<array<string, mixed>>
     */
    public function setName(int $index, string $text): array
    {
        $entries = $this->entries;
        if (trim($text) === '') {
            unset($entries[$index]['name']);
            return $entries;
        }
        $message = $entries[$index]['name']['message'] ?? MapLandmarkCatalog::getNameMessageKey($this->map->mapId, (string) $entries[$index]['id']);
        $entries[$index]['name'] = ['message' => $message, 'default' => $text];
        return $entries;
    }

    /** @return list<array<string, mixed>> */
    public function setNameMessage(int $index, string $message): array
    {
        $entries = $this->entries;
        if (!isset($entries[$index]['name'])) { throw new InvalidArgumentException('Name the landmark before choosing its translation key.'); }
        if (trim($message) === '') { throw new InvalidArgumentException('A named landmark needs a translation key.'); }
        $entries[$index]['name']['message'] = trim($message);
        return $entries;
    }

    /**
     * Makes a landmark secret or ordinary. A secret is revealed only explicitly, so it says nothing about approach;
     * an ordinary one is learned on approach at the Engine's distance until an author sets another.
     *
     * @return list<array<string, mixed>>
     */
    public function setSecret(int $index, bool $secret): array
    {
        $entries = $this->entries;
        if ($secret) {
            $entries[$index]['secret'] = true;
            unset($entries[$index]['approachDistance']);
        } else {
            unset($entries[$index]['secret']);
        }
        return $entries;
    }

    /**
     * Sets how near the party must come to learn a landmark: a distance in cells, or null when approach never
     * reveals it.
     *
     * @return list<array<string, mixed>>
     */
    public function setApproachDistance(int $index, ?int $distance): array
    {
        $entries = $this->entries;
        if (($entries[$index]['secret'] ?? false) === true) {
            throw new InvalidArgumentException('A secret landmark is revealed only explicitly; approach never reveals it.');
        }
        $entries[$index]['approachDistance'] = $distance;
        return $entries;
    }

    /**
     * Returns a landmark to the Engine's approach distance by saying nothing about it.
     *
     * @return list<array<string, mixed>>
     */
    public function resetApproachDistance(int $index): array
    {
        $entries = $this->entries;
        unset($entries[$index]['approachDistance']);
        return $entries;
    }

    /**
     * Sets the world conditions that reveal a landmark through play. An empty list removes them: the Engine reads a
     * declared empty list as an error, never as "always".
     *
     * @param list<array<string, mixed>> $conditions
     * @return list<array<string, mixed>>
     */
    public function setRevealConditions(int $index, array $conditions): array
    {
        $entries = $this->entries;
        if ($conditions === []) { unset($entries[$index]['revealConditions']); }
        else { $entries[$index]['revealConditions'] = array_values($conditions); }
        return $entries;
    }

    /** @return list<array<string, mixed>> */
    public function removeLandmark(int $index): array
    {
        $entries = $this->entries;
        array_splice($entries, $index, 1);
        return $entries;
    }

    /**
     * The landmarks that anchor to a source, which keep it from being deleted or from changing between a route and
     * anything else. A landmark block that cannot be read is reported, since its references cannot be proved.
     *
     * @param array<string, string> $anchor
     * @return list<string>
     */
    public static function describeAnchorReferences(ProjectMap $map, array $anchor): array
    {
        try {
            $landmarks = self::readMap($map);
        } catch (InvalidArgumentException $error) {
            return [sprintf('%s landmarks: %s', $map->mapId, $error->getMessage())];
        }
        $index = $landmarks->findAnchorIndex($anchor);
        return $index === null ? [] : [sprintf('%s landmark %s (%s)', $map->mapId, $landmarks->entries[$index]['id'] ?? $index,
            $landmarks->entries[$index]['kind'] ?? 'no kind')];
    }

    /**
     * Where landmarks name a place category: what keeps the category from being deleted.
     *
     * @param iterable<ProjectMap> $maps
     * @return list<string>
     */
    public static function describeCategoryReferences(iterable $maps, string $category): array
    {
        $found = [];
        foreach ($maps as $map) {
            try {
                $entries = self::readMap($map)->entries;
            } catch (InvalidArgumentException $error) {
                $found[] = sprintf('%s landmarks: %s', $map->mapId, $error->getMessage());
                continue;
            }
            foreach ($entries as $entry) {
                if (($entry['category'] ?? null) === $category) {
                    $found[] = sprintf('landmark %s on %s', $entry['id'] ?? '?', $map->mapId);
                }
            }
        }
        return $found;
    }

    /**
     * The landmark an anchor carries, when one does and its block can be read.
     *
     * @param array<string, string> $anchor
     * @return array<string, mixed>|null
     */
    public static function findAnchoredEntry(ProjectMap $map, array $anchor): ?array
    {
        try {
            $landmarks = self::readMap($map);
        } catch (InvalidArgumentException) {
            return null;
        }
        $index = $landmarks->findAnchorIndex($anchor);
        return $index === null ? null : $landmarks->entries[$index];
    }

    private function findUnusedId(array $anchor): string
    {
        $ids = array_column($this->entries, 'id');
        $own = (string) $anchor[array_key_first($anchor)];
        if (array_key_first($anchor) !== 'event' && preg_match(MapLandmarkCatalog::ID_PATTERN, $own) === 1 && !in_array($own, $ids, true)) {
            return $own;
        }
        for ($number = 1; ; $number++) {
            if (!in_array('landmark-' . $number, $ids, true)) { return 'landmark-' . $number; }
        }
    }
}
