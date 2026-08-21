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
     * The file is read once. If every dirty category can say what it wants
     * as edits to the author's own source, those edits are composed against
     * that one reading and written in one replacement. Otherwise every dirty
     * category folds its records into that one reading's payload, in turn,
     * and the result is written in one replacement -- unless a category's
     * entries are constructor calls whose list has changed, which is
     * refused with the reason rather than regenerated. Either way there is
     * one payload, one write, and no baseline moves until it has succeeded.
     *
     * @param array<array-key, object> $databases The categories sharing one file.
     * @return bool True when the file was written.
     * @throws SourceIdentityConflict When an entry cannot be addressed with certainty.
     * @throws RuntimeException When the write is refused or fails.
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

        foreach ($dirty as $database) {
            $refusal = $database->regenerationRefusal($file);

            if ($refusal !== null) {
                throw new RuntimeException($refusal);
            }
        }

        // One authoritative payload: the file as it is now, with every dirty
        // category's part folded in, in turn, and written once. No category
        // describes the whole file from its own older reading of it.
        $payload = is_array($file->payload) ? $file->payload : [];

        foreach ($dirty as $database) {
            $payload = $database->foldInto($payload);
        }

        $file->save($payload);
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
        $insertions = [];

        foreach ($dirty as $database) {
            $plan = $database->sourcePlan($document, $file);

            if ($plan === null) {
                return false;
            }

            $patches = [...$patches, ...$plan['patches']];
            $removals = [...$removals, ...$plan['removals']];
            $insertions = [...$insertions, ...$plan['insertions']];
        }

        if ($patches === [] && $removals === [] && $insertions === []) {
            // Nothing to write, but the categories are still adopted so a
            // save that found no work leaves them clean.
            return true;
        }

        // Values first, while every position still means what the snapshot
        // said. Then the list changes, from the back of the file forward, so
        // that removing an entry or placing one ahead of another moves only
        // the positions already dealt with. Entries with nothing to go ahead
        // of are appended last.
        foreach ($patches as $patch) {
            $document = $patch['value'] === null
                ? $document->withoutArgument($patch['entry'], $patch['path'])
                : $document->withArgument($patch['entry'], $patch['path'], PhpValueExporter::export($patch['value']));
        }

        $anchored = [];
        $appended = [];

        foreach ($insertions as $insertion) {
            if ($insertion['before'] === null) {
                $appended[] = $insertion;
            } else {
                $anchored[$insertion['before']][] = $insertion;
            }
        }

        $removals = array_values(array_unique($removals));
        $positions = array_values(array_unique([...$removals, ...array_keys($anchored)]));
        rsort($positions);

        foreach ($positions as $position) {
            if (in_array($position, $removals, true)) {
                $document = $document->withoutEntry($position);
            }

            // Placed in the order the category lists them: each goes ahead of
            // the neighbour, after the ones already placed ahead of it.
            foreach (array_reverse($anchored[$position] ?? []) as $insertion) {
                $document = $document->withNewEntry($insertion['class'], $insertion['arguments'], $position);
            }
        }

        foreach ($appended as $insertion) {
            $document = $document->withNewEntry($insertion['class'], $insertion['arguments']);
        }

        AtomicFile::write($file->path, $document->source);

        return true;
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
