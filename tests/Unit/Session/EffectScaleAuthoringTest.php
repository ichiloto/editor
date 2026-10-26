<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;

/** A skill's or item's Effect Scale, authored beside its Animation in the database both editors share. Synthetic fixtures only. */

/** @return array<string, mixed> The record row a field id names. */
function findEffectScaleRow(EditorSession $session, string $category): array
{
    return array_find($session->readDatabaseRecord($category, 0)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === 'animationScale')
        ?? throw new RuntimeException("No Effect Scale row in {$category}.");
}

it('authors a skill effect scale as one undoable edit the Engine reads, and clears it back to the timeline size', function () {
    $root = makeTemporaryProject();
    writeSkillRecords($root, new MagicSkill('Grade I', '', '', 1, 0, animationId: 1));
    $path = $root . '/assets/Data/Skills/0001-grade-i.php';
    $session = EditorSession::open($root);
    $row = findEffectScaleRow($session, 'skills');

    expect($row['label'])->toBe('Effect Scale')->and($row['kind'])->toBe('float');
    $session->applyDatabaseRecord('skills', 0, $row['key'], '0.75');
    $session->saveDatabase('skills');
    expect(SkillCatalog::load($root . '/assets')->findSkill('Grade I')?->animationScale)->toBe(.75)
        ->and(file_get_contents($path))->toContain("'animationScale' => 0.75");

    $session->applyDatabaseRecord('skills', 0, findEffectScaleRow($session, 'skills')['key'], '');
    $session->saveDatabase('skills');
    expect(SkillCatalog::load($root . '/assets')->findSkill('Grade I')?->animationScale)->toBe(1.0)
        ->and(file_get_contents($path))->not->toContain('animationScale');
});

it('reports a scale the Engine cannot play as the Engine reads it', function () {
    $root = makeTemporaryProject();
    writeSkillRecords($root, new MagicSkill('Grade I', '', '', 1, 0, animationId: 1));
    $workspace = ProjectWorkspace::fromProject($root);
    $workspace->getRecordDatabase('skills')?->setField(0, 'animationScale', '9');

    expect(implode("\n", $workspace->loadSkillCatalog()->getProblems()))->toContain('animationScale must be a number from 0.25 to 4');
});

it('authors an item effect scale through the same binding field', function () {
    $root = makeTemporaryProject();
    $database = loadRecordDatabase($root, 'items');
    $database->setField(0, 'animationId', '1');
    $database->setField(0, 'animationScale', '1.5');
    $database->save();
    expect(loadRecordDatabase($root, 'items')->getRecords()[0]->get('animationScale'))->toBe(1.5);
});
