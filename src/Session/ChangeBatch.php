<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Session;

use JsonException;

/**
 * Applies a list of session requests to a project as one change set, without
 * a window: the write path for agents and scripts changing maps.
 *
 * Each change is a request of the editor session protocol
 * ({@see SessionHost}), `{"method": "...", "params": {...}}`, served by the
 * same host the GUI editor uses, so a batch can make exactly the edits the
 * editors make and none they refuse. They run inside a change set
 * ({@see EditorSession::beginChanges()}), which writes them only when the
 * project they leave is valid, and otherwise writes nothing.
 *
 * Two conveniences keep a batch writable by hand: a change naming a `map`
 * without a `revision` edits the map as it stands at that step, and a change
 * whose method asks a question (inserting rows, for one) is answered with
 * the change's own `answer`, confirming what the question showed. A glyph
 * that several pieces could draw is answered as the editors answer it, by
 * `choices` in the change's params; the refusal names the keys.
 */
final class ChangeBatch
{
    /** Requests the batch itself makes, never one of its changes. */
    private const array RESERVED = ['hello', 'project.create', 'changes.begin', 'changes.commit', 'changes.cancel'];

    private function __construct(private readonly SessionHost $host)
    {
    }

    /**
     * Reads a batch file: `{"changes": [...]}`, or the list of changes alone.
     *
     * @return list<array<string, mixed>>
     * @throws InvalidRequest When the file is not a batch.
     */
    public static function readFile(string $path): array
    {
        $json = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new InvalidRequest(sprintf('%s cannot be read.', $path));
        }
        try {
            $batch = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidRequest(sprintf('%s is not JSON: %s', $path, $error->getMessage()), previous: $error);
        }
        $changes = is_array($batch) && array_key_exists('changes', $batch) ? $batch['changes'] : $batch;
        if (! is_array($changes) || ! array_is_list($changes)) {
            throw new InvalidRequest(sprintf('%s must hold a list of changes, or {"changes": [...]}.', $path));
        }

        return $changes;
    }

    /**
     * Applies changes to a project and commits them when the result is valid.
     *
     * @param list<mixed> $changes The session requests, in order.
     * @param bool $relocateSaves Whether saves standing where a changed map is now solid may move to open floor.
     * @param bool $checkOnly Whether to only report what committing would find, writing nothing.
     * @return array<string, mixed> The commit's outcome ({@see EditorSession::commitChanges()}), or
     *     `{status: 'failed', step, method, message}` when a change was refused and nothing was written.
     */
    public static function apply(string $projectRoot, array $changes, bool $relocateSaves = false, bool $checkOnly = false): array
    {
        $memory = fopen('php://memory', 'r+');
        $batch = new self(new SessionHost($memory, $memory, STDERR));

        try {
            $batch->request('hello', ['protocol' => SessionHost::PROTOCOL, 'project' => $projectRoot]);
            $batch->request('changes.begin');
            foreach ($changes as $index => $change) {
                $refusal = $batch->applyChange($change);
                if ($refusal !== null) {
                    $batch->request('changes.cancel');

                    return ['status' => 'failed', 'step' => $index + 1, ...$refusal];
                }
            }

            return $batch->request('changes.commit', ['relocateSaves' => $relocateSaves, 'check' => $checkOnly]);
        } catch (SessionRefusal $refusal) {
            return ['status' => 'failed', 'step' => null, 'method' => null, 'message' => $refusal->getMessage()];
        } finally {
            $batch->host->close();
        }
    }

    /**
     * Applies one change.
     *
     * @return array{method: ?string, message: string}|null Why it was refused; null once applied.
     */
    private function applyChange(mixed $change): ?array
    {
        $method = is_array($change) && is_string($change['method'] ?? null) ? $change['method'] : null;
        $params = is_array($change) ? ($change['params'] ?? []) : null;
        if ($method === null || ! is_array($params)) {
            return ['method' => $method, 'message' => 'A change is {"method": "...", "params": {...}}.'];
        }
        if (in_array($method, self::RESERVED, true)) {
            return ['method' => $method, 'message' => sprintf('%s is made by the batch itself, never as one of its changes.', $method)];
        }
        if (is_string($params['map'] ?? null) && ! array_key_exists('revision', $params)) {
            $map = array_find($this->request('maps.list'), static fn(array $map): bool => $map['id'] === $params['map']);
            if ($map !== null) {
                $params['revision'] = $map['revision'];
            }
        }

        try {
            $result = $this->request($method, $params);
            if (($result['status'] ?? null) === 'question' && is_string($result['glyph'] ?? null)) {
                // A glyph several pieces draw is answered in the change's own params, as an editor answers it.
                return ['method' => $method, 'message' => sprintf('Glyph %s could be several pieces; give the change "choices": {"%s": key}, one of %s.',
                    $result['glyph'], $result['glyph'], implode(', ', array_map(static fn(array $role): string => "{$role['key']} ({$role['label']})",
                        (array) ($result['roles'] ?? []))))];
            }
            if (($result['status'] ?? null) === 'question') {
                $answer = is_array($change) ? ($change['answer'] ?? null) : null;
                if (! is_string($answer)) {
                    return ['method' => $method, 'message' => sprintf('It asks "%s"; give the change an "answer".',
                        is_string($result['question'] ?? null) ? $result['question'] : 'a question')];
                }
                $result = $this->request($method, [...$params, 'answer' => $answer,
                    ...(is_string($result['confirm'] ?? null) ? ['confirm' => $result['confirm']] : [])]);
                if (($result['status'] ?? null) === 'question') {
                    return ['method' => $method, 'message' => sprintf('"%s" did not answer its question.', $answer)];
                }
            }
        } catch (SessionRefusal $refusal) {
            return ['method' => $method, 'message' => $refusal->getMessage()];
        }

        return null;
    }

    /**
     * Makes one request of the host, as a client would.
     *
     * @param array<string, mixed> $params
     * @return array<mixed>
     * @throws SessionRefusal When the host refuses it or cannot make it.
     */
    private function request(string $method, array $params = []): array
    {
        $response = $this->host->handle(json_encode(['id' => 1, 'method' => $method, 'params' => (object) $params], JSON_THROW_ON_ERROR));
        if (isset($response['error'])) {
            throw new SessionRefusal(sprintf('%s: %s', $response['error']['kind'], $response['error']['message']));
        }

        return $response['result'];
    }
}
