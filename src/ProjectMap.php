<?php

declare(strict_types=1);

namespace Ichiloto\Editor;

use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Cutscenes\Source\SourceUnreadable;
use Ichiloto\Editor\Field\NpcCollection;
use Ichiloto\Editor\Field\ProjectNpc;
use Ichiloto\Editor\History\TracksPersistedState;
use Ichiloto\Editor\Storage\FileSetOperations;
use Ichiloto\Editor\Storage\FilesystemFileSetOperations;
use Ichiloto\Editor\Storage\FileSetTransaction;
use RuntimeException;

/**
 * Represents a discovered project map and its split source files.
 */
final class ProjectMap
{
    use TracksPersistedState;

    /**
     * @var array<int, array<int, array{symbol: string, prefix: string, suffix: string}>>
     */
    private array $tileCells;
    /**
     * @var array<int, array<int, string>>
     */
    private array $eventCells;
    /**
     * @var array<string, mixed>
     */
    private array $editableData;

    /**
     * Memoized widest-row width; invalidated when the grid dimensions change.
     */
    private ?int $cachedWidth = null;

    /**
     * The data file's authored source, when the editor can rewrite it in
     * place. Null when the source cannot be parsed for preservation, in
     * which case `$dataSourceIssue` says why and data edits are refused.
     */
    private ?PhpArraySourceDocument $dataDocument = null;

    /**
     * Why the data file cannot be preserved, or null when it can.
     */
    private ?string $dataSourceIssue = null;

    /**
     * The data file's raw bytes when its source cannot be parsed for
     * preservation, so a grid-only save still knows the data member is
     * untouched and a reload adopts the same bytes.
     */
    private ?string $unparsedDataSource = null;

    /**
     * @var array<string, mixed> The data array the document corresponds to:
     * what the file held at load, or at the last successful save.
     */
    private array $loadedData;

    /**
     * The tile payload as of the last load or save, so a save can tell a
     * changed grid from an untouched one without comparing against a file
     * an author may have written in another form entirely.
     */
    private string $baselineMapPayload;

    /**
     * The event-layer payload as of the last load or save.
     */
    private string $baselineEventPayload;

    /**
     * @param string[] $tileLines
     * @param string[] $eventLines
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $mapId,
        public readonly string $directory,
        public readonly string $dataPath,
        public readonly string $mapPath,
        public readonly string $eventPath,
        public readonly array $data,
        public readonly array $tileLines,
        public readonly array $eventLines,
        ?string $dataSource = null,
    ) {
        $this->editableData = $data;
        $this->tileCells = self::parseStyledLines($tileLines);
        $this->eventCells = self::parsePlainLines($eventLines);
        $this->loadedData = $data;
        $this->adoptDataSource($dataSource ?? "<?php\n\nreturn " . self::exportPhpValue($data) . ";\n");
        $this->baselineMapPayload = $this->buildMapPayload();
        $this->baselineEventPayload = $this->buildEventPayload();
    }

    /**
     * Adopts a data-file source as the one edits are rewritten into.
     *
     * A source the parser cannot hold is not a loading error: the map stays
     * browsable and its grids stay editable, but every data edit is refused
     * with the reason until the file is repaired by hand.
     */
    private function adoptDataSource(string $source): void
    {
        try {
            $this->dataDocument = PhpArraySourceDocument::parse($source);
            $this->dataSourceIssue = null;
            $this->unparsedDataSource = null;
        } catch (SourceUnreadable $unreadable) {
            $this->dataDocument = null;
            $this->dataSourceIssue = rtrim($unreadable->getMessage(), '.');
            $this->unparsedDataSource = $source;
        }
    }

    /**
     * Loads a project map from the given map directory.
     *
     * @param string $mapsRoot The maps root path.
     * @param string $directory The map directory.
     * @return self
     */
    public static function fromDirectory(string $mapsRoot, string $directory): self
    {
        $baseName = basename($directory);
        $dataPath = $directory . DIRECTORY_SEPARATOR . $baseName . '.data.php';
        $mapPath = $directory . DIRECTORY_SEPARATOR . $baseName . '.map.php';
        $eventPath = $directory . DIRECTORY_SEPARATOR . $baseName . '.event.php';

        if (! is_file($dataPath) || ! is_file($mapPath) || ! is_file($eventPath)) {
            throw new RuntimeException("Map files are incomplete in {$directory}.");
        }

        $data = require $dataPath;
        $mapText = require $mapPath;
        $eventText = require $eventPath;

        if (! is_array($data) || ! is_string($mapText) || ! is_string($eventText)) {
            throw new RuntimeException("Map files could not be parsed in {$directory}.");
        }

        $relativeDirectory = substr($directory, strlen($mapsRoot) + 1);

        return new self(
            mapId: str_replace(DIRECTORY_SEPARATOR, '/', $relativeDirectory),
            directory: $directory,
            dataPath: $dataPath,
            mapPath: $mapPath,
            eventPath: $eventPath,
            data: $data,
            tileLines: self::splitMapText($mapText),
            eventLines: self::splitMapText($eventText),
            dataSource: (string) file_get_contents($dataPath),
        )->withLoadedBaseline();
    }

    /**
     * Adopts the just-loaded content as the saved baseline.
     *
     * @return self This map.
     */
    private function withLoadedBaseline(): self
    {
        $this->captureBaseline();

        return $this;
    }

    /**
     * Returns the display name for the map.
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        return (string) ($this->editableData['name'] ?? basename($this->directory));
    }

    /**
     * Returns the display region for the map.
     *
     * @return string
     */
    public function getRegion(): string
    {
        return (string) ($this->editableData['region'] ?? 'Unknown');
    }

    /**
     * Returns the map description.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return (string) ($this->editableData['description'] ?? '');
    }

    /**
     * Returns the number of tile rows.
     *
     * @return int
     */
    public function getHeight(): int
    {
        return count($this->tileCells);
    }

    /**
     * Returns the widest tile row after formatting tags are stripped.
     *
     * @return int
     */
    public function getWidth(): int
    {
        if ($this->cachedWidth !== null) {
            return $this->cachedWidth;
        }

        $width = 0;

        foreach ($this->tileCells as $row) {
            $width = max($width, count($row));
        }

        return $this->cachedWidth = $width;
    }

    /**
     * Returns whether the map has unsaved changes.
     *
     * @return bool
     */


    /**
     * Returns the number of declared event definitions.
     *
     * @return int
     */
    public function getEventDefinitionCount(): int
    {
        $events = $this->editableData['events'] ?? [];

        return is_array($events) ? count($events) : 0;
    }

    /**
     * Returns the number of configured triggers.
     *
     * @return int
     */
    public function getTriggerCount(): int
    {
        $triggers = $this->editableData['triggers'] ?? [];

        return is_array($triggers) ? count($triggers) : 0;
    }

