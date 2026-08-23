<?php

declare(strict_types=1);

use Ichiloto\Editor\Cutscenes\CutsceneAsset;
use Ichiloto\Editor\Cutscenes\CutsceneLibrary;
use Ichiloto\Editor\Cutscenes\CutsceneType;
use Ichiloto\Editor\Storage\FilesystemFileSetOperations;
use Ichiloto\Editor\Storage\FileSetOperations;
use Ichiloto\Editor\Storage\FileSetTransaction;
use Ichiloto\Editor\Storage\FileSetTransactionFailure;

/**
 * A cutscene pair is one asset: the data file and its script or timeline are
 * installed together or not at all, and removed together or not at all. A
 * refused save or deletion must leave the complete old pair, or -- for an
 * asset that never existed -- no pair at all.
 */

/**
 * A folder with a pair in it, for the transaction's own tests.
 *
 * @return array{0: string, 1: string, 2: string} The folder and both paths.
 */
function transactionFolder(bool $withPair = true): array
{
    $folder = rememberTemporaryProject(sys_get_temp_dir() . '/' . uniqid('paired-transaction-', true)) . '/asset';

    if ($withPair) {
        mkdir($folder, 0o777, true);
        file_put_contents($folder . '/asset.data.php', "<?php\n\nreturn ['id' => 'asset'];\n");
        file_put_contents($folder . '/asset.script.php', "<?php\n\nreturn [];\n");
        touch($folder . '/asset.data.php', time() - 3600);
        touch($folder . '/asset.script.php', time() - 3600);
    }

    return [$folder, $folder . '/asset.data.php', $folder . '/asset.script.php'];
}

/**
 * The files of a folder with their bytes and modification times.
 *
 * @return array<string, array{0: string, 1: int}>
 */
function folderState(string $folder): array
{
    $state = [];

    foreach (is_dir($folder) ? array_diff(scandir($folder) ?: [], ['.', '..']) : [] as $entry) {
        $path = $folder . '/' . $entry;

        if (is_file($path)) {
            $state[$entry] = [(string) file_get_contents($path), (int) filemtime($path)];
        }
    }

    ksort($state);

    return $state;
}

it('installs a new pair only when both files land, and leaves no folder when the second cannot', function () {
    [$folder, $dataPath, $partnerPath] = transactionFolder(withPair: false);
    $files = new FailingFileSetOperations(failures: ['move' => [$partnerPath]]);
    $transaction = new FileSetTransaction($folder, $files);
    $transaction->write($dataPath, "<?php\n\nreturn ['id' => 'asset'];\n");
    $transaction->write($partnerPath, "<?php\n\nreturn [];\n");
    $transaction->stage();

    expect(is_dir($folder))->toBeTrue('staging creates the folder for a new asset')
        ->and(is_file($dataPath))->toBeFalse('staging installs nothing');

    $failure = null;

    try {
        $transaction->commit();
    } catch (FileSetTransactionFailure $thrown) {
        $failure = $thrown;
    }

    expect($failure)->not->toBeNull()
        ->and($failure->wasRolledBack)->toBeTrue()
        ->and($failure->unrestoredPaths)->toBe([])
        ->and($failure->getMessage())->toContain('nothing was changed')
        // The half-pair the review found: a data file with no script, in a
        // folder that stayed behind. Neither survives now.
        ->and(is_file($dataPath))->toBeFalse('the installed data file was taken back')
        ->and(is_file($partnerPath))->toBeFalse()
        ->and(is_dir($folder))->toBeFalse('the folder this transaction created is gone');
});

it('restores both existing files, bytes and modification times, when the second cannot be replaced', function () {
    [$folder, $dataPath, $partnerPath] = transactionFolder();
    $before = folderState($folder);
    $files = new FailingFileSetOperations(failures: ['move' => [$partnerPath]]);
    $transaction = new FileSetTransaction($folder, $files);
    $transaction->write($dataPath, "<?php\n\nreturn ['id' => 'asset', 'name' => 'Edited'];\n");
    $transaction->write($partnerPath, "<?php\n\nreturn [['type' => 'wait']];\n");
    $transaction->stage();

    expect(fn() => $transaction->commit())->toThrow(FileSetTransactionFailure::class);
    expect(folderState($folder))->toBe($before, 'both files are exactly what they were, at the time they were');
});

