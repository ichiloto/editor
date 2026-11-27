<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneHydration;
use Ichiloto\Editor\Cutscenes\Preview\CinematicPreviewSession;
use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandContext;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandHandlerInterface;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandOutcome;
use Ichiloto\Engine\IO\Console\TerminalText;

final class PreviewLampCommand implements ScriptCommandHandlerInterface
{
    public function execute(ScriptCommandContext $context, array $command): ScriptCommandOutcome
    {
        $context->scene->gameState->recordStoryEvent('preview_lamp_lit');

        return ScriptCommandOutcome::complete();
    }
}

it('keeps registered commands with their own preview while another project is open', function () {
    $root = cutsceneProject();
    $declarations = [['type' => 'light_lamp', 'class' => PreviewLampCommand::class, 'label' => 'Light Lamp', 'fields' => []]];
    file_put_contents($root . '/assets/Data/script-commands.php', '<?php return ' . var_export($declarations, true) . ';');
    ProjectWorkspace::fromProject($root);
    $definition = CutsceneHydration::cinematic(['id' => 'lamps', 'name' => 'Lamps', 'startMap' => 'harbour'], [
        ['type' => 'wait', 'seconds' => 0.2],
        ['type' => 'light_lamp'],
    ], $root);
    $preview = CinematicPreviewSession::start($root, $definition);
    try {
        ProjectWorkspace::fromProject(makeTemporaryProject());
        expect($preview->runToCompletion(3.0))->toBeTrue()
            ->and($preview->snapshot()->values['story events'])->toContain('preview_lamp_lit');
    } finally {
        $preview->dispose();
    }
});

/**
 * The Engine plays a cinematic inside the editor: the interpreter,
 * controller, stage and presentation are the Engine's; the editor supplies a
 * clock, a frame buffer, and read-only views.
 */

it('plays the harbour cinematic to completion through the Engine and records its final state', function () {
    $root = cutsceneProject();
    $preview = CinematicPreviewSession::start($root, harbourDefinition($root), ['x' => 2, 'y' => 3, 'width' => 40, 'height' => 12]);

    try {
        $field = new ReflectionProperty($preview, 'field')->getValue($preview);
        $configuration = $field->scene->getGame()->getProjectConfiguration();
        expect($configuration->get('audio.music'))->toBeFalse()
            ->and($configuration->get('audio.sfx'))->toBeFalse()
            ->and($configuration->get('audio.voice'))->toBeFalse();
        expect($preview->status())->toBe(CinematicPreviewSession::STATUS_PAUSED)
            ->and($preview->lanes())->not->toBe([])
            ->and($preview->lanes()[0]['key'])->toBe('commands.0');

        $preview->play();
        expect($preview->isPlaying())->toBeTrue();

        // Step through the fade-in and the camera detach into the parallel block.
        for ($tick = 0; $tick < 5 && $preview->status() !== CinematicPreviewSession::STATUS_COMPLETED; $tick++) {
            $preview->tick(CinematicPreviewSession::TICK_SECONDS);
        }

        $lanes = $preview->lanes();
        expect(count($lanes))->toBeGreaterThanOrEqual(4)
            ->and($lanes[0]['key'])->toBe('commands.2')
            ->and($lanes[1]['key'])->toBe('commands.2.lanes.0.0')
            ->and($lanes[2]['key'])->toBe('commands.2.lanes.1.0')
            ->and($lanes[3]['key'])->toBe('commands.2.lanes.2.0')
            ->and($preview->activeKeys())->toContain('commands.2.lanes.2.0');

        $frame = $preview->frame();
        expect(count($frame))->toBe(12)
            ->and(implode("\n", $frame))->toContain('The lanterns were');

        expect($preview->runToCompletion(30.0))->toBeTrue()
            ->and($preview->status())->toBe(CinematicPreviewSession::STATUS_COMPLETED)
            ->and($preview->checkpoints())->toBe(['boats-crossed']);

        $snapshot = $preview->snapshot();
        expect($snapshot->values['story events'])->toContain('cinematic:harbour-lanterns:completed')
            ->and($snapshot->values['staged actors'])->toBe([])
            ->and($snapshot->values['camera']['followsPlayer'])->toBeTrue()
            ->and($preview->failure())->toBeNull();
    } finally {
        $preview->dispose();
    }
});

it('skips through the authored finalizer and reaches the same switch the watched run does', function () {
    $root = cutsceneProject();
    $definition = harbourDefinition($root);
    $watched = CinematicPreviewSession::start($root, $definition, ['x' => 2, 'y' => 3]);
    $skipped = CinematicPreviewSession::start($root, $definition, ['x' => 2, 'y' => 3]);

    try {
        $watched->runToCompletion(30.0);
        expect($skipped->skipRefusalReason())->toBeNull()
            ->and($skipped->skip())->toBeTrue();
        $skipped->runToCompletion(30.0);

        expect($skipped->status())->toBe(CinematicPreviewSession::STATUS_COMPLETED)
            ->and($skipped->snapshot()->values['switch harbour_lanterns_seen'])->toBeTrue()
            ->and($watched->snapshot()->values['switch harbour_lanterns_seen'])->toBeTrue();

        $differences = $watched->snapshot()->diff($skipped->snapshot());
        $labels = array_column($differences, 'label');
        // The watched run records the checkpoint the skip never reaches.
        expect($labels)->toContain('checkpoints')
            ->and($labels)->not->toContain('switch harbour_lanterns_seen')
            ->and($labels)->not->toContain('story events');
    } finally {
        $watched->dispose();
        $skipped->dispose();
    }
});

