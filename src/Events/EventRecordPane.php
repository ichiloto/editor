<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Events;

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordAuthoring;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordFieldCodec;
use Ichiloto\Editor\Database\RecordItem;
use Ichiloto\Editor\Database\RecordRefusal;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Dialogue\DialogueVariants;
use Ichiloto\Editor\Inspector\InspectorRefusal;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Core\WorldStateWriter;
use InvalidArgumentException;

/**
 * One map event's record rows: what every trigger reads beside its class
 * and data, and the lists a type keeps that are edited the way an NPC's are.
 *
 * One `ProjectRecordDatabase` over the event's definition, with the schema
 * {@see RecordSchemaCatalog::mapEvent()} gives its class, so these rows are
 * the same rows, pickers, condition and write editors, command frames and
 * item rules as every record pane. A Dialogue event's pages and talk-to id
 * and a Story Script's inline script live in `data`; the pane lifts them to
 * its record's root so they edit as an NPC's dialogue and script do, and
 * puts them back where they were. A lifted list nobody changed goes back
 * exactly as authored, so plain pages stay plain pages.
 *
 * The pane holds no state of its own beyond the definition it was made
 * from: an edit returns the definition the event should have, and its
 * owner writes it ({@see \Ichiloto\Editor\Inspector\MapInspector}).
 */
final class EventRecordPane
{
    /** The target of every row this pane describes. */
    public const string TARGET = 'event-record';

    /** The schema keys that live in the event's `data`, lifted to the record's root while it is edited. */
    private const array DATA_KEYS = ['npcId', 'dialogue', 'script'];

    private readonly RecordSchema $schema;

    private readonly ProjectRecordDatabase $records;

    /** @var list<string> The data keys this pane lifted. */
    private readonly array $lifted;

    /**
     * @param string $marker The event's marker.
     * @param array<string, mixed> $definition The event's definition as the map holds it.
     * @param ReferenceCatalog|null $references The project's references, for pickers and dialogue emotions.
     */
    public function __construct(
        public readonly string $marker,
        private readonly array $definition,
        ?ReferenceCatalog $references = null,
        bool $graphical = false,
    ) {
        $className = is_string($definition['class'] ?? null) ? $definition['class'] : null;
        $this->schema = RecordSchemaCatalog::mapEvent($className, $graphical);
        [$payload, $this->lifted] = $this->project($definition);
        $this->records = ProjectRecordDatabase::overOwnedList($this->schema, '', [$payload], static function (array $entries): void {}, $graphical);

        if ($references !== null) {
            $this->records->useAuthoringReferences($references);
        }
    }

    /**
     * The definition paths these rows edit, which no other row of the event
     * describes: the generic trigger fields always, and a type's data lists
     * when they could be lifted.
     *
     * @return list<list<string>>
     */
    public function listOwnedPaths(): array
    {
        $paths = [];

        foreach ($this->schema->fields as $field) {
            if (! in_array($field->key, self::DATA_KEYS, true)) {
                $paths[] = explode('.', $field->key);
            }
        }

        foreach ($this->lifted as $key) {
            $paths[] = ['data', $key];
        }

        return $paths;
    }

    /** The record pane, for what reads its rows by its own rules (a movement route's owner, an item's place). */
    public function records(): ProjectRecordDatabase
    {
        return $this->records;
    }

    /**
     * The rows of one frame: at the root the generic trigger fields, the
     * talk-to id, the dialogue variants and the row that opens an inline
     * script; inside a script frame, its commands. Null when the frame no
     * longer resolves.
     *
     * @param list<int|string> $frame The frame; [] for the event's own rows.
     * @return list<array<string, mixed>>|null
     */
    public function getFields(array $frame = []): ?array
    {
        if ($frame !== [] && $this->records->getFrameCommands(0, $frame) === null) {
            return null;
        }

        $payload = (array) ($this->records->getRecordByIndex(0)?->toArray() ?? []);
        $rows = [];

        $subList = $this->schema->subList;
        if ($frame === [] && $subList !== null) {
            // The heading a first variant is added at, as a list beside a record's own is headed.
            $rows[] = ['label' => ucfirst($subList->key), 'value' => (string) count((array) ($payload[$subList->key] ?? [])),
                'field' => $subList->prefix . 'List', 'editable' => false, 'item' => true, 'itemNoun' => $subList->singular,
                'listHeading' => true, 'childNoun' => $subList->singular, 'target' => self::TARGET, 'marker' => $this->marker, 'eventFrame' => []];
        }

        foreach ($this->records->getFrameSettingsFields(0, $frame) as $row) {
            $rows[] = $this->describeRow($row, $frame);
        }

        if ($frame === [] && in_array('script', $this->lifted, true) && ($payload['script'] ?? []) !== []
            && trim((string) ($this->definition['data']['scriptId'] ?? '')) !== '') {
            // The Engine runs a non-empty inline script instead of the script the id names.
            $rows[] = ['label' => '! Inline script replaces Event Script', 'value' => 'the game runs the inline commands', 'editable' => false];
        }

        return $rows;
    }