it('says so when a restoration itself fails, and never claims the rollback succeeded', function () {
    [$folder, $dataPath, $partnerPath] = transactionFolder();
    // The data file is replaced, the partner cannot be, and putting the data
    // file back fails too: the one case where the pair is left in a state
    // nobody chose, which the failure must name rather than hide.
    $files = new FailingFileSetOperations(failures: ['move' => [$partnerPath], 'write' => [$dataPath]]);
    $transaction = new FileSetTransaction($folder, $files);
    $transaction->write($dataPath, "<?php\n\nreturn ['id' => 'asset', 'name' => 'Edited'];\n");
    $transaction->write($partnerPath, "<?php\n\nreturn [];\n");
    $transaction->stage();

    $failure = null;

    try {
        $transaction->commit();
    } catch (FileSetTransactionFailure $thrown) {
        $failure = $thrown;
    }

    expect($failure)->not->toBeNull()
        ->and($failure->wasRolledBack)->toBeFalse()
        ->and($failure->unrestoredPaths)->toBe([$dataPath])
        ->and($failure->getMessage())->toContain('could not be put back')
        ->and($failure->getMessage())->toContain('asset.data.php')
        ->and($failure->getMessage())->not->toContain('nothing was changed');
});

it('keeps the complete pair when a paired deletion cannot remove the second file', function () {
    [$folder, $dataPath, $partnerPath] = transactionFolder();
    $before = folderState($folder);
    $files = new FailingFileSetOperations(failures: ['remove' => [$partnerPath]]);
    $transaction = new FileSetTransaction($folder, $files);
    $transaction->remove($dataPath);
    $transaction->remove($partnerPath);

    $failure = null;

    try {
        $transaction->commit();
    } catch (FileSetTransactionFailure $thrown) {
        $failure = $thrown;
    }

    expect($failure)->not->toBeNull()
        ->and($failure->wasRolledBack)->toBeTrue()
        ->and(folderState($folder))->toBe($before, 'the complete original pair is still there')
        ->and(is_dir($folder))->toBeTrue();
});

it('backs the pair up once, before any destructive work, and leaves no staged file behind', function () {
    [$folder, $dataPath, $partnerPath] = transactionFolder();
    $backups = [];
    $contentsAtBackup = [];
    $transaction = new FileSetTransaction($folder);
    $transaction->write($dataPath, "<?php\n\nreturn ['id' => 'asset', 'name' => 'Edited'];\n");
    $transaction->write($partnerPath, "<?php\n\nreturn [['type' => 'wait']];\n");
    $transaction->stage();

    // Staged copies exist while the caller evaluates them, and only then.
    expect(count(array_diff(scandir($folder) ?: [], ['.', '..'])))->toBe(4);

    $transaction->commit(function (string ...$paths) use (&$backups, &$contentsAtBackup): void {
        $backups[] = $paths;

        foreach ($paths as $path) {
            $contentsAtBackup[basename($path)] = (string) file_get_contents($path);
        }
    });

    expect($backups)->toHaveCount(1, 'one backup call for the pair, not one per file')
        ->and($backups[0])->toBe([$dataPath, $partnerPath])
        ->and($contentsAtBackup['asset.data.php'])->toBe("<?php\n\nreturn ['id' => 'asset'];\n", 'the backup saw the old bytes')
        ->and(array_keys(folderState($folder)))->toBe(['asset.data.php', 'asset.script.php'], 'no temporary survives a commit')
        ->and(file_get_contents($dataPath))->toContain('Edited');
});

it('removes the folder when a deletion empties it, and leaves an author\'s own files alone', function () {
    [$folder, $dataPath, $partnerPath] = transactionFolder();
    $transaction = new FileSetTransaction($folder);
    $transaction->remove($dataPath);
    $transaction->remove($partnerPath);
    $transaction->commit();

    expect(is_dir($folder))->toBeFalse();

    [$otherFolder, $otherData, $otherPartner] = transactionFolder();
    file_put_contents($otherFolder . '/notes.md', 'mine');
    $second = new FileSetTransaction($otherFolder);
    $second->remove($otherData);
    $second->remove($otherPartner);
    $second->commit();

    expect(is_dir($otherFolder))->toBeTrue()
        ->and(array_keys(folderState($otherFolder)))->toBe(['notes.md']);
});

it('refuses before touching anything when an existing file cannot be read back', function () {
    [$folder, $dataPath, $partnerPath] = transactionFolder();
    $before = folderState($folder);
    $files = new FailingFileSetOperations(failures: ['read' => [$dataPath]]);
    $transaction = new FileSetTransaction($folder, $files);
    $transaction->write($dataPath, "<?php\n\nreturn ['id' => 'asset', 'name' => 'Edited'];\n");
    $transaction->write($partnerPath, "<?php\n\nreturn [];\n");

    expect(fn() => $transaction->stage())->toThrow(FileSetTransactionFailure::class, 'could not be read')
        ->and(folderState($folder))->toBe($before)
        ->and(count(array_diff(scandir($folder) ?: [], ['.', '..'])))->toBe(2, 'nothing was staged');
});

