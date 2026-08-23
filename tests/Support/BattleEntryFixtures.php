<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;

/**
 * Returns the battle-entry rule file path inside a project.
 */
function battleEntryRulesPath(string $root): string
{
    return $root . '/assets/Data/battle-entry-rules.php';
}

/**
 * Writes a battle-entry rule file from exact PHP source.
 */
function writeBattleEntryRules(string $root, string $source): void
{
    file_put_contents(battleEntryRulesPath($root), $source);
}

/**
 * Writes one additional actor definition file in the engine's shape.
 */
function addProjectActor(string $root, string $fileStem, string $name, ?string $id = null): void
{
    $idLine = $id === null ? '' : sprintf("    'id' => %s,\n", var_export($id, true));
    file_put_contents(
        $root . '/assets/Data/Actors/' . $fileStem . '.php',
        sprintf(
            "<?php\n\nuse Ichiloto\\Engine\\Entities\\Character;\n\nreturn [\n  'class' => Character::class,\n  'data' => [\n%s    'name' => %s,\n    'level' => 1,\n    'stats' => ['currentHp' => 50, 'currentMp' => 10, 'currentAp' => 5],\n    'images' => ['dialog' => [], 'field' => [], 'battle' => []],\n  ]\n];\n",
            $idLine,
            var_export($name, true),
        ),
    );
}

/**
 * Runs project validation and returns the battle-entry findings.
 *
 * @return Issue[] The issues whose `where` names the rule file.
 */
function battleEntryIssues(string $root): array
{
    $workspace = ProjectWorkspace::fromProject($root);
    $issues = new ProjectValidator()->validate($workspace);

    return array_values(array_filter(
        $issues,
        static fn(Issue $issue): bool => $issue->where === 'assets/Data/battle-entry-rules.php',
    ));
}

/**
 * Returns just the battle-entry finding messages, in report order.
 *
 * @return string[]
 */
function battleEntryProblemMessages(string $root): array
{
    return array_map(
        static fn(Issue $issue): string => $issue->message,
        battleEntryIssues($root),
    );
}
