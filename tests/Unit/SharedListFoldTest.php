<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordField;
use Ichiloto\Editor\Database\RecordSchema;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Database\RecordStorage;
use Ichiloto\Editor\Database\SharedFileTransaction;
use Ichiloto\Editor\Inspector\InputControlType;

/**
 * Several categories over one list that is data, not constructor calls.
 *
 * A file of array literals is written by regenerating its returned
 * expression, and when two categories share it each used to regenerate the
 * whole file from its own, older reading -- so the second could put back what
 * the first had just changed. Now the file is read once, every dirty category
 * folds its part into that one reading, and the result is written once.
 *
 * Last Legend has no such file: its shared list is constructor-authored and
 * edited in place. This fixture is synthetic so that the branch is exercised
 * rather than trusted.
 */

/**
 * Builds a project holding one list shared by three record-filter categories.
 *
 * @return array{0: string, 1: string} The project root and the list's path.
 */
function rosterProject(): array
{
    $root = makeTemporaryProject('ichiloto-roster-');
    $path = $root . '/assets/Data/roster.php';

    file_put_contents($path, <<<'PHP_SOURCE'
<?php

// The house roster: guests, staff, and the odd visitor, in one list.
return [
  ['kind' => 'guest', 'id' => 'guest.ada', 'name' => 'Ada', 'room' => 1],
  ['kind' => 'staff', 'id' => 'staff.bram', 'name' => 'Bram', 'shift' => 'day'],
  ['kind' => 'guest', 'id' => 'guest.cleo', 'name' => 'Cleo', 'room' => 2],
  ['kind' => 'visitor', 'id' => 'visitor.dev', 'name' => 'Dev'],
  ['kind' => 'staff', 'id' => 'staff.eve', 'name' => 'Eve', 'shift' => 'night'],
  ['kind' => 'guest', 'id' => 'guest.finn', 'name' => 'Finn', 'room' => 3],
];
PHP_SOURCE);

    return [$root, $path];
}

/**
 * Declares one category over the roster: the entries of one kind.
 */
function rosterSchema(string $kind): RecordSchema
{
    return new RecordSchema(
        key: 'roster_' . $kind . 's',
        entryNoun: $kind,
        storage: RecordStorage::LIST_FILE,
        relativePath: 'assets/Data/roster.php',
        fields: [
            new RecordField('id', 'Id', isReadOnly: true),
            new RecordField('name', 'Name'),
            new RecordField('room', 'Room', InputControlType::INTEGER, removeWhenEmpty: true),
            new RecordField('shift', 'Shift', removeWhenEmpty: true),
        ],
        labelKey: 'name',
        identityKey: 'id',
        blank: ['kind' => $kind, 'id' => $kind . '.new', 'name' => 'New ' . ucfirst($kind)],
        recordFilter: static fn(mixed $entry): bool => is_array($entry) && ($entry['kind'] ?? null) === $kind,
    );
}

/**
 * Opens every roster category at once, as the editor would hold them.
 *
 * @return array<string, ProjectRecordDatabase>
 */
function rosterCategories(string $root): array
{
    $open = [];

    foreach (['guest', 'staff', 'visitor'] as $kind) {
        $open[$kind] = ProjectRecordDatabase::fromProject($root, rosterSchema($kind));
    }

    return $open;
}

/**
 * Reads the roster fresh: every entry's id, in file order, and each entry.
 *
 * @return array{ids: string[], entries: array<string, array<string, mixed>>}
 */
function rosterOnDisk(string $path): array
{
    $ids = [];
    $entries = [];

    foreach ((array) (require $path) as $entry) {
        $ids[] = strval($entry['id']);
        $entries[strval($entry['id'])] = $entry;
    }

    return ['ids' => $ids, 'entries' => $entries];
}

it('reads each kind as its own category over the one list', function () {
    [$root, $path] = rosterProject();
    $open = rosterCategories($root);

    expect($open['guest']->getEntryLabels())->toBe(['Ada', 'Cleo', 'Finn'])
        ->and($open['staff']->getEntryLabels())->toBe(['Bram', 'Eve'])
        ->and($open['visitor']->getEntryLabels())->toBe(['Dev'])
        ->and($open['guest']->isEditable())->toBeTrue()
        ->and($open['guest']->sharesBackingFile())->toBeTrue()
        ->and(count(SharedFileTransaction::groupByPath($open)))->toBe(1);
});

it('keeps an earlier sibling save when a later category writes from an older reading', function () {
    [$root, $path] = rosterProject();
    $open = rosterCategories($root);

    // Guests save a change. Staff, opened before that save, then remove an
    // entry and save: their reading of the file predates the guests' write.
    $open['guest']->setField(0, 'room', '11');
    $open['guest']->save();

    $open['staff']->removeRecord(0);
    $open['staff']->save();

    $disk = rosterOnDisk($path);

    // Both survive: the file was read fresh for the staff write and only the
    // staff part folded into it.
    expect($disk['ids'])->toBe(['guest.ada', 'guest.cleo', 'visitor.dev', 'staff.eve', 'guest.finn'])
        ->and($disk['entries']['guest.ada']['room'])->toBe(11)
        ->and($disk['entries']['visitor.dev'])->toBe(['kind' => 'visitor', 'id' => 'visitor.dev', 'name' => 'Dev']);
});

