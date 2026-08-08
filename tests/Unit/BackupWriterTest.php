<?php

declare(strict_types=1);

use Ichiloto\Editor\Backup\BackupSettings;
use Ichiloto\Editor\Backup\BackupWriter;
use Ichiloto\Editor\ProjectWorkspace;

/**
 * Enables project backups by writing the opt-in block into ichiloto.json.
 */
function enableProjectBackups(string $root, int $retain = 5): void
{
    $configPath = $root . '/ichiloto.json';
    $config = json_decode((string) file_get_contents($configPath), true);
    $config['editor'] = ['backups' => ['enabled' => true, 'retain' => $retain]];
    file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

afterEach(function () {
    putenv('ICHILOTO_EDITOR_BACKUPS');
    putenv('ICHILOTO_EDITOR_BACKUP_RETAIN');
});

it('is off unless a project opts in', function () {
    $root = makeTemporaryProject();

    try {
        $settings = BackupSettings::fromProject($root);

        expect($settings->isEnabled)->toBeFalse()
            ->and($settings->directory)->toBe($root . '/' . BackupSettings::DEFAULT_DIRECTORY)
            ->and($settings->retain)->toBe(BackupSettings::DEFAULT_RETAIN)
            ->and($settings->describe())->toContain('Backups off');

        enableProjectBackups($root, retain: 3);
        $enabled = BackupSettings::fromProject($root);

        expect($enabled->isEnabled)->toBeTrue()
            ->and($enabled->retain)->toBe(3)
            ->and($enabled->describe())->toContain('Backups on');
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('lets the environment force backups on or off', function () {
    $root = makeTemporaryProject();

    try {
        putenv('ICHILOTO_EDITOR_BACKUPS=1');
        putenv('ICHILOTO_EDITOR_BACKUP_RETAIN=9');

        expect(BackupSettings::fromProject($root)->isEnabled)->toBeTrue()
            ->and(BackupSettings::fromProject($root)->retain)->toBe(9);

        enableProjectBackups($root);
        putenv('ICHILOTO_EDITOR_BACKUPS=off');

        expect(BackupSettings::fromProject($root)->isEnabled)->toBeFalse();
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('writes nothing at all while disabled', function () {
    $root = makeTemporaryProject();

    try {
        $writer = new BackupWriter(BackupSettings::fromProject($root), $root);
        $result = $writer->backup($root . '/ichiloto.json');

        expect($result['written'])->toBe(0)
            ->and($result['skipped'])->toBe(1)
            ->and(is_dir($root . '/.ichiloto'))->toBeFalse();
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('mirrors the project path under the backup root', function () {
    $root = makeTemporaryProject();

    try {
        enableProjectBackups($root);
        $writer = new BackupWriter(BackupSettings::fromProject($root), $root);
        $source = $root . '/assets/Maps/test-map/test-map.map.php';
        $result = $writer->backup($source);

        expect($result['written'])->toBe(1)
            ->and($result['failed'])->toBe([]);

        $backups = glob($root . '/' . BackupSettings::DEFAULT_DIRECTORY . '/assets/Maps/test-map/test-map.map.php.*.bak') ?: [];

        expect($backups)->toHaveCount(1)
            ->and(file_get_contents($backups[0]))->toBe(file_get_contents($source))
            // The source itself is untouched by the backup pass.
            ->and(is_file($source))->toBeTrue();
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('skips a file that does not exist yet', function () {
    $root = makeTemporaryProject();

    try {
        enableProjectBackups($root);
        $writer = new BackupWriter(BackupSettings::fromProject($root), $root);
        $result = $writer->backup($root . '/assets/Data/nothing-here.php');

        expect($result['written'])->toBe(0)
            ->and($result['skipped'])->toBe(1)
            ->and($result['failed'])->toBe([]);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('prunes older copies down to the retained count', function () {
    $root = makeTemporaryProject();

    try {
        enableProjectBackups($root, retain: 2);
        $writer = new BackupWriter(BackupSettings::fromProject($root), $root);
        $source = $root . '/ichiloto.json';
        $stem = $root . '/' . BackupSettings::DEFAULT_DIRECTORY . '/ichiloto.json';

        // Pre-seed older stamps so pruning has something to evict without
        // waiting a real second between writes.
        @mkdir(dirname($stem), 0777, true);

        foreach (['20200101-000001', '20200101-000002', '20200101-000003'] as $stamp) {
            copy($source, $stem . '.' . $stamp . '.bak');
        }

        $writer->backup($source);
        $remaining = glob($stem . '.*.bak') ?: [];

        expect($remaining)->toHaveCount(2)
            ->and(basename($remaining[0]))->not->toContain('20200101-000001');
    } finally {
        removeDirectoryRecursively($root);
    }
});

it("backs up a map's three files on save only when enabled", function () {
    $root = makeTemporaryProject();

    try {
        enableProjectBackups($root);
        $editor = createEditorForTesting($root);
        setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
        setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
        setEditorProperty($editor, 'isRunning', true);
        setEditorProperty($editor, 'focusedPane', 'canvas');
        setEditorProperty($editor, 'cursorX', 2);
        setEditorProperty($editor, 'cursorY', 2);
        callEditorMethod($editor, 'dispatchInput', 'x');
        callEditorMethod($editor, 'dispatchInput', "\x13");

        $backups = glob($root . '/' . BackupSettings::DEFAULT_DIRECTORY . '/assets/Maps/test-map/*.bak') ?: [];

        expect($backups)->toHaveCount(3);
    } finally {
        removeDirectoryRecursively($root);
    }
});

it('takes no backup on save while disabled', function () {
    $root = makeTemporaryProject();

    try {
        $editor = createEditorForTesting($root);
        setEditorProperty($editor, 'workspace', ProjectWorkspace::fromProject($root));
        setEditorProperty($editor, 'lastTerminalSize', ['width' => 120, 'height' => 40]);
        setEditorProperty($editor, 'isRunning', true);
        setEditorProperty($editor, 'focusedPane', 'canvas');
        setEditorProperty($editor, 'cursorX', 2);
        setEditorProperty($editor, 'cursorY', 2);
        callEditorMethod($editor, 'dispatchInput', 'x');
        callEditorMethod($editor, 'dispatchInput', "\x13");

        expect(is_dir($root . '/.ichiloto'))->toBeFalse();
    } finally {
        removeDirectoryRecursively($root);
    }
});
