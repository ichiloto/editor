<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\{EditorSession, SessionRefusal};
use Ichiloto\Engine\Animations\{ActionMovement, Animation};

it('authors movement on one animation binding with undo and source-preserving save', function () {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/animations.php';
    $records = [['id' => 1, 'name' => 'Shared step'], ['id' => 2, 'name' => 'Close contact']];
    file_put_contents($path, '<?php return ' . var_export($records, true) . ';');
    $session = EditorSession::open($root);
    $row = array_find($session->readDatabaseRecord('animations', 1)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'movement');
    expect($row['options'])->toBe(array_column(ActionMovement::cases(), 'value'));
    $source = file_get_contents($path);
    $session->applyDatabaseRecord('animations', 1, $row['key'], 'run');
    expect(fn() => $session->saveDatabase('animations'))->toThrow(SessionRefusal::class);
    expect(file_get_contents($path))->toBe($source);
    $session->undo();
    $session->applyDatabaseRecord('animations', 1, $row['key'], 'run-to-target');
    $session->undo();
    expect($session->listUnsavedChanges())->toBe([]);
    $session->redo();
    $session->saveDatabase('animations');
    $after = require $path;
    expect($after[0])->toBe($records[0])->and($after[1])->toBe([...$records[1], 'movement' => 'run-to-target'])
        ->and(Animation::fromArray($after[1])->movement)->toBe(ActionMovement::RUN_TO_TARGET);
    $reloaded = EditorSession::open($root);
    $read = array_find($reloaded->readDatabaseRecord('animations', 1)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'movement');
    expect($read['value'])->toBe('run-to-target');
});
