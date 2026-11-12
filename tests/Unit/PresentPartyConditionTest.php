<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ConditionCodec;
use Ichiloto\Editor\Database\ConditionEditor;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Engine\Core\PresentPartyScope;

/**
 * The present party conditions the Engine evaluates against who is in the
 * party now: `present_actor` names a member by stable actor id, and
 * `present_party_size` names the Engine's party scope and the exact number of
 * members present, which is required. Both negate as every condition does.
 * Projects are synthetic.
 */

it('reads and writes both present party conditions through the one condition line, negation included', function (): void {
    $actor = ['type' => 'present_actor', 'name' => 'actor.kaelion'];
    $size = ['type' => 'present_party_size', 'name' => 'party', 'quantity' => 4];

    expect(ConditionCodec::encode($actor))->toBe('present_actor:actor.kaelion')
        ->and(ConditionCodec::encode($size))->toBe('present_party_size:party:4')
        // One member is still written: the quantity is required, never a default.
        ->and(ConditionCodec::encode([...$size, 'quantity' => 1]))->toBe('present_party_size:party:1')
        ->and(ConditionCodec::decodeAllStrictly('!present_actor:actor.drazek; !present_party_size:party:4'))->toBe([
            ['type' => 'present_actor', 'name' => 'actor.drazek', 'negate' => true],
            ['type' => 'present_party_size', 'name' => 'party', 'quantity' => 4, 'negate' => true],
        ])
        ->and(ConditionCodec::findCarryIssue([$actor, $size]))->toBeNull()
        ->and(ConditionCodec::findCarryIssue([['type' => 'present_party_size', 'name' => 'party']]))->toContain('cannot carry');
});

it('refuses a party size without its exact positive quantity or the Engine\'s scope, and a member with extras', function (string $line, string $reason): void {
    expect(fn() => ConditionCodec::decodeAllStrictly($line))->toThrow(InvalidArgumentException::class, $reason);
})->with([
    'no quantity' => ['present_party_size:party', 'the exact number of members present'],
    'a zero quantity' => ['present_party_size:party:0', 'whole number above zero'],
    'a fractional quantity' => ['present_party_size:party:2.5', 'whole number above zero'],
    'a scope the Engine does not count' => ['present_party_size:crew:4', 'requires one of these names [party]'],
    'a member with a quantity' => ['present_actor:actor.kaelion:2', 'takes nothing'],
]);

it('builds both conditions a part at a time, as the grammar describes them', function (): void {
    $session = EditorSession::open(npcSessionProject([]));
    $grammar = array_column($session->describeWorldStateGrammar()['conditions'], null, 'type');

    expect($grammar['present_actor'])->toMatchArray(['label' => 'Party Member', 'nameCategory' => 'actor_ids', 'extras' => []])
        ->and($grammar['present_party_size'])->toMatchArray(['label' => 'Members Present In', 'nameCategory' => ReferenceCatalog::PRESENT_PARTY_SCOPES])
        ->and($grammar['present_party_size']['extras'])->toBe([['key' => 'quantity', 'label' => 'Exactly', 'kind' => 'integer', 'default' => 1]])
        ->and(array_column($session->listReferences(null, ReferenceCatalog::PRESENT_PARTY_SCOPES), 'value'))->toBe(PresentPartyScope::values());

    // The Terminal's condition editor: the size starts on the party and one member, and counts up and down to one.
    $editor = new ConditionEditor();
    $editor->open('conditions', 'Conditions', []);
    $editor->add();
    $types = ConditionCodec::getLeafTypes();
    $editor->cycleType(array_search('present_party_size', $types, true) - array_search('switch', $types, true));
    expect($editor->encoded())->toBe('present_party_size:party:1');
    foreach (range(1, 3) as $step) { $editor->cycleExtra(1); }
    expect($editor->encoded())->toBe('present_party_size:party:4')
        ->and($editor->rows())->toBe(['Members Present In party number exactly 4']);
    foreach (range(1, 5) as $step) { $editor->cycleExtra(-1); }
    expect($editor->encoded())->toBe('present_party_size:party:1');

    $editor->cycleType(array_search('present_actor', $types, true) - array_search('present_party_size', $types, true));
    $editor->setName('actor.kaelion');
    $editor->toggleNegate();
    expect($editor->encoded())->toBe('!present_actor:actor.kaelion')
        ->and($editor->nameReference())->toBe(['label' => 'Party Member', 'category' => 'actor_ids'])
        ->and($editor->rows())->toBe(['NOT Party Member actor.kaelion is present']);
});

it('edits an NPC\'s present party conditions in its own source, and keeps one it cannot carry as written', function (): void {
    $conditions = [['type' => 'present_actor', 'name' => 'actor.kaelion'], ['type' => 'present_party_size', 'name' => 'party', 'quantity' => 4, 'negate' => true]];
    $root = npcSessionProject([['id' => 'seller', 'name' => 'Seller', 'sprite' => '@', 'x' => 2, 'y' => 1, 'conditions' => $conditions]]);
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $session = EditorSession::open($root);
    $read = $session->readNpc('test-map', 0);

    expect(npcSessionRow($read, 'conditions'))->toMatchArray(['kind' => 'conditions', 'value' => 'present_actor:actor.kaelion; !present_party_size:party:4']);
    $session->applyNpc('test-map', $read['revision'], 0, npcSessionRow($read, 'conditions')['key'], '!present_party_size:party:1');
    $session->saveMap('test-map');
    expect((require $path)['npcs'][0]['conditions'])->toBe([['type' => 'present_party_size', 'name' => 'party', 'quantity' => 1, 'negate' => true]]);

    $read = $session->readNpc('test-map', 0);
    expect(fn() => $session->applyNpc('test-map', $read['revision'], 0, npcSessionRow($read, 'conditions')['key'], 'present_party_size:party:0'))
        ->toThrow(SessionRefusal::class, 'whole number above zero');

    // A hand-written size without its quantity is shown read-only and left as authored.
    $root = npcSessionProject([['id' => 'seller', 'name' => 'Seller', 'sprite' => '@', 'x' => 2, 'y' => 1,
        'conditions' => [['type' => 'present_party_size', 'name' => 'party']]]]);
    $original = (string) file_get_contents($root . '/assets/Maps/test-map/test-map.data.php');
    $session = EditorSession::open($root);
    $read = $session->readNpc('test-map', 0);
    expect(npcSessionRow($read, 'conditions')['kind'])->toBe('info')
        ->and(fn() => $session->applyNpc('test-map', $read['revision'], 0, npcSessionRow($read, 'conditions')['key'], 'present_party_size:party:4'))
        ->toThrow(SessionRefusal::class);
    expect(file_get_contents($root . '/assets/Maps/test-map/test-map.data.php'))->toBe($original);
});
