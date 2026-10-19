<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Core\ProjectFormat;
use Ichiloto\Engine\Exceptions\UnsupportedProjectFormatException;

/**
 * The editor opens only projects in the Engine's format, and refuses any
 * other with the Engine's own explanation.
 */

it('opens a project in the Engine format', function () {
    $root = makeTemporaryProject('ichiloto-format-');
    $config = json_decode((string) file_get_contents($root . '/ichiloto.json'), true);

    expect($config[ProjectFormat::KEY] ?? null)->toBe(ProjectFormat::CURRENT)
        ->and(ProjectWorkspace::fromProject($root)->maps)->not->toBe([]);
});

it('refuses to open a project in another format with the Engine explanation', function (mixed $format) {
    $root = makeTemporaryProject('ichiloto-format-');
    $config = json_decode((string) file_get_contents($root . '/ichiloto.json'), true);
    if ($format === null) {
        unset($config[ProjectFormat::KEY]);
    } else {
        $config[ProjectFormat::KEY] = $format;
    }
    file_put_contents($root . '/ichiloto.json', json_encode($config));
    try {
        ProjectFormat::assertSupported($format);
        $expected = null;
    } catch (UnsupportedProjectFormatException $refusal) {
        $expected = $refusal->getMessage();
    }

    expect($expected)->not->toBeNull()
        ->and(fn() => ProjectWorkspace::fromProject($root))->toThrow(UnsupportedProjectFormatException::class, $expected);
})->with([
    'unrecorded' => [null],
    'older' => [ProjectFormat::CURRENT - 1],
    'newer' => [ProjectFormat::CURRENT + 1],
]);
