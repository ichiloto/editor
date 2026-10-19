<?php

declare(strict_types=1);

use Ichiloto\Editor\Storage\FileSetTransaction;
use Ichiloto\Editor\Storage\PreparedSave;
use Ichiloto\Editor\Storage\SaveSet;
use Ichiloto\Editor\Storage\SaveSetRefusal;

/**
 * Several documents' saves installed as one transaction: all of them, or
 * none, each adopting its written state only once every file is installed.
 */

/** A folder holding a.txt and b.txt, with c.txt yet to be written. @return array{string, array<string, string>} */
function saveSetFolder(): array
{
    $folder = sys_get_temp_dir() . '/ichiloto-save-set-' . bin2hex(random_bytes(4));
    mkdir($folder);
    file_put_contents($folder . '/a.txt', "a\n");
    file_put_contents($folder . '/b.txt', "b\n");

    return [$folder, ['a' => $folder . '/a.txt', 'b' => $folder . '/b.txt', 'c' => $folder . '/c.txt']];
}

/**
 * A document writing one file, noting when it verifies and adopts.
 *
 * @param list<string> $log
 */
function saveSetDocument(string $label, string $path, string $contents, array &$log, ?string $refusal = null): PreparedSave
{
    return new PreparedSave(
        $label,
        static fn(FileSetTransaction $transaction) => $transaction->write($path, $contents),
        static function (array $staged) use ($label, $path, $contents, $refusal, &$log): void {
            $log[] = 'verify ' . $label;
            expect(file_get_contents($staged[$path]))->toBe($contents);
            if ($refusal !== null) {
                throw new RuntimeException($refusal);
            }
        },
        static function () use ($label, $path, $contents, &$log): void {
            // Every document of the set is on disk before any adopts.
            expect(file_get_contents($path))->toBe($contents);
            $log[] = 'adopt ' . $label;
        },
    );
}

it('installs every document of a set, verifying all before any file and adopting each once all are written', function () {
    [$folder, $paths] = saveSetFolder();
    $log = [];

    $written = SaveSet::commit($folder, [
        saveSetDocument('first', $paths['a'], "A\n", $log),
        saveSetDocument('second', $paths['c'], "C\n", $log),
        PreparedSave::adoptOnly('unchanged', static function () use (&$log): void {
            $log[] = 'adopt unchanged';
        }),
    ]);

    expect($written)->toBe(['first' => [$paths['a']], 'second' => [$paths['c']], 'unchanged' => []])
        ->and($log)->toBe(['verify first', 'verify second', 'adopt first', 'adopt second', 'adopt unchanged'])
        ->and(file_get_contents($paths['a']))->toBe("A\n")
        ->and(file_get_contents($paths['c']))->toBe("C\n");
});

it('writes nothing when the last document\'s staged bytes do not read back, and names it', function () {
    [$folder, $paths] = saveSetFolder();
    $log = [];
    $before = sourceHashTree($folder);

    expect(function () use ($folder, $paths, &$log): void {
        SaveSet::commit($folder, [
            saveSetDocument('first', $paths['a'], "A\n", $log),
            saveSetDocument('last', $paths['b'], "B\n", $log, 'b.txt would not read back as the edited document'),
        ]);
    })->toThrow(SaveSetRefusal::class, 'last: b.txt would not read back as the edited document')
        ->and(sourceHashTree($folder))->toBe($before)
        ->and($log)->toBe(['verify first', 'verify last']);
});

it('puts back every file already installed when a later one cannot be, and adopts nothing', function () {
    [$folder, $paths] = saveSetFolder();
    $log = [];
    $before = sourceHashTree($folder);
    $files = new FailingFileSetOperations(failures: ['move' => [$paths['b']]]);

    try {
        SaveSet::commit($folder, [
            saveSetDocument('first', $paths['a'], "A\n", $log),
            saveSetDocument('second', $paths['c'], "C\n", $log),
            saveSetDocument('third', $paths['b'], "B\n", $log),
        ], files: $files);
        $refusal = null;
    } catch (SaveSetRefusal $caught) {
        $refusal = $caught;
    }

    expect($refusal)->toBeInstanceOf(SaveSetRefusal::class)
        ->and($refusal?->wasRolledBack)->toBeTrue()
        ->and($refusal?->unrestored)->toBe([])
        ->and($refusal?->getMessage())->toContain('Unable to replace b.txt')
        ->and(sourceHashTree($folder))->toBe($before)
        ->and(array_filter($log, static fn(string $entry): bool => str_starts_with($entry, 'adopt')))->toBe([]);
});

it('refuses two documents writing one file before anything is staged', function () {
    [$folder, $paths] = saveSetFolder();
    $log = [];
    $before = sourceHashTree($folder);

    expect(function () use ($folder, $paths, &$log): void {
        SaveSet::commit($folder, [
            saveSetDocument('first', $paths['a'], "A\n", $log),
            saveSetDocument('second', $paths['a'], "A2\n", $log),
        ]);
    })->toThrow(SaveSetRefusal::class, 'second: a.txt is written twice in one transaction')
        ->and(sourceHashTree($folder))->toBe($before)
        ->and($log)->toBe([]);
});

it('says every file was written when a document cannot take up its own, still adopting the rest', function () {
    [$folder, $paths] = saveSetFolder();
    $log = [];
    $stubborn = new PreparedSave('stubborn', static fn(FileSetTransaction $transaction) => $transaction->write($paths['c'], "C\n"),
        adopt: static fn() => throw new RuntimeException('its index could not be reread'));

    try {
        SaveSet::commit($folder, [$stubborn, saveSetDocument('other', $paths['a'], "A\n", $log)]);
        $failure = null;
    } catch (Ichiloto\Editor\Storage\SaveSetAdoptionFailure $caught) {
        $failure = $caught;
    }

    expect($failure?->written)->toBe(['stubborn' => [$paths['c']], 'other' => [$paths['a']]])
        ->and($failure?->getMessage())->toStartWith('Every file was written, but stubborn could not take up what was written (its index could not be reread)')
        ->and($log)->toBe(['verify other', 'adopt other'])
        ->and(file_get_contents($paths['c']))->toBe("C\n");
});