    /**
     * Returns the editable map data.
     *
     * @return array<string, mixed>
     */
    public function getEditableData(): array
    {
        return $this->editableData;
    }

    /**
     * Builds a merged preview where event markers override map tiles.
     *
     * @param int $width The preview width.
     * @param int $height The preview height.
     * @param int $offsetX The horizontal preview offset.
     * @param int $offsetY The vertical preview offset.
     * @param bool $showEventOverlay Whether event markers should be rendered.
     * @return string[]
     */
    public function renderPreview(
        int $width,
        int $height,
        int $offsetX = 0,
        int $offsetY = 0,
        bool $showEventOverlay = true,
        bool $showNpcOverlay = false,
        ?int $selectedNpcIndex = null,
        ?string $selectedNpcSprite = null,
    ): array
    {
        if ($width < 1 || $height < 1) {
            return [];
        }

        $lines = [];
        $offsetX = max(0, $offsetX);
        $offsetY = max(0, $offsetY);
        $rowLimit = min($offsetY + $height, max(count($this->tileCells), count($this->eventCells)));
        $npcCells = $showNpcOverlay ? $this->npcOverlayCells($selectedNpcIndex, $selectedNpcSprite) : [];

        for ($row = $offsetY; $row < $rowLimit; $row++) {
            $tileSymbols = array_map(
                static fn(array $cell): string => $cell['symbol'],
                $this->tileCells[$row] ?? []
            );
            $eventSymbols = $showEventOverlay ? ($this->eventCells[$row] ?? []) : [];
            $mergedSymbols = [];

            for ($column = $offsetX; $column < $offsetX + $width; $column++) {
                if (isset($npcCells[$row][$column])) {
                    // NPCs draw over everything, as they do in the game; the
                    // overlay is derived from map data and never painted.
                    $mergedSymbols[] = $npcCells[$row][$column];
                    continue;
                }

                $eventSymbol = $eventSymbols[$column] ?? ' ';
                $tileSymbol = $tileSymbols[$column] ?? ' ';
                $mergedSymbols[] = trim($eventSymbol) !== '' ? $eventSymbol : $tileSymbol;
            }

            $lines[] = rtrim(implode('', $mergedSymbols));
        }

        return array_pad($lines, $height, '');
    }

    /**
     * Returns the cells the NPC overlay occupies, row => column => symbol.
     *
     * The engine anchors a sprite at its tile and lets it overhang to the
     * right (NpcManager::eraseNpc clears displayWidth cells), so a
     * two-column emoji owns its anchor and the cell after it. The overhang
     * cell holds an empty string, so the terminal draws the wide glyph in
     * the space it needs rather than a symbol shoved half under it. The
     * selected NPC is drawn with brackets around a one-column sprite, or as
     * itself when wide, since brackets would misalign the row.
     *
     * @param int|null $selectedNpcIndex The NPC to mark selected.
     * @param string|null $selectedNpcSprite A glyph to draw for the selected
     *   NPC in place of its base sprite -- a directional sprite being
     *   previewed -- as authored; it is shown as the terminal would show it.
     * @return array<int, array<int, string>> The cells.
     */
    private function npcOverlayCells(?int $selectedNpcIndex, ?string $selectedNpcSprite = null): array
    {
        $cells = [];

        foreach ($this->getNpcs()->all() as $index => $npc) {
            $sprite = $npc->getVisibleSprite();
            $columns = $npc->getSpriteWidth();
            $x = $npc->getX();
            $y = $npc->getY();

            if ($index === $selectedNpcIndex && $selectedNpcSprite !== null && trim($selectedNpcSprite) !== '') {
                $sprite = ProjectNpc::visibleGlyph($selectedNpcSprite);
                $columns = max(1, mb_strwidth($sprite));
            }

            if ($index === $selectedNpcIndex && $columns === 1) {
                $sprite = '[' . $sprite . ']';
                $x = max(0, $x - 1);
                $columns = 3;
            }

            $cells[$y][$x] = $sprite;

            for ($column = 1; $column < $columns; $column++) {
                $cells[$y][$x + $column] = '';
            }
        }

        return $cells;
    }

    /**
     * Returns the editable tile symbol at the given coordinate.
     *
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @return string
     */
    public function getTileSymbol(int $x, int $y): string
    {
        return $this->tileCells[$y][$x]['symbol'] ?? ' ';
    }

    /**
     * Returns the editable event symbol at the given coordinate.
     *
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @return string
     */
    public function getEventSymbol(int $x, int $y): string
    {
        return $this->eventCells[$y][$x] ?? ' ';
    }

    /**
     * Replaces a tile symbol while preserving its surrounding formatting tags.
     *
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @param string $symbol The replacement symbol.
     * @return void
     */
    public function setTileSymbol(int $x, int $y, string $symbol): void
    {
        if (! isset($this->tileCells[$y][$x])) {
            return;
        }

        $this->tileCells[$y][$x]['symbol'] = self::normalizeSymbol($symbol);
        $this->touchState();
    }

    /**
     * Replaces an event symbol.
     *
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @param string $symbol The replacement symbol.
     * @return void
     */
    public function setEventSymbol(int $x, int $y, string $symbol): void
    {
        if (! isset($this->eventCells[$y][$x])) {
            return;
        }

        $this->eventCells[$y][$x] = self::normalizeSymbol($symbol);
        $this->touchState();
    }

    /**
     * Returns every distinct event marker painted on the grid.
     *
     * @return string[]
     */
    public function getPlacedEventMarkers(): array
    {
        $markers = [];

        foreach ($this->eventCells as $row) {
            foreach ($row as $symbol) {
                if (trim($symbol) !== '') {
                    $markers[$symbol] = $symbol;
                }
            }
        }

        return array_values($markers);
    }

    /**
     * Captures the editable grid state for undoable whole-grid mutations
     * (resize, event bounds rewrites).
     *
     * @return array{tiles: array<int, array<int, array{symbol: string, prefix: string, suffix: string}>>, events: array<int, array<int, string>>}
     */
    public function captureGridSnapshot(): array
    {
        return [
            'tiles' => $this->tileCells,
            'events' => $this->eventCells,
        ];
    }

    /**
     * Restores a previously captured grid snapshot.
     *
     * @param array{tiles: array<int, array<int, array{symbol: string, prefix: string, suffix: string}>>, events: array<int, array<int, string>>} $snapshot The captured state.
     * @return void
     */
    public function restoreGridSnapshot(array $snapshot): void
    {
        $this->tileCells = $snapshot['tiles'];
        $this->eventCells = $snapshot['events'];
        $this->cachedWidth = null;
        $this->touchState();
    }