it('reports a failing command with the outline key to jump to, and refuses skips the policy forbids', function () {
    $root = cutsceneProject();
    $definition = CutsceneHydration::cinematic(
        ['id' => 'broken', 'name' => 'Broken', 'skip' => ['policy' => 'forbidden']],
        [
            ['type' => 'wait', 'seconds' => 0.1],
            ['type' => 'sequence', 'commands' => [
                ['type' => 'wait', 'seconds' => 0.1],
                ['type' => 'move_route', 'subject' => 'staged_actor', 'actorId' => 'nobody', 'steps' => [['direction' => 'up', 'count' => 1]]],
            ]],
        ],
        $root,
    );
    $preview = CinematicPreviewSession::start($root, $definition, ['mapId' => 'harbour']);

    try {
        expect($preview->skipRefusalReason())->toContain('forbidden');
        $preview->runToCompletion(10.0);

        expect($preview->status())->toBe(CinematicPreviewSession::STATUS_FAILED)
            ->and($preview->failure()['message'])->toContain('nobody')
            ->and($preview->failure()['key'])->toBe('commands.1.commands.1');
    } finally {
        $preview->dispose();
    }
});

it('waits on dialogue until the author continues, and resolves choices from the highlighted option', function () {
    $root = cutsceneProject();
    $definition = CutsceneHydration::cinematic(
        ['id' => 'talk', 'name' => 'Talk'],
        [
            ['type' => 'text', 'name' => 'Keeper', 'text' => 'Mind the step.'],
            ['type' => 'choice', 'prompt' => 'Go on?', 'options' => [
                ['text' => 'Yes', 'then' => [['type' => 'set_switch', 'name' => 'went_on', 'value' => true]]],
                ['text' => 'No', 'then' => [['type' => 'set_switch', 'name' => 'stayed', 'value' => true]]],
            ]],
        ],
        $root,
    );
    $preview = CinematicPreviewSession::start($root, $definition, ['mapId' => 'harbour']);

    try {
        $preview->play();
        $preview->tick(0.1);
        expect($preview->status())->toBe(CinematicPreviewSession::STATUS_WAITING)
            ->and($preview->waitDescription())->toContain('Enter')
            ->and(implode("\n", $preview->frame()))->toContain('Mind the step');

        // Time passing does not dismiss the line.
        $preview->tick(5.0);
        expect($preview->status())->toBe(CinematicPreviewSession::STATUS_WAITING);

        expect($preview->confirm())->toBeTrue();
        $preview->tick(0.1);
        expect($preview->waitDescription())->toContain('Choice');
        $preview->moveChoice(1);
        $preview->confirm();
        $preview->tick(0.1);
        $preview->runToCompletion(5.0);

        expect($preview->status())->toBe(CinematicPreviewSession::STATUS_COMPLETED)
            ->and($preview->snapshot()->values['switch stayed'] ?? null)->toBeTrue()
            ->and($preview->snapshot()->values)->not->toHaveKey('switch went_on')
            ->and(array_column($preview->dialogueLog(), 'kind'))->toBe(['text', 'choice']);
    } finally {
        $preview->dispose();
    }
});

it('draws an answer as the dialogue list and a decision as buttons, as the game does', function (?string $kind, bool $listed) {
    $root = cutsceneProject();
    $choice = ['type' => 'choice', 'prompt' => 'Go on?', 'options' => [
        ['text' => 'Onward', 'then' => []],
        ['text' => 'Stay', 'then' => []],
    ]];
    $definition = CutsceneHydration::cinematic(['id' => 'ask', 'name' => 'Ask'],
        [$kind === null ? $choice : $choice + ['kind' => $kind]], $root);
    $preview = CinematicPreviewSession::start($root, $definition, ['mapId' => 'harbour']);

    try {
        $preview->play();
        $preview->tick(0.1);
        $frame = implode("\n", array_map(TerminalText::stripAnsi(...), $preview->frame()));

        expect($preview->waitDescription())->toContain('Choice')
            ->and($frame)->toContain('Onward');
        expect(str_contains($frame, '> Onward'))->toBe($listed);

        $preview->confirm();
        $preview->tick(0.1);
        $preview->runToCompletion(5.0);
        expect($preview->status())->toBe(CinematicPreviewSession::STATUS_COMPLETED);
    } finally {
        $preview->dispose();
    }
})->with([
    'an answer to someone' => ['answer', true],
    'a decision' => ['decision', false],
    'an unmarked event choice, a decision' => [null, false],
]);
