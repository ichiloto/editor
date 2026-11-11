<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectConfig;
use Ichiloto\Editor\Session\EditorSession;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Editor\Validation\SavePointLimitCheck;
use Ichiloto\Editor\Validation\Severity;
use Ichiloto\Engine\Field\MapGridSource;

/**
 * A map holds at most the project's number of save points, one unless its
 * config says otherwise. The 6 x 2 synthetic map takes its save points from
 * the fixtures glyphs; synthetic pieces only.
 */

/** @param string $fixtures The fixtures layer's glyphs; `?` saves. */
function savePointLimitProject(string $fixtures, ?int $limit = null): string
{
    $root = makeTemporaryProject('editor-save-point-limit-');
    file_put_contents($root . '/assets/Maps/collisions.php', "<?php\n\nuse Ichiloto\\Engine\\Events\\Enumerations\\CollisionType;\n\n"
        . "return ['.' => CollisionType::NONE, '#' => CollisionType::SOLID, '?' => CollisionType::SAVE_POINT];\n");
    $directory = $root . '/assets/Maps/test-map';
    unlink($directory . '/test-map.map.php');
    mkdir($directory . '/layers');
    file_put_contents($directory . '/layers/01.terrain.map.php', MapGridSource::buildSource("......\n......", 'AUTHORED', ''));
    file_put_contents($directory . '/layers/02.fixtures.map.php', MapGridSource::buildSource($fixtures, 'AUTHORED', ''));
    file_put_contents($directory . '/test-map.event.php', MapGridSource::buildSource("      \n      ", 'EVENTS'));
    $data = ['name' => 'Save point limit', 'region' => '', 'events' => []];
    file_put_contents($directory . '/test-map.data.php', "<?php\nreturn " . var_export($data, true) . ";\n");
    if ($limit !== null) {
        $config = require $root . '/config.php';
        $config['save']['points_per_map'] = $limit;
        file_put_contents($root . '/config.php', "<?php\n\nreturn " . var_export($config, true) . ";\n");
    }

    return $root;
}

/** @return list<Issue> */
function readSavePointLimitFindings(string $root): array
{
    $session = EditorSession::open($root);
    $workspace = new ReflectionProperty(EditorSession::class, 'workspace')->getValue($session);

    return SavePointLimitCheck::validate($workspace->getMapByIndex(0), $workspace->config);
}

it('accepts a map with one save point', function () {
    expect(readSavePointLimitFindings(savePointLimitProject("  ?   \n      ")))->toBe([]);
});

it('reports a second save point on the same map as an error naming both', function () {
    $issues = readSavePointLimitFindings(savePointLimitProject("?     \n    ? "));

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->severity)->toBe(Severity::ERROR)
        ->and($issues[0]->code)->toBe(SavePointLimitCheck::TOO_MANY)
        ->and($issues[0]->cells)->toBe([[0, 0], [4, 1]]);
});

it('counts side-by-side saving cells as one save point', function () {
    expect(readSavePointLimitFindings(savePointLimitProject(" ??   \n  ?   ")))->toBe([]);
});

it('follows the limit the project sets, with 0 allowing any number', function () {
    $three = "? ? ? \n      ";

    expect(readSavePointLimitFindings(savePointLimitProject($three, 3)))->toBe([])
        ->and(readSavePointLimitFindings(savePointLimitProject($three, 2)))->toHaveCount(1)
        ->and(readSavePointLimitFindings(savePointLimitProject($three, 0)))->toBe([]);
});

it('offers the limit in Configuration with its default', function () {
    $config = new ProjectConfig(savePointLimitProject("      \n      "));

    expect($config->getRecord(ProjectConfig::SAVE_POINTS_PER_MAP)->get('default'))->toBe(1)
        ->and(SavePointLimitCheck::readLimit($config))->toBe(1)
        ->and(SavePointLimitCheck::readLimit(null))->toBe(1);
});

it('fails project validation on a map with two save points', function () {
    $root = savePointLimitProject("?    ?\n      ");
    $session = EditorSession::open($root);
    $workspace = new ReflectionProperty(EditorSession::class, 'workspace')->getValue($session);
    $codes = array_map(static fn(Issue $issue): ?string => $issue->code,
        array_filter(new ProjectValidator()->validate($workspace), static fn(Issue $issue): bool => $issue->severity === Severity::ERROR));

    expect($codes)->toContain(SavePointLimitCheck::TOO_MANY);
});
