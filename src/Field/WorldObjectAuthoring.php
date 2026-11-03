<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Field;

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordAuthoring;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordFieldCodec;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordStorage;
use Ichiloto\Editor\Database\RecordSubList;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\History\Command;
use Ichiloto\Editor\History\GenericCommand;
use Ichiloto\Editor\Inspector\InputControlType;
use Ichiloto\Editor\MapSourceRefusal;
use Ichiloto\Editor\ProjectMap;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourceNode;
use Ichiloto\Engine\Animations\Field\FieldPresentationCatalog;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapPhysicalOccupancy;
use Ichiloto\Engine\Field\WorldObjectDefinition;
use Ichiloto\Engine\Rendering\Sprites\FieldSpriteRole;
use Throwable;
use InvalidArgumentException;

/** A transient record view. Only the map owns or persists these declarations. */
final readonly class WorldObjectAuthoring
{
    /** The event subject kind that names a world object, one of {@see \Ichiloto\Engine\Events\MapEventDefinition::SUBJECT_KINDS}. */
    public const string SUBJECT_KIND = 'world_object';

    public function __construct(public ProjectMap $map, private ReferenceCatalog $references) {}

    public static function readEntries(ProjectMap $map): array
    {
        $entries = $map->getMapDataField([WorldObjectDefinition::DATA_KEY]) ?? [];
        if (!is_array($entries) || !array_is_list($entries) || array_any($entries, static fn($entry): bool => !is_array($entry))) {
            throw new InvalidArgumentException('worldObjects requires literal object records in a list; it cannot be flattened.');
        }
        return $entries;
    }

    /**
     * The attachments a map's world object offers: those its resource declares, as the map's field catalogue holds
     * them, with the object's anchor that their cells are relative to. Null for an inline object, an unknown one or
     * a map or catalogue that cannot be read; validation reports why.
     *
     * @return array{anchor: array{x: int, y: int}, attachments: list<\Ichiloto\Engine\Field\WorldObjectAttachment>}|null
     */
    public static function findAttachments(ProjectMap $map, string $objectId): ?array
    {
        try {
            $entry = array_find(self::readEntries($map), static fn(array $entry): bool => ($entry['id'] ?? null) === $objectId);
            $resource = is_string($entry['resource'] ?? null) ? $map->getFieldCatalog()->getResource($entry['resource']) : null;
        } catch (InvalidArgumentException|MapSourceRefusal) {
            return null;
        }
        $anchor = $entry['anchor'] ?? null;
        if ($resource === null || !is_int($anchor['x'] ?? null) || !is_int($anchor['y'] ?? null)) {
            return null;
        }
        return ['anchor' => ['x' => $anchor['x'], 'y' => $anchor['y']], 'attachments' => $resource->attachments];
    }

    /**
     * The glyph and tile coverage of a map's world objects at rest, as the field applies it: an object covers its
     * cells only while it can be drawn, so its base role's current frame resolves, or its sign board can be read.
     * An object without art covers its cells by design. Validation uses it to judge what the field still shows as
     * glyphs; a map whose objects cannot be read covers nothing, as its own validation reports why.
     *
     * @return array<string, array<string, array<int, array<int, true>>>>
     */
    public static function readRestingCoverage(ProjectMap $map): array
    {
        try {
            $definitions = self::validateEntries($map, self::readEntries($map));
        } catch (InvalidArgumentException|MapSourceRefusal) {
            return [];
        }
        $coverage = [];
        foreach ($definitions as $object) {
            $sign = $object->resource?->sign;
            if ($sign !== null) {
                try {
                    $sign->getBoard($map->getAssetRoot());
                } catch (Throwable) {
                    continue;
                }
            } elseif ($object->sprites !== null) {
                $role = new FieldSpriteRole($object->sprites, $map->getAssetRoot(), 'World-object coverage ' . $object->id);
                try {
                    if ($role->getFrame($object->pivot) === null) {
                        continue;
                    }
                } finally {
                    $role->release();
                }
            }
            $coverage = array_replace_recursive($coverage, self::readOwnedCoverage($object));
        }

        return $coverage;
    }

    /**
     * The coverage an object applies while it draws, as the field's projection applies it: an object presented by
     * its tiles alone keeps its covered glyphs to the terminal and removes only the source tiles it owns.
     *
     * @return array<string, array<string, array<int, array<int, true>>>>
     */
    public static function readOwnedCoverage(WorldObjectDefinition $object): array
    {
        $coverage = $object->coverage;
        if ($object->tilePresentation !== [] && $object->sprites === null) { unset($coverage['glyphs']); }
        return $coverage;
    }

    /**
     * The graphical tiles the map's world objects present, by tile layer, row and column, merged as the field
     * merges available objects' contributions before shaping and shadows.
     *
     * @param list<WorldObjectDefinition> $objects
     * @return array<string, array<int, array<int, int>>>
     */
    public static function mergeTilePresentation(array $objects): array
    {
        $tiles = [];
        foreach ($objects as $object) {
            foreach ($object->tilePresentation as $name => $rows) {
                foreach ($rows as $y => $cells) { $tiles[$name][$y] = ($tiles[$name][$y] ?? []) + $cells; }
            }
        }
        return $tiles;
    }

    public static function validateEntries(ProjectMap $map, array $entries, bool $complete = true): array
    {
        $owners = array_column($map->describeTileLayers()['layers'], 'owner', 'name');
        if (!$complete) {
            // Empty conditions are an unfinished authoring draft, never a runtime default.
            foreach ($entries as &$entry) {
                // A placement of a named resource has no variants of its own to finish.
                if (array_key_exists('resource', $entry)) { continue; }
                $variants = $entry['variants'] ?? [];
                if (!is_array($variants) || !array_is_list($variants) || count($variants) > WorldObjectDefinition::MAX_VARIANTS) {
                    throw new InvalidArgumentException('World-object variants exceed the Engine list contract.');
                }
                $ids = [];
                foreach ($variants as $variant) {
                    if (!is_array($variant) || array_diff(array_keys($variant), ['id', 'conditions', 'sprites2d', 'terminal']) !== []
                        || !array_key_exists('sprites2d', $variant) || !array_key_exists('conditions', $variant)) {
                        throw new InvalidArgumentException('A variant requires id, conditions and explicit sprites2d.');
                    }
                    $checked = WorldObjectDefinition::readMap(['worldObjects' => [[
                        'id' => $variant['id'] ?? null, 'anchor' => $entry['anchor'], 'pivot' => $entry['pivot'], 'sprites2d' => $variant['sprites2d'],
                        // A footprint colour recolours the parent's covered glyphs, so the variant is checked with them.
                        ...(isset($entry['covers']) ? ['covers' => $entry['covers']] : []),
                        ...(array_key_exists('terminal', $variant) ? ['terminal' => $variant['terminal']] : []),
                    ]]], $map->getLayerSet(), tileOwners: $owners);
                    $id = $checked[0]->id;
                    if (isset($ids[$id])) { throw new InvalidArgumentException('Duplicate world-object variant id.'); }
                    $ids[$id] = true;
                }
                $entry['variants'] = array_values(array_filter($entry['variants'] ?? [], static fn($variant): bool => ($variant['conditions'] ?? null) !== []));
            }
            unset($entry);
        }
        return WorldObjectDefinition::readMap([WorldObjectDefinition::DATA_KEY => $entries], $map->getLayerSet(), tileOwners: $owners,
            catalog: self::loadCatalog($map, $entries));
    }

    /**
     * The project's named field resources, read as the Engine reads them when a map names one: only then, so a map
     * of inline objects never depends on the catalogue.
     */
    private static function loadCatalog(ProjectMap $map, array $entries): ?FieldPresentationCatalog
    {
        return array_any($entries, static fn($entry): bool => is_array($entry) && array_key_exists('resource', $entry))
            ? $map->getFieldCatalog() : null;
    }

    public function getSourceIssue(): ?string
    {
        return self::readSourceIssue($this->map);
    }

    public static function readSourceIssue(ProjectMap $map): ?string
    {
        try {
            $document = PhpArraySourceDocument::parse($map->captureLayerSnapshot()['source']);
            $node = $document->root()->entryFor(WorldObjectDefinition::DATA_KEY)?->value;
            $isLiteral = static function (SourceNode $node) use (&$isLiteral): bool {
                if ($node->kind === SourceNode::SCALAR) { return true; }
                return $node->kind === SourceNode::ARRAY && !$node->hasOpaqueKey
                    && array_all($node->entries, static fn($entry): bool => $isLiteral($entry->value));
            };
            return $node === null || $isLiteral($node) ? null : 'World-object edits require literal declarations; expressions and spread arrays are preserved, not rewritten.';
        } catch (\RuntimeException $error) { return $error->getMessage(); }
    }

    public function getRecords(): ProjectRecordDatabase
    {
        $entries = self::readEntries($this->map);
        $variants = new RecordSubList(key: 'variants', prefix: 'variant', singular: 'variant', fields: [],
            blank: ['id' => $this->getUnusedId(array_merge(...array_map(static fn($entry): array => $entry['variants'] ?? [], $entries)), 'variant'),
                'conditions' => [], 'sprites2d' => null], heading: 'Ordered Variants', removeWhenEmpty: true,
            getFields: static fn(array $entry): array => [new RecordField('id', 'Variant Id'),
                new RecordField('conditions', 'When (all conditions)', codec: RecordFieldCodec::CONDITIONS),
                ...FieldSpriteFields::getFields($entry, true), ...self::getTerminalFields($entry, true)],
            prepareEdit: fn(array $entry, string $field): array => FieldSpriteFields::prepareEdit($this->prepareTerminalEdit($entry, $field), $field, true));
        $cells = new RecordSubList(key: 'covers.cells', prefix: 'cell', singular: 'covered cell',
            fields: [new RecordField('x', 'X', InputControlType::INTEGER), new RecordField('y', 'Y', InputControlType::INTEGER)],
            blank: ['x' => 0, 'y' => 0], heading: 'Covered Cells');
        $tiles = new RecordSubList(key: WorldObjectTileFields::DATA_KEY, prefix: 'objectTile', singular: 'graphical tile',
            fields: WorldObjectTileFields::getFields($this->map), blank: [], heading: 'Graphical Tiles', removeWhenEmpty: true);
        $signs = self::readSignResources($this->map);
        $schema = new RecordSchema(key: 'world_objects', entryNoun: 'world object', storage: RecordStorage::MAP_OWNED,
            relativePath: '', fields: [], labelKey: 'id',
            fieldsFor: function (array $entry) use ($signs): array {
                // A placement of a named resource takes its art and pivot from the resource; it chooses which one, and a
                // sign placement the text its board shows: a translatable message, and the text where none is given.
                // An object with its own art may become a placement by choosing a resource, and a placement choosing
                // none goes back to its own art.
                $resource = new RecordField('resource', 'Field Resource', reference: 'field_resources', allowsNone: true,
                    removeWhenEmpty: true, displayDefault: '(its own art)');
                $presentation = array_key_exists('resource', $entry)
                    ? [$resource,
                        ...(isset($signs[$entry['resource']]) ? [new RecordField('label.default', 'Sign Text'),
                            new RecordField('label.message', 'Sign Text Translation Key')] : [])]
                    : [$resource,
                        FieldResourceFields::getAppearanceField(),
                        new RecordField('pivot.x', 'Image Pivot X (0..1)', InputControlType::FLOAT),
                        new RecordField('pivot.y', 'Image Pivot Y (0..1)', InputControlType::FLOAT),
                        ...FieldSpriteFields::getFields($entry, true)];
                $fields = [new RecordField('id', 'Stable Map-local Id', isReadOnly: true),
                    new RecordField('anchor.x', 'Anchor X', InputControlType::INTEGER),
                    new RecordField('anchor.y', 'Anchor Y', InputControlType::INTEGER),
                    // Every placement, resource or inline, may show only while saved world state holds; none means always.
                    new RecordField('conditions', 'Visible When (all conditions)', removeWhenEmpty: true,
                        codec: RecordFieldCodec::CONDITIONS, displayDefault: '(always)'),
                    ...$presentation,
                    ...self::getTerminalFields($entry, false),
                    new RecordField('covers.layer', 'Covered Gameplay Layer', options: ['', ...array_column(array_filter($this->map->getLayers(),
                        static fn($layer): bool => !$layer['decoration'] && $layer['id'] !== \Ichiloto\Editor\Maps\MapLayers::EVENT), 'name')],
                        removeWhenEmpty: true, displayDefault: '(no coverage)')];
                if (isset($entry['covers'])) {
                    $fields[] = new RecordField('covers.tileLayers', 'Covered Tile Layers', codec: RecordFieldCodec::CSV_LIST,
                        reference: 'world_object_tile_layers', allowsNone: true);
                }
                return $fields;
            },
            subLists: [$variants, $cells, $tiles],
            // Any placement, inline or of a resource, may present map-owned tiles on the cells it owns.
            subListsFor: static fn(array $entry): array => [...(array_key_exists('resource', $entry) ? [] : ['variants']),
                ...(isset($entry['covers']) ? ['covers.cells'] : []), WorldObjectTileFields::DATA_KEY],
            prepareEdit: function (array $entry, string $field) use ($signs): array {
                if ($field === 'resource') {
                    // A sign placement needs its label and only a sign accepts one; the board's name starts it.
                    $sign = $signs[$entry['resource'] ?? ''] ?? null;
                    if ($sign === null) { unset($entry['label']); }
                    else { $entry['label'] ??= self::makeLabel((string) $entry['id'], $sign); }
                    // A placement's art and pivot are its resource's; without one, the object holds its own, as a new one does.
                    if (array_key_exists('resource', $entry)) {
                        unset($entry['pivot'], $entry['sprites2d'], $entry['variants'], $entry['appearance']);
                    } else {
                        $entry['pivot'] ??= ['x' => .5, 'y' => 1.0];
                        $entry['sprites2d'] ??= null;
                    }
                }
                if ($field === 'covers.layer') {
                    if (!isset($entry['covers']['layer'])) { unset($entry['covers']); }
                    else {
                        $entry['covers']['cells'] ??= [['x' => $entry['anchor']['x'], 'y' => $entry['anchor']['y']]];
                        $entry['covers']['tileLayers'] ??= [];
                    }
                }
                return FieldSpriteFields::prepareEdit($this->prepareTerminalEdit($entry, $field), $field, true);
            });
        foreach ($entries as &$entry) {
            if (isset($entry['covers'])) {
                $entry['covers']['cells'] = array_map(static fn($cell): array => ['x' => $cell[0], 'y' => $cell[1]], $entry['covers']['cells']);
            }
            $entry = WorldObjectTileFields::readEntry($entry);
            $entry = self::readTerminalChoice($entry, false);
            foreach ($entry['variants'] ?? [] as $index => $variant) { $entry['variants'][$index] = self::readTerminalChoice($variant, true); }
        }
        unset($entry);
        $records = ProjectRecordDatabase::overOwnedList($schema, $this->map->dataPath, $entries, static function (): void {}, graphical: true);
        $records->useAuthoringReferences($this->references);
        return $records;
    }

    /** How an object or a variant presents in the Terminal, as an author chooses it. */
    private const string TERMINAL_MAP_GLYPH = "map's own glyph";
    private const string TERMINAL_INHERIT = "the object's";
    private const string TERMINAL_SYMBOL = 'a symbol at the anchor';
    private const string TERMINAL_COLOUR = 'its covered glyphs recoloured';
    /** The terminal's named colours; an authored colour outside them stays selectable as written. */
    private const array TERMINAL_COLOURS = ['white', 'yellow', 'red', 'green', 'blue', 'magenta', 'cyan', 'gray', 'black',
        'bright-white', 'bright-yellow', 'bright-red', 'bright-green', 'bright-blue', 'bright-magenta', 'bright-cyan'];

    /**
     * The Terminal presentation as the record shows it: which kind it is, and its symbol or colour. An object without
     * one shows the map's glyph; a variant without one shows its object's, and an explicit null the map's glyph again.
     * Stored data is written back exactly ({@see writeTerminalChoice()}): a string symbol, or `['color' => ...]`.
     */
    private static function readTerminalChoice(array $entry, bool $variant): array
    {
        $exists = array_key_exists('terminal', $entry);
        $terminal = $entry['terminal'] ?? null;
        $entry['terminal'] = match (true) {
            !$exists => ['mode' => $variant ? self::TERMINAL_INHERIT : self::TERMINAL_MAP_GLYPH],
            is_string($terminal) => ['mode' => self::TERMINAL_SYMBOL, 'symbol' => $terminal],
            is_array($terminal) => ['mode' => self::TERMINAL_COLOUR, 'color' => (string) ($terminal['color'] ?? '')],
            default => ['mode' => self::TERMINAL_MAP_GLYPH],
        };
        return $entry;
    }

    private static function writeTerminalChoice(array $entry, bool $variant): array
    {
        $choice = $entry['terminal'] ?? null;
        unset($entry['terminal']);
        if (!is_array($choice) || !isset($choice['mode'])) { return $entry; }
        return match ($choice['mode']) {
            self::TERMINAL_SYMBOL => [...$entry, 'terminal' => (string) ($choice['symbol'] ?? '')],
            self::TERMINAL_COLOUR => [...$entry, 'terminal' => ['color' => (string) ($choice['color'] ?? '')]],
            // Inheriting is the absence of the key; a variant's map glyph is its explicit null.
            self::TERMINAL_MAP_GLYPH => $variant ? [...$entry, 'terminal' => null] : $entry,
            default => $entry,
        };
    }

    /** @return list<RecordField> */
    private static function getTerminalFields(array $entry, bool $variant): array
    {
        $choice = is_array($entry['terminal'] ?? null) ? $entry['terminal'] : [];
        $modes = $variant ? [self::TERMINAL_INHERIT, self::TERMINAL_MAP_GLYPH, self::TERMINAL_SYMBOL, self::TERMINAL_COLOUR]
            : [self::TERMINAL_MAP_GLYPH, self::TERMINAL_SYMBOL, self::TERMINAL_COLOUR];
        $fields = [new RecordField('terminal.mode', 'Terminal Shows', options: $modes)];
        if (($choice['mode'] ?? null) === self::TERMINAL_SYMBOL) {
            $fields[] = new RecordField('terminal.symbol', 'Terminal Symbol (one styled cell)');
        }
        if (($choice['mode'] ?? null) === self::TERMINAL_COLOUR) {
            $colour = (string) ($choice['color'] ?? '');
            $fields[] = new RecordField('terminal.color', 'Terminal Colour',
                options: array_values(array_unique([...self::TERMINAL_COLOURS, ...($colour === '' ? [] : [$colour])])));
        }
        return $fields;
    }

    /**
     * A new kind starts from what the cell shows now: the anchor's own glyph as the symbol, and white as the colour,
     * the Terminal's ordinary text; the author changes either at once.
     */
    private function prepareTerminalEdit(array $entry, string $field): array
    {
        if ($field !== 'terminal.mode') { return $entry; }
        $mode = $entry['terminal']['mode'] ?? null;
        if ($mode === self::TERMINAL_SYMBOL && !isset($entry['terminal']['symbol'])) {
            $anchor = $entry['anchor'] ?? null;
            $glyph = is_array($anchor) ? ($this->map->getLayerSet()->getComposedGrid()[$anchor['y']][$anchor['x']] ?? ' ') : ' ';
            $glyph = \Ichiloto\Engine\IO\Console\TerminalText::stripAnsi($glyph);
            $entry['terminal']['symbol'] = '<fg=white>' . (trim($glyph) === '' ? '*' : $glyph) . '</>';
        }
        if ($mode === self::TERMINAL_COLOUR && !isset($entry['terminal']['color'])) { $entry['terminal']['color'] = 'white'; }
        return $entry;
    }

    /**
     * Creates an object at a cell: an inline one with no art yet, or a placement of a named resource. A sign
     * placement is given the text its board shows, under a translation key of its own id.
     */
    public function createObject(string $id, int $x, int $y, ?string $resource = null, ?string $text = null): ?Command
    {
        $entries = self::readEntries($this->map);
        if ($resource === null) {
            if ($text !== null) { throw new InvalidArgumentException('Only a sign placement shows text.'); }
            $entries[] = ['id' => $id, 'anchor' => ['x' => $x, 'y' => $y], 'pivot' => ['x' => .5, 'y' => 1.0], 'sprites2d' => null];
            return $this->writeEntries($entries, 'Create world object');
        }
        $sign = self::readSignResources($this->map)[$resource] ?? null;
        $entry = ['id' => $id, 'resource' => $resource, 'anchor' => ['x' => $x, 'y' => $y]];
        if ($sign !== null) {
            if ($text === null || trim($text) === '') { throw new InvalidArgumentException('A sign needs the text it shows.'); }
            $entry['label'] = ['message' => self::getLabelMessage($id), 'default' => $text];
        } elseif ($text !== null) {
            throw new InvalidArgumentException('Only a sign placement shows text.');
        }
        $entries[] = $entry;
        return $this->writeEntries($entries, $sign === null ? 'Place field resource' : 'Place sign');
    }

    /** A sign placement's translation key: its own map-local id under signs, so translators find each sign. */
    public static function getLabelMessage(string $id): string
    {
        return 'signs.' . $id;
    }

    private static function makeLabel(string $id, string $name): array
    {
        return ['message' => self::getLabelMessage($id), 'default' => $name];
    }

    /**
     * The project's sign resources, by id, with their names: the ones a placement labels. A catalogue that cannot be
     * read offers none; validation says why.
     *
     * @return array<string, string>
     */
    private static function readSignResources(ProjectMap $map): array
    {
        try {
            $catalog = $map->getFieldCatalog();
        } catch (\Throwable) {
            return [];
        }
        $signs = [];
        foreach ($catalog->resources as $id => $resource) {
            if ($resource->sign !== null) { $signs[(string) $id] = $resource->name; }
        }
        return $signs;
    }

    public function deleteObject(string $id): ?Command
    {
        $entries = self::readEntries($this->map);
        $index = $this->getIndex($id);
        $this->assertObjectUnreferenced($id);
        array_splice($entries, $index, 1);
        $command = $this->writeEntries($entries, 'Delete world object');
        if ($command === null) { return null; }
        return new GenericCommand('Delete world object', function () use ($id, $command): void {
            $this->assertObjectUnreferenced($id);
            $command->execute();
        }, static fn() => $command->undo());
    }

    private function assertObjectUnreferenced(string $id): void
    {
        $references = $this->references->describeWorldObjectReferences($this->map, $id);
        if ($references !== []) {
            throw new MapSourceRefusal("Cannot delete world object {$id}; references or unproven authored sources remain:\n" . implode("\n", $references));
        }
    }

    public function getIndex(string $id): int
    {
        foreach (self::readEntries($this->map) as $index => $entry) { if (($entry['id'] ?? null) === $id) { return $index; } }
        throw new InvalidArgumentException('That map-local world object no longer exists.');
    }

    public function applyField(string $id, string $field, string $value): ?Command
    {
        $records = $this->getRecords();
        $index = $this->getIndex($id);
        $fields = $records->getSettingsFields($index);
        $row = array_find($fields, static fn($row): bool => ($row['field'] ?? null) === $field);
        if ($row === null || ($row['editable'] ?? true) === false) { throw new InvalidArgumentException('Read the current editable world-object row before changing it.'); }
        if (isset($row['options']) && !in_array($value, $row['options'], true)) { throw new InvalidArgumentException('Choose a listed value.'); }
        if (($row['reference'] ?? null) === 'png_assets' && $value !== '') {
            if (!in_array($value, $this->references->valuesFor('png_assets'), true)) { throw new InvalidArgumentException('Choose a current project PNG.'); }
            \Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight::inspect($this->map->getAssetRoot(), $value);
        }
        if (($row['reference'] ?? null) === 'world_object_tile_layers') {
            $owner = self::readEntries($this->map)[$index]['covers']['layer'];
            $choices = array_column(array_filter($this->map->describeTileLayers()['layers'], static fn($layer): bool => $layer['owner'] === $owner), 'name');
            foreach (array_filter(array_map(trim(...), explode(',', $value))) as $selected) {
                if (!in_array($selected, $choices, true)) { throw new InvalidArgumentException('Choose a tile layer owned by the covered gameplay layer.'); }
            }
        }
        (new RecordAuthoring())->applyField($records, $index, [], $field, $value, $row['label']);
        return $this->writeRecords($records, 'Edit world object');
    }

    public function changeItem(string $id, string $field, bool $remove): ?Command
    {
        $records = $this->getRecords();
        $index = $this->getIndex($id);
        $item = $records->locateItem($index, [], $field);
        if (!$remove && $item?->listKey === 'covers.cells') {
            $entries = self::readEntries($this->map);
            $object = $entries[$index];
            $claimed = [];
            foreach ($entries as $entry) {
                if (($entry['covers']['layer'] ?? null) === $object['covers']['layer']) {
                    array_push($claimed, ...$entry['covers']['cells']);
                }
            }
            for ($y = 0; $y < $this->map->getHeight(); $y++) {
                for ($x = 0; $x < $this->map->getWidth(); $x++) {
                    if (!in_array([$x, $y], $claimed, true)) {
                        $entries[$index]['covers']['cells'][] = [$x, $y];
                        return $this->writeEntries($entries, 'Add covered cell');
                    }
                }
            }
            throw new InvalidArgumentException('No unclaimed cell remains on the covered gameplay layer.');
        }
        if (!$remove && $item?->listKey === WorldObjectTileFields::DATA_KEY) {
            $entries = self::readEntries($this->map);
            [$name, $x, $y, $tile] = WorldObjectTileFields::findUnclaimedTile($this->map, $entries, $index);
            $entries[$index][WorldObjectTileFields::DATA_KEY][$name][] = ['x' => $x, 'y' => $y, 'tile' => $tile];
            return $this->writeEntries($entries, 'Add world-object tile');
        }
        $authoring = new RecordAuthoring();
        $remove ? $authoring->removeItem($records, $index, [], $field) : $authoring->addItem($records, $index, [], $field);
        return $this->writeRecords($records, $remove ? 'Remove world-object item' : 'Add world-object item');
    }

    public function moveVariant(string $id, string $variantId, int $offset): ?Command
    {
        if (!in_array($offset, [-1, 1], true)) { throw new InvalidArgumentException('Move one variant up or down.'); }
        $entries = self::readEntries($this->map);
        $index = $this->getIndex($id);
        $variants = $entries[$index]['variants'] ?? [];
        $from = array_search($variantId, array_column($variants, 'id'), true);
        if ($from === false) { throw new InvalidArgumentException('Choose a known variant to reorder.'); }
        $to = $from + $offset;
        if ($to < 0 || $to >= count($variants)) { return null; }
        [$variants[$from], $variants[$to]] = [$variants[$to], $variants[$from]];
        $entries[$index]['variants'] = $variants;
        return $this->writeEntries($entries, 'Reorder world-object variants');
    }

    /** Canvas operations use the same complete declaration and history boundary as rows. */
    public function placeObject(string $id, int $x, int $y, bool $coverage = false): ?Command
    {
        $entries = self::readEntries($this->map);
        $index = $this->getIndex($id);
        if ($coverage) {
            if (!isset($entries[$index]['covers'])) { throw new InvalidArgumentException('Select a covered gameplay layer first.'); }
            $cells = $entries[$index]['covers']['cells'];
            $at = array_search([$x, $y], $cells, true);
            if ($at === false) { $cells[] = [$x, $y]; } else { array_splice($cells, $at, 1); }
            $entries[$index]['covers']['cells'] = $cells;
        } else { $entries[$index]['anchor'] = ['x' => $x, 'y' => $y]; }
        return $this->writeEntries($entries, $coverage ? 'Toggle world-object coverage' : 'Place world-object anchor');
    }

    /**
     * Sets the graphical tiles an object presents on a tile layer of the map, each `[x, y, tile]` on a cell the object
     * owns; `0` shows no tile there. A cell it already presents is changed in place, a new one is added, as one undo
     * step. The Engine's own declaration rules decide what is accepted, before anything changes.
     *
     * @param list<array{0: int, 1: int, 2: int}> $cells
     */
    public function setTiles(string $id, string $layer, array $cells): ?Command
    {
        $entries = self::readEntries($this->map);
        $index = $this->getIndex($id);
        $entries[$index] = WorldObjectTileFields::setTiles($this->map, $entries[$index], $layer, $cells);
        return $this->writeEntries($entries, 'Set world-object tiles');
    }

    /**
     * Stops an object presenting tiles on cells of a tile layer, each `[x, y]`, so the layer's own tiles show there
     * again, as one undo step.
     *
     * @param list<array{0: int, 1: int}> $cells
     */
    public function unsetTiles(string $id, string $layer, array $cells): ?Command
    {
        $entries = self::readEntries($this->map);
        $index = $this->getIndex($id);
        $entries[$index] = WorldObjectTileFields::unsetTiles($entries[$index], $layer, $cells);
        return $this->writeEntries($entries, 'Remove world-object tiles');
    }

    /**
     * Moves an object's anchor and the cells it covers by a step, keeping its
     * shape. The glyphs on those cells are the map's to move with it
     * ({@see EditorSession::moveWorldObject()}).
     *
     * @throws InvalidArgumentException When the object is unknown, or the step leaves the map or reaches cells another object covers.
     */
    public function moveObject(string $id, int $dx, int $dy): ?Command
    {
        $entries = self::readEntries($this->map);
        $index = $this->getIndex($id);
        $moved = static fn(array $cell): array => [$cell[0] + $dx, $cell[1] + $dy];
        $entries[$index]['anchor'] = ['x' => $entries[$index]['anchor']['x'] + $dx, 'y' => $entries[$index]['anchor']['y'] + $dy];
        if (isset($entries[$index]['covers'])) {
            $layer = $entries[$index]['covers']['layer'];
            $cells = array_map($moved, $entries[$index]['covers']['cells']);
            foreach ($entries as $other => $entry) {
                if ($other === $index || ($entry['covers']['layer'] ?? null) !== $layer) {
                    continue;
                }
                $taken = array_values(array_filter($cells, static fn(array $cell): bool => in_array($cell, $entry['covers']['cells'], true)));
                if ($taken !== []) {
                    throw new InvalidArgumentException(sprintf('%d, %d on the %s layer is covered by world object %s; move it first.',
                        $taken[0][0], $taken[0][1], $layer, $entry['id']));
                }
            }
            $entries[$index]['covers']['cells'] = $cells;
        }
        // The tiles it presents stand on the cells it owns, so they move with them.
        $entries[$index] = WorldObjectTileFields::moveTiles($entries[$index], $dx, $dy);
        foreach ([$entries[$index]['anchor'], ...array_map(static fn(array $cell): array => ['x' => $cell[0], 'y' => $cell[1]],
            $entries[$index]['covers']['cells'] ?? [])] as $cell) {
            if ($cell['x'] < 0 || $cell['y'] < 0 || $cell['x'] >= $this->map->getWidth() || $cell['y'] >= $this->map->getHeight()) {
                throw new InvalidArgumentException(sprintf('Moving %s by %d, %d reaches %d, %d, outside the map.', $id, $dx, $dy, $cell['x'], $cell['y']));
            }
        }

        return $this->writeEntries($entries, 'Move world object');
    }

    private function writeRecords(ProjectRecordDatabase $records, string $label): ?Command
    {
        $entries = array_map(static fn($record): array => (array) $record->toArray(), $records->getRecords());
        foreach ($entries as &$entry) {
            if (isset($entry['covers'])) { $entry['covers']['cells'] = array_map(static fn($cell): array => [$cell['x'], $cell['y']], $entry['covers']['cells']); }
            $entry = WorldObjectTileFields::writeEntry($entry);
            $entry = self::writeTerminalChoice($entry, false);
            foreach ($entry['variants'] ?? [] as $index => $variant) { $entry['variants'][$index] = self::writeTerminalChoice($variant, true); }
        }
        unset($entry);
        return $this->writeEntries($entries, $label);
    }

    /**
     * Writes the map's world objects, and on a map that declares its own
     * collision, the footprints their resources stamp ({@see planFootprints()}),
     * as one undo step.
     */
    private function writeEntries(array $entries, string $label): ?Command
    {
        $before = $this->map->getMapDataField([WorldObjectDefinition::DATA_KEY]);
        $entries = array_map(WorldObjectTileFields::keepPresentation(...), $entries);
        if ($before === $entries) { return null; }
        self::validateEntries($this->map, $entries, false);
        if (($issue = $this->getSourceIssue()) !== null) { throw new MapSourceRefusal($issue); }
        $footprints = $this->planFootprints(is_array($before) ? $before : [], $entries);
        $map = $this->map;
        $occupancyBefore = $footprints === [] ? null : $map->getMapDataField([MapPhysicalOccupancy::DATA_KEY]);
        $map->setMapDataField([WorldObjectDefinition::DATA_KEY], $entries);
        if ($occupancyBefore === null) {
            return new GenericCommand($label,
                static fn() => $map->setMapDataField([WorldObjectDefinition::DATA_KEY], $entries),
                static fn() => $map->setMapDataField([WorldObjectDefinition::DATA_KEY], $before));
        }
        try {
            $map->writePhysicalOccupancy($footprints);
        } catch (MapSourceRefusal $refusal) {
            $map->setMapDataField([WorldObjectDefinition::DATA_KEY], $before);
            throw $refusal;
        }
        $occupancyAfter = $map->getMapDataField([MapPhysicalOccupancy::DATA_KEY]);
        return new GenericCommand($label,
            static function () use ($map, $entries, $occupancyAfter): void {
                $map->setMapDataField([WorldObjectDefinition::DATA_KEY], $entries);
                $map->setMapDataField([MapPhysicalOccupancy::DATA_KEY], $occupancyAfter);
            },
            static function () use ($map, $before, $occupancyBefore): void {
                $map->setMapDataField([WorldObjectDefinition::DATA_KEY], $before);
                $map->setMapDataField([MapPhysicalOccupancy::DATA_KEY], $occupancyBefore);
            });
    }

    /**
     * The collision writes that keep each world object's resource footprint
     * (its occupancy stamp, an authoring recipe the Engine never reads at run
     * time) in the map's own declared collision as objects are made, moved,
     * changed or deleted. A cell a footprint leaves goes back to plain floor
     * when it still holds what the footprint stamped and no other object
     * stamps it; a cell a footprint takes must be plain floor, as a tree the
     * tree brush plants must, so leaving it later gives back exactly that
     * floor. Maps that resolve collision from their glyphs keep none.
     *
     * @param list<array<string, mixed>> $before
     * @param list<array<string, mixed>> $after
     * @return list<array{0: int, 1: int, 2: CollisionType}>
     * @throws MapSourceRefusal When a footprint would take a cell that is not plain floor; nothing is changed.
     */
    private function planFootprints(array $before, array $after): array
    {
        if (! $this->map->hasMapDataField([MapPhysicalOccupancy::DATA_KEY])) {
            return [];
        }
        $left = $this->readFootprints($before);
        $taken = $this->readFootprints($after);
        if ($left === $taken) {
            return [];
        }
        // The declared cells as authored, which footprints write; never what glyphs or terrain kinds resolve to.
        $collision = array_map(static fn(mixed $row): array => array_map(
            static fn(mixed $cell): ?int => $cell instanceof CollisionType ? $cell->value : null, (array) $row),
            (array) $this->map->getMapDataField([MapPhysicalOccupancy::DATA_KEY]));
        $writes = [];
        foreach ($left as $key => [$x, $y, $type]) {
            if (! isset($taken[$key]) && ($collision[$y][$x] ?? null) === $type->value) {
                $writes[] = [$x, $y, CollisionType::NONE];
            }
        }
        foreach ($taken as $key => [$x, $y, $type, $id]) {
            if (($left[$key][2] ?? null) === $type) {
                continue;
            }
            $current = $collision[$y][$x] ?? null;
            if ($current !== CollisionType::NONE->value && ! isset($left[$key])) {
                throw new MapSourceRefusal(sprintf('World object %s stamps %s on %d, %d, which is %s, not plain floor. '
                    . 'An object\'s footprint stands on plain floor, so moving or removing it gives that floor back; '
                    . 'choose another place or paint the cell\'s collision first. Nothing was changed.',
                    $id, $type->name, $x, $y, CollisionType::tryFrom((int) $current)?->name ?? 'outside the map'));
            }
            $writes[] = [$x, $y, $type];
        }

        return $writes;
    }

    /**
     * Each cell the objects' resources stamp, with its collision and the
     * object stamping it first. Objects without a known resource stamp none.
     *
     * @param list<array<string, mixed>> $entries
     * @return array<string, array{0: int, 1: int, 2: CollisionType, 3: string}>
     */
    private function readFootprints(array $entries): array
    {
        $catalog = $this->map->getFieldCatalog();
        $cells = [];
        foreach ($entries as $entry) {
            $anchor = $entry['anchor'] ?? null;
            if (! is_string($entry['resource'] ?? null) || ! is_int($anchor['x'] ?? null) || ! is_int($anchor['y'] ?? null)) {
                continue;
            }
            try {
                $stamp = $catalog->getResource($entry['resource'])->occupancyStamp;
            } catch (InvalidArgumentException) {
                continue;
            }
            foreach ($stamp as $cell) {
                [$x, $y] = [$anchor['x'] + $cell['x'], $anchor['y'] + $cell['y']];
                $cells["{$x},{$y}"] ??= [$x, $y, $cell['type'], (string) ($entry['id'] ?? $entry['resource'])];
            }
        }

        return $cells;
    }

    private function getUnusedId(array $entries, string $stem): string
    {
        $ids = array_column($entries, 'id');
        for ($number = 1; ; $number++) { if (!in_array($stem . '-' . $number, $ids, true)) { return $stem . '-' . $number; } }
    }
}
