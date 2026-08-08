<?php

declare(strict_types=1);

use Ichiloto\Editor\ProjectWorkspace;

/**
 * Renders one full editor frame into a string using the same output
 * buffering the live loop uses, with the terminal size pinned.
 */
function renderGoldenFrame(int $width, int $height): string
{
  $editor = createEditorForTesting(fixturePath('sample-project'));
  setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject(fixturePath('sample-project')));
  setEditorProperty($editor, 'lastTerminalSize', ['width' => $width, 'height' => $height]);

  ob_start();

  try {
    callEditorMethod($editor, 'renderFullScreen');
  } finally {
    $frame = (string) ob_get_clean();
  }

  return $frame;
}

it('renders the main shell frame byte-for-byte against the stored snapshot', function () {
  $snapshotPath = __DIR__ . '/../__snapshots__/main_shell_100x30.ansi';
  $frame = renderGoldenFrame(100, 30);

  expect($frame)->not->toBe('');

  if (! is_file($snapshotPath)) {
    // First run primes the snapshot; subsequent runs enforce it.
    @mkdir(dirname($snapshotPath), 0777, true);
    file_put_contents($snapshotPath, $frame);
  }

  expect($frame)->toBe((string) file_get_contents($snapshotPath));
});

it('renders the fixture map content inside the frame', function () {
  $frame = renderGoldenFrame(100, 30);
  $plainFrame = (string) preg_replace('/\033\[[0-9;?]*[A-Za-z]/', '', $frame);

  expect($plainFrame)->toContain('Ichiloto Editor')
    ->and($plainFrame)->toContain('Project: Sample Project')
    ->and($plainFrame)->toContain('> test-map')
    ->and($plainFrame)->toContain('Preview: test-map')
    ->and($plainFrame)->toContain('############')
    ->and($plainFrame)->toContain('Name: Test Map')
    ->and($plainFrame)->toContain('Ready.');
});

it('renders a stable frame across repeated renders', function () {
  expect(renderGoldenFrame(100, 30))->toBe(renderGoldenFrame(100, 30));
});