    /**
     * Names the NPCs a resize to the given size would leave outside the map,
     * or with a wander area outside it.
     *
     * The runtime does not clamp: an NPC anchored past the edge is drawn
     * off-map and unreachable, and a wander area past the edge is a promise
     * the engine cannot keep. Nothing here changes anything -- it is what a
     * shrink has to be refused for until the author moves, resizes, or
     * removes the NPC.
     *
     * @param int $width The proposed width.
     * @param int $height The proposed height.
     * @return string[] One line per stranded NPC, empty when the resize is safe.
     */
    public function describeNpcsStrandedBy(int $width, int $height): array
    {
        $stranded = [];

        foreach ($this->getNpcs()->all() as $npc) {
            $label = sprintf('%s (%s)', $npc->getName(), $npc->getId() ?? 'no id');

            if ($npc->getX() >= $width || $npc->getY() >= $height) {
                $stranded[] = sprintf('%s stands at %d,%d', $label, $npc->getX(), $npc->getY());
            }

            $area = $npc->getWanderArea();

            if ($npc->wanders() && $area !== null
                && ($area['x'] + $area['width'] > $width || $area['y'] + $area['height'] > $height)) {
                $stranded[] = sprintf(
                    '%s wanders %d,%d %dx%d',
                    $label,
                    $area['x'],
                    $area['y'],
                    $area['width'],
                    $area['height'],
                );
            }
        }

        return $stranded;
    }

    /**
     * Returns the map's event definitions as they currently stand.
     *
     * `$data` is the loaded payload; this is the live one, edits included.
     *
     * @return array<string, mixed> The events, keyed by marker.
     */
    public function getEventDefinitions(): array
    {
        $events = $this->editableData['events'] ?? [];

        return is_array($events) ? $events : [];
    }

    /**
     * Returns the markers of the map's events, which a cinematic subject
     * reference may name.
     *
     * @return string[] The markers, in map order.
     */
    public function getEventMarkers(): array
    {
        return array_values(array_map(strval(...), array_keys($this->getEventDefinitions())));
    }

    /**
     * Returns the map's NPCs.
     *
     * @return NpcCollection The collection, read fresh from the map data.
     */
    public function getNpcs(): NpcCollection
    {
        return NpcCollection::fromMapData($this->editableData['npcs'] ?? null);
    }

    /**
     * Stores a rewritten NPC collection.
     *
     * The one mutation path for `npcs`: the coordinator never reaches into
     * the array. An unchanged collection is a no-op, so undoing to the saved
     * list is clean by content, not by accident.
     *
     * @param NpcCollection $npcs The collection to store.
     * @return void
     */
    public function setNpcs(NpcCollection $npcs): void
    {
        $entries = $npcs->toMapData();

        if (($this->editableData['npcs'] ?? null) === $entries) {
            return;
        }

        if ($entries === [] && ! array_key_exists('npcs', $this->editableData)) {
            return;
        }

        $next = $this->editableData;
        $next['npcs'] = $entries;
        $this->writeData($next);
    }

    /**
     * Returns a top-level map metadata field value.
     *
     * @param string $field The field to read.
     * @return mixed
     */
    public function getMapField(string $field): mixed
    {
        return $this->editableData[$field] ?? null;
    }

    /**
     * Applies a new data array, refusing it when the authored source cannot
     * take the change reversibly.
     *
     * Every data mutation funnels through here. The rewrite is computed
     * against the authored bytes before anything is applied, so a change
     * the source cannot hold -- a value written as an expression, an array
     * behind an unreadable key -- is refused with the map, the file and the
     * path named, and the record, history and dirty state are exactly what
     * they were.
     *
     * @param array<string, mixed> $next The data array as it should now be.
     */
    private function writeData(array $next): void
    {
        if ($next === $this->editableData) {
            // Setting data to what it already is neither dirties nor
            // deserves a history entry at the call site.
            return;
        }

        $this->assertDataPreservable($next);
        $this->editableData = $next;
        $this->touchState();
    }

    /**
     * Proves the authored data source can be rewritten into this array.
     *
     * @param array<string, mixed> $next
     * @throws MapSourceRefusal When it cannot.
     */
    private function assertDataPreservable(array $next): void
    {
        if ($this->dataDocument === null) {
            throw new MapSourceRefusal(sprintf(
                '%s: %s cannot be edited: %s. The file keeps its authored form; repair it by hand, then reload.',
                $this->mapId,
                basename($this->dataPath),
                $this->dataSourceIssue ?? 'its source cannot be preserved',
            ));
        }

        try {
            ArraySourceWriter::rewrite($this->dataDocument, $this->loadedData, $next);
        } catch (SourcePreservationRefusal $refusal) {
            throw new MapSourceRefusal(sprintf(
                '%s: %s — %s Everything else in the file is untouched.',
                $this->mapId,
                basename($this->dataPath),
                rtrim($refusal->getMessage(), '.') . '.',
            ), previous: $refusal);
        }
    }

    /**
     * The data-file source this map's current content should be saved as.
     *
     * @throws MapSourceRefusal When the source cannot take the changes.
     */
    private function proposedDataSource(): string
    {
        if ($this->dataDocument === null) {
            if ($this->editableData === $this->loadedData) {
                throw new RuntimeException(sprintf('%s has no preservable data source to rewrite.', $this->mapId));
            }

            $this->assertDataPreservable($this->editableData);
        }

        try {
            return ArraySourceWriter::rewrite($this->dataDocument, $this->loadedData, $this->editableData)->source;
        } catch (SourcePreservationRefusal $refusal) {
            throw new MapSourceRefusal(sprintf(
                '%s: %s — %s',
                $this->mapId,
                basename($this->dataPath),
                rtrim($refusal->getMessage(), '.') . '.',
            ), previous: $refusal);
        }
    }

    /**
     * Why the data file cannot be preserved by the editor, or null when its
     * authored source round-trips. Validation reports this so the refusals
     * an author meets in the Inspector are visible outside it too.
     */
    public function dataSourceIssue(): ?string
    {
        return $this->dataSourceIssue;
    }

    /**
     * Reads a nested value from the map's own data, unsaved edits included.
     *
     * @param array<int, string> $path The nested data path.
     * @return mixed The value, or null when the path names nothing.
     */
    public function getMapDataField(array $path): mixed
    {
        $reference = $this->editableData;

        foreach ($path as $segment) {
            if (! is_array($reference) || ! array_key_exists($segment, $reference)) {
                return null;
            }

            $reference = $reference[$segment];
        }

        return $reference;
    }

