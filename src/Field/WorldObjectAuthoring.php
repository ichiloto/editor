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
use Ichiloto\Engine\Field\WorldObjectDefinition;
use Ichiloto\Engine\Rendering\Sprites\FieldSpriteRole;
use Throwable;
use InvalidArgumentException;

/** A transient record view. Only the map owns or persists these declarations. */
final readonly class WorldObjectAuthoring
{
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
            $coverage = array_replace_recursive($coverage, $object->coverage);
        }

        return $coverage;
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
                        ...(array_key_exists('terminal', $variant) ? ['terminal' => $variant['terminal']] : []),
                    ]]], $map->getLayerSet());
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
            ? FieldPresentationCatalog::load($map->getAssetRoot()) : null;
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
                ...FieldSpriteFields::getFields($entry, true), self::createTerminalField('(the object\'s)')],
            prepareEdit: static fn(array $entry, string $field): array => FieldSpriteFields::prepareEdit($entry, $field, true));
        $cells = new RecordSubList(key: 'covers.cells', prefix: 'cell', singular: 'covered cell',
            fields: [new RecordField('x', 'X', InputControlType::INTEGER), new RecordField('y', 'Y', InputControlType::INTEGER)],
            blank: ['x' => 0, 'y' => 0], heading: 'Covered Cells');
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
                        new RecordField('pivot.x', 'Image Pivot X (0..1)', InputControlType::FLOAT),
                        new RecordField('pivot.y', 'Image Pivot Y (0..1)', InputControlType::FLOAT),
                        ...FieldSpriteFields::getFields($entry, true)];
                $fields = [new RecordField('id', 'Stable Map-local Id', isReadOnly: true),
                    new RecordField('anchor.x', 'Anchor X', InputControlType::INTEGER),
                    new RecordField('anchor.y', 'Anchor Y', InputControlType::INTEGER),
                    ...$presentation,
                    self::createTerminalField('(the map\'s glyph)'),
                    new RecordField('covers.layer', 'Covered Gameplay Layer', options: ['', ...array_column(array_filter($this->map->getLayers(),
                        static fn($layer): bool => !$layer['decoration'] && $layer['id'] !== \Ichiloto\Editor\Maps\MapLayers::EVENT), 'name')],
                        removeWhenEmpty: true, displayDefault: '(no coverage)')];
                if (isset($entry['covers'])) {
                    $fields[] = new RecordField('covers.tileLayers', 'Covered Tile Layers', codec: RecordFieldCodec::CSV_LIST,
                        reference: 'world_object_tile_layers', allowsNone: true);
                }
                return $fields;
            },
            subLists: [$variants, $cells],
            subListsFor: static fn(array $entry): array => [...(array_key_exists('resource', $entry) ? [] : ['variants']),
                ...(isset($entry['covers']) ? ['covers.cells'] : [])],
            prepareEdit: static function (array $entry, string $field) use ($signs): array {
                if ($field === 'resource') {
                    // A sign placement needs its label and only a sign accepts one; the board's name starts it.
                    $sign = $signs[$entry['resource'] ?? ''] ?? null;
                    if ($sign === null) { unset($entry['label']); }
                    else { $entry['label'] ??= self::makeLabel((string) $entry['id'], $sign); }
                    // A placement's art and pivot are its resource's; without one, the object holds its own, as a new one does.
                    if (array_key_exists('resource', $entry)) {
                        unset($entry['pivot'], $entry['sprites2d'], $entry['variants']);
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
                return FieldSpriteFields::prepareEdit($entry, $field, true);
            });
        foreach ($entries as &$entry) {
            if (isset($entry['covers'])) {
                $entry['covers']['cells'] = array_map(static fn($cell): array => ['x' => $cell[0], 'y' => $cell[1]], $entry['covers']['cells']);
            }
        }
        unset($entry);
        $records = ProjectRecordDatabase::overOwnedList($schema, $this->map->dataPath, $entries, static function (): void {}, graphical: true);
        $records->useAuthoringReferences($this->references);
        return $records;
    }

    /**
     * The one styled terminal cell an object shows at its anchor in the Terminal, as `<fg=yellow>m</>`: a chest
     * yellow while closed and white once opened. Left empty, the field is removed, so the map's own glyph shows
     * for the object, and a variant shows its object's.
     */
    private static function createTerminalField(string $default): RecordField
    {
        return new RecordField('terminal', 'Terminal Symbol', removeWhenEmpty: true, displayDefault: $default);
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
            $catalog = FieldPresentationCatalog::load($map->getAssetRoot());
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

    private function writeRecords(ProjectRecordDatabase $records, string $label): ?Command
    {
        $entries = array_map(static fn($record): array => (array) $record->toArray(), $records->getRecords());
        foreach ($entries as &$entry) {
            if (isset($entry['covers'])) { $entry['covers']['cells'] = array_map(static fn($cell): array => [$cell['x'], $cell['y']], $entry['covers']['cells']); }
        }
        unset($entry);
        return $this->writeEntries($entries, $label);
    }

    private function writeEntries(array $entries, string $label): ?Command
    {
        $before = $this->map->getMapDataField([WorldObjectDefinition::DATA_KEY]);
        if ($before === $entries) { return null; }
        self::validateEntries($this->map, $entries, false);
        if (($issue = $this->getSourceIssue()) !== null) { throw new MapSourceRefusal($issue); }
        $this->map->setMapDataField([WorldObjectDefinition::DATA_KEY], $entries);
        $map = $this->map;
        return new GenericCommand($label,
            static fn() => $map->setMapDataField([WorldObjectDefinition::DATA_KEY], $entries),
            static fn() => $map->setMapDataField([WorldObjectDefinition::DATA_KEY], $before));
    }

    private function getUnusedId(array $entries, string $stem): string
    {
        $ids = array_column($entries, 'id');
        for ($number = 1; ; $number++) { if (!in_array($stem . '-' . $number, $ids, true)) { return $stem . '-' . $number; } }
    }
}
