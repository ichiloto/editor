<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Session\SessionRefusal;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Editor\Validation\ResponseWindowValidator;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\PrepareResponseWindowSkillEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;

/**
 * A battle-only warning skill authors its response window: the follow-up skill it schedules, picked from the skills
 * the Engine's battle reactions accept, and its response turns, through the shared session rows, saves and undo.
 * Synthetic fixtures only.
 */
function createResponseWindowSkill(string $name, array $effects, ItemScopeNumber $number = ItemScopeNumber::ALL): SpecialSkill
{
    return new SpecialSkill($name, '', '', 0, 0, new ItemScope(ItemScopeSide::ENEMY, $number, ItemScopeStatus::ALIVE),
        Occasion::BATTLE_SCREEN, effects: $effects);
}

/**
 * A project with a warning still dealing damage, a compatible follow-up, a single-target skill and another warning,
 * the warning's file carrying an authored comment.
 *
 * @return array{0: string, 1: string}
 */
function createResponseWindowProject(): array
{
    $root = makeTemporaryProject();
    writeSkillRecords($root,
        createResponseWindowSkill('Synthetic warning', [new HPDamageSkillEffect('$user->stats->attack')]),
        createResponseWindowSkill('Synthetic impact', [new HPDamageSkillEffect('$user->stats->attack * 3')]),
        createResponseWindowSkill('Synthetic jab', [new HPDamageSkillEffect('$user->stats->attack')], ItemScopeNumber::ONE),
        createResponseWindowSkill('Synthetic other warning', [new PrepareResponseWindowSkillEffect('Synthetic impact', 2)]),
    );
    $path = glob($root . '/assets/Data/' . SkillCatalog::DIRECTORY . '/0001-*.php')[0];
    file_put_contents($path, str_replace('<?php', "<?php\n// Keep this authored comment.", (string) file_get_contents($path)));

    return [$root, $path];
}

function findResponseWindowRow(EditorSession $session, string $field, int $index = 0): array
{
    return array_find($session->readDatabaseRecord('skills', $index)['rows'],
        static fn(array $row): bool => ($row['key']['field'] ?? null) === $field)
        ?? throw new RuntimeException('Missing skill row: ' . $field);
}

/** Turns the warning's one effect into a response window, as an author choosing the type does. */
function chooseResponseWindowType(EditorSession $session): void
{
    $session->applyDatabaseRecord('skills', 0, findResponseWindowRow($session, 'effect0Type')['key'], 'prepare_response_window');
}

it('authors a response window from the Engine\'s compatible follow-ups, keeping source and undo', function () {
    [$root, $path] = createResponseWindowProject();
    $before = file_get_contents($path);
    $session = EditorSession::open($root);
    chooseResponseWindowType($session);
    $skill = findResponseWindowRow($session, 'effect0Skill');
    expect($skill['kind'])->toBe('reference')->and($skill['reference'])->toBe(ReferenceCatalog::RESPONSE_SKILLS)
        ->and(findResponseWindowRow($session, 'effect0ResponseTurns')['kind'])->toBe('integer');
    // Only the compatible follow-up: never the warning itself, a single-target skill or another warning.
    expect(array_column($session->listReferences(null, ReferenceCatalog::RESPONSE_SKILLS), 'value'))->toBe(['Synthetic impact']);

    $session->applyDatabaseRecord('skills', 0, $skill['key'], 'Synthetic impact');
    $session->applyDatabaseRecord('skills', 0, findResponseWindowRow($session, 'effect0ResponseTurns')['key'], '1');
    $session->saveDatabase('skills');
    $effects = SkillCatalog::load($root . '/assets')->findSkill('Synthetic warning')->effects;
    expect($effects)->toHaveCount(1)->and($effects[0])->toBeInstanceOf(PrepareResponseWindowSkillEffect::class)
        ->and($effects[0]->skill)->toBe('Synthetic impact')->and($effects[0]->responseTurns)->toBe(1)
        ->and(file_get_contents($path))->toContain('// Keep this authored comment.')
        ->and(json_encode($session->readDatabaseRecord('skills', 0)['panes']))->toContain('Synthetic impact after 1 response turn');
    expect(new ResponseWindowValidator()->validate(ProjectWorkspace::fromProject($root)))->toBe([]);

    $session->undo();
    $session->undo();
    $session->undo();
    $session->saveDatabase('skills');
    expect(file_get_contents($path))->toBe($before);
});

