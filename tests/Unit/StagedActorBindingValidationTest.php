<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\ProjectValidator;

/**
 * A staged actor bound to a real subject inherits that subject's position
 * and facing. The Engine's stage manager owns the rule; project validation
 * reports what it refuses at the script that owns the command, for the flat
 * and the wrapped `actor` form and through nested sequences.
 */

/**
 * The messages project validation reports for a Common Event running a script.
 *
 * @param list<array<string, mixed>> $script
 * @return array{list<string>, string} The issue lines and the script's file.
 */
function validateStagedScript(array $script): array
{
    $root = makeTemporaryProject();
    $path = $root . '/assets/Events/staged-actor-probe.php';
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), recursive: true);
    }
    $source = "<?php\n\n// Staged by hand.\nreturn " . var_export($script, true) . ";\n";
    file_put_contents($path, $source);
    $issues = new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));

    expect(file_get_contents($path))->toBe($source);

    return [array_map(static fn(object $issue): string => $issue->where . ': ' . $issue->message, $issues), $path];
}

const STAGED_REFUSAL = 'A bound visual inherits real position/facing and cannot add collision.';

$invalid = ['id' => 'reveal', 'subject' => ['kind' => 'player'], 'sprite' => 'T', 'facing' => 'south'];

it('reports a bound actor that adds its own facing, flat and wrapped, at the owning script', function (array $script) {
    [$issues] = validateStagedScript($script);

    expect(array_values(array_filter($issues, static fn(string $line): bool => str_contains($line, STAGED_REFUSAL))))
        ->toHaveCount(1)
        ->and(implode("\n", $issues))->toContain('staged-actor-probe');
})->with([
    'flat' => [[['type' => 'stage_actor', ...$invalid]]],
    'wrapped' => [[['type' => 'stage_actor', 'actor' => $invalid]]],
    'wrapped in a nested sequence' => [[['type' => 'sequence', 'commands' => [['type' => 'stage_actor', 'actor' => $invalid]]]]],
]);

it('accepts valid independent facing and valid bound actors', function (array $script) {
    [$issues] = validateStagedScript($script);

    expect(array_values(array_filter($issues, static fn(string $line): bool => str_contains($line, 'staged-actor-probe')
        && (str_contains($line, 'bound visual') || str_contains($line, 'subject')))))->toBe([]);
})->with([
    'independent with its own facing' => [[['type' => 'stage_actor', 'id' => 'guide', 'sprite' => 'G', 'x' => 2, 'y' => 2, 'facing' => 'south']]],
    'bound to the player' => [[['type' => 'stage_actor', 'id' => 'reveal', 'subject' => ['kind' => 'player'], 'sprite' => 'T']]],
    'bound to an NPC, wrapped' => [[['type' => 'stage_actor', 'actor' => ['id' => 'reveal', 'subject' => ['kind' => 'npc', 'id' => 'seller'], 'sprite' => 'T']]]],
]);
