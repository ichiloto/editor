<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

it('shows a condition list its line cannot carry whole as read-only, and refuses to rewrite it', function () {
    // A quest status the condition line does not express.
    $conditions = [['type' => 'quest', 'name' => 'harvest', 'status' => 'failed']];
    $root = npcSessionProject([['id' => 'keeper', 'name' => 'Keeper', 'sprite' => '@', 'x' => 2, 'y' => 1, 'conditions' => $conditions]]);
    $session = EditorSession::open($root);
    $read = $session->readNpc('test-map', 0);
    $row = npcSessionRow($read, 'conditions');

    expect($row['kind'])->toBe('info')
        ->and($row['value'])->toStartWith('! condition 1 has a quest status the condition editor would rewrite')
        ->and(fn() => $session->applyNpc('test-map', $read['revision'], 0, $row['key'], 'switch door-open'))
            ->toThrow(SessionRefusal::class, 'cannot be edited')
        ->and(getSessionMap($session)->getNpcs()->all()[0]->toArray()['conditions'])->toBe($conditions)
        ->and($session->listUnsavedChanges())->toBe([]);
});

it('keeps a condition list the line carries whole editable through the same row', function () {
    $root = npcSessionProject([['id' => 'keeper', 'name' => 'Keeper', 'sprite' => '@', 'x' => 2, 'y' => 1,
        'conditions' => [['type' => 'switch', 'name' => 'door-open', 'value' => true]]]]);
    $session = EditorSession::open($root);
    $read = $session->readNpc('test-map', 0);

    expect(npcSessionRow($read, 'conditions')['kind'])->toBe('conditions');
});