    /**
     * Writes a nested value into the map's own data.
     *
     * A null value removes the key rather than writing an empty one: a map
     * with no background music says nothing about music, and a misleading
     * empty track would be read as one. Nothing is touched, and nothing is
     * dirtied, when the value is already what it should be.
     *
     * @param array<int, string> $path The nested data path.
     * @param mixed $value The value, or null to remove the key.
     * @return void
     */
    public function setMapDataField(array $path, mixed $value): void
    {
        if ($path === []) {
            return;
        }

        $exists = $this->hasMapDataField($path);

        if ($value === null ? ! $exists : ($exists && $this->getMapDataField($path) === $value)) {
            // Already exactly this, or already absent: neither a write nor a
            // history entry, so browsing a map can never dirty it.
            return;
        }

        $next = $this->editableData;
        $reference = &$next;
        $last = array_key_last($path);

        foreach ($path as $position => $segment) {
            if ($position === $last) {
                if ($value === null) {
                    unset($reference[$segment]);
                } else {
                    $reference[$segment] = $value;
                }

                break;
            }

            if (! isset($reference[$segment]) || ! is_array($reference[$segment])) {
                if ($value === null) {
                    // Nothing to remove down a path that does not exist.
                    return;
                }

                $reference[$segment] = [];
            }

            $reference = &$reference[$segment];
        }

        unset($reference);
        $this->writeData($next);
    }

    /**
     * Whether the map's data holds this exact path.
     *
     * @param array<int, string> $path The nested data path.
     */
    public function hasMapDataField(array $path): bool
    {
        $reference = $this->editableData;

        foreach ($path as $segment) {
            if (! is_array($reference) || ! array_key_exists($segment, $reference)) {
                return false;
            }

            $reference = $reference[$segment];
        }

        return true;
    }

    /**
     * Reads a nested event field value (the undo counterpart of setEventField).
     *
     * @param string $marker The event marker.
     * @param string[] $path The nested data path.
     * @return mixed
     */
    public function getEventField(string $marker, array $path): mixed
    {
        $reference = $this->editableData['events'][$marker] ?? null;

        foreach ($path as $segment) {
            if (! is_array($reference) || ! array_key_exists($segment, $reference)) {
                return null;
            }

            $reference = $reference[$segment];
        }

        return $reference;
    }

    /**
     * Returns the event marker located at the given coordinate.
     *
     * @param int $x The cell x coordinate.
     * @param int $y The cell y coordinate.
     * @return string|null
     */
    public function getEventMarkerAt(int $x, int $y): ?string
    {
        $symbol = trim($this->getEventSymbol($x, $y));

        return $symbol === '' ? null : $symbol;
    }

    /**
     * Returns the event definition for the given marker.
     *
     * @param string $marker The event marker.
     * @return array<string, mixed>|null
     */
    public function getEventDefinition(string $marker): ?array
    {
        $events = $this->editableData['events'] ?? null;

        if (! is_array($events)) {
            return null;
        }

        $event = $events[$marker] ?? null;

        return is_array($event) ? $event : null;
    }

