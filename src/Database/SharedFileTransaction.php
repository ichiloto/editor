<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

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
        $file = PhpDataFile::load($first->backingFilePath(), $first->projectRoot());
        $payload = is_array($file->payload) ? $file->payload : [];

        foreach ($dirty as $database) {
            $payload = $database->foldInto($payload);
        }

        // Throws rather than half-writing; nothing below runs if it does.
        $file->save($payload);

        foreach ($dirty as $database) {
            $database->adoptSavedFile();
        }

        return true;
    }
}