it('refuses a follow-up the Engine would not schedule, the warning itself included, before writing', function () {
    [$root] = createResponseWindowProject();
    $session = EditorSession::open($root);
    chooseResponseWindowType($session);
    $key = findResponseWindowRow($session, 'effect0Skill')['key'];
    foreach (['Synthetic jab', 'Synthetic other warning', 'Synthetic warning', 'Missing skill'] as $refused) {
        expect(fn() => $session->applyDatabaseRecord('skills', 0, $key, $refused))
            ->toThrow(SessionRefusal::class, sprintf('Response skill "%s" must be a battle-only follow-up', $refused));
    }
    expect(findResponseWindowRow($session, 'effect0Skill')['value'] ?? '')->not->toContain('Synthetic');

    // A finished warning still refuses to name itself.
    $session->applyDatabaseRecord('skills', 0, $key, 'Synthetic impact');
    $session->applyDatabaseRecord('skills', 0, findResponseWindowRow($session, 'effect0ResponseTurns')['key'], '1');
    expect(fn() => $session->applyDatabaseRecord('skills', 0, $key, 'Synthetic warning'))
        ->toThrow(SessionRefusal::class, 'Response skill "Synthetic warning"');
});

it('refuses a warning record the Engine\'s reader refuses on save, writing nothing', function () {
    [$root, $path] = createResponseWindowProject();
    $before = file_get_contents($path);
    $session = EditorSession::open($root);
    chooseResponseWindowType($session);
    $session->applyDatabaseRecord('skills', 0, findResponseWindowRow($session, 'effect0Skill')['key'], 'Synthetic impact');
    $session->applyDatabaseRecord('skills', 0, findResponseWindowRow($session, 'effect0ResponseTurns')['key'], '0');
    expect(fn() => $session->saveDatabase('skills'))->toThrow(SessionRefusal::class, 'at least one response turn');
    expect(file_get_contents($path))->toBe($before);

    $session->applyDatabaseRecord('skills', 0, findResponseWindowRow($session, 'effect0ResponseTurns')['key'], '1');
    $session->applyDatabaseRecord('skills', 0, findResponseWindowRow($session, 'scope.number')['key'], ItemScopeNumber::ONE->value);
    expect(fn() => $session->saveDatabase('skills'))->toThrow(SessionRefusal::class, 'must be the only effect and target all living opponents');
    expect(file_get_contents($path))->toBe($before);
});

it('reports a warning whose follow-up went missing or became incompatible, in the Engine\'s words', function () {
    $root = makeTemporaryProject();
    writeSkillRecords($root,
        createResponseWindowSkill('Synthetic warning', [new PrepareResponseWindowSkillEffect('Synthetic impact', 1)]),
        createResponseWindowSkill('Synthetic impact', [new HPDamageSkillEffect('$user->stats->attack')], ItemScopeNumber::ONE),
        createResponseWindowSkill('Synthetic echo', [new PrepareResponseWindowSkillEffect('Synthetic echo', 1)]),
        createResponseWindowSkill('Synthetic lost', [new PrepareResponseWindowSkillEffect('Synthetic gone', 1)]),
    );
    $issues = new ResponseWindowValidator()->validate(ProjectWorkspace::fromProject($root));
    expect(array_map(static fn($issue): string => $issue->where, $issues))->toBe([
        'assets/Data/Skills: Synthetic warning', 'assets/Data/Skills: Synthetic echo', 'assets/Data/Skills: Synthetic lost'])
        ->and($issues[0]->message)->toContain('Response skill "Synthetic impact" must be a battle-only follow-up')
        ->and($issues[1]->message)->toContain('Response skill "Synthetic echo"')
        ->and($issues[2]->message)->toContain('Response skill "Synthetic gone"');
    $project = array_filter(new ProjectValidator()->validate(ProjectWorkspace::fromProject($root)),
        static fn($issue): bool => str_contains($issue->message, 'Response skill'));
    expect($project)->toHaveCount(3);
});
