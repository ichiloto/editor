<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use Ichiloto\Editor\IO\AtomicFile;
use RuntimeException;

/**
 * One write for one file, however many categories are editing it.
 *
 * A knowledge catalogue's subjects and its reports, and an optimization
 * policy's weights, outcomes and exclusions, are separate categories over one
 * file. Saved one at a time they each fold into what the file currently
 * holds, which is correct but writes the file once per category. Save All
 * asks them all at once instead: the payload is read once, every dirty
 * category folds its own part into it, and the result is written once.
 *
 * The ordering that makes this safe is the whole point. Nothing is written
 * until every projection has been folded, and no category's baseline is
 * advanced until the write has succeeded -- so a refused write leaves every
 * category exactly as dirty as it was, holding exactly the edits it held,
 * over a file whose bytes did not change.
 *
 * @package Ichiloto\Editor\Database
 */
final class SharedFileTransaction
{
    /**
     * Groups saveable things by the file they write through.
     *
     * Anything that does not share a file -- a whole-file category, an actor
     * database, a map -- is returned in a group of its own, so a caller has
     * one shape for everything it saves rather than two code paths.
     *
     * @param array<array-key, object> $databases The saveable things.
     * @return array<string, array<array-key, object>> Groups by canonical path.
     */
    public static function groupByPath(array $databases): array
    {
        $groups = [];

        foreach ($databases as $key => $database) {
            $path = $database instanceof ProjectRecordDatabase && $database->sharesBackingFile()
                ? $database->backingFilePath()
                // Its own group, keyed by something no path can collide with.
                : "\0" . strval($key);

            $groups[$path][$key] = $database;
        }

        return $groups;
    }

    /**
     * Writes one file for every dirty category that shares it.
     *
     * @param array<array-key, object> $databases The categories sharing one file.
     * @return bool True when the file was written.
     */
    public static function commit(array $databases): bool
    {
        $dirty = array_values(array_filter(
            $databases,
            static fn(object $database): bool => $database instanceof ProjectRecordDatabase
                && $database->sharesBackingFile()
                && $database->isEditable()
                && $database->isDirty(),
        ));

        if ($dirty === []) {
            return false;
        }

        $first = $dirty[0];
        $path = $first->backingFilePath();
        $file = PhpDataFile::load($path, $first->projectRoot());

        // One snapshot of the source, and every category's wants resolved
        // against it before a byte is written.
        if (self::commitToSource($dirty, $file)) {
            self::adopt($dirty);

            return true;
        }

        if (self::wouldRegenerateAuthoredSource($dirty)) {
            throw new RuntimeException(sprintf(
                'Refusing to add or remove records in %s: its entries are constructor calls this editor cannot rewrite safely. Edit the file directly.',
                basename($path),
            ));
        }

        $payload = is_array($file->payload) ? $file->payload : [];

        foreach ($dirty as $database) {
            $payload = $database->foldInto($payload);
        }

        // A file no category owns a key of is rebuilt from the one category
        // that can describe the whole of it; several such categories would
        // each describe a different whole, so they are written in turn.
        if (self::projectedOnly($dirty)) {
            $file->save($payload);
        } else {
            foreach ($dirty as $database) {
                $file->save($database->wholeFilePayload());
                $file = PhpDataFile::load($path, $first->projectRoot());
            }
        }

        self::adopt($dirty);

        return true;
    }

    /**
     * Writes the file by editing the author's own source, when every dirty
     * category can say what it wants in those terms.
     *
     * @param ProjectRecordDatabase[] $dirty The dirty categories.
     * @param PhpDataFile $file The file, read once.
     * @return bool True when the file was written this way.
     */
    private static function commitToSource(array $dirty, PhpDataFile $file): bool
    {
        if (! is_file($file->path)) {
            return false;
        }

        $document = PhpSourceDocument::parse((string) file_get_contents($file->path));
        $patches = [];
        $removals = [];
        $additions = [];

        foreach ($dirty as $database) {
            $plan = $database->sourcePlan($document, $file);

            if ($plan === null) {
                return false;
            }

            $patches = [...$patches, ...$plan['patches']];
            $removals = [...$removals, ...$plan['removals']];
            $additions = [...$additions, ...$plan['additions']];
        }

        if ($patches === [] && $removals === [] && $additions === []) {
            // Nothing to write, but the categories are still adopted so a
            // save that found no work leaves them clean.
            return true;
        }

        // Values first, while every position still means what the snapshot
        // said; then removals from the back, so one removal cannot move the
        // entry another was about to remove; then the new entries.
        foreach ($patches as $patch) {
            $document = $patch['value'] === null
                ? $document->withoutArgument($patch['entry'], $patch['path'])
                : $document->withArgument($patch['entry'], $patch['path'], PhpValueExporter::export($patch['value']));
        }

        $removals = array_values(array_unique($removals));
        rsort($removals);

        foreach ($removals as $position) {
            $document = $document->withoutEntry($position);
        }

        foreach ($additions as $entry) {
            $document = $document->withNewEntry($entry['class'], $entry['arguments']);
        }

        AtomicFile::write($file->path, $document->source);

        return true;
    }

    /**
     * Returns whether every dirty category owns a key of the file rather
     * than a subset of its entries.
     *
     * @param ProjectRecordDatabase[] $dirty The dirty categories.
     * @return bool True when they all do.
     */
    private static function projectedOnly(array $dirty): bool
    {
        foreach ($dirty as $database) {
            if (! $database->ownsFileKey()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns whether writing this group the ordinary way would rebuild an
     * author's constructor calls.
     *
     * @param ProjectRecordDatabase[] $dirty The dirty categories.
     * @return bool True when it would.
     */
    private static function wouldRegenerateAuthoredSource(array $dirty): bool
    {
        foreach ($dirty as $database) {
            if ($database->wouldRegenerate()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tells every category the file it shares now holds what it holds.
     *
     * @param ProjectRecordDatabase[] $dirty The categories written for.
     * @return void
     */
    private static function adopt(array $dirty): void
    {
        foreach ($dirty as $database) {
            $database->adoptSavedFile();
        }
    }
}
