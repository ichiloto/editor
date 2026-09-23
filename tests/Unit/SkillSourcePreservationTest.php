<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectSkillDatabase;

it('changes only the animation field in real Last Legend skills and preserves every effect', function (): void {
    $game = gameSourceRoot();
    if ($game === null) { $this->markTestSkipped('ICHILOTO_GAME_SRC is required for the production source fixture.'); }
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/skills.php';
    $before = (string) file_get_contents($game . '/assets/Data/skills.php');
    file_put_contents($path, $before);
    $original = require $path;
    $database = ProjectSkillDatabase::fromProject($root);
    $database->setField(2, 'animationId', 17);
    $database->save();
    $after = (string) file_get_contents($path);
    expect(str_replace("    animationId: 17,\n", '', $after))->toBe($before);
    $saved = require $path;
    expect($saved[2]->animationId)->toBe(17);
    foreach ($original as $index => $skill) {
        expect(serialize($saved[$index]->effects))->toBe(serialize($skill->effects))
            ->and(serialize($saved[$index]->requiredWeapons))->toBe(serialize($skill->requiredWeapons));
    }
    $database->setField(2, 'animationId', 18);
    $database->save();
    expect(file_get_contents($path))->toBe(str_replace('animationId: 17', 'animationId: 18', $after));
    $database->setField(2, 'animationId', null);
    $database->save();
    expect((require $path)[2]->animationId)->toBeNull();
});

it('preserves positional and aliased constructors with nested effects and trailing comments', function (): void {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/skills.php';
    $source = <<<'PHP'
<?php
use Ichiloto\Engine\Entities\Skills\SpecialSkill as Ability;
use Ichiloto\Engine\Entities\Effects\SkillEffects\AddStateSkillEffect;
return [
    // Keep this authored comment.
    new Ability('Venom', 'Desc', '', 4, 0, effects: [new AddStateSkillEffect('poison', 85)], animationId: 3),
];
PHP;
    file_put_contents($path, $source);
    $database = ProjectSkillDatabase::fromProject($root);
    $database->setField(0, 'cost', 8);
    $database->setField(0, 'animationId', 4);
    $database->save();
    expect(file_get_contents($path))->toBe(str_replace(["'', 4, 0", 'animationId: 3'], ["'', 8, 0", 'animationId: 4'], $source));
    expect((require $path)[0]->effects[0]->stateId)->toBe('poison');
});

it('preserves skill removal and saved undo without regenerating effects', function (): void {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/skills.php';
    $source = <<<'PHP'
<?php
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Effects\SkillEffects\RemoveStateSkillEffect;
return [
  new SpecialSkill('Cleanse', '', '', 5, 0, effects: [new RemoveStateSkillEffect(['poison'])]),
  new SpecialSkill('Other', '', '', 0, 0),
];
PHP;
    file_put_contents($path, $source);
    $database = ProjectSkillDatabase::fromProject($root);
    $removed = $database->removeSkill(0);
    $database->save();
    expect(require $path)->toHaveCount(1);
    $database->insertSkill(0, $removed);
    $database->save();
    expect(file_get_contents($path))->toBe($source);
    expect((require $path)[0]->effects[0]->stateIds)->toBe(['poison']);
});

it('adds a named animation after positional arguments without swallowing a trailing comment', function (): void {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/skills.php';
    file_put_contents($path, <<<'PHP'
<?php
return [new \Ichiloto\Engine\Entities\Skills\SpecialSkill(
    'Commented', '', '', 0, 0 // Keep the cooldown comment.
)];
PHP);
    $database = ProjectSkillDatabase::fromProject($root);
    $database->setField(0, 'animationId', 42);
    $database->save();
    expect((require $path)[0]->animationId)->toBe(42)
        ->and(file_get_contents($path))->toContain('0, // Keep the cooldown comment.');
});

it('refuses changing skill type rather than dropping its authored effects', function (): void {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/skills.php';
    $source = "<?php return [new \\Ichiloto\\Engine\\Entities\\Skills\\SpecialSkill('Original', '', '', 0, 0)];";
    file_put_contents($path, $source);
    $database = ProjectSkillDatabase::fromProject($root);
    $database->setField(0, 'type', 'magic');
    expect(fn() => $database->save())->toThrow(RuntimeException::class, 'Refusing to regenerate')
        ->and(file_get_contents($path))->toBe($source)->and($database->isDirty())->toBeTrue();
});

it('refuses unsafe source and externally changed skills without writing or clearing dirty state', function (): void {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/skills.php';
    $source = <<<'PHP'
<?php
$skill = new \Ichiloto\Engine\Entities\Skills\SpecialSkill('Computed', '', '', 0, 0);
return [$skill];
PHP;
    file_put_contents($path, $source);
    $database = ProjectSkillDatabase::fromProject($root);
    expect(fn() => $database->setField(0, 'animationId', 1))->toThrow(RuntimeException::class, 'skill "Computed" (entry 1)')
        ->and(file_get_contents($path))->toBe($source)
        ->and($database->isDirty())->toBeFalse();
    file_put_contents($path, $source . "\n// External edit\n");
    $database->addSkill();
    expect(fn() => $database->save())->toThrow(RuntimeException::class, 'changed outside')
        ->and(file_get_contents($path))->toBe($source . "\n// External edit\n");
});

it('keeps an unsupported skill untouched while saving and removing supported neighbours', function (): void {
    $root = makeTemporaryProject();
    $path = $root . '/assets/Data/skills.php';
    $source = <<<'PHP'
<?php
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
$computed = new SpecialSkill('Computed', '', '', 0, 0);
return [
  new SpecialSkill('Before', '', '', 0, 0),
  $computed,
  new SpecialSkill('After', '', '', 0, 0),
];
PHP;
    file_put_contents($path, $source);
    $database = ProjectSkillDatabase::fromProject($root);
    expect(fn() => $database->setField(1, 'animationId', 3))->toThrow(RuntimeException::class, '"Computed"');
    $database->setField(2, 'animationId', 4);
    $database->save();
    expect(file_get_contents($path))->toBe(str_replace("'After', '', '', 0, 0)", "'After', '', '', 0, 0, animationId: 4)", $source));
    $database->removeSkill(0);
    $database->save();
    expect((require $path)[0]->name)->toBe('Computed')
        ->and((require $path)[1]->animationId)->toBe(4);
});