it('drops staged copies without installing them when the caller rolls back', function () {
    [$folder, $dataPath, $partnerPath] = transactionFolder();
    $before = folderState($folder);
    $transaction = new FileSetTransaction($folder);
    $transaction->write($dataPath, "<?php\n\nreturn ['id' => 'asset', 'name' => 'Edited'];\n");
    $transaction->write($partnerPath, "<?php\n\nreturn [];\n");
    $transaction->stage();
    $transaction->rollBack();

    expect(folderState($folder))->toBe($before)
        ->and(count(array_diff(scandir($folder) ?: [], ['.', '..'])))->toBe(2);
});

// -- The asset's own save and delete, over the real filesystem ---------------

it('leaves no half pair on disk when a new cutscene cannot install its script', function () {
    $root = cutsceneProject();
    $library = CutsceneLibrary::fromProject($root);
    $created = CutsceneAsset::create(
        CutsceneType::CINEMATIC,
        'half-written',
        $library->rootFor(CutsceneType::CINEMATIC),
        ['id' => 'half-written', 'name' => 'Half Written', 'commands' => []],
        $root,
    );
    $folder = $root . '/assets/Cutscenes/Cinematics/half-written';
    // A directory where the script must go: the rename the engine's own
    // install performs cannot replace it, on any POSIX filesystem.
    mkdir($folder . '/half-written.script.php', 0o777, true);
    file_put_contents($folder . '/half-written.script.php/inside', 'not ours');

    expect(fn() => $created->save())->toThrow(FileSetTransactionFailure::class);
    expect(is_file($folder . '/half-written.data.php'))->toBeFalse('no data file without its script')
        ->and(glob($folder . '/*.tmp-*'))->toBe([])
        ->and($created->isDirty())->toBeTrue('a refused save leaves the work unsaved')
        ->and($created->isNew())->toBeTrue();
});

it('keeps both original files when an existing cutscene cannot install its second file', function () {
    $root = cutsceneProject();
    $folder = $root . '/assets/Cutscenes/Summons/lantern-wisp';
    touch($folder . '/lantern-wisp.data.php', time() - 3600);
    touch($folder . '/lantern-wisp.timeline.php', time() - 3600);
    $before = folderState($folder);
    $library = CutsceneLibrary::fromProject($root);
    $asset = $library->find(CutsceneType::SUMMON, 'lantern-wisp');
    $payload = $asset->payload();
    $payload['name'] = 'Renamed Wisp';
    $payload['fps'] = 24;
    $asset->apply($payload);

    // Both files must be written, and the timeline cannot be.
    $blocked = $folder . '/lantern-wisp.timeline.php.blocked';
    rename($folder . '/lantern-wisp.timeline.php', $blocked);
    mkdir($folder . '/lantern-wisp.timeline.php', 0o777, true);
    file_put_contents($folder . '/lantern-wisp.timeline.php/inside', 'not ours');

    $failure = null;

    try {
        $asset->save();
    } catch (FileSetTransactionFailure $thrown) {
        $failure = $thrown;
    }

    // Put the timeline back where it was to compare the pair as a whole.
    unlink($folder . '/lantern-wisp.timeline.php/inside');
    rmdir($folder . '/lantern-wisp.timeline.php');
    rename($blocked, $folder . '/lantern-wisp.timeline.php');

    expect($failure)->not->toBeNull()
        ->and($failure->wasRolledBack)->toBeTrue()
        ->and(folderState($folder))->toBe($before, 'the data file is byte-identical and its mtime untouched')
        ->and(glob($folder . '/*.tmp-*'))->toBe([])
        ->and($asset->isDirty())->toBeTrue();
});

it('keeps the complete pair when a cutscene deletion cannot remove its files', function () {
    $root = cutsceneProject();
    $folder = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns';
    touch($folder . '/harbour-lanterns.data.php', time() - 3600);
    touch($folder . '/harbour-lanterns.script.php', time() - 3600);
    $before = folderState($folder);
    $library = CutsceneLibrary::fromProject($root);
    $asset = $library->find(CutsceneType::CINEMATIC, 'harbour-lanterns');
    $asset->markDeleted(true);
    // A folder nothing may be unlinked from: POSIX needs write permission on
    // the directory to remove an entry from it.
    chmod($folder, 0o555);

    $failure = null;

    try {
        $asset->save();
    } catch (FileSetTransactionFailure $thrown) {
        $failure = $thrown;
    }

    chmod($folder, 0o777);

    expect($failure)->not->toBeNull()
        ->and($failure->wasRolledBack)->toBeTrue()
        ->and(folderState($folder))->toBe($before, 'the complete original pair survives a refused deletion');
});