    /**
     * Returns the event bounds for a marker as x/y/width/height.
     *
     * @param string $marker The event marker.
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    public function getEventBounds(string $marker): ?array
    {
        $positions = [];

        foreach ($this->eventCells as $rowIndex => $row) {
            foreach ($row as $columnIndex => $symbol) {
                if ($symbol === $marker) {
                    $positions[] = [$columnIndex, $rowIndex];
                }
            }
        }

        if ($positions === []) {
            return null;
        }

        $xValues = array_column($positions, 0);
        $yValues = array_column($positions, 1);
        $minX = min($xValues);
        $maxX = max($xValues);
        $minY = min($yValues);
        $maxY = max($yValues);

        return [
            'x' => $minX,
            'y' => $minY,
            'width' => ($maxX - $minX) + 1,
            'height' => ($maxY - $minY) + 1,
        ];
    }

    /**
     * Reports whether every cell inside an event marker's bounds contains
     * that marker. The runtime represents one marker as one rectangular
     * trigger area and rejects sparse, cross-shaped, or disconnected areas.
     */
    public function isEventMarkerSolidRectangle(string $marker): bool
    {
        $bounds = $this->getEventBounds($marker);

        if ($bounds === null) {
            return false;
        }

        $maxX = $bounds['x'] + $bounds['width'];
        $maxY = $bounds['y'] + $bounds['height'];

        for ($y = $bounds['y']; $y < $maxY; $y++) {
            for ($x = $bounds['x']; $x < $maxX; $x++) {
                if ($this->getEventSymbol($x, $y) !== $marker) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Updates a top-level map metadata field.
     *
     * @param string $field The field to update.
     * @param mixed $value The new value.
     * @return void
     */
    public function setMapField(string $field, mixed $value): void
    {
        $next = $this->editableData;
        $next[$field] = $value;
        $this->writeData($next);
    }

    /**
     * Resizes the map and its event overlay while preserving existing content.
     *
     * @param int $width The new map width.
     * @param int $height The new map height.
     * @return void
     */
    public function resize(int $width, int $height): void
    {
        $width = max(1, $width);
        $height = max(1, $height);

        if ($width === $this->getWidth() && $height === $this->getHeight()) {
            return;
        }

        foreach ($this->tileCells as $rowIndex => $row) {
            $this->tileCells[$rowIndex] = array_slice($row, 0, $width);

            while (count($this->tileCells[$rowIndex]) < $width) {
                $this->tileCells[$rowIndex][] = self::createBlankTileCell();
            }
        }

        foreach ($this->eventCells as $rowIndex => $row) {
            $this->eventCells[$rowIndex] = array_slice($row, 0, $width);

            while (count($this->eventCells[$rowIndex]) < $width) {
                $this->eventCells[$rowIndex][] = ' ';
            }
        }

        $this->tileCells = array_slice($this->tileCells, 0, $height);
        $this->eventCells = array_slice($this->eventCells, 0, $height);

        while (count($this->tileCells) < $height) {
            $this->tileCells[] = self::createBlankTileRow($width);
        }

        while (count($this->eventCells) < $height) {
            $this->eventCells[] = array_fill(0, $width, ' ');
        }

        $this->cachedWidth = null;
        $this->touchState();
    }

    /**
     * Updates a nested event field.
     *
     * @param string $marker The event marker.
     * @param string[] $path The nested data path.
     * @param mixed $value The replacement value.
     * @return void
     */
    public function setEventField(string $marker, array $path, mixed $value): void
    {
        if ($path === []) {
            return;
        }

        if (! isset($this->editableData['events'][$marker]) || ! is_array($this->editableData['events'][$marker])) {
            return;
        }

        $next = $this->editableData;
        $reference = &$next['events'][$marker];

        foreach ($path as $index => $segment) {
            if ($index === array_key_last($path)) {
                $reference[$segment] = $value;
                unset($reference);
                $this->writeData($next);
                return;
            }

            if (! isset($reference[$segment]) || ! is_array($reference[$segment])) {
                $reference[$segment] = [];
            }

            $reference = &$reference[$segment];
        }
    }

    /**
     * Replaces the definition for the given event marker.
     *
     * @param string $marker The event marker.
     * @param array<string, mixed> $definition The replacement definition.
     * @return void
     */
    public function setEventDefinition(string $marker, array $definition): void
    {
        $next = $this->editableData;

        if (! isset($next['events']) || ! is_array($next['events'])) {
            $next['events'] = [];
        }

        $next['events'][$marker] = $definition;
        $this->writeData($next);
    }

    /**
     * Removes the definition for the given event marker (the undo counterpart
     * of a first-time setEventDefinition).
     *
     * @param string $marker The event marker.
     * @return void
     */
    public function removeEventDefinition(string $marker): void
    {
        if (! isset($this->editableData['events'][$marker])) {
            return;
        }

        $next = $this->editableData;
        unset($next['events'][$marker]);
        $this->writeData($next);
    }

    /**
     * Updates the rectangular bounds of an event marker.
     *
     * @param string $marker The event marker.
     * @param int $x The left coordinate.
     * @param int $y The top coordinate.
     * @param int $width The marker width.
     * @param int $height The marker height.
     * @return void
     */
    public function setEventBounds(string $marker, int $x, int $y, int $width, int $height): void
    {
        foreach ($this->eventCells as $rowIndex => $row) {
            foreach ($row as $columnIndex => $symbol) {
                if ($symbol === $marker) {
                    $this->eventCells[$rowIndex][$columnIndex] = ' ';
                }
            }
        }

        $maxX = max(0, min($this->getWidth() - 1, $x + max(1, $width) - 1));
        $maxY = max(0, min($this->getHeight() - 1, $y + max(1, $height) - 1));

        for ($row = max(0, $y); $row <= $maxY; $row++) {
            for ($column = max(0, $x); $column <= $maxX; $column++) {
                if (isset($this->eventCells[$row][$column])) {
                    $this->eventCells[$row][$column] = $marker;
                }
            }
        }

        $this->touchState();
    }

    /**
     * Returns where the next save() will write, so callers can detect and
     * confirm a folder move before any file is touched.
     *
     * @return array{mapId: string, directory: string, dataPath: string, mapPath: string, eventPath: string}
     */
    public function getSaveTarget(): array
    {
        return $this->resolveSaveTarget();
    }

    /**
     * Returns whether saving would move the map folder (the rename flow that
     * deletes the current directory).
     *
     * @return bool
     */
    public function willMoveOnSave(): bool
    {
        return $this->resolveSaveTarget()['directory'] !== $this->directory;
    }

    /**
     * Saves the current map and event overlay using transactional writes.
     *
     * @return string The saved map id.
     */
    public function save(?callable $backup = null, ?FileSetOperations $files = null): string
    {
        $target = $this->resolveSaveTarget();
        $moving = $target['directory'] !== $this->directory;

        if (! $this->isDirty() && ! $moving && is_dir($this->directory)) {
            // Nothing diverges from the last save: writing would only
            // canonicalize hand-authored formatting and churn mtimes.
            return $target['mapId'];
        }

        $this->assertGridsAgree();

        // Which members actually changed. The tile and event files compare
        // against the grids as of the last save, never against the bytes on
        // disk, so a file an author keeps in another form -- a tile map
        // built by a helper class -- is rewritten only when its grid is.
        // A data file the parser cannot hold never reaches the writer: its
        // edits were refused, so its bytes are its own and stay put.
        $dataSource = $this->dataDocument === null ? (string) $this->unparsedDataSource : $this->proposedDataSource();
        $writeData = $this->dataDocument !== null && $dataSource !== $this->dataDocument->source;
        $mapPayload = $this->buildMapPayload();
        $writeMap = $mapPayload !== $this->baselineMapPayload || ! is_file($this->mapPath);
        $eventPayload = $this->buildEventPayload();
        $writeEvent = $eventPayload !== $this->baselineEventPayload || ! is_file($this->eventPath);

        if (! $moving && ! $writeData && ! $writeMap && ! $writeEvent) {
            // Dirty by fingerprint but identical in content: a same-value
            // round trip. Clean without writing.
            $this->adoptWritten($dataSource, $mapPayload, $eventPayload);

            return $target['mapId'];
        }

        $transaction = new FileSetTransaction($target['directory'], $files ?? new FilesystemFileSetOperations());

        if ($moving) {
            // A move installs the complete triplet at the new path and takes
            // the old one away, as one operation.
            $transaction->write($target['dataPath'], $dataSource);
            $transaction->write($target['mapPath'], $mapPayload);
            $transaction->write($target['eventPath'], $eventPayload);
            $transaction->remove($this->dataPath);
            $transaction->remove($this->mapPath);
            $transaction->remove($this->eventPath);
        } else {
            if ($writeData) {
                $transaction->write($target['dataPath'], $dataSource);
            }

            if ($writeMap) {
                $transaction->write($target['mapPath'], $mapPayload);
            }

            if ($writeEvent) {
                $transaction->write($target['eventPath'], $eventPayload);
            }
        }

        // Stage everything beside its destination, then prove the staged
        // data file evaluates to exactly the map being saved -- run where
        // the game would run it, so relative requires resolve.
        $staged = $transaction->stage();

        if ($writeData || $moving) {
            try {
                $evaluated = $this->evaluateFile($staged[$target['dataPath']] ?? $target['dataPath']);
            } catch (\Throwable $throwable) {
                $transaction->rollBack();

                throw new RuntimeException(sprintf(
                    '%s: the rewritten %s could not be evaluated (%s); nothing was written.',
                    $this->mapId,
                    basename($target['dataPath']),
                    $throwable->getMessage(),
                ), previous: $throwable);
            }

            if (! is_array($evaluated) || $evaluated !== $this->editableData) {
                $transaction->rollBack();

                throw new RuntimeException(sprintf(
                    '%s: the rewritten %s would not read back as the edited map; nothing was written.',
                    $this->mapId,
                    basename($target['dataPath']),
                ));
            }
        }

        $transaction->commit($backup);

        if ($moving) {
            // The transaction removed the triplet; the folder follows only
            // when nothing else of the author's lives in it.
            if (is_dir($this->directory) && array_diff(scandir($this->directory) ?: [], ['.', '..']) === []) {
                @rmdir($this->directory);
            }

            self::deleteEmptyParentDirectories(dirname($this->directory), $this->getMapsRoot());
        }

        $this->adoptWritten($dataSource, $mapPayload, $eventPayload);

        return $target['mapId'];
    }

    /**
     * Adopts what a save (or a proven no-op) established as the new baseline.
     */
    private function adoptWritten(string $dataSource, string $mapPayload, string $eventPayload): void
    {
        $this->adoptDataSource($dataSource);
        $this->loadedData = $this->editableData;
        $this->baselineMapPayload = $mapPayload;
        $this->baselineEventPayload = $eventPayload;
        $this->captureBaseline();
    }

    /**
     * Refuses a save whose two grids no longer describe the same map.
     */
    private function assertGridsAgree(): void
    {
        if (count($this->eventCells) !== count($this->tileCells)) {
            throw new RuntimeException(sprintf(
                '%s: the event layer is %d rows but the map is %d; nothing was written.',
                $this->mapId,
                count($this->eventCells),
                count($this->tileCells),
            ));
        }
    }

    /**
     * Evaluates a PHP file as the game would: from the project root, so a
     * `require` written relative to the working directory resolves.
     */
    private function evaluateFile(string $path): mixed
    {
        $projectRoot = dirname($this->getMapsRoot(), 2);
        $operation = static function () use ($path): mixed {
            // A failing require raises a warning before its error; the
            // error is what callers report, so the warning is only noise.
            set_error_handler(static fn(): bool => true);

            try {
                return require $path;
            } finally {
                restore_error_handler();
            }
        };

        if (is_dir($projectRoot)) {
            return ProjectDirectoryContext::run($projectRoot, static fn(): mixed => $operation());
        }

        return $operation();
    }

    /**
     * Creates a blank map folder with default files.
     *
     * @param string $directory The destination map directory.
     * @param string $baseName The base filename.
     * @param string $displayName The map display name.
     * @param int $width The map width.
     * @param int $height The map height.
     * @return void
     */
    public static function createBlank(string $directory, string $baseName, string $displayName, int $width = 48, int $height = 18): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create {$directory}.");
        }

        $blankTileLine = str_repeat(' ', $width);
        $blankEventLine = str_repeat(' ', $width);
        $tileText = implode(PHP_EOL, array_fill(0, $height, $blankTileLine));
        $eventText = implode(PHP_EOL, array_fill(0, $height, $blankEventLine));
        $data = [
            'name' => $displayName,
            'region' => '',
            'description' => '',
            'triggers' => [],
            'events' => [],
        ];

        self::writeFileTransactionally(
            $directory . DIRECTORY_SEPARATOR . $baseName . '.data.php',
            "<?php\n\nreturn " . self::exportPhpValue($data) . ";\n",
        );
        self::writeFileTransactionally(
            $directory . DIRECTORY_SEPARATOR . $baseName . '.map.php',
            "<?php\n\nreturn <<<'ICHILOTO_MAP'\n{$tileText}\nICHILOTO_MAP;\n",
        );
        self::writeFileTransactionally(
            $directory . DIRECTORY_SEPARATOR . $baseName . '.event.php',
            "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n{$eventText}\nICHILOTO_EVENT_MAP;\n",
        );
    }

    /**
     * Writes this map into a new directory as a duplicate.
     *
     * @param string $directory The destination map directory.
     * @param string $baseName The destination basename.
     * @param string $displayName The duplicated display name.
     * @return void
     */
    public function duplicateTo(string $directory, string $baseName, string $displayName): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create {$directory}.");
        }

