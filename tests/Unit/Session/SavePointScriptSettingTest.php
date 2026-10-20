<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\ChangeBatch;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Validation\ProjectValidator;

/**
 * The script a save point runs is a Configuration setting chosen from the
 * project's event scripts, and validation names one that does not exist.
 * Synthetic fixtures only.
 */

it('chooses the save point script from the project\'s event scripts and writes it to config.php', function () {
    $root = makeTemporaryProject();
    $session = EditorSession::open($root);
    $index = array_search('save.point_script', $session->listDatabaseRecords('configuration')['records'], true);
    $value = array_find($session->readDatabaseRecord('configuration', $index)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'value');

    $outcome = ChangeBatch::apply($root, [['method' => 'database.apply', 'params' => ['category' => 'configuration',
        'index' => $index, 'key' => $value['key'], 'value' => 'dresser-note']]]);

    expect($value['kind'])->toBe('reference')
        ->and(array_column($session->listReferences(null, 'common_events'), 'value'))->toContain('dresser-note')
        ->and($outcome['status'])->toBe('saved')
        ->and((require $root . '/config.php')['save']['point_script'] ?? null)->toBe('dresser-note');
});

it('reports a save point script the project does not have', function () {
    $root = makeTemporaryProject();
    $source = (string) file_get_contents($root . '/config.php');
    file_put_contents($root . '/config.php', preg_replace('/return \[/', "return [\n  'save' => ['point_script' => 'missing-ledger'],", $source, 1));

    $messages = array_map(static fn($issue): string => $issue->where . ' ' . $issue->message,
        new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)));

    expect(array_filter($messages, static fn(string $message): bool => str_contains($message, 'config.php: save.point_script')
        && str_contains($message, '"missing-ledger"')))->not->toBe([]);
});
