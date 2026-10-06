<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Session;

use Ichiloto\Editor\Console\ConsoleBinary;
use Ichiloto\Editor\Console\ProjectCreator;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Serves an {@see EditorSession} to a native editor over a child process's
 * standard streams: one JSON request per line in, one JSON response per line
 * out. Diagnostics go to standard error, never into the protocol.
 *
 * A request is `{"id": n, "method": "...", "params": {...}}`. Its response
 * carries the same id and either `result`, or `error` with a `kind`:
 * `refusal` for an edit or read the session will not make (the author reads
 * the message), `request` for a malformed or unknown request, `failure` for
 * anything unexpected. The first request must be `hello`, which opens the
 * project and agrees the protocol version.
 */
final class SessionHost
{
    public const int PROTOCOL = 1;

    private ?EditorSession $session = null;

    /**
     * @param resource $input
     * @param resource $output
     * @param resource $diagnostics
     */
    public function __construct(private $input, private $output, private $diagnostics)
    {
    }

    /** Serves requests until the input closes. */
    public function run(): void
    {
        while (($line = fgets($this->input)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $this->write($this->handle($line));
        }
        // The editor has gone: nothing it started may outlive it.
        $this->session?->close();
    }

    /**
     * Answers one request line.
     *
     * @return array<string, mixed>
     */
    public function handle(string $line): array
    {
        try {
            $request = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            return ['id' => null, 'error' => ['kind' => 'request', 'message' => 'Not JSON: ' . $error->getMessage()]];
        }
        $id = is_array($request) ? ($request['id'] ?? null) : null;
        $method = is_array($request) && is_string($request['method'] ?? null) ? $request['method'] : '';
        $params = is_array($request) && is_array($request['params'] ?? null) ? $request['params'] : [];

        try {
            return ['id' => $id, 'result' => $this->dispatch($method, $params)];
        } catch (SessionRefusal $refusal) {
            return ['id' => $id, 'error' => ['kind' => 'refusal', 'message' => $refusal->getMessage()]];
        } catch (InvalidRequest $invalid) {
            return ['id' => $id, 'error' => ['kind' => 'request', 'message' => $invalid->getMessage()]];
        } catch (Throwable $failure) {
            fwrite($this->diagnostics, sprintf("[session-host] %s: %s\n%s\n", $failure::class, $failure->getMessage(),
                $failure->getTraceAsString()));

            return ['id' => $id, 'error' => ['kind' => 'failure', 'message' => $failure->getMessage()]];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function dispatch(string $method, array $params): array
    {
        if ($method === 'hello') {
            $protocol = $params['protocol'] ?? null;
            if ($protocol !== self::PROTOCOL) {
                throw new InvalidRequest(sprintf('This host speaks protocol %d, not %s.', self::PROTOCOL, var_export($protocol, true)));
            }
            $this->session = EditorSession::open(self::requireString($params, 'project'));

            return ['protocol' => self::PROTOCOL, 'project' => $this->session->describeProject()];
        }

        // Creating a project needs none open: an editor with no project offers it.
        if ($method === 'project.create') {
            try {
                return ['root' => new ProjectCreator(ConsoleBinary::discover())->createProject(
                    self::requireString($params, 'title'),
                    self::requireString($params, 'directory'),
                    self::readOptionalString($params, 'hero'),
                    self::readOptionalString($params, 'battleEngine'),
                    ($params['install'] ?? false) === true,
                )];
            } catch (RuntimeException $refused) {
                throw new SessionRefusal($refused->getMessage(), previous: $refused);
            }
        }

        $session = $this->session ?? throw new InvalidRequest('Say hello with a project first.');

        return match ($method) {
            'project.search' => $session->searchProject(self::requireString($params, 'query')),
            'maps.list' => $session->describeMaps(),
            'maps.kinds' => $session->listMapKinds(),
            'map.create' => $session->createMap(
                is_string($params['name'] ?? null) ? $params['name'] : null,
                is_string($params['kind'] ?? null) ? $params['kind'] : null,
                self::requireInt($params, 'width'),
                self::requireInt($params, 'height'),
            ),
            'map.delete' => $session->deleteMap(self::requireString($params, 'map'), ($params['confirm'] ?? false) === true),
            'map.read' => $session->readMap(self::requireString($params, 'map')),
            'map.world' => $session->readWorld(self::requireString($params, 'map'),
                is_bool($params['tileShadows'] ?? false) ? ($params['tileShadows'] ?? false) : throw new InvalidRequest('"tileShadows" must be a boolean.')),
            'tiles.palette' => $session->readTilePalette(self::requireString($params, 'map')),
            'tiles.read' => $session->readTiles(self::requireString($params, 'map')),
            'tiles.stamp' => $session->stampTiles(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireString($params, 'layer'),
                self::requireTileCells($params),
                is_string($params['label'] ?? null) ? $params['label'] : 'Place tiles',
                self::readTileChoices($params),
            ),
            'tiles.paint' => $session->paintTiles(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireString($params, 'layer'),
                self::requireCells($params),
                self::requireInt($params, 'tile'),
                is_string($params['label'] ?? null) ? $params['label'] : 'Place tiles',
                self::readTileChoices($params),
            ),
            'map.paint' => $session->paint(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireString($params, 'layer'),
                self::requireCells($params),
                self::requireString($params, 'symbol'),
                array_key_exists('color', $params) && (is_string($params['color']) || $params['color'] === null) ? $params['color'] : null,
                self::readChoices($params),
                is_string($params['label'] ?? null) ? $params['label'] : 'Paint',
            ),
            'layer.create' => $session->createLayer(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireString($params, 'name'),
                is_bool($params['decoration'] ?? false) ? ($params['decoration'] ?? false) : throw new InvalidRequest('"decoration" must be a boolean.'),
            ),
            'layer.rename' => $session->renameLayer(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireString($params, 'layer'),
                self::requireString($params, 'name'),
                is_bool($params['confirm'] ?? false) ? ($params['confirm'] ?? false) : throw new InvalidRequest('"confirm" must be a boolean.'),
            ),
            'layer.remove' => $session->removeLayer(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireString($params, 'layer'),
                is_bool($params['confirm'] ?? false) ? ($params['confirm'] ?? false) : throw new InvalidRequest('"confirm" must be a boolean.'),
            ),
            'layer.reorder' => $session->moveLayer(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireString($params, 'layer'),
                is_int($params['order'] ?? null) || ! isset($params['order']) ? ($params['order'] ?? null) : throw new InvalidRequest('"order" must be an integer.'),
                is_string($params['direction'] ?? null) || ! isset($params['direction']) ? ($params['direction'] ?? null) : throw new InvalidRequest('"direction" must be "above" or "below".'),
                is_bool($params['confirm'] ?? false) ? ($params['confirm'] ?? false) : throw new InvalidRequest('"confirm" must be a boolean.'),
            ),
            'layer.decoration' => $session->setLayerDecoration(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireString($params, 'layer'),
                is_bool($params['decoration'] ?? null) ? $params['decoration'] : throw new InvalidRequest('"decoration" must be a boolean.'),
                is_bool($params['confirm'] ?? false) ? ($params['confirm'] ?? false) : throw new InvalidRequest('"confirm" must be a boolean.'),
            ),
            'tileLayer.create' => $session->createTileLayer(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireString($params, 'name'),
            ),
            'tileLayer.rename' => $session->renameTileLayer(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireString($params, 'name'),
                self::requireString($params, 'newName'),
            ),
            'tileLayer.remove' => $session->removeTileLayer(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireString($params, 'name'),
            ),
            'tileLayer.reorder' => $session->moveTileLayer(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireString($params, 'name'),
                is_int($params['order'] ?? null) || ! isset($params['order']) ? ($params['order'] ?? null) : throw new InvalidRequest('"order" must be an integer.'),
                is_string($params['direction'] ?? null) || ! isset($params['direction']) ? ($params['direction'] ?? null) : throw new InvalidRequest('"direction" must be "above" or "below".'),
            ),
            'tileLayer.settings' => $session->setTileLayerSettings(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireString($params, 'name'),
                is_array($params['offset'] ?? null) && array_is_list($params['offset']) && count($params['offset']) === 2
                    && array_all($params['offset'], static fn(mixed $value): bool => is_int($value) || is_float($value))
                    ? $params['offset'] : throw new InvalidRequest('"offset" must be two numbers, across and down.'),
                array_key_exists('movesWith', $params) && (is_string($params['movesWith']) || $params['movesWith'] === null)
                    ? $params['movesWith'] : throw new InvalidRequest('"movesWith" must be a gameplay layer name or null.'),
            ),
            'map.save' => $session->saveMap(self::requireString($params, 'map')),
            'inspector.read' => $session->readInspector(
                self::requireString($params, 'map'),
                is_string($params['event'] ?? null) ? $params['event'] : null,
            ),
            'inspector.apply' => $session->applyInspector(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                is_array($params['key'] ?? null) ? $params['key'] : throw new InvalidRequest('"key" must be the row key the inspector gave.'),
                is_scalar($params['value'] ?? null) ? (string) $params['value'] : throw new InvalidRequest('"value" must be a string, number or boolean.'),
                self::readOptionalString($params, 'answer'),
            ),
            'inspector.add' => $session->addInspectorListEntry(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireKey($params),
            ),
            'inspector.remove' => $session->removeInspectorListEntry(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireKey($params),
            ),
            'event.types' => $session->listEventTypes(),
            'event.create' => $session->createEvent(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireCells($params),
                self::requireString($params, 'type'),
                self::readOptionalString($params, 'marker'),
            ),
            'event.delete' => $session->deleteEvent(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireString($params, 'marker'),
            ),
            'event.move' => $session->moveEvent(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireString($params, 'marker'),
                self::requireInt($params, 'dx'),
                self::requireInt($params, 'dy'),
            ),
            'event.bounds' => $session->setEventBounds(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireString($params, 'marker'),
                self::requireInt($params, 'x'),
                self::requireInt($params, 'y'),
                self::requireInt($params, 'width'),
                self::requireInt($params, 'height'),
            ),
            'event.destination' => $session->setEventDestination(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireString($params, 'marker'),
                self::requireString($params, 'destination'),
                self::requireInt($params, 'x'),
                self::requireInt($params, 'y'),
            ),
            'database.records' => $session->listDatabaseRecords(self::requireString($params, 'category')),
            'troops.formation' => $session->readTroopFormation(self::requireInt($params, 'index'),
                is_string($params['arena'] ?? null) ? $params['arena'] : null),
            'actors.preview' => $session->readActorPreview(self::requireInt($params, 'index'),
                is_string($params['arena'] ?? null) ? $params['arena'] : null),
            'enemies.preview' => $session->readEnemyPreview(self::requireInt($params, 'index'),
                is_string($params['arena'] ?? null) ? $params['arena'] : null),
            'database.record' => $session->readDatabaseRecord(
                self::requireString($params, 'category'),
                self::requireInt($params, 'index'),
                is_array($params['frame'] ?? []) ? ($params['frame'] ?? []) : throw new InvalidRequest('"frame" must be a list of indexes and keys.'),
            ),
            'database.apply' => $session->applyDatabaseRecord(
                self::requireString($params, 'category'),
                self::requireInt($params, 'index'),
                is_array($params['key'] ?? null) ? $params['key'] : throw new InvalidRequest('"key" must be the row key database.record gave.'),
                is_scalar($params['value'] ?? null) ? (string) $params['value'] : throw new InvalidRequest('"value" must be a string, number or boolean.'),
                self::readOptionalString($params, 'answer'),
                self::readOptionalString($params, 'confirm'),
            ),
            'database.applyMany' => $session->applyDatabaseRecordValues(
                self::requireString($params, 'category'),
                self::requireInt($params, 'index'),
                is_array($params['changes'] ?? null) ? array_values($params['changes']) : throw new InvalidRequest('"changes" must be a list of {key, value}.'),
                self::requireString($params, 'label'),
            ),
            'database.add' => $session->addDatabaseItem(
                self::requireString($params, 'category'),
                self::requireInt($params, 'index'),
                is_array($params['key'] ?? null) ? $params['key'] : throw new InvalidRequest('"key" must be a row key database.record gave, or {"frame": [...]}.'),
                is_bool($params['child'] ?? false) ? ($params['child'] ?? false) : throw new InvalidRequest('"child" must be a boolean.'),
            ),
            'database.remove' => $session->removeDatabaseItem(
                self::requireString($params, 'category'),
                self::requireInt($params, 'index'),
                is_array($params['key'] ?? null) ? $params['key'] : throw new InvalidRequest('"key" must be the row key database.record gave.'),
            ),
            'database.create' => $session->createDatabaseRecord(self::requireString($params, 'category'), self::readOptionalString($params, 'identity')),
            'database.duplicate' => $session->duplicateDatabaseRecord(self::requireString($params, 'category'), self::requireInt($params, 'index')),
            'database.delete' => $session->deleteDatabaseRecord(self::requireString($params, 'category'), self::requireInt($params, 'index')),
            'database.move' => $session->moveDatabaseRecord(
                self::requireString($params, 'category'),
                self::requireInt($params, 'index'),
                in_array($params['direction'] ?? null, ['up', 'down'], true) ? $params['direction'] : throw new InvalidRequest('"direction" must be "up" or "down".'),
            ),
            'database.save' => $session->saveDatabase(self::requireString($params, 'category')),
            'references.list' => $session->listReferences(self::requireString($params, 'map'), self::requireString($params, 'category')),
            'conditions.grammar' => $session->describeWorldStateGrammar(),
            'conditions.encode' => $session->encodeWorldState(
                self::requireString($params, 'codec'),
                is_array($params['entries'] ?? null) ? $params['entries'] : throw new InvalidRequest('"entries" must be a list.'),
                is_array($params['writeTypes'] ?? null) ? array_values(array_filter($params['writeTypes'], is_string(...))) : null,
            ),
            'battlers.describe' => $session->describeBattlerArt(self::requireString($params, 'side'), self::requireString($params, 'identity')),
            'animations.conversion' => $session->describeAnimationConversion(self::requireInt($params, 'index')),
            'animations.convert' => $session->convertAnimation(
                self::requireInt($params, 'index'),
                self::requireString($params, 'timeline'),
                self::requireString($params, 'cadence'),
                is_int($params['fps'] ?? null) ? $params['fps'] : null,
                self::requireInt($params, 'ticksPerFrame'),
                self::requireInt($params, 'restFrame'),
                is_bool($params['includeFlash'] ?? true) ? ($params['includeFlash'] ?? true) : throw new InvalidRequest('"includeFlash" must be a boolean.'),
                self::readOptionalString($params, 'binding'),
                self::readOptionalString($params, 'answer'),
                self::readOptionalString($params, 'confirm'),
            ),
            'affinities.vocabulary' => $session->describeAffinityVocabulary(),
            'affinities.encode' => $session->encodeAffinities(
                is_array($params['entries'] ?? null) ? $params['entries'] : throw new InvalidRequest('"entries" must be a list.'),
            ),
            'npc.create' => $session->createNpc(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireInt($params, 'x'),
                self::requireInt($params, 'y'),
                is_string($params['name'] ?? null) ? $params['name'] : '',
            ),
            'npc.move' => $session->moveNpc(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireInt($params, 'index'),
                self::requireInt($params, 'x'),
                self::requireInt($params, 'y'),
            ),
            'npc.duplicate' => $session->duplicateNpc(self::requireString($params, 'map'), self::requireInt($params, 'revision'), self::requireInt($params, 'index')),
            'npc.delete' => $session->deleteNpc(self::requireString($params, 'map'), self::requireInt($params, 'revision'), self::requireInt($params, 'index')),
            'npc.assignId' => $session->assignNpcId(self::requireString($params, 'map'), self::requireInt($params, 'revision'), self::requireInt($params, 'index')),
            'npc.read' => $session->readNpc(
                self::requireString($params, 'map'),
                self::requireInt($params, 'index'),
                is_array($params['frame'] ?? []) ? ($params['frame'] ?? []) : throw new InvalidRequest('"frame" must be a list of indexes and keys.'),
            ),
            'npc.apply' => $session->applyNpc(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireInt($params, 'index'),
                is_array($params['key'] ?? null) ? $params['key'] : throw new InvalidRequest('"key" must be the row key npc.read gave.'),
                is_scalar($params['value'] ?? null) ? (string) $params['value'] : throw new InvalidRequest('"value" must be a string, number or boolean.'),
            ),
            'npc.add' => $session->addNpcItem(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireInt($params, 'index'),
                is_array($params['key'] ?? null) ? $params['key'] : throw new InvalidRequest('"key" must be a row key npc.read gave, or {"frame": [...]}.'),
            ),
            'npc.remove' => $session->removeNpcItem(
                self::requireString($params, 'map'),
                self::requireInt($params, 'revision'),
                self::requireInt($params, 'index'),
                is_array($params['key'] ?? null) ? $params['key'] : throw new InvalidRequest('"key" must be the row key npc.read gave.'),
            ),
            'history.undo' => $session->undo(),
            'history.redo' => $session->redo(),
            'project.dirty' => ['dirty' => $session->hasUnsavedChanges(), 'unsaved' => $session->listUnsavedChanges()],
            'project.saveAll' => $session->saveAll(),
            'playtest.start' => $session->startPlaytest(self::requireString($params, 'map'), self::requireInt($params, 'x'), self::requireInt($params, 'y')),
            'playtest.status' => $session->describePlaytest(),
            'playtest.stop' => $session->stopPlaytest(),
            'battleTest.describe' => $session->describeBattleTest(match (true) {
                ! array_key_exists('battleTest', $params) => null,
                is_array($params['battleTest']) => $params['battleTest'],
                default => throw new InvalidRequest('"battleTest" must be a battle test object.'),
            }),
            'battleTest.apply' => $session->applyBattleTest(is_array($params['battleTest'] ?? null) ? $params['battleTest']
                : throw new InvalidRequest('"battleTest" must be a battle test object.')),
            'battleTest.start' => $session->startBattleTest(array_key_exists('troop', $params) ? self::requireInt($params, 'troop') : null),
            'battleTest.status' => $session->describeBattleTestRun(),
            'battleTest.stop' => $session->stopBattleTest(),
            default => throw new InvalidRequest(sprintf('Unknown method "%s".', $method)),
        };
    }

    /** @param array<string, mixed> $response */
    private function write(array $response): void
    {
        fwrite($this->output, json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        fflush($this->output);
    }

    /** @param array<string, mixed> $params */
    private static function requireString(array $params, string $key): string
    {
        return is_string($params[$key] ?? null) ? $params[$key] : throw new InvalidRequest(sprintf('"%s" must be a string.', $key));
    }

    /** @param array<string, mixed> $params */
    private static function requireInt(array $params, string $key): int
    {
        return is_int($params[$key] ?? null) ? $params[$key] : throw new InvalidRequest(sprintf('"%s" must be an integer.', $key));
    }

    /** @param array<string, mixed> $params */
    private static function readOptionalString(array $params, string $key): ?string
    {
        return match (true) {
            ($params[$key] ?? null) === null => null,
            is_string($params[$key]) => $params[$key],
            default => throw new InvalidRequest(sprintf('"%s" must be a string when given.', $key)),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function requireKey(array $params): array
    {
        return is_array($params['key'] ?? null) ? $params['key'] : throw new InvalidRequest('"key" must be the row key the inspector gave.');
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array{0: int, 1: int}>
     */
    private static function requireCells(array $params): array
    {
        $cells = $params['cells'] ?? null;
        if (! is_array($cells) || ! array_is_list($cells) || ! array_all($cells, static fn(mixed $cell): bool =>
            is_array($cell) && count($cell) === 2 && is_int($cell[0] ?? null) && is_int($cell[1] ?? null))) {
            throw new InvalidRequest('"cells" must be a list of [x, y] integer pairs.');
        }

        return $cells;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private static function requireTileCells(array $params): array
    {
        $cells = $params['cells'] ?? null;
        if (! is_array($cells) || ! array_is_list($cells) || ! array_all($cells, static fn(mixed $cell): bool => is_array($cell) && count($cell) === 3
            && is_int($cell[0] ?? null) && is_int($cell[1] ?? null) && is_int($cell[2] ?? null))) {
            throw new InvalidRequest('"cells" must be a list of [x, y, tile] integer triples.');
        }

        return $cells;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, ?string>
     */
    private static function readChoices(array $params): array
    {
        $choices = $params['choices'] ?? [];
        if (! is_array($choices) || ! array_all($choices, static fn(mixed $choice): bool => is_string($choice) || $choice === null)) {
            throw new InvalidRequest('"choices" must map glyphs to a role key or null.');
        }

        return $choices;
    }

    /**
     * The role key the author chose for each tile that could stand for
     * several glyphs, by tile entry. A tile that stands for a glyph always
     * takes one, so there is no answer for none.
     *
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    private static function readTileChoices(array $params): array
    {
        $choices = $params['choices'] ?? [];
        if (! is_array($choices) || ! array_all($choices, static fn(mixed $choice): bool => is_string($choice))) {
            throw new InvalidRequest('"choices" must map tiles to a role key.');
        }

        return array_combine(array_map(strval(...), array_keys($choices)), $choices);
    }
}
