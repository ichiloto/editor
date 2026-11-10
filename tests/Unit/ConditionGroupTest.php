<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ConditionCodec;
use Ichiloto\Editor\Database\ConditionEditor;
use Ichiloto\Engine\Core\WorldConditionEvaluator;

it('round-trips an any-of group between the line and the Engine shape', function () {
    $conditions = [
        ['type' => 'any', 'conditions' => [
            ['type' => 'event', 'name' => 'phase_started', 'negate' => true],
            ['type' => 'event', 'name' => 'phase_finished'],
        ]],
        ['type' => 'quest', 'name' => 'some-quest', 'status' => 'active'],
    ];
    $line = ConditionCodec::encodeAll($conditions);

    expect($line)->toBe('any(!event:phase_started; event:phase_finished); quest:some-quest:active')
        ->and(ConditionCodec::decodeAllStrictly($line))->toBe($conditions)
        ->and(ConditionCodec::findCarryIssue($conditions))->toBeNull();
    WorldConditionEvaluator::validateAll(ConditionCodec::decodeAllStrictly($line));
});

it('reads negated and nested groups as written', function () {
    $line = '!any(switch:a:false; any(item:Potion:2; key_item:Rusty Key))';
    $decoded = ConditionCodec::decodeAllStrictly($line);

    expect($decoded)->toBe([['type' => 'any', 'conditions' => [
        ['type' => 'switch', 'name' => 'a', 'value' => false],
        ['type' => 'any', 'conditions' => [
            ['type' => 'item', 'name' => 'Potion', 'quantity' => 2],
            ['type' => 'key_item', 'name' => 'Rusty Key'],
        ]],
    ], 'negate' => true]])->and(ConditionCodec::encodeAll($decoded))->toBe($line);
});

it('keeps parentheses inside names apart from groups', function () {
    $conditions = [['type' => 'event', 'name' => 'met (twice); then:left']];
    $line = ConditionCodec::encodeAll($conditions);

    expect($line)->toBe('event:met \(twice\)\; then\:left')
        ->and(ConditionCodec::decodeAllStrictly($line))->toBe($conditions);
});

it('refuses an unreadable group whole and names why', function (string $line, string $reason) {
    expect(fn() => ConditionCodec::decodeAllStrictly($line))->toThrow(InvalidArgumentException::class, $reason);
})->with([
    'empty group' => ['any()', 'at least one condition'],
    'bad member' => ['any(event:a; nonsense:b)', 'member 2 of the any-of group'],
    'leaf spelling' => ['any:a', 'any(condition; condition)'],
    'too deep' => ['any(any(any(any(any(any(event:a))))))', 'the Engine refuses it'],
]);

it('describes a group and keeps the cursor editor from flattening it', function () {
    $editor = new ConditionEditor();
    $editor->open('conditions', 'Conditions', ConditionCodec::decodeAll('!any(event:a; quest:b:active)'));
    $editor->cycleType(1);
    $editor->setName('rewritten');

    expect($editor->encoded())->toBe('!any(event:a; quest:b:active)')
        ->and(ConditionEditor::describe($editor->conditions()[0]))
        ->toBe('NOT ANY OF (Story Event a happened; Quest b is active)')
        ->and(array_column(ConditionEditor::getGrammar(), 'type'))->not->toContain('any');
});
