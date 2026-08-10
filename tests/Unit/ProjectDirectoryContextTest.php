<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectDirectoryContext;
use Ichiloto\Editor\ProjectWorkspace;

it('loads a project through absolute and relative paths without leaking cwd', function () {
    $originalDirectory = getcwd();
    $projectRoot = realpath(fixturePath('sample-project'));

    expect($originalDirectory)->toBeString()
        ->and($projectRoot)->toBeString();

    try {
        chdir(dirname($projectRoot));
        $outsideDirectory = getcwd();

        $relativeWorkspace = ProjectWorkspace::fromProject(basename($projectRoot));
        $absoluteWorkspace = ProjectWorkspace::fromProject($projectRoot);

        expect($relativeWorkspace->projectRoot)->toBe($projectRoot)
            ->and($absoluteWorkspace->mapIds)->toBe($relativeWorkspace->mapIds)
            ->and(getcwd())->toBe($outsideDirectory);
    } finally {
        chdir($originalDirectory);
    }
});

it('restores nested project directory contexts after exceptions', function () {
    $originalDirectory = getcwd();
    $outerRoot = realpath(fixturePath('sample-project'));
    $innerRoot = realpath(fixturePath());

    expect(fn() => ProjectDirectoryContext::run($outerRoot, function () use ($outerRoot, $innerRoot): void {
        expect(getcwd())->toBe($outerRoot);

        ProjectDirectoryContext::run($innerRoot, function () use ($innerRoot): void {
            expect(getcwd())->toBe($innerRoot);
            throw new RuntimeException('deliberate test failure');
        });
    }))->toThrow(RuntimeException::class, 'deliberate test failure')
        ->and(getcwd())->toBe($originalDirectory);
});