    /** The trail an author reads for a frame, such as "Script > Command 3 > Then". */
    public function describeFrame(array $frame): string
    {
        return $this->records->describeFramePath($frame);
    }

    /**
     * The definition the event has once one row is edited, or null when the
     * edit changes nothing. Conditions and writes are checked as the Engine
     * reads them.
     *
     * @param list<int|string> $frame The frame the row is in.
     * @param string $fieldId The row's field id.
     * @return array<string, mixed>|null
     * @throws InspectorRefusal When the row is gone or read-only, or the value is refused.
     */
    public function applyField(array $frame, string $fieldId, string $rawValue): ?array
    {
        $row = $this->requireRow($frame, $fieldId);

        if (! self::isEditable($row)) {
            throw new InspectorRefusal(sprintf('%s cannot be edited here.', trim((string) ($row['label'] ?? 'That row'))));
        }

        return $this->edit(fn(RecordAuthoring $authoring) => $authoring->applyField($this->records, 0, $frame, $fieldId, $rawValue,
            trim((string) ($row['label'] ?? 'Event field'))));
    }

    /**
     * The definition the event has once an item is added at a row: after the
     * item the row belongs to (a command, a dialogue variant, a line, an
     * option), or at the end of the frame for a row that names none.
     *
     * @param list<int|string> $frame The frame the row is in.
     * @param string|null $fieldId The row's field id; null for the end of the frame.
     * @return array{definition: array<string, mixed>|null, summary: string}
     * @throws InspectorRefusal When the frame is gone or the row's item cannot take another.
     */
    public function addItem(array $frame, ?string $fieldId): array
    {
        $this->requireFrame($frame);
        $ownListHeading = $frame === [] && $this->schema->subList !== null ? $this->schema->subList->prefix . 'List' : null;
        // A frame's placeholder and the heading of the event's own list add at its end.
        $fieldId = in_array($fieldId, [ProjectRecordDatabase::EMPTY_FRAME_FIELD, $ownListHeading], true) ? null : $fieldId;
        $item = $fieldId === null ? null : $this->records->locateItem(0, $frame, $fieldId);
        $noun = match (true) {
            $item?->kind === RecordItem::LIST => $item->childNoun ?? $item->noun,
            $item !== null => $item->noun,
            default => $this->records->getFrameSubList($frame)?->singular ?? 'item',
        };

        if ($fieldId !== null && $item === null && $frame === []) {
            throw new InspectorRefusal('Nothing here is a list to add to.');
        }

        return ['definition' => $this->edit(fn(RecordAuthoring $authoring) => $authoring->addItem($this->records, 0, $frame, $fieldId)),
            'summary' => sprintf('Added %s.', self::describeOne($noun))];
    }

    /**
     * The definition the event has once the item a row belongs to is removed.
     *
     * @param list<int|string> $frame The frame the row is in.
     * @return array{definition: array<string, mixed>|null, summary: string}
     * @throws InspectorRefusal When the frame is gone or the row belongs to no item.
     */
    public function removeItem(array $frame, string $fieldId): array
    {
        $this->requireFrame($frame);
        $item = $this->records->locateItem(0, $frame, $fieldId);

        if ($item === null || $item->kind === RecordItem::LIST) {
            throw new InspectorRefusal('Nothing here is a list entry to remove.');
        }

        return ['definition' => $this->edit(fn(RecordAuthoring $authoring) => $authoring->removeItem($this->records, 0, $frame, $fieldId)),
            'summary' => sprintf('Removed %s.', self::describeOne($item->noun))];
    }

