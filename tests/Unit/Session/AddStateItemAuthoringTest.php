<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Entities\Effects\AddStateEffect;
use Ichiloto\Engine\Entities\Inventory\ItemCatalog;
use Ichiloto\Engine\Entities\Inventory\Items\Item;

function findStateItemRow(EditorSession $session, string $field): array
{
    return array_find($session->readDatabaseRecord('items', 0)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field)
        ?? throw new RuntimeException('Missing item row: ' . $field);
}

it('authors a state-granting item through the shared picker and source-preserving undo', function () {
    $root = makeTemporaryProject();
    $files = writeItemRecords($root, new Item('Synthetic tonic', '', '', 0,
        effects: [new AddStateEffect('Grant state', '', 'poison')], id: 'item.synthetic-tonic'));
    $path = $root . '/' . $files[0];
    file_put_contents($path, str_replace('<?php', "<?php\n// Keep this authored comment.", (string) file_get_contents($path)));
    $before = file_get_contents($path);
    $session = EditorSession::open($root);
    $state = findStateItemRow($session, 'effect0StateId');
    expect($state['reference'])->toBe('states');
    $session->applyDatabaseRecord('items', 0, $state['key'], 'stun');
    $session->saveDatabase('items');
    $read = ItemCatalog::loadProjectItems($root . '/assets')[0];
    expect($read->effects[0])->toBeInstanceOf(AddStateEffect::class)
        ->and($read->effects[0]->stateId)->toBe('stun')
        ->and(file_get_contents($path))->toContain('// Keep this authored comment.');
    $session->undo();
    $session->saveDatabase('items');
    expect(file_get_contents($path))->toBe($before);
    $session->redo();
    $session->applyDatabaseRecord('items', 0, findStateItemRow($session, 'effect0Guaranteed')['key'], 'true');
    $session->saveDatabase('items');
    expect(ItemCatalog::loadProjectItems($root . '/assets')[0]->effects[0]->guaranteed)->toBeTrue();
});

it('offers add-state when changing a value effect and removes fields owned by the previous effect type', function () {
    $session = EditorSession::open(makeTemporaryProject());
    $session->addDatabaseItem('items', 0, ['frame' => []]);
    $type = findStateItemRow($session, 'effect0Type');
    expect($type['options'])->toContain('add_state');
    $session->applyDatabaseRecord('items', 0, $type['key'], 'add_state');
    $session->applyDatabaseRecord('items', 0, findStateItemRow($session, 'effect0StateId')['key'], 'poison');
    $session->applyDatabaseRecord('items', 0, findStateItemRow($session, 'effect0ChancePercent')['key'], '50');
    $session->saveDatabase('items');
    $effects = $session->readDatabaseRecord('items', 0);
    expect(json_encode($effects))->not->toContain('effect0ValueBasis')->not->toContain('effect0SuccessRate');
});