        $duplicatedData = $this->editableData;
        $duplicatedData['name'] = $displayName;

        // The copy keeps everything the original authored -- comments,
        // expressions, formatting -- with only the display name rewritten;
        // a data source the editor cannot rewrite falls back to canonical
        // export, since a duplicate must at least be a valid map.
        try {
            $dataSource = $this->dataDocument !== null
                ? ArraySourceWriter::rewrite($this->dataDocument, $this->loadedData, $duplicatedData)->source
                : "<?php\n\nreturn " . self::exportPhpValue($duplicatedData) . ";\n";
        } catch (SourcePreservationRefusal) {
            $dataSource = "<?php\n\nreturn " . self::exportPhpValue($duplicatedData) . ";\n";
        }

        self::writeFileTransactionally(
            $directory . DIRECTORY_SEPARATOR . $baseName . '.data.php',
            $dataSource,
        );
        self::writeFileTransactionally(
            $directory . DIRECTORY_SEPARATOR . $baseName . '.map.php',
            $this->buildMapPayload() === $this->baselineMapPayload && is_file($this->mapPath)
                ? (string) file_get_contents($this->mapPath)
                : $this->buildMapPayload(),
        );
        self::writeFileTransactionally(
            $directory . DIRECTORY_SEPARATOR . $baseName . '.event.php',
            $this->buildEventPayload() === $this->baselineEventPayload && is_file($this->eventPath)
                ? (string) file_get_contents($this->eventPath)
                : $this->buildEventPayload(),
        );
    }

    /**
     * Returns distinct symbols that are useful for the character map.
     *
     * @return string[]
     */
    public function getCharacterPalette(): array
    {
        $symbols = [];

        foreach ($this->tileCells as $row) {
            foreach ($row as $cell) {
                $symbol = $cell['symbol'];

                if (trim($symbol) === '') {
                    continue;
                }

                $symbols[$symbol] = $symbol;
            }
        }

        foreach ($this->eventCells as $row) {
            foreach ($row as $symbol) {
                if (trim($symbol) === '') {
                    continue;
                }

                $symbols[$symbol] = $symbol;
            }
        }

        foreach ([' ', ';', '~', '+', '-', '=', '|', '/', '\\', '_', '█', '░', '▒', '▓', '🧍', '🏃🏽‍➡️', '@'] as $symbol) {
            $symbols[$symbol] = $symbol;
        }

        return array_values($symbols);
    }

    /**
     * Splits multi-line map text into rows.
     *
     * @param string $text The raw map text.
     * @return string[]
     */
    private static function splitMapText(string $text): array
    {
        return preg_split('/\R/u', rtrim($text, "\r\n")) ?: [];
    }

    /**
     * Parses formatted tile-map lines into editable cells.
     *
     * @param string[] $lines The raw tile-map lines.
     * @return array<int, array<int, array{symbol: string, prefix: string, suffix: string}>>
     */
    private static function parseStyledLines(array $lines): array
    {
        return array_map(static function (string $line): array {
            preg_match_all('/<[^>]+>|[^<]+/u', $line, $matches);
            $segments = $matches[0] ?? [];
            $cells = [];
            $activePrefix = '';

            foreach ($segments as $segment) {
                if (preg_match('/^<[^\/][^>]*>$/u', $segment) === 1) {
                    $activePrefix .= $segment;
                    continue;
                }

                if (preg_match('/^<\/[^>]*>$/u', $segment) === 1 || $segment === '</>') {
                    if ($cells !== []) {
                        $cells[array_key_last($cells)]['suffix'] .= $segment;
                    }

                    $activePrefix = '';
                    continue;
                }

                foreach (self::toSymbols($segment) as $index => $symbol) {
                    $cells[] = [
                        'symbol' => $symbol,
                        'prefix' => $index === 0 ? $activePrefix : '',
                        'suffix' => '',
                    ];
                }
            }

            return $cells;
        }, $lines);
    }

    /**
     * Parses plain event-map lines into editable symbols.
     *
     * @param string[] $lines The raw event-map lines.
     * @return array<int, array<int, string>>
     */
    private static function parsePlainLines(array $lines): array
    {
        return array_map(static fn(string $line): array => self::toSymbols($line), $lines);
    }

    /**
     * Strips text-formatting tags from a map line.
     *
     * @param string $line The formatted line.
     * @return string
     */
    private static function stripFormatting(string $line): string
    {
        return preg_replace('/<[^>]+>/', '', $line) ?? $line;
    }

    /**
     * Normalizes input down to a single visible symbol.
     *
     * @param string $symbol The raw input symbol.
     * @return string
     */
    private static function normalizeSymbol(string $symbol): string
    {
        $symbols = self::toSymbols($symbol);

        return $symbols[0] ?? ' ';
    }

    /**
     * Splits a line into Unicode grapheme symbols.
     *
     * @param string $line The line to split.
     * @return string[]
     */
    private static function toSymbols(string $line): array
    {
        if ($line === '') {
            return [];
        }

        preg_match_all('/\X/u', $line, $matches);

        return $matches[0] ?? [];
    }

    /**
     * Rebuilds a formatted tile row from editable cells.
     *
     * @param array<int, array{symbol: string, prefix: string, suffix: string}> $cells The tile cells.
     * @return string
     */
    private function buildStyledLine(array $cells): string
    {
        $line = '';

        foreach ($cells as $cell) {
            $line .= $cell['prefix'] . $cell['symbol'] . $cell['suffix'];
        }

        return $line;
    }

    /**
     * Rebuilds a plain row from editable symbols.
     *
     * @param array<int, string> $cells The plain symbols.
     * @return string
     */
    private function buildPlainLine(array $cells): string
    {
        return implode('', $cells);
    }

    /**
     * Writes a file using a temp-file swap.
     *
     * @param string $path The destination file path.
     * @param string $contents The file contents.
     * @return void
     */
    private static function writeFileTransactionally(string $path, string $contents): void
    {
        if (is_file($path) && (string) file_get_contents($path) === $contents) {
            // Saving an unchanged asset rewrites nothing: no churn for git,
            // no mtime bump for build tools, no backup for the writer.
            return;
        }

        $temporaryPath = $path . '.tmp';

        if (file_put_contents($temporaryPath, $contents) === false) {
            throw new RuntimeException("Unable to write temporary file for {$path}.");
        }

        if (! rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new RuntimeException("Unable to replace {$path}.");
        }
    }

    /**
     * The content fingerprint compares canonical exports, deliberately not
     * the source writer's output: what makes a map dirty is its content,
     * and a map whose authored source cannot even be parsed must still
     * fingerprint cleanly so its grids stay saveable.
     */

    /**
     * The tile file the current grid should be saved as.
     *
     * The tile and event files are the editor's own format -- a heredoc
     * grid -- so a *changed* grid is written canonically; an untouched grid
     * is never written at all, whatever form its author kept it in.
     */
    private function buildMapPayload(): string
    {
        return "<?php\n\nreturn <<<'ICHILOTO_MAP'\n"
            . implode(PHP_EOL, array_map($this->buildStyledLine(...), $this->tileCells))
            . "\nICHILOTO_MAP;\n";
    }

    /**
     * The event-layer file the current grid should be saved as.
     */
    private function buildEventPayload(): string
    {
        return "<?php\n\nreturn <<<'ICHILOTO_EVENT_MAP'\n"
            . implode(PHP_EOL, array_map($this->buildPlainLine(...), $this->eventCells))
            . "\nICHILOTO_EVENT_MAP;\n";
    }

    /**
     * @inheritDoc
     */
    protected function buildPersistedPayload(): string
    {
        // The id is part of what a save persists: a moved map differs from
        // its old self even when every cell matches.
        return $this->mapId
            . "\0" . self::exportPhpValue($this->editableData)
            . "\0" . $this->buildMapPayload()
            . $this->buildEventPayload();
    }

    /**
     * Moves this map to a new stable path — the one explicit way a map's
     * identity changes.
     *
     * Ordinary saves never relocate anything. This writes the map at the new
     * path first, deletes the old directory only after every file landed,
     * and rolls the new copy back if that deletion fails — the map is never
     * left half-moved. References are NOT migrated: doors, quests, saves and
     * event identities that name the old id keep naming it, which is the
     * caller's warning to give.
     *
     * @param string $newRelativeId The new project-relative map id.
     * @return self The relocated map, freshly loaded from its new path; the
     *   caller replaces this instance with it.
     */
    public function moveTo(string $newRelativeId): self
    {
        $newRelativeId = trim(str_replace('\\', '/', $newRelativeId), '/ ');

        if ($newRelativeId === '') {
            throw new RuntimeException('A map path cannot be empty.');
        }

        if ($newRelativeId === $this->mapId) {
            return $this;
        }

        $mapsRoot = $this->getMapsRoot();
        $directory = $mapsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $newRelativeId);

        if (is_dir($directory)) {
            throw new RuntimeException("Map path {$newRelativeId} already exists.");
        }

        $previousDirectory = $this->directory;
        $previousMapId = $this->mapId;
        $baseName = basename($directory);

        try {
            $transaction = new FileSetTransaction($directory);
            // A move carries the map's content as authored: the data file
            // is the preserved source (unsaved edits rewritten into it, not
            // a regeneration of the whole array), and untouched grids keep
            // their bytes exactly.
            $movedDataPath = $directory . DIRECTORY_SEPARATOR . $baseName . '.data.php';
            $transaction->write(
                $movedDataPath,
                $this->dataDocument === null ? (string) $this->unparsedDataSource : $this->proposedDataSource(),
            );
            $transaction->write(
                $directory . DIRECTORY_SEPARATOR . $baseName . '.map.php',
                $this->buildMapPayload() === $this->baselineMapPayload && is_file($this->mapPath)
                    ? (string) file_get_contents($this->mapPath)
                    : $this->buildMapPayload(),
            );
            $transaction->write(
                $directory . DIRECTORY_SEPARATOR . $baseName . '.event.php',
                $this->buildEventPayload() === $this->baselineEventPayload && is_file($this->eventPath)
                    ? (string) file_get_contents($this->eventPath)
                    : $this->buildEventPayload(),
            );
            $transaction->remove($this->dataPath);
            $transaction->remove($this->mapPath);
            $transaction->remove($this->eventPath);

            // Preserved bytes must still evaluate where they land: a
            // require written against the old folder depth would install a
            // map the game cannot load, so the staged copy is proved at the
            // destination first.
            $staged = $transaction->stage();

            try {
                $evaluated = $this->evaluateFile($staged[$movedDataPath] ?? $movedDataPath);
            } catch (\Throwable $evaluationFailure) {
                $transaction->rollBack();

                throw new RuntimeException(sprintf(
                    '%s does not evaluate at %s (%s) — an expression written against the old folder depth, such as a relative require, must be adjusted in the file first.',
                    basename($movedDataPath),
                    $newRelativeId,
                    $evaluationFailure->getMessage(),
                ), previous: $evaluationFailure);
            }

            if (! is_array($evaluated) || $evaluated !== $this->editableData) {
                $transaction->rollBack();

                throw new RuntimeException(sprintf(
                    '%s would not read back as this map at %s.',
                    basename($movedDataPath),
                    $newRelativeId,
                ));
            }

            $transaction->commit();

            if (is_dir($previousDirectory) && array_diff(scandir($previousDirectory) ?: [], ['.', '..']) === []) {
                @rmdir($previousDirectory);
            }

            self::deleteEmptyParentDirectories(dirname($previousDirectory), $mapsRoot);
        } catch (\Throwable $throwable) {
            // Fail closed: the old directory is still the map, so the new
            // copy goes rather than leaving two claims to one identity.
            if (is_dir($directory) && is_dir($previousDirectory)) {
                self::deleteDirectoryRecursively($directory);
            }

            throw new RuntimeException(sprintf(
                'The move to %s failed and was rolled back (%s). The map is still %s.',
                $newRelativeId,
                $throwable->getMessage(),
                $previousMapId,
            ), previous: $throwable);
        }

        return self::fromDirectory($mapsRoot, $directory);
    }

    /**
     * Resolves the save target directory, filenames, and map id from the current metadata.
     *
     * @return array{mapId: string, directory: string, dataPath: string, mapPath: string, eventPath: string}
     */
    private function resolveSaveTarget(): array
    {
        // A map that exists on disk keeps its path: the relative path is the
        // map's stable identity -- doors transfer to it, quests reach for it,
        // saves record it. Deriving the path from the display name and region
        // made every map whose metadata did not slug-match its location a
        // permanent rename target: bsa/licensing-facility/administration can
        // never equal region/name, so saving wanted to relocate it and Save
        // All skipped it as a pending move. Name and region are display
        // metadata; where a map lives only changes by an explicit move.
        if (is_dir($this->directory)) {
            $baseName = basename($this->directory);

            return [
                'mapId' => $this->mapId,
                'directory' => $this->directory,
                'dataPath' => $this->directory . DIRECTORY_SEPARATOR . $baseName . '.data.php',
                'mapPath' => $this->directory . DIRECTORY_SEPARATOR . $baseName . '.map.php',
                'eventPath' => $this->directory . DIRECTORY_SEPARATOR . $baseName . '.event.php',
            ];
        }

        $mapsRoot = $this->getMapsRoot();
        $baseName = self::slugify($this->getDisplayName(), basename($this->directory));
        // An empty region must stay empty — falling back to the default slug
        // would silently relocate region-less maps into a "new-map" folder.
        $region = self::slugify($this->getRegion(), '');
        $relativePath = $region !== '' ? $region . DIRECTORY_SEPARATOR . $baseName : $baseName;
        $directory = $mapsRoot . DIRECTORY_SEPARATOR . $relativePath;
        $mapId = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

        if (is_dir($directory)) {
            throw new RuntimeException("Map path {$mapId} already exists.");
        }

        return [
            'mapId' => $mapId,
            'directory' => $directory,
            'dataPath' => $directory . DIRECTORY_SEPARATOR . $baseName . '.data.php',
            'mapPath' => $directory . DIRECTORY_SEPARATOR . $baseName . '.map.php',
            'eventPath' => $directory . DIRECTORY_SEPARATOR . $baseName . '.event.php',
        ];
    }

    /**
     * Resolves the maps root from the current map id and directory.
     *
     * @return string
     */
    private function getMapsRoot(): string
    {
        $segments = max(1, count(explode('/', $this->mapId)));

        return dirname($this->directory, $segments);
    }

    /**
     * Deletes a directory tree.
     *
     * @param string $directory The directory to remove.
     * @return void
     */
    private static function deleteDirectoryRecursively(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();

            if ($item->isDir()) {
                if (! @rmdir($path)) {
                    throw new RuntimeException("Failed to remove directory {$path}.");
                }

                continue;
            }

            if (! @unlink($path)) {
                throw new RuntimeException("Failed to remove file {$path}.");
            }
        }

        if (! @rmdir($directory)) {
            throw new RuntimeException("Failed to remove directory {$directory}.");
        }
    }

    /**
     * Removes empty parent directories up to, but not including, the maps root.
     *
     * @param string $directory The starting directory.
     * @param string $mapsRoot The maps root.
     * @return void
     */
    private static function deleteEmptyParentDirectories(string $directory, string $mapsRoot): void
    {
        $mapsRoot = rtrim($mapsRoot, DIRECTORY_SEPARATOR);
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);

        while ($directory !== '' && $directory !== $mapsRoot && str_starts_with($directory, $mapsRoot . DIRECTORY_SEPARATOR)) {
            if (! is_dir($directory) || self::directoryHasContents($directory)) {
                return;
            }

            if (! @rmdir($directory)) {
                return;
            }

            $directory = dirname($directory);
        }
    }

    /**
     * Returns whether a directory still contains files or folders.
     *
     * @param string $directory The directory to inspect.
     * @return bool
     */
    private static function directoryHasContents(string $directory): bool
    {
        $entries = scandir($directory);

        if (! is_array($entries)) {
            return true;
        }

        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * Converts display text into a filesystem-safe kebab-case slug.
     *
     * @param string $value The display text.
     * @param string $fallback The fallback slug.
     * @return string
     */
    private static function slugify(string $value, string $fallback = 'new-map'): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? $value : $fallback;
    }

    /**
     * Creates a blank tile cell for padded map rows.
     *
     * @return array{symbol: string, prefix: string, suffix: string}
     */
    private static function createBlankTileCell(): array
    {
        return [
            'symbol' => ' ',
            'prefix' => '',
            'suffix' => '',
        ];
    }

    /**
     * Creates a blank tile row.
     *
     * @param int $width The desired row width.
     * @return array<int, array{symbol: string, prefix: string, suffix: string}>
     */
    private static function createBlankTileRow(int $width): array
    {
        $row = [];

        for ($column = 0; $column < $width; $column++) {
            $row[] = self::createBlankTileCell();
        }

        return $row;
    }

    /**
     * Exports a PHP value using the repository's short-array style.
     *
     * @param mixed $value The value to export.
     * @param int $indentLevel The current indentation depth.
     * @return string
     */
    private static function exportPhpValue(mixed $value, int $indentLevel = 0): string
    {
        if (! is_array($value)) {
            return var_export($value, true);
        }

        if ($value === []) {
            return '[]';
        }

        $indent = str_repeat('  ', $indentLevel);
        $nextIndent = str_repeat('  ', $indentLevel + 1);
        $isList = array_is_list($value);
        $lines = ['['];

        foreach ($value as $key => $item) {
            $exportedItem = self::exportPhpValue($item, $indentLevel + 1);

            if ($isList) {
                $lines[] = "{$nextIndent}{$exportedItem},";
                continue;
            }

            $exportedKey = var_export($key, true);
            $lines[] = "{$nextIndent}{$exportedKey} => {$exportedItem},";
        }

        $lines[] = "{$indent}]";

        return implode("\n", $lines);
    }
}
