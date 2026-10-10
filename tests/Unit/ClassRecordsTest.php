<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ProjectRecordDatabase;
use Ichiloto\Editor\Database\RecordSchemaCatalog;
use Ichiloto\Editor\Session\EditorSession;

function classRecords(string $root): ProjectRecordDatabase
{
    return ProjectRecordDatabase::fromProject($root, RecordSchemaCatalog::forKey('classes'));
}

it('opens classes in the GUI session through the shared record service', function () {
    $records = EditorSession::open(makeTemporaryProject())->listDatabaseRecords('classes');

    expect($records['records'])->toBe(['Vanguard', 'Oracle'])
        ->and($records['editable'])->toBeTrue()
        ->and($records['canCreate'])->toBeTrue();
});

it('edits what the engine reads in its own source: curves, equipment types and skills learned', function () {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/classes.php';
    file_put_contents($path, str_replace("    'name' => 'Oracle',", "    // The healers.\n    'name' => 'Oracle',", (string) file_get_contents($path)));
    $classes = classRecords($root);

    $classes->setField(1, 'parameterCurves.speed.extraGrowth', '18');
    $classes->setField(1, 'equipment.weapons', 'Staff, Wand');
    $learn = $classes->addSubItem(1);
    $classes->setField(1, ProjectRecordDatabase::subFieldId('learn', $learn, 'skill'), 'Fire');
    $classes->setField(1, ProjectRecordDatabase::subFieldId('learn', $learn, 'level'), '3');
    $classes->save();

    $oracle = (require $path)[1];

    expect((string) file_get_contents($path))->toContain('// The healers.')
        ->and($oracle['parameterCurves']['speed']['extraGrowth'])->toBe(18)
        ->and($oracle['equipment']['weapons'])->toBe(['Staff', 'Wand'])
        ->and($oracle['skillsToLearn'])->toBe([['level' => 3, 'skill' => 'Fire']])
        ->and($oracle['parameterCurves']['totalHp'])->toBe(['baseValue' => 90, 'extraGrowth' => 360, 'flatIncrement' => 28])
        ->and((require $path)[0])->toBe((require makeTemporaryProject() . '/assets/Data/classes.php')[0]);
});

it('gives a new class the next id and a name of its own', function () {
    $classes = classRecords(makeTemporaryProject());
    $index = $classes->addRecord();
    $record = $classes->getRecordByIndex($index);

    expect($record?->get('id'))->toBe(3)
        ->and($classes->getEntryLabels())->toBe(['Vanguard', 'Oracle', 'New Class']);
});

it('offers the engine\'s weapon and armor types for a class\'s equipment', function () {
    $session = EditorSession::open(makeTemporaryProject());
    $map = $session->describeMaps()[0]['id'] ?? '';
    $values = static fn(string $category): array => array_column($session->listReferences($map, $category), 'value');

    expect($values('weapon_types'))->toContain('Sword', 'Staff')
        ->and($values('armor_types'))->toContain('Heavy Armor', 'Large Shield')
        ->and($values('classes'))->toBe(['Vanguard', 'Oracle']);
});

it('lists classes in the terminal by their number and name', function () {
    $editor = deletionEditor(makeTemporaryProject());
    openDatabaseCategory($editor, 'classes');

    expect(callEditorMethod($editor, 'getDatabaseListLines'))->toBe(['> 0001 Vanguard', '  0002 Oracle']);
});
