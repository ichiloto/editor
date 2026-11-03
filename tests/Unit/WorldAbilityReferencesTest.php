<?php

declare(strict_types=1);

use Ichiloto\Editor\Database\ConditionEditor;
use Ichiloto\Editor\Database\ReferenceCatalog;
use Ichiloto\Editor\Events\ScriptReferenceScope;
use Ichiloto\Editor\Events\ScriptReferenceSource;
use Ichiloto\Editor\Field\NpcAuthoring;
use Ichiloto\Editor\Field\NpcRefusal;
use Ichiloto\Editor\Progression\ProjectWorldAbilities;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Engine\Core\WorldConditionExclusion;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandReference;
use Ichiloto\Engine\Progression\WorldAbilityCatalog;
use Ichiloto\Engine\Util\Config\ConfigStore;

/** Declares two synthetic exploration powers, the second depending on the first. */
function writeSyntheticWorldAbilities(string $root): void
{
    file_put_contents($root . '/' . ProjectWorldAbilities::RELATIVE_PATH, "<?php\n\nreturn " . var_export([
        ['id' => 'sprint', 'name' => 'Sprint', 'conditions' => [['type' => 'switch', 'name' => 'sprint_granted']]],
        ['id' => 'far-sight', 'name' => 'Far Sight', 'conditions' => [['type' => 'world_ability', 'name' => 'sprint']]],
    ], true) . ";\n");
}

/** Writes an authored PHP data file. */
function writeWorldAbilityData(string $path, array $payload): void
{
    file_put_contents($path, "<?php\n\nreturn " . var_export($payload, true) . ";\n");
}

/** @return list<string> The messages validation reports where the path contains the fragment. */
function worldAbilityMessages(string $root, string $where): array
{
    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));

    return array_values(array_map(static fn($issue): string => $issue->message,
        array_filter($issues, static fn($issue): bool => str_contains($issue->where, $where))));
}

afterEach(function (): void {
    ConfigStore::remove(WorldAbilityCatalog::class);
});

it('offers the project\'s declared world powers through one picker for commands and conditions', function (): void {
    $root = makeTemporaryProject();
    writeSyntheticWorldAbilities($root);
    $references = new ReferenceCatalog(ProjectWorkspace::fromProject($root));
    $source = ScriptReferenceSource::forReference(ScriptCommandReference::WORLD_ABILITY);
    $grammar = array_column(ConditionEditor::getGrammar(), null, 'type');

    expect($source->isSupported)->toBeTrue()
        ->and($source->scope)->toBe(ScriptReferenceScope::PROJECT)
        ->and($source->category)->toBe(ReferenceCatalog::WORLD_ABILITIES)
        ->and($grammar['world_ability']['nameCategory'])->toBe(ReferenceCatalog::WORLD_ABILITIES)
        // Authored order and stable ids, labelled with the declared names.
        ->and($references->valuesFor(ReferenceCatalog::WORLD_ABILITIES))->toBe(['sprint', 'far-sight'])
        ->and($references->labelsFor(ReferenceCatalog::WORLD_ABILITIES))->toBe(['sprint' => 'Sprint (sprint)', 'far-sight' => 'Far Sight (far-sight)'])
        ->and(ConditionEditor::describe(['type' => 'world_ability', 'name' => 'sprint', 'negate' => true]))->toBe('NOT World Power sprint is unlocked');
});

it('reads the current source on every read, so a changed file needs no reopening', function (): void {
    $root = makeTemporaryProject();
    $workspace = ProjectWorkspace::fromProject($root);
    expect($workspace->getWorldAbilities()->getLabels())->toBe([]);

    writeSyntheticWorldAbilities($root);

    expect(array_keys($workspace->getWorldAbilities()->getLabels()))->toBe(['sprint', 'far-sight']);
});

