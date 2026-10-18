<?php

declare(strict_types=1);

use Ichiloto\Editor\EquipmentOptimizationPolicy;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * Database edits under data a file builds with its own code.
 *
 * The author's PHP owns that code: a save may change data the file writes
 * as data, never swap the code for a literal of what it produced. An edit
 * the source cannot take is refused when it is made, with the reason, and
 * nothing is written.
 */

/** A project whose Optimize policy is the given source, opened in a session. */
function openOptimizePolicy(string $source): array
{
    $root = makeTemporaryProject('editor-computed-source-');
    $path = $root . '/' . EquipmentOptimizationPolicy::RELATIVE_PATH;
    file_put_contents($path, $source);

    return [$path, EditorSession::open($root)];
}

/** The row of an Optimize Weights record for one weight. */
function findWeightRow(EditorSession $session, int $index, string $field): array
{
    $row = array_find($session->readDatabaseRecord('optimize_weights', $index)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field);
    expect($row)->not->toBeNull("weights {$index} has a {$field} row");

    return $row;
}

it('refuses a weight the file builds in a loop when it is edited, saying why, and writes nothing', function () {
    [$path, $session] = openOptimizePolicy(<<<'PHP'
<?php

$weapon = ['attack' => 5, 'speed' => 1];
$roleSlots = [];
foreach (['vanguard', 'oracle'] as $role) {
  $roleSlots[$role] = ['weapon' => $weapon];
}

return [
  'roleSlotStatWeights' => $roleSlots,
  'elementOutcomeWeights' => ['defence:*:absorb' => 16],
];
PHP);
    $before = (string) file_get_contents($path);
    $labels = $session->listDatabaseRecords('optimize_weights')['records'];
    $slot = array_search('vanguard · weapon', $labels, true);
    expect($slot)->toBeInt();

    $refusal = null;
    try {
        $session->applyDatabaseRecord('optimize_weights', $slot, findWeightRow($session, $slot, 'weights.attack')['key'], '9');
    } catch (SessionRefusal $thrown) {
        $refusal = $thrown;
    }

    expect($refusal)->not->toBeNull()
        ->and($refusal->getMessage())->toContain('$roleSlots, which the file\'s own code builds or uses')
        ->and(findWeightRow($session, $slot, 'weights.attack')['value'])->toBe('5', 'the record kept its value')
        ->and((string) file_get_contents($path))->toBe($before);
});

it('edits a weight written in a variable as data where the variable is written', function () {
    [$path, $session] = openOptimizePolicy(<<<'PHP'
<?php

// Weapon weights, kept apart for reading.
$roleSlots = [
  'vanguard' => ['weapon' => ['attack' => 5, 'speed' => 1]],
];

return [
  'roleSlotStatWeights' => $roleSlots,
];
PHP);
    $before = (string) file_get_contents($path);
    $slot = array_search('vanguard · weapon', $session->listDatabaseRecords('optimize_weights')['records'], true);
    expect($slot)->toBeInt();

    $session->applyDatabaseRecord('optimize_weights', $slot, findWeightRow($session, $slot, 'weights.attack')['key'], '9');
    $session->saveDatabase('optimize_weights');

    expect((string) file_get_contents($path))->toBe(str_replace("'attack' => 5", "'attack' => 9", $before));
});
