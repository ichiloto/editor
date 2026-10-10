<?php

declare(strict_types=1);

use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;

/**
 * Every Database category, as an editor outside the terminal reaches it:
 * each opens and reads every record, each that can be written creates a
 * record and saves it, and then takes an edit, saves it, undoes it and saves
 * again back to exactly the bytes it read. Synthetic fixtures only; the
 * categories come from the catalogue, so a new one is covered without being
 * named here.
 */

/**
 * Makes one edit to a record through the session: the first row that takes
 * a different value (a word more, a number more, another option or
 * reference, the other truth value), else a list gains an entry, from its
 * heading or at the record's own list's end, as an interface adds one.
 *
 * @return bool Whether an edit was made.
 */
function editRecordOnce(EditorSession $session, string $category, int $index): bool
{
    $read = $session->readDatabaseRecord($category, $index);
    // References are read in the context of a map.
    $map = $session->describeMaps()[0]['id'];

    foreach ($read['rows'] as $row) {
        $value = match ($row['kind']) {
            'reference' => array_values(array_diff(
                array_column($session->listReferences($map, $row['reference']), 'value'),
                [$row['value']],
            ))[0] ?? null,
            'text' => ($row['value'] ?? '') === '' ? null : $row['value'] . ' Revised',
            'integer' => is_numeric($row['raw'] ?? null) ? (string) ((int) $row['raw'] + 1) : null,
            'float' => is_numeric($row['raw'] ?? null) ? (string) ((float) $row['raw'] + 0.5) : null,
            'boolean' => ($row['raw'] ?? $row['value']) === 'true' ? 'false' : 'true',
            'options' => array_values(array_diff($row['options'] ?? [], [$row['value']]))[0] ?? null,
            default => null,
        };

        if ($value === null || ! isset($row['key'])) {
            continue;
        }

        try {
            if (($session->applyDatabaseRecord($category, $index, $row['key'], $value)['changed'] ?? false) === true) {
                return true;
            }
        } catch (SessionRefusal) {
            continue;
        }
    }

    // A list's heading offers its first entry, as the GUI's "+ element" does.
    $heading = array_find($read['rows'], static fn(array $row): bool => ($row['listHeading'] ?? false) === true && isset($row['key']));

    if ($heading !== null) {
        return $session->addDatabaseItem($category, $index, $heading['key'], child: true)['changed'];
    }

    return ($read['listNoun'] ?? null) !== null
        && $session->addDatabaseItem($category, $index, ['frame' => []])['changed'];
}

it('opens every category, and creates, edits, saves and undoes in each writable one back to the bytes it read', function () {
    $root = makeTemporaryProject();
    // A new enemy starts on the project's first enemy sprite.
    mkdir($root . '/assets/Graphics/Enemies', 0o777, true);
    file_put_contents($root . '/assets/Graphics/Enemies/blob.txt', "(oo)\n");
    // A new field resource starts on the project's first image.
    writeTilesetTestPng($root . '/assets/Graphics/Props/Coverage.png', 4, 4);
    $session = EditorSession::open($root);
    $writable = [];

    foreach ($session->describeProject()['databases'] as $category) {
        $key = $category['key'];
        $list = $session->listDatabaseRecords($key);

        foreach (array_keys($list['records']) as $index) {
            expect($session->readDatabaseRecord($key, $index)['rows'])->toBeArray();
        }

        if (! $list['editable']) {
            continue;
        }

        if ($list['records'] === [] && $list['canCreate']) {
            $created = $session->createDatabaseRecord($key);

            expect($session->saveDatabase($key)['saved'])->toBeTrue()
                ->and(EditorSession::open($root)->listDatabaseRecords($key)['records'])->toBe($created['records']);
        }

        $writable[] = $key;
    }

    $before = sourceHashTree($root);
    $roundTripped = [];

    foreach ($writable as $key) {
        $read = $session->readDatabaseRecord($key, 0)['rows'];

        if (! editRecordOnce($session, $key, 0)) {
            continue;
        }

        expect($session->saveDatabase($key)['saved'])->toBeTrue()
            ->and(sourceHashTree($root))->not->toBe($before);

        $session->undo();
        $session->saveDatabase($key);

        $after = sourceHashTree($root);

        // Every file it read holds its bytes again. A category over defaults
        // alone (a project with no system.php) has its file now, written by
        // the save before the undo and holding what it read.
        expect(array_intersect_key($after, $before))->toBe($before, sprintf('%s did not save back to the bytes it read.', $key))
            ->and(count($after) - count($before))->toBeLessThanOrEqual(1)
            ->and(EditorSession::open($root)->readDatabaseRecord($key, 0)['rows'])->toBe($read);
        $before = $after;
        $roundTripped[] = $key;
    }

    // Every category the session can write took an edit and gave it back.
    expect($roundTripped)->toBe($writable);
});

it('says what a new record needs when the project lacks it', function () {
    $session = EditorSession::open(makeTemporaryProject());

    expect(fn() => $session->createDatabaseRecord('enemies'))
        ->toThrow(SessionRefusal::class, 'A new enemy starts on the project\'s first enemy sprite. Add one under assets/Graphics/Enemies first.');
});