it('installs the open project\'s catalogue for Engine evaluation and never keeps another project\'s', function (): void {
    $first = makeTemporaryProject();
    writeSyntheticWorldAbilities($first);
    ProjectWorkspace::fromProject($first);

    // The Engine's reachability calls exclusion without a catalogue; the installed one answers it.
    expect(WorldConditionExclusion::proveExclusive(
        [['type' => 'world_ability', 'name' => 'far-sight']],
        [['type' => 'switch', 'name' => 'sprint_granted', 'value' => false]],
    ))->toBeTrue();

    ProjectWorkspace::fromProject(makeTemporaryProject());
    expect(WorldAbilityCatalog::getProjectCatalog()->getAbilities())->toBe([]);

    $broken = makeTemporaryProject();
    file_put_contents($broken . '/' . ProjectWorldAbilities::RELATIVE_PATH, "<?php\n\nreturn [['id' => 'loop', 'name' => 'Loop', 'conditions' => [['type' => 'world_ability', 'name' => 'loop']]]];\n");
    ProjectWorkspace::fromProject($broken);

    expect(ConfigStore::has(WorldAbilityCatalog::class))->toBeFalse();
});

it('reports world power references the project does not declare, in commands, conditions and the dash', function (): void {
    $root = makeTemporaryProject();
    writeSyntheticWorldAbilities($root);
    writeWorldAbilityData($root . '/assets/Events/powers.php', [
        ['type' => 'show_power_unlocks', 'abilities' => [['ability' => 'sprint'], ['ability' => 'levitate']]],
        ['type' => 'branch', 'conditions' => [['type' => 'world_ability', 'name' => 'far-sight'], ['type' => 'world_ability', 'name' => 'burrow']], 'then' => []],
    ]);
    $system = $root . '/assets/Data/system.php';
    $data = is_file($system) ? require $system : [];
    $data['dash'] = ['enabled' => true, 'conditions' => [['type' => 'world_ability', 'name' => 'glide']]];
    writeWorldAbilityData($system, $data);

    $scripts = implode("\n", worldAbilityMessages($root, 'powers'));
    $dash = implode("\n", worldAbilityMessages($root, 'system.php: dash'));

    expect($scripts)->toContain('"levitate"')->toContain('"burrow"')
        ->not->toContain('"sprint"')->not->toContain('"far-sight"')
        ->and($dash)->toContain('It names the world power "glide", which assets/Data/world-abilities.php does not declare.');
});

it('reports a world-abilities source the Engine refuses and still validates the rest of the project', function (): void {
    $root = makeTemporaryProject();
    file_put_contents($root . '/' . ProjectWorldAbilities::RELATIVE_PATH, "<?php\n\nreturn [['id' => 'sprint', 'name' => 'Sprint', 'conditions' => [['type' => 'world_ability', 'name' => 'missing']]]];\n");

    $messages = worldAbilityMessages($root, ProjectWorldAbilities::RELATIVE_PATH);

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toStartWith('The project\'s world abilities could not be read:')
        ->and(fn() => ProjectWorkspace::fromProject($root)->analyzeReachability())
        ->toThrow(RuntimeException::class, 'world abilities in assets/Data/world-abilities.php could not be read');
});

it('lets phases share a tile when the project\'s world powers prove them exclusive, and nothing else', function (): void {
    $root = makeTemporaryProject();
    writeSyntheticWorldAbilities($root);
    $path = $root . '/assets/Maps/test-map/test-map.data.php';
    $data = require $path;
    $data['npcs'] = [
        ['id' => 'watcher', 'name' => 'Watcher', 'x' => 2, 'y' => 1, 'conditions' => [['type' => 'world_ability', 'name' => 'far-sight']]],
        // Far Sight implies Sprint, which implies its switch: the switch off excludes it.
        ['id' => 'sleeper', 'name' => 'Sleeper', 'x' => 6, 'y' => 3, 'conditions' => [['type' => 'switch', 'name' => 'sprint_granted', 'value' => false]]],
        // An undeclared power is refused by the Engine, so it proves nothing.
        ['id' => 'ghost', 'name' => 'Ghost', 'x' => 8, 'y' => 3, 'conditions' => [['type' => 'world_ability', 'name' => 'phase', 'negate' => true]]],
    ];
    file_put_contents($path, "<?php\n\nreturn " . var_export($data, true) . ";\n");
    $workspace = ProjectWorkspace::fromProject($root);
    $map = $workspace->getMapByIndex(0);
    $authoring = new NpcAuthoring($workspace);

    expect($authoring->move($map, 1, 2, 1)->command)->not->toBeNull()
        ->and(fn() => $authoring->move($map, 2, 2, 1))->toThrow(NpcRefusal::class, 'already stands at 2,1 while both can be visible.');
});