    /**
     * Makes one change through the record pane and returns the definition it
     * leaves, refusing conditions and writes the Engine would not read.
     *
     * @param callable(RecordAuthoring): mixed $change
     * @return array<string, mixed>|null
     * @throws InspectorRefusal
     */
    private function edit(callable $change): ?array
    {
        try {
            $change(new RecordAuthoring());
        } catch (RecordRefusal $refusal) {
            throw new InspectorRefusal($refusal->getMessage(), previous: $refusal);
        }

        $next = $this->unproject((array) ($this->records->getRecordByIndex(0)?->toArray() ?? []));

        if ($next === $this->definition) {
            return null;
        }

        $problem = self::findInvalidState($this->definition, $next);

        if ($problem !== null) {
            throw new InspectorRefusal($problem);
        }

        return $next;
    }

    /**
     * The record the pane edits: the definition with the type's data lists
     * lifted to its root. A list in a shape the pane would not carry whole
     * is left where it is, for the event's other rows to show as authored.
     *
     * @param array<string, mixed> $definition
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function project(array $definition): array
    {
        $data = $definition['data'] ?? null;
        $edited = [
            ...array_map(static fn(RecordField $field): string => $field->key, $this->schema->fields),
            ...($this->schema->subList === null ? [] : [$this->schema->subList->key]),
            ...array_keys($this->schema->commandLists),
        ];
        $lifted = [];

        foreach (self::DATA_KEYS as $key) {
            if (! in_array($key, $edited, true) || ! is_array($data) || array_key_exists($key, $definition)) {
                // Not this type's, no data to lift it from, or a root key of that name already there.
                continue;
            }

            if (! array_key_exists($key, $data)) {
                $lifted[] = $key;
                continue;
            }

            $value = $data[$key];
            $whole = match ($key) {
                'npcId' => is_scalar($value),
                default => is_array($value) && array_is_list($value) && array_all($value, static fn(mixed $entry): bool => is_array($entry)),
            };

            if (! $whole) {
                continue;
            }

            $definition[$key] = $key === 'dialogue' ? DialogueVariants::fold($value) : $value;
            unset($definition['data'][$key]);
            $lifted[] = $key;
        }

        return [$definition, $lifted];
    }

    /**
     * The definition a record payload stands for: lifted lists put back in
     * `data`, exactly as authored where nobody changed them.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function unproject(array $payload): array
    {
        [$initial] = $this->project($this->definition);
        $next = $payload;

        foreach ($this->lifted as $key) {
            $held = array_key_exists($key, $payload);
            $value = $payload[$key] ?? null;
            unset($next[$key]);

            if ($held === array_key_exists($key, $initial) && $value === ($initial[$key] ?? null)) {
                if (array_key_exists($key, (array) ($this->definition['data'] ?? []))) {
                    $next['data'][$key] = $this->definition['data'][$key];
                }

                continue;
            }

            if (! $held || ($key === 'script' && $value === [])) {
                // An inline script with no commands leaves the event to its Event Script.
                continue;
            }

            $next['data'][$key] = $key === 'dialogue' && is_array($value) ? DialogueVariants::unfold($value) : $value;
        }

        if (is_array($next['data'] ?? null) && is_array($this->definition['data'] ?? null)) {
            // Authored keys keep their places; a new one follows them.
            $next['data'] = [...array_intersect_key(array_replace($this->definition['data'], $next['data']), $next['data']), ...$next['data']];
        }

        return $next;
    }

    /**
     * A record row as the inspector lists it: aimed back at this pane, in its
     * frame, with what it belongs to, and refused by name when it is a
     * condition or write list the line would not carry whole.
     *
     * @param array<string, mixed> $row
     * @param list<int|string> $frame
     * @return array<string, mixed>
     */
    private function describeRow(array $row, array $frame): array
    {
        $row['target'] = self::TARGET;
        $row['marker'] = $this->marker;
        $row['eventFrame'] = $frame;
        $fieldId = (string) ($row['field'] ?? '');
        $item = $fieldId === '' ? null : $this->records->locateItem(0, $frame, $fieldId);

        if ($item !== null) {
            $row['item'] = true;
            $row['itemNoun'] = $item->noun;
            if ($item->kind === RecordItem::LIST) { $row['listHeading'] = true; }
            if ($item->childNoun !== null) { $row['childNoun'] = $item->childNoun; }
        }

        // A list its line cannot carry whole is already read-only, as the record layer describes it.
        if (($row['conditions'] ?? false) === true) {
            // The map inspector's condition editor, in every interface that hosts one.
            $row['mapConditions'] = true;
            $row['encoded'] = (string) ($row['value'] ?? '');
            $row['conditionsTitle'] = trim((string) ($row['label'] ?? 'Conditions'));
            $row['conditionsHint'] = 'Building conditions. An empty list always holds.';
        }

        if (is_array($row['mapPlacement']['sourcePaths'] ?? null)) {
            // A lifted list's commands are at their place in the event's data.
            $row['mapPlacement']['sourcePaths'] = array_map(fn(array $path): array => in_array($path[0] ?? null, $this->lifted, true)
                ? ['data', ...$path] : $path, $row['mapPlacement']['sourcePaths']);
        }

        return $row;
    }

