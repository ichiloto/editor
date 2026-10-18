<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;

/**
 * Shared helpers for every test that runs the project validator or rewrites
 * a fixture map's data file.
 */

/**
 * Runs the validator over a project.
 *
 * @param string $root The project root.
 * @return Issue[] The issues found.
 */
function validateProject(string $root): array
{
    return new ProjectValidator()->validate(ProjectWorkspace::fromProject($root));
}

/**
 * Rewrites the sample map's legacy `<blue>` row in the current style, so the
 * Engine can load it: the Engine knows no `blue` style, reads the tag as
 * glyphs, and refuses the map's event layer as narrower than its tiles.
 * Tests of legacy-tag preservation keep the fixture as it is.
 */
function writeLoadableSampleMap(string $root): void
{
    $path = $root . '/assets/Maps/test-map/test-map.map.php';
    file_put_contents($path, str_replace(['<blue>', '</blue>'], ['<fg=blue>', '</>'], (string) file_get_contents($path)));
}

/**
 * Returns the issues whose message contains the given text.
 *
 * @param Issue[] $issues The issues.
 * @param string $text The text to look for.
 * @return Issue[] The matching issues.
 */
function issuesMentioning(array $issues, string $text): array
{
    return array_values(array_filter(
        $issues,
        static fn(Issue $issue): bool => str_contains($issue->message, $text)
    ));
}

/**
 * Rewrites a fixture map's data file.
 *
 * @param string $root The project root.
 * @param callable $edit Given the file's source, returns the new source.
 * @return void
 */
function editTestMapData(string $root, callable $edit): void
{
    $path = $root . '/assets/Maps/test-map/test-map.data.php';

    file_put_contents($path, $edit(file_get_contents($path)));
}