it('writes one file of the pair without touching the other, or its modification time', function () {
    $root = cutsceneProject();
    $folder = $root . '/assets/Cutscenes/Cinematics/harbour-lanterns';
    $scriptPath = $folder . '/harbour-lanterns.script.php';
    $dataPath = $folder . '/harbour-lanterns.data.php';
    touch($dataPath, time() - 3600);
    touch($scriptPath, time() - 3600);
    $library = CutsceneLibrary::fromProject($root);
    $asset = $library->find(CutsceneType::CINEMATIC, 'harbour-lanterns');

    // A data-only edit.
    $scriptBefore = folderState($folder)['harbour-lanterns.script.php'];
    $payload = $asset->payload();
    $payload['name'] = 'Harbour Lanterns At Dusk';
    $asset->apply($payload);
    expect($asset->save())->toBeTrue()
        ->and(folderState($folder)['harbour-lanterns.script.php'])->toBe($scriptBefore, 'the script file was not rewritten')
        ->and(file_get_contents($dataPath))->toContain('Harbour Lanterns At Dusk');

    // A script-only edit.
    $dataBefore = folderState($folder)['harbour-lanterns.data.php'];
    $payload = $asset->payload();
    $payload['commands'][] = ['type' => 'wait', 'seconds' => 0.5];
    $asset->apply($payload);
    expect($asset->save())->toBeTrue()
        ->and(folderState($folder)['harbour-lanterns.data.php'])->toBe($dataBefore, 'the data file was not rewritten')
        ->and(file_get_contents($scriptPath))->toContain("'type' => 'wait'")
        ->and(glob($folder . '/*.tmp-*'))->toBe([]);
});

it('reports a rollback as failed when a restored file\'s modification time cannot be put back', function () {
    [$folder, $dataPath, $partnerPath] = transactionFolder();
    // The partner cannot be replaced, forcing a rollback of the data file --
    // whose bytes go back, but whose original modification time cannot.
    $files = new FailingFileSetOperations(failures: ['move' => [$partnerPath], 'setModifiedAt' => [$dataPath]]);
    $transaction = new FileSetTransaction($folder, $files);
    $transaction->write($dataPath, "<?php\n\nreturn ['id' => 'asset', 'name' => 'Edited'];\n");
    $transaction->write($partnerPath, "<?php\n\nreturn [];\n");
    $transaction->stage();

    $failure = null;

    try {
        $transaction->commit();
    } catch (FileSetTransactionFailure $thrown) {
        $failure = $thrown;
    }

    expect($failure)->not->toBeNull()
        ->and($failure->wasRolledBack)->toBeFalse('a file at the wrong time is not the file that was there')
        ->and($failure->unrestoredPaths)->toBe([$dataPath])
        ->and($failure->getMessage())->toContain('asset.data.php')
        ->and($failure->getMessage())->not->toContain('nothing was changed');
});

it('leaves destinations untouched and takes back all staging when a backup callback throws', function () {
    [$folder, $dataPath, $partnerPath] = transactionFolder();
    $before = folderState($folder);
    $transaction = new FileSetTransaction($folder);
    $transaction->write($dataPath, "<?php\n\nreturn ['id' => 'asset', 'name' => 'Edited'];\n");
    $transaction->write($partnerPath, "<?php\n\nreturn [['type' => 'wait']];\n");
    $transaction->stage();

    $failure = null;

    try {
        $transaction->commit(static function (string ...$paths): void {
            throw new RuntimeException('the backup disk is full');
        });
    } catch (FileSetTransactionFailure $thrown) {
        $failure = $thrown;
    }

    expect($failure)->not->toBeNull()
        ->and($failure->wasRolledBack)->toBeTrue('nothing was installed, so nothing needed restoring')
        ->and($failure->getMessage())->toContain('backup step failed')
        ->and($failure->getMessage())->toContain('the backup disk is full')
        ->and(folderState($folder))->toBe($before, 'both destinations are exactly what they were')
        ->and(count(array_diff(scandir($folder) ?: [], ['.', '..'])))->toBe(2, 'no staged temporary survives');
});

it('takes back a folder it created when the backup callback throws on a moving set', function () {
    [$folder, $dataPath, $partnerPath] = transactionFolder();
    $destination = dirname($folder) . '/moved-asset';
    $transaction = new FileSetTransaction($destination);
    $transaction->write($destination . '/moved-asset.data.php', "<?php\n\nreturn ['id' => 'moved'];\n");
    $transaction->remove($dataPath);
    $transaction->remove($partnerPath);
    $transaction->stage();

    expect(is_dir($destination))->toBeTrue('staging created the destination folder');

    try {
        $transaction->commit(static function (): void {
            throw new RuntimeException('no backups today');
        });
    } catch (FileSetTransactionFailure) {
        // Expected.
    }

    expect(is_dir($destination))->toBeFalse('the folder this transaction created is gone')
        ->and(is_file($dataPath))->toBeTrue()
        ->and(is_file($partnerPath))->toBeTrue();
});