it('folds every dirty category into one payload and writes it once', function () {
    [$root, $path] = rosterProject();
    $open = rosterCategories($root);

    // Three dirty categories at once: a field edit, a removal, an addition.
    $open['guest']->setField(1, 'name', 'Cleopatra');
    $open['staff']->removeRecord(1);
    $added = $open['visitor']->addRecord();
    $open['visitor']->setField(intval($added), 'name', 'Gus');

    expect(SharedFileTransaction::commit(array_values($open)))->toBeTrue();

    $disk = rosterOnDisk($path);

    expect($disk['ids'])->toBe(['guest.ada', 'staff.bram', 'guest.cleo', 'visitor.dev', 'guest.finn', 'visitor.new'])
        ->and($disk['entries']['guest.cleo']['name'])->toBe('Cleopatra')
        ->and($disk['entries']['visitor.new'])->toBe(['kind' => 'visitor', 'id' => 'visitor.new', 'name' => 'Gus'])
        ->and($open['guest']->isDirty())->toBeFalse()
        ->and($open['staff']->isDirty())->toBeFalse()
        ->and($open['visitor']->isDirty())->toBeFalse()
        // The header above the list is not the list, and stays.
        ->and((string) file_get_contents($path))->toContain('// The house roster: guests, staff, and the odd visitor, in one list.');

    // Reloaded, every category lists exactly what it saved.
    $reloaded = rosterCategories($root);

    expect($reloaded['guest']->getEntryLabels())->toBe(['Ada', 'Cleopatra', 'Finn'])
        ->and($reloaded['staff']->getEntryLabels())->toBe(['Bram'])
        ->and($reloaded['visitor']->getEntryLabels())->toBe(['Dev', 'Gus']);
});

it('regenerates the list around a delete, an undo after save, and an edit of the restored entry', function () {
    [$root, $path] = rosterProject();
    $open = rosterCategories($root);
    $guests = $open['guest'];

    // The review's sequence, on a list that is data: delete the middle
    // guest, save, undo, save, edit the restored guest, save, reload.
    $removed = $guests->removeRecord(1);
    $guests->save();

    expect(rosterOnDisk($path)['ids'])->toBe(['guest.ada', 'staff.bram', 'visitor.dev', 'staff.eve', 'guest.finn']);

    $guests->insertRecord(1, $removed);
    $guests->save();
    $guests->setField(1, 'room', '22');
    $guests->save();

    $disk = rosterOnDisk($path);
    $reloaded = ProjectRecordDatabase::fromProject($root, rosterSchema('guest'));

    // Cleo is back with her edit; Ada and Finn are exactly themselves.
    expect($reloaded->getEntryLabels())->toBe(['Ada', 'Cleo', 'Finn'])
        ->and($disk['entries']['guest.cleo']['room'])->toBe(22)
        ->and($disk['entries']['guest.ada'])->toBe(['kind' => 'guest', 'id' => 'guest.ada', 'name' => 'Ada', 'room' => 1])
        ->and($disk['entries']['guest.finn'])->toBe(['kind' => 'guest', 'id' => 'guest.finn', 'name' => 'Finn', 'room' => 3])
        ->and($disk['entries']['staff.bram'])->toBe(['kind' => 'staff', 'id' => 'staff.bram', 'name' => 'Bram', 'shift' => 'day']);
});

it('changes no byte and leaves every category dirty when the one write is refused', function () {
    [$root, $path] = rosterProject();
    $before = (string) file_get_contents($path);
    $open = rosterCategories($root);

    $open['guest']->setField(0, 'room', '11');
    $open['staff']->removeRecord(0);

    chmod($root . '/assets/Data', 0o555);
    set_error_handler(static fn(): bool => true, E_WARNING);

    try {
        expect(static fn() => SharedFileTransaction::commit(array_values($open)))->toThrow(RuntimeException::class);
    } finally {
        restore_error_handler();
        chmod($root . '/assets/Data', 0o755);
    }

    expect((string) file_get_contents($path))->toBe($before)
        ->and($open['guest']->isDirty())->toBeTrue()
        ->and($open['staff']->isDirty())->toBeTrue()
        ->and($open['visitor']->isDirty())->toBeFalse();
});

it('removes a troop from a list of arrays and leaves a file that still loads', function () {
    // The same fallback, on a category the editor ships: removing an entry
    // of an array-literal list used to be attempted as source surgery on a
    // placeholder, which cut one byte and left a file that no longer parsed.
    $root = makeTemporaryProject('ichiloto-roster-');
    $path = $root . '/assets/Data/troops.php';
    $troops = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('troops'));
    $names = $troops->getEntryLabels();

    $troops->removeRecord(0);
    $troops->save();

    $reloaded = ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('troops'));

    expect($reloaded->getReadOnlyReason())->toBeNull()
        ->and($reloaded->getEntryLabels())->toBe(array_slice($names, 1))
        ->and($troops->isDirty())->toBeFalse();
});
