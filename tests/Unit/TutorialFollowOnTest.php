<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Editor\Validation\Severity;
use Ichiloto\Engine\Progression\TutorialCatalog;

/**
 * One concept per tutorial, started when it comes into play: a tutorial's
 * follow-on cinematic carries the story on, and validation warns when one
 * starts another tutorial straight after it. Synthetic fixtures only.
 */

/** The fixture's cinematic, optionally starting a tutorial, and a tutorial that follows on with it. */
function writeTutorialFollowOn(bool $chains): string
{
    $root = cutsceneProject();
    $file = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns/harbour-lanterns.script.php';
    if ($chains) {
        file_put_contents($file, str_replace("  ['type' => 'checkpoint', 'name' => 'boats-crossed'],",
            "  ['type' => 'start_tutorial', 'tutorial' => 'test.next'],\n  ['type' => 'checkpoint', 'name' => 'boats-crossed'],", (string) file_get_contents($file), $count));
        expect($count)->toBe(1);
    }
    $step = ['target' => ['screen' => 'main-menu', 'view' => 'commands', 'id' => 'summons'], 'action' => 'confirm', 'until' => ['type' => 'switch', 'name' => 'test_done']];
    file_put_contents($root . '/assets/' . TutorialCatalog::FILE, '<?php return ' . var_export([
        ['id' => 'test.first', 'category' => 'synthetic', 'title' => 'First', 'start' => 'script', 'steps' => [$step], 'replay' => 'none', 'onFinish' => 'harbour-lanterns'],
        ['id' => 'test.next', 'category' => 'synthetic', 'title' => 'Next', 'start' => 'script', 'steps' => [$step], 'replay' => 'none'],
    ], true) . ';');

    return $root;
}

it('warns when a tutorial\'s follow-on cinematic starts another tutorial straight after it', function (bool $chains): void {
    $issues = array_filter(new ProjectValidator()->validate(ProjectWorkspace::fromProject(writeTutorialFollowOn($chains))),
        static fn($issue): bool => str_contains($issue->message, 'follows on with cinematic'));

    expect(array_map(static fn($issue): array => [$issue->severity, $issue->message], array_values($issues)))->toBe($chains
        ? [[Severity::WARNING, 'Tutorial test.first follows on with cinematic harbour-lanterns, which starts tutorial test.next straight after it.']]
        : []);
})->with(['a follow-on that starts a tutorial' => true, 'a follow-on that tells the story on' => false]);