    /** One of a kind of item, as an author reads it: "a command", "an option". */
    private static function describeOne(string $noun): string
    {
        return (preg_match('/^[aeiou]/i', $noun) === 1 ? 'an ' : 'a ') . $noun;
    }

    /** Whether a row is one an edit applies through: typed, picked, stepped, or built in an editor. */
    private static function isEditable(array $row): bool
    {
        if (($row['editable'] ?? true) === false) {
            return false;
        }

        return isset($row['control']) || isset($row['options']) || isset($row['choices']) || isset($row['reference'])
            || ($row['conditions'] ?? false) === true || ($row['worldWrites'] ?? false) === true;
    }

    /**
     * @param list<int|string> $frame
     * @return array<string, mixed>
     * @throws InspectorRefusal
     */
    private function requireRow(array $frame, string $fieldId): array
    {
        $rows = $this->getFields($frame) ?? throw new InspectorRefusal(sprintf('%s is no longer there; read the event again.', $this->describeFrame($frame)));

        return array_find($rows, static fn(array $row): bool => ($row['field'] ?? null) === $fieldId)
            ?? throw new InspectorRefusal('That row is no longer on the event; read it again.');
    }

    /**
     * @param list<int|string> $frame
     * @throws InspectorRefusal
     */
    private function requireFrame(array $frame): void
    {
        if ($frame !== [] && $this->records->getFrameCommands(0, $frame) === null) {
            throw new InspectorRefusal(sprintf('%s is no longer there; read the event again.', $this->describeFrame($frame)));
        }
    }

    /**
     * Why the Engine would refuse a condition or write list an edit changed,
     * or null when it reads every one. Lists the edit left alone are not its
     * to answer for.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private static function findInvalidState(array $before, array $after): ?string
    {
        $old = self::collectWorldStateLists($before);

        foreach (self::collectWorldStateLists($after) as $source => [$kind, $list]) {
            if (($old[$source][1] ?? null) === $list) {
                continue;
            }

            try {
                $kind === RecordFieldCodec::CONDITIONS
                    ? WorldConditionEvaluator::validateAll($list, $source)
                    : WorldStateWriter::validateAll($list, $source);
            } catch (InvalidArgumentException $error) {
                return $error->getMessage();
            }
        }

        return null;
    }

    /**
     * Every condition and write list a definition holds, by where it is.
     *
     * @return array<string, array{0: RecordFieldCodec, 1: array<int, mixed>}>
     */
    private static function collectWorldStateLists(array $definition): array
    {
        $lists = [];
        $add = static function (string $source, RecordFieldCodec $kind, mixed $list) use (&$lists): void {
            if (is_array($list)) { $lists[$source] = [$kind, $list]; }
        };
        $add('conditions', RecordFieldCodec::CONDITIONS, $definition['conditions'] ?? null);
        $add('sets', RecordFieldCodec::WORLD_WRITES, $definition['sets'] ?? null);
        $add('cue.conditions', RecordFieldCodec::CONDITIONS, $definition['cue']['conditions'] ?? null);
        $dialogue = $definition['data']['dialogue'] ?? null;

        if (is_array($dialogue)) {
            foreach (DialogueVariants::fold($dialogue) as $index => $variant) {
                $add(sprintf('dialogue[%d].conditions', $index), RecordFieldCodec::CONDITIONS, $variant['conditions'] ?? null);
                $add(sprintf('dialogue[%d].sets', $index), RecordFieldCodec::WORLD_WRITES, $variant['sets'] ?? null);
            }
        }

        return $lists;
    }
}
