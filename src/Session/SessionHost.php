<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Session;

use JsonException;
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

        $session = $this->session ?? throw new InvalidRequest('Say hello with a project first.');

        return match ($method) {
            'maps.list' => $session->describeMaps(),
            'map.read' => $session->readMap(self::requireString($params, 'map')),
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
            ),
            'database.records' => $session->listDatabaseRecords(self::requireString($params, 'category')),
            'database.record' => $session->readDatabaseRecord(self::requireString($params, 'category'), self::requireInt($params, 'index')),
            'references.list' => $session->listReferences(self::requireString($params, 'map'), self::requireString($params, 'category')),
            'history.undo' => $session->undo(),
            'history.redo' => $session->redo(),
            'project.dirty' => ['dirty' => $session->hasUnsavedChanges()],
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
}
