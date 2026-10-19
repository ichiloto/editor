<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ConditionCodec;
use Ichiloto\Editor\Database\WorldWriteCodec;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Core\WorldConditionEvaluator;

it('refuses a condition line with a part its type does not take, rather than reading the rest as a name', function () {
    expect(fn() => ConditionCodec::decodeAllStrictly('event:a & !event:b'))
        ->toThrow(InvalidArgumentException::class,
            'Condition "event:a & !event:b" cannot be read: the event condition takes nothing after its name, not "b"');
});

it('refuses the malformed line through the NPC session whole, leaving the NPC conditions and the file as they were', function () {
    $conditions = [['type' => 'event', 'name' => 'harvest-done']];
    $root = npcSessionProject([['id' => 'keeper', 'name' => 'Keeper', 'sprite' => '@', 'x' => 2, 'y' => 1, 'conditions' => $conditions]]);
    $before = sourceHashTree($root . '/assets');
    $session = EditorSession::open($root);
    $read = $session->readNpc('test-map', 0);

    expect(fn() => $session->applyNpc('test-map', $read['revision'], 0, npcSessionRow($read, 'conditions')['key'], 'event:a & !event:b'))
        ->toThrow(SessionRefusal::class, 'cannot be read')
        ->and(getSessionMap($session)->getNpcs()->all()[0]->toArray()['conditions'])->toBe($conditions)
        ->and($session->listUnsavedChanges())->toBe([])
        ->and(sourceHashTree($root . '/assets'))->toBe($before);
});

it('round-trips every condition type through the line, names with colons, semicolons and backslashes included', function () {
    $conditions = [
        ['type' => 'quest', 'name' => 'harvest', 'status' => 'active'],
        ['type' => 'quest', 'name' => 'harvest', 'status' => 'completed', 'negate' => true],
        ['type' => 'switch', 'name' => 'gate:open', 'value' => false],
        ['type' => 'event', 'name' => 'deckers:won'],
        ['type' => 'event', 'name' => 'a & !event; b \\ c'],
        ['type' => 'map_event', 'name' => 'town/square', 'marker' => 'B'],
        ['type' => 'variable', 'name' => 'gold', 'op' => '>=', 'value' => 5],
        ['type' => 'variable', 'name' => 'mood', 'op' => '!=', 'value' => 'calm'],
        ['type' => 'item', 'name' => 'item.tonic', 'quantity' => 3],
        ['type' => 'key_item', 'name' => 'item.key'],
    ];
    $line = ConditionCodec::encodeAll($conditions);

    expect(ConditionCodec::decodeAllStrictly($line))->toBe($conditions)
        ->and(ConditionCodec::decodeAll($line))->toBe($conditions)
        ->and(ConditionCodec::encode(['type' => 'event', 'name' => 'deckers:won']))->toBe('event:deckers\\:won')
        ->and(ConditionCodec::decodeAllStrictly('event:deckers\\:won'))->toBe([['type' => 'event', 'name' => 'deckers:won']])
        ->and(ConditionCodec::findCarryIssue($conditions))->toBeNull();
    // What the line yields is what the Engine accepts.
    WorldConditionEvaluator::validateAll(ConditionCodec::decodeAllStrictly($line));
});

it('refuses a condition value the Engine would refuse instead of rewriting it', function (string $line, string $reason) {
    expect(fn() => ConditionCodec::decodeAllStrictly($line))->toThrow(InvalidArgumentException::class, $reason)
        ->and(ConditionCodec::decodeAll($line))->toBe([]);
})->with([
    'a quest status' => ['quest:harvest:failed', 'a quest status is completed or active, not "failed"'],
    'a switch value' => ['switch:gate:maybe', 'a switch is true or false, not "maybe"'],
    'a zero quantity' => ['item:item.tonic:0', 'a quantity is a whole number above zero, not "0"'],
    'a word quantity' => ['item:item.tonic:many', 'a quantity is a whole number above zero, not "many"'],
    'a variable operator' => ['variable:gold:~:3', 'a variable compares with == != > >= < <=, not "~"'],
    'a map event marker' => ['map_event:town/square', 'needs its map and its event marker'],
    'a fourth part' => ['quest:harvest:active:twice', 'takes at most a status'],
    'an unknown type' => ['flag:open', 'with a type of'],
    'a double negation' => ['!!switch:gate', 'with a type of'],
    'an unknown escape' => ['event:a\\b', '"\\b" escapes nothing'],
    'a trailing backslash' => ['event:a\\', 'escapes nothing'],
]);

it('round-trips every world write type through the line and refuses parts and values it cannot be', function () {
    $sets = [
        ['type' => 'switch', 'name' => 'gate:open', 'value' => true],
        ['type' => 'event', 'name' => 'deckers:won'],
        ['type' => 'variable', 'name' => 'gold', 'op' => 'add', 'value' => 5],
        ['type' => 'quest', 'name' => 'harvest', 'confirm' => false],
    ];
    $line = WorldWriteCodec::encodeAll($sets);

    expect(WorldWriteCodec::decodeAllStrictly($line))->toBe($sets)
        ->and(WorldWriteCodec::findCarryIssue($sets))->toBeNull()
        ->and(fn() => WorldWriteCodec::decodeAllStrictly('event:a & event:b'))->toThrow(InvalidArgumentException::class, 'the event write takes nothing after its name, not "b"')
        ->and(fn() => WorldWriteCodec::decodeAllStrictly('switch:gate:maybe'))->toThrow(InvalidArgumentException::class, 'a switch is set true or false, not "maybe"')
        ->and(fn() => WorldWriteCodec::decodeAllStrictly('variable:gold:mul:2'))->toThrow(InvalidArgumentException::class, 'a variable write is set or add, not "mul"')
        ->and(fn() => WorldWriteCodec::decodeAllStrictly('quest:harvest:offer'))->toThrow(InvalidArgumentException::class, 'a quest write is confirm or grant, not "offer"');
});

it('encodes a colon-bearing name for a graphical editor and reads it back whole', function () {
    $session = EditorSession::open(makeTemporaryProject());

    $encoded = $session->encodeWorldState('conditions', [['type' => 'event', 'name' => 'deckers:won'], ['type' => 'switch', 'name' => 'lamp', 'value' => true]]);

    expect($encoded['line'])->toBe('event:deckers\\:won; switch:lamp')
        ->and(ConditionCodec::decodeAllStrictly($encoded['line']))->toBe([['type' => 'event', 'name' => 'deckers:won'], ['type' => 'switch', 'name' => 'lamp']]);
});
