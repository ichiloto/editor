<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Source;

use Ichiloto\Editor\Database\PhpValueExporter;

/**
 * Turns the difference between what a file evaluated to and what it should
 * now evaluate to into the fewest edits of the file's own bytes.
 *
 * The values an author edits are arrays; the file is source. This walks both
 * together: a scalar that changed is rewritten where its literal sits, a
 * keyed entry that appeared is added on lines of its own, ahead of the entry
 * that follows it in the new value so the file reads in that order (at the
 * end when nothing the file holds follows it), one that vanished
 * is cut with its heading, and a list is aligned so that an entry moved
 * keeps its bytes and an entry edited keeps everything but the changed
 * field. A value written as a variable is retargeted rather than expanded:
 * a nowdoc referenced once is edited between its markers, and a literal
 * named once is edited where it is assigned, as the data it is. One shared
 * by several entries is left alone for them while the edited entry gets a
 * value of its own -- a new, safely named variable for text -- when the
 * shared value is plain data a copy holds in full. Text with line breaks
 * is always written as a nowdoc, so ASCII art stays readable and exact --
 * trailing spaces, backslashes, Unicode and all.
 *
 * What it cannot express -- a change under an expression, under a key it
 * could not read, a list turned into a map, under a variable the file builds
 * with its own code -- it refuses by path, so nothing is written that a
 * reader would not have written by hand. A variable built in code is never
 * replaced by a literal of what the code produced.
 *
 * @package Ichiloto\Editor\Cutscenes\Source
 */
final class ArraySourceWriter
{
    /** The longest a value written on one line may be; a longer one is written an entry per line. */
    private const int INLINE_WIDTH = 100;

    /**
     * Entries queued for the end of an array, per parent node: several
     * appends into one array become one composable edit at flush time.
     *
     * @var array<int, array{node: SourceNode, lines: string[], inline: string[]}>
     */
    private array $appends = [];

    /**
     * Keyed entries queued ahead of an entry the file already holds, per
     * entry: several inserted before one entry become one edit, in order.
     *
     * @var array<int, array{node: SourceNode, anchor: SourceEntry, entries: list<array{key: int|string|null, literal: string}>}>
     */
    private array $inserts = [];

    /**
     * Entries removed from arrays whose entries share their lines, per
     * parent node, cut together at flush time so the separators that joined
     * them go with them.
     *
     * @var array<int, array{node: SourceNode, indexes: list<int>}>
     */
    private array $sharedLineRemovals = [];

    /**
     * Arrays whose last surviving entry lost its comma with the entries
     * after it, by node.
     *
     * @var array<int, true>
     */
    private array $separatorCuts = [];

    /**
     * Entry indexes this rewrite removes (or moves away), per parent node,
     * so an append knows which entry will actually precede it.
     *
     * @var array<int, array<int, true>>
     */
    private array $removedEntries = [];

    /** @var array<int, array{0: int, 1: int, 2: string}> */
    private array $edits = [];

    /** @var list<string> The variables written as data the diff is inside, innermost last. */
    private array $hosts = [];

    /** @var array<string, true> Variable names claimed by this plan. */
    private array $claimedVariables = [];

    /** @var array<string, string>|null Class imports in the returned array's scope. */
    private ?array $classImports = null;

    private function __construct(private readonly PhpArraySourceDocument $document)
    {
    }

    /**
     * Returns the document rewritten so that it evaluates to `$new`, given
     * that it now evaluates to `$old`.
     *
     * @param PhpArraySourceDocument $document The file as it is.
     * @param array<array-key, mixed> $old What the file evaluates to now.
     * @param array<array-key, mixed> $new What it must evaluate to.
     * @return PhpArraySourceDocument The rewritten document; the same
     *   instance when nothing differs.
     * @throws SourcePreservationRefusal When a change cannot be expressed in the source.
     */
    public static function rewrite(PhpArraySourceDocument $document, array $old, array $new, array $keyRenames = []): PhpArraySourceDocument
    {
        foreach ($keyRenames as $rename) {
            $path = $rename['path'];
            $oldKey = array_pop($path);
            $parent = &$old;
            foreach ($path as $step) {
                $parent = &$parent[$step];
            }
            if (! is_array($parent) || ! array_key_exists($oldKey, $parent) || array_key_exists($rename['key'], $parent)) {
                throw new SourcePreservationRefusal('The key rename does not match the loaded data.');
            }
            $document = $document->withEdits([$document->renameKeyEdit($rename['path'], $rename['key'])]);
            $renamed = [];
            foreach ($parent as $key => $value) {
                $renamed[$key === $oldKey ? $rename['key'] : $key] = $value;
            }
            $parent = $renamed;
            unset($parent);
        }
        if ($old === $new) {
            return $document;
        }

        $writer = new self($document);
        $writer->diff($document->root(), $old, $new, []);
        $writer->flushAppends();

        return $document->withEdits($writer->edits);
    }

    /**
     * @param array<int, int|string> $path
     */
    private function diff(SourceNode $node, mixed $old, mixed $new, array $path): void
    {
        if ($old === $new) {
            return;
        }

        if ($node->kind === SourceNode::VARIABLE) {
            $this->diffVariable($node, $old, $new, $path);

            return;
        }

        if (is_array($new) && $this->outgrowsItsLine($node, $new, $path)) {
            // Plain data written on one line that would grow past the width is written an entry per line instead.
            $this->edits[] = $this->document->replaceValueEdit($path, $this->literalFor($new, $path, true, true));

            return;
        }

        if (is_array($new)) {
            $this->diffArray($node, is_array($old) ? $old : null, $new, $path);

            return;
        }

        if ($node->kind === SourceNode::EXPRESSION && ($edits = $this->findEnumCaseEdits($node, $old, $new)) !== null) {
            array_push($this->edits, ...$edits);

            return;
        }

        // An enum case's value, written as `Enum::CASE->value`, stays written
        // that way: the new value is the matching case of the same enum.
        if ($node->kind === SourceNode::EXPRESSION && ($literal = $this->findEnumValueExpression($node, $new)) !== null) {
            $this->edits[] = $this->document->replaceValueEdit($path, $literal);

            return;
        }

        // A scalar or null now sits here. An array written as plain data (literals only, no comments) holds nothing
        // authored beyond its values, so the new value takes its place; anything more stays refused below.
        match ($node->kind) {
            SourceNode::SCALAR => $this->edits[] = $this->replacementFor($node, $path, $new),
            SourceNode::ARRAY => $this->isPlainData($node)
                ? $this->edits[] = $this->replacementFor($node, $path, $new)
                : throw new SourcePreservationRefusal(sprintf(
                    'The value at %s is an array in the file and cannot be replaced by a single value without rewriting it.',
                    PhpArraySourceDocument::describePath($path),
                )),
            SourceNode::EXPRESSION => throw new SourcePreservationRefusal(sprintf(
                'The value at %s is written as an expression the editor cannot rewrite; change it in the file, or write it as plain data.',
                PhpArraySourceDocument::describePath($path),
            )),
            default => throw new SourcePreservationRefusal(sprintf(
                'The value at %s is an array in the file and cannot be replaced by a single value without rewriting it.',
                PhpArraySourceDocument::describePath($path),
            )),
        };
    }

    /**
     * Whether plain data the file writes on one line would run past the
     * width written that way with its new value.
     *
     * @param array<array-key, mixed> $new
     * @param array<int, int|string> $path
     */
    private function outgrowsItsLine(SourceNode $node, array $new, array $path): bool
    {
        if ($node->kind !== SourceNode::ARRAY || $path === [] || ! $this->isPlainData($node)
            || str_contains(substr($this->document->source, $node->start, $node->end - $node->start), "\n")
            || ! $this->fitsOnOneLine($new)) {
            return false;
        }

        $line = self::renderOneLine($new);

        // Text with line breaks is written as a variable, which this leaves to the entry-by-entry diff.
        return strlen($line) > self::INLINE_WIDTH && ! str_contains($line, "\n");
    }

    /**
     * A plain value written on one line, as an entry that fits there is.
     *
     * @param array<array-key, mixed> $value
     */
    private static function renderOneLine(array $value): string
    {
        $isList = array_is_list($value);
        $parts = [];
        foreach ($value as $key => $item) {
            $literal = is_array($item) ? self::renderOneLine($item) : PhpValueExporter::export($item);
            $parts[] = $isList ? $literal : var_export($key, true) . ' => ' . $literal;
        }

        return '[' . implode(', ', $parts) . ']';
    }

    /** Whether an array's source is only literal keys and values, with no comment, expression or variable in it. */
    private function isPlainData(SourceNode $node): bool
    {
        foreach ($node->entries as $entry) {
            $value = $entry->value;
            if ($value->kind === SourceNode::ARRAY ? !$this->isPlainData($value) : $value->kind !== SourceNode::SCALAR) {
                return false;
            }
        }
        foreach (\PhpToken::tokenize('<?php ' . substr($this->document->source, $node->start, $node->end - $node->start)) as $token) {
            if ($token->is([T_COMMENT, T_DOC_COMMENT])) { return false; }
        }
        return ! $node->hasOpaqueKey;
    }

    /**
     * @param array<array-key, mixed>|null $old
     * @param array<array-key, mixed> $new
     * @param array<int, int|string> $path
     */
    private function diffArray(SourceNode $node, ?array $old, array $new, array $path): void
    {
        if ($node->kind !== SourceNode::ARRAY) {
            if ($node->kind === SourceNode::EXPRESSION) {
                throw new SourcePreservationRefusal(sprintf(
                    'The value at %s is written as an expression the editor cannot rewrite.',
                    PhpArraySourceDocument::describePath($path),
                ));
            }

            // A scalar holding what is now an array: the literal takes its place.
            $this->edits[] = $this->replacementFor($node, $path, $new);

            return;
        }

        if ($node->hasOpaqueKey) {
            throw new SourcePreservationRefusal(sprintf(
                'The array at %s has a key or spread the editor cannot read, so it will not rewrite the array.',
                PhpArraySourceDocument::describePath($path),
            ));
        }

        $old ??= [];

        if ($node->isList && $node->entries !== []) {
            if (! array_is_list($new)) {
                throw new SourcePreservationRefusal(sprintf(
                    'The list at %s would become a keyed array, which the editor will not rewrite in place.',
                    PhpArraySourceDocument::describePath($path),
                ));
            }

            $this->diffList($node, array_values($old), $new, $path);

            return;
        }

        if ($node->entries === [] && array_is_list($new)) {
            // An empty array in the file gaining list entries.
            $this->diffList($node, [], $new, $path);

            return;
        }

        // Keyed: recurse into keys both have, add the new ones where the new
        // value orders them, cut the gone.
        $keys = array_keys($new);

        foreach ($keys as $position => $key) {
            $value = $new[$key];
            $entry = $node->entryFor($key);

            if ($entry !== null && array_key_exists($key, $old)) {
                $this->diff($entry->value, $old[$key], $value, [...$path, $key]);

                continue;
            }

            // An array whose entries share their lines takes a new entry the same way when it fits.
            $literal = $this->literalFor($value, [...$path, $key], $this->sharesEntryLines($node));
            $anchor = $this->findFollowingEntry($node, $old, array_slice($keys, $position + 1));

            if ($anchor === null) {
                $this->queueAppend($node, $key, $literal);

                continue;
            }

            $this->inserts[spl_object_id($anchor)] ??= ['node' => $node, 'anchor' => $anchor, 'entries' => []];
            $this->inserts[spl_object_id($anchor)]['entries'][] = ['key' => $key, 'literal' => $literal];
        }

        foreach ($node->entries as $index => $entry) {
            if ($entry->key !== null && ! array_key_exists($entry->key, $new)) {
                $this->queueRemoval($node, $index, [...$path, $entry->key]);
            }
        }
    }

    /**
     * Aligns the entries of a list with the items it should now hold.
     *
     * Entries equal to an item stay; entries and items sharing a stable id,
     * or equal to each other elsewhere, or left over at the same rank
     * between the kept ones, are one entry each -- diffed field by field --
     * and any that no longer sit in order are moved with their bytes,
     * patches included. What remains is inserted or removed.
     *
     * @param array<int, mixed> $old
     * @param array<int, mixed> $new
     * @param array<int, int|string> $path
     */
    private function diffList(SourceNode $node, array $old, array $new, array $path): void
    {
        $oldCount = count($old);
        $newCount = count($new);
        $matchedOld = [];
        $matchedNew = [];

        // 1. Longest common subsequence of equal items: the anchors.
        $lengths = array_fill(0, $oldCount + 1, array_fill(0, $newCount + 1, 0));

        for ($i = $oldCount - 1; $i >= 0; $i--) {
            for ($j = $newCount - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $old[$i] === $new[$j]
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $i = 0;
        $j = 0;
        $anchors = [];

        while ($i < $oldCount && $j < $newCount) {
            if ($old[$i] === $new[$j]) {
                $anchors[] = [$i, $j];
                $matchedOld[$i] = $j;
                $matchedNew[$j] = $i;
                $i++;
                $j++;
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        // 2. Same stable id: one entry, edited.
        foreach ($new as $j => $item) {
            if (isset($matchedNew[$j]) || ($id = self::identityOf($item)) === null) {
                continue;
            }

            foreach ($old as $i => $entry) {
                if (! isset($matchedOld[$i]) && self::identityOf($entry) === $id) {
                    $matchedOld[$i] = $j;
                    $matchedNew[$j] = $i;

                    break;
                }
            }
        }

        // 3. Same unique name: one entry, edited. The explicit identity rule
        //    for entries with no stable id -- a legacy map NPC -- and only
        //    where the name settles it: a name carried by two entries on
        //    either side settles nothing and falls through to rank pairing.
        foreach ($new as $j => $item) {
            if (isset($matchedNew[$j]) || ($name = self::uniqueNameOf($item, $new, $matchedNew)) === null) {
                continue;
            }

            foreach ($old as $i => $entry) {
                if (! isset($matchedOld[$i])
                    && self::identityOf($entry) === null
                    && self::uniqueNameOf($entry, $old, $matchedOld) === $name
                ) {
                    $matchedOld[$i] = $j;
                    $matchedNew[$j] = $i;

                    break;
                }
            }
        }

        // 4. Equal but elsewhere: one entry, moved.
        foreach ($new as $j => $item) {
            if (isset($matchedNew[$j])) {
                continue;
            }

            foreach ($old as $i => $entry) {
                if (! isset($matchedOld[$i]) && $entry === $item) {
                    $matchedOld[$i] = $j;
                    $matchedNew[$j] = $i;

                    break;
                }
            }
        }

        // 5. Between consecutive anchors, what is left pairs up by rank: an
        //    edited keyframe is a patched keyframe, not a new one.
        $bounds = [...$anchors, [$oldCount, $newCount]];
        $previousOld = -1;
        $previousNew = -1;

        foreach ($bounds as [$anchorOld, $anchorNew]) {
            $olds = [];
            $news = [];

            for ($i = $previousOld + 1; $i < $anchorOld; $i++) {
                if (! isset($matchedOld[$i])) {
                    $olds[] = $i;
                }
            }

            for ($j = $previousNew + 1; $j < $anchorNew; $j++) {
                if (! isset($matchedNew[$j])) {
                    $news[] = $j;
                }
            }

            foreach ($news as $rank => $j) {
                if (! isset($olds[$rank])) {
                    break;
                }

                $matchedOld[$olds[$rank]] = $j;
                $matchedNew[$j] = $olds[$rank];
            }

            $previousOld = $anchorOld;
            $previousNew = $anchorNew;
        }

        $this->emitListMatches($node, $old, $new, $path, $matchedOld, $matchedNew);
    }

    /** Emit aligned entries through the same source/comment ownership rules for every list. */
    private function emitListMatches(SourceNode $node, array $old, array $new, array $path, array $matchedOld, array $matchedNew): void
    {
        // 6. Which matched entries stay in place: the longest run of them
        //    whose old order agrees with their new order. The rest move.
        ksort($matchedNew);
        $sequence = array_values($matchedNew);
        $inPlace = self::longestIncreasingSubsequence($sequence);
        $newIndexes = array_keys($matchedNew);
        $kept = [];
        $moved = [];

        foreach ($newIndexes as $rank => $j) {
            $i = $matchedNew[$j];

            if (isset($inPlace[$rank])) {
                $kept[$j] = $i;
            } else {
                $moved[$j] = $i;
            }
        }

        // 7. Emit. Patches for kept entries; removals for entries that are
        //    gone; then, in new order, insertions and moves ahead of the
        //    next kept entry, or after everything.
        foreach ($kept as $j => $i) {
            $this->diff($node->entries[$i]->value, $old[$i], $new[$j], [...$path, $i]);
        }

        foreach ($old as $i => $entry) {
            if (! isset($matchedOld[$i])) {
                $this->queueRemoval($node, $i, [...$path, $i]);
            }
        }

        $anchor = null;
        $anchors = [];
        for ($j = count($new) - 1; $j >= 0; $j--) {
            $anchors[$j] = $anchor;
            if (isset($kept[$j])) { $anchor = $kept[$j]; }
        }
        $inline = $this->prefersInline($node);
        foreach ($new as $j => $item) {
            if (isset($kept[$j])) {
                continue;
            }

            $position = $anchors[$j] ?? count($node->entries);

            if (isset($moved[$j])) {
                $this->emitMove($node, $moved[$j], $old[$moved[$j]], $item, $path, $position);

                continue;
            }

            if ($position >= count($node->entries)) {
                $this->queueAppend($node, null, $this->literalFor($item, [...$path, $j], $inline));

                continue;
            }

            $this->edits[] = $this->document->insertEntryEdit(
                $path,
                $position,
                null,
                $this->literalFor($item, [...$path, $j], $inline),
            );
        }
    }

    /**
     * Returns the entry a new key goes ahead of: the first of the keys after
     * it in the new value that the file holds and keeps. Null puts the new
     * key at the end.
     *
     * @param array<array-key, mixed> $old
     * @param list<array-key> $following
     */
    private function findFollowingEntry(SourceNode $node, array $old, array $following): ?SourceEntry
    {
        foreach ($following as $key) {
            $entry = $node->entryFor($key);

            if ($entry !== null && array_key_exists($key, $old)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Removes an entry: at once, with its lines and heading, from an array
     * written a line per entry; at flush time, with the separators that
     * joined it, from one whose entries share their lines.
     *
     * @param array<int, int|string> $path The entry's path.
     */
    private function queueRemoval(SourceNode $node, int $index, array $path): void
    {
        $id = spl_object_id($node);
        $this->removedEntries[$id][$index] = true;

        if (! $this->document->sharesLines($node)) {
            $this->edits[] = $this->document->removeEntryEdit($path);

            return;
        }

        $this->sharedLineRemovals[$id] ??= ['node' => $node, 'indexes' => []];
        $this->sharedLineRemovals[$id]['indexes'][] = $index;
    }

    /**
     * Queues an entry for the end of an array. Appends flush together, one
     * composable edit per parent, so they never collide with each other or
     * with a removal of the entry that used to be last.
     */
    private function queueAppend(SourceNode $node, int|string|null $key, string $literal): void
    {
        $id = spl_object_id($node);
        $this->appends[$id] ??= ['node' => $node, 'lines' => [], 'inline' => []];

        // An array whose entries share their lines takes its appends the
        // same way, joined after the last entry.
        $last = $node->entries === [] ? null : $node->entries[count($node->entries) - 1];

        if ($last !== null && $this->sharesEntryLines($node)) {
            // A value too long for the line continues under the line it joins.
            $this->appends[$id]['inline'][] = $this->document->renderInlineEntry($key, $literal, $last->start);

            return;
        }

        $this->appends[$id]['lines'][] = $this->document->renderEntryLine($node, $key, $literal);
    }

    /** Whether an array's entries share their lines, as a one-line objective's keys do. */
    private function sharesEntryLines(SourceNode $node): bool
    {
        $last = $node->entries === [] ? null : $node->entries[count($node->entries) - 1];

        return $last !== null && $this->document->lineIndentBefore($last->start) === null;
    }

    /**
     * Emits one edit per parent with queued appends.
     */
    private function flushAppends(): void
    {
        foreach ($this->sharedLineRemovals as $id => $removal) {
            $indexes = $removal['indexes'];
            sort($indexes);
            $planned = $this->document->removeSharedLineEntriesEdits($removal['node'], $indexes);
            array_push($this->edits, ...$planned['edits']);

            if ($planned['separatorCut']) {
                $this->separatorCuts[$id] = true;
            }
        }

        $this->sharedLineRemovals = [];

        foreach ($this->inserts as $insert) {
            $this->edits[] = $this->document->insertEntriesBeforeEdit($insert['node'], $insert['anchor'], $insert['entries']);
        }

        $this->inserts = [];

        foreach ($this->appends as $id => $append) {
            $node = $append['node'];
            $removed = $this->removedEntries[$id] ?? [];
            $lastSurviving = null;

            foreach ($node->entries as $index => $entry) {
                if (! isset($removed[$index])) {
                    $lastSurviving = $entry;
                }
            }

            if ($append['inline'] !== []) {
                // After the last entry this rewrite keeps; right at the body
                // start when it keeps none.
                $this->edits[] = $this->document->planInlineAppend($node, $append['inline'], $lastSurviving, isset($this->separatorCuts[$id]));
            }

            if ($append['lines'] === []) {
                continue;
            }

            foreach ($this->document->appendEntriesEdit($node, implode('', $append['lines']), $lastSurviving) as $edit) {
                $this->edits[] = $edit;
            }
        }

        $this->appends = [];
    }

    /**
     * Returns whether a list writes each entry on one line -- a keyframe
     * list in the game's own timelines does -- so a new entry reads like its
     * neighbours. A list whose entries span several lines each (a states
     * file) gets new entries written the same way.
     */
    private function prefersInline(SourceNode $list): bool
    {
        if ($list->entries === []) {
            return false;
        }

        foreach ($list->entries as $entry) {
            if ($entry->value->kind !== SourceNode::ARRAY
                || str_contains(substr($this->document->source, $entry->value->start, $entry->value->end - $entry->value->start), "\n")) {
                return false;
            }
        }

        return true;
    }

    /**
     * Plans the patches a value that moves needs, against its current place,
     * and returns those inside its bytes, last first, for the move to apply
     * to the bytes it carries. Everything the diff defers to the end --
     * removals from arrays whose entries share their lines, insertions,
     * appends -- is planned now, inside the moving bytes, rather than
     * against bytes the move cuts. A variable defined for the value lives in
     * the prelude and stays a global edit.
     *
     * @param array<int, int|string> $path The value's path.
     * @return list<array{0: int, 1: int, 2: string}>
     */
    private function planPatchesWithin(SourceNode $value, mixed $oldValue, mixed $newValue, array $path, int $start, int $end): array
    {
        $deferred = [$this->appends, $this->inserts, $this->sharedLineRemovals];
        [$this->appends, $this->inserts, $this->sharedLineRemovals] = [[], [], []];
        $before = count($this->edits);
        $this->diff($value, $oldValue, $newValue, $path);
        $this->flushAppends();
        [$this->appends, $this->inserts, $this->sharedLineRemovals] = $deferred;
        $inner = [];

        foreach (array_splice($this->edits, $before) as $edit) {
            if ($edit[0] >= $start && $edit[1] <= $end) {
                $inner[] = $edit;
            } else {
                $this->edits[] = $edit;
            }
        }

        usort($inner, static fn(array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        return $inner;
    }

    /**
     * Plans removing an entry and re-inserting its own bytes -- with any
     * patches its new value needs applied inside them -- ahead of another
     * position.
     *
     * @param array<int, int|string> $path The list's path.
     */
    private function emitMove(SourceNode $list, int $from, mixed $oldValue, mixed $newValue, array $path, int $position): void
    {
        $entry = $list->entries[$from];
        [$start, $end] = $this->document->blockSpan($entry);

        // Patches for the entry, planned against its current place, applied
        // to its own bytes from the end forward.
        $block = substr($this->document->source, $start, $end - $start);

        foreach ($this->planPatchesWithin($entry->value, $oldValue, $newValue, [...$path, $from], $start, $end) as [$editStart, $editEnd, $text]) {
            $block = substr($block, 0, $editStart - $start) . $text . substr($block, $editEnd - $start);
        }

        if (! str_ends_with($block, "\n")) {
            $block .= "\n";
        }

        if ($entry->separatorEnd === $entry->end) {
            // It had no comma because it was last: it needs one now.
            $offset = $entry->end - $start;
            $block = substr($block, 0, $offset) . ',' . substr($block, $offset);
        }

        $this->edits[] = [$start, $end, ''];
        $this->removedEntries[spl_object_id($list)][$from] = true;

        if ($position >= count($list->entries)) {
            $id = spl_object_id($list);
            $this->appends[$id] ??= ['node' => $list, 'lines' => [], 'inline' => []];
            $this->appends[$id]['lines'][] = $block;

            return;
        }

        $this->edits[] = $this->placementEdit($list, $position, $block);
    }

    /**
     * Plans placing a ready block of lines ahead of an entry position, or
     * after the last entry.
     *
     * @return array{0: int, 1: int, 2: string}
     */
    private function placementEdit(SourceNode $list, int $position, string $block): array
    {
        if ($position < count($list->entries)) {
            [$anchorStart] = $this->document->blockSpan($list->entries[$position]);

            return [$anchorStart, $anchorStart, $block];
        }

        $last = $list->entries[count($list->entries) - 1];
        $afterLast = $this->document->lineEndAfter(max($last->end, $last->separatorEnd) - 1);
        $needsComma = $last->separatorEnd === $last->end;
        $rest = substr($this->document->source, $last->end, $afterLast - $last->end);

        return [$last->end, $afterLast, ($needsComma ? ',' : '') . $rest . $block];
    }

    /**
     * Returns the positions of a longest strictly increasing subsequence.
     *
     * @param int[] $sequence
     * @return array<int, true> The positions kept, as keys.
     */
    private static function longestIncreasingSubsequence(array $sequence): array
    {
        $count = count($sequence);

        if ($count === 0) {
            return [];
        }

        $tails = [];
        $tailPositions = [];
        $previous = array_fill(0, $count, -1);

        foreach ($sequence as $position => $value) {
            $low = 0;
            $high = count($tails);

            while ($low < $high) {
                $middle = intdiv($low + $high, 2);

                if ($tails[$middle] < $value) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }

            $tails[$low] = $value;
            $tailPositions[$low] = $position;
            $previous[$position] = $low > 0 ? $tailPositions[$low - 1] : -1;
        }

        $kept = [];
        $cursor = $tailPositions[count($tails) - 1];

        while ($cursor !== -1) {
            $kept[$cursor] = true;
            $cursor = $previous[$cursor];
        }

        return $kept;
    }

    /**
     * Returns an entry's name when it can serve as its identity: declared,
     * scalar, non-empty, with no stable id beside it, and carried by no
     * other unmatched entry of the same list.
     *
     * @param array<int, mixed> $list The list the entry belongs to.
     * @param array<int, int> $matched The list's already-matched indexes.
     */
    private static function uniqueNameOf(mixed $entry, array $list, array $matched): ?string
    {
        if (! is_array($entry) || self::identityOf($entry) !== null || ! is_scalar($entry['name'] ?? null)) {
            return null;
        }

        $name = strval($entry['name']);

        if ($name === '') {
            return null;
        }

        $holders = 0;

        foreach ($list as $index => $sibling) {
            if (isset($matched[$index]) || ! is_array($sibling)) {
                continue;
            }

            if (self::identityOf($sibling) === null && strval($sibling['name'] ?? '') === $name) {
                $holders++;
            }
        }

        return $holders === 1 ? $name : null;
    }

    /**
     * Returns the stable id an entry declares, when it declares one.
     */
    private static function identityOf(mixed $entry): ?string
    {
        if (! is_array($entry) || ! isset($entry['id']) || ! is_scalar($entry['id'])) {
            return null;
        }

        $id = strval($entry['id']);

        return $id === '' ? null : $id;
    }

    /**
     * Verified enum literals retain spacing and comments. Nullable and cross-enum
     * edits are explicit data replacements; record schemas own admissible types.
     * Aliases to cases and computed values are not case literals.
     *
     * @return list<array{0: int, 1: int, 2: string}>|null
     */
    private function findEnumCaseEdits(SourceNode $node, mixed $old, mixed $new): ?array
    {
        if (! $old instanceof \UnitEnum || ($new !== null && ! $new instanceof \UnitEnum)) {
            return null;
        }
        $prefix = '<?php ';
        $tokens = array_values(array_filter(\PhpToken::tokenize($prefix
            . substr($this->document->source, $node->start, $node->end - $node->start)),
            static fn(\PhpToken $token): bool => ! $token->is([T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));
        if (count($tokens) !== 3
            || ! $tokens[0]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            || ! $tokens[1]->is(T_DOUBLE_COLON) || ! $tokens[2]->is(T_STRING)
            || $tokens[2]->text !== $old->name) {
            return null;
        }
        $class = $this->resolveClassName($tokens[0]->text);
        if ($class === null || strcasecmp($class, $old::class) !== 0) {
            return null;
        }
        $edit = static function (\PhpToken $token, string $replacement) use ($node, $prefix): array {
            $start = $node->start + $token->pos - strlen($prefix);

            return [$start, $start + strlen($token->text), $replacement];
        };
        if ($new === null) {
            return [$edit($tokens[0], 'null'), $edit($tokens[1], ''), $edit($tokens[2], '')];
        }
        $edits = [$edit($tokens[2], $new->name)];
        if ($old::class !== $new::class) {
            $edits[] = $edit($tokens[0], '\\' . $new::class);
        }

        return $edits;
    }

    /**
     * The expression for a new value where the file writes an enum case's
     * value as `Enum::CASE->value`: the same enum, the case holding the new
     * value. Null when the expression is anything else, or no case holds it.
     */
    private function findEnumValueExpression(SourceNode $node, mixed $new): ?string
    {
        $text = trim(substr($this->document->source, $node->start, $node->end - $node->start));

        if ((! is_string($new) && ! is_int($new))
            || preg_match('/^(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)::[A-Za-z_][A-Za-z0-9_]*->value$/', $text, $match) !== 1) {
            return null;
        }

        $class = $this->resolveClassName($match[1]);

        if ($class === null || ! is_subclass_of($class, \BackedEnum::class)) {
            return null;
        }

        $case = $class::tryFrom($new);

        return $case === null ? null : $match[1] . '::' . $case->name . '->value';
    }

    /** A class name as the file writes it, resolved through its own imports. */
    private function resolveClassName(string $name): ?string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $first = explode('\\', $name)[0];
        $class = $this->getClassImports()[strtolower($first)] ?? null;

        return $class === null ? null : $class . substr($name, strlen($first));
    }

    /** @return array<string, string> Simple class imports, never text inside strings or nested uses. */
    private function getClassImports(): array
    {
        if ($this->classImports !== null) {
            return $this->classImports;
        }
        $imports = [];
        $tokens = array_values(array_filter(\PhpToken::tokenize($this->document->source),
            static fn(\PhpToken $token): bool => ! $token->is([T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])));
        $depth = 0;

        foreach ($tokens as $index => $token) {
            if ($token->pos >= $this->document->root()->start) {
                break;
            }
            if ($token->is(['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->is([')', ']', '}'])) {
                $depth--;
            }
            if ($depth !== 0) {
                continue;
            }
            if ($token->is(T_NAMESPACE)) {
                $imports = [];
                continue;
            }
            if (! $token->is(T_USE)) {
                continue;
            }
            $class = $tokens[$index + 1] ?? null;
            if ($class === null || ! $class->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }
            $end = $index + 2;
            $name = ltrim($class->text, '\\');
            $alias = substr((string) strrchr('\\' . $name, '\\'), 1);
            if (($tokens[$end] ?? null)?->is(T_AS)) {
                $explicitAlias = $tokens[$end + 1] ?? null;
                if ($explicitAlias === null || ! $explicitAlias->is(T_STRING)) {
                    continue;
                }
                $alias = $explicitAlias->text;
                $end += 2;
            }
            if (($tokens[$end] ?? null)?->is(';')) {
                $imports[strtolower($alias)] = $name;
            }
        }

        return $this->classImports = $imports;
    }

    /**
     * Plans replacing a node with the literal for a value.
     *
     * @param array<int, int|string> $path
     * @return array{0: int, 1: int, 2: string}
     */
    private function replacementFor(SourceNode $node, array $path, mixed $value): array
    {
        // An entry sharing its line with its neighbours keeps a value that fits there on that line.
        $entry = $this->document->entryAt($path);
        $inline = $entry !== null && $this->document->lineIndentBefore($entry->start) === null && $this->fitsOnOneLine($value);

        return $this->document->replaceValueEdit($path, $this->literalFor($value, $path, $inline));
    }

    /**
     * Changes a value written as a variable reference.
     *
     * A variable the file builds with its own code is refused: replacing the
     * reference with a literal of its result would leave the code running
     * for nothing. A literal only this entry names is diffed where it is
     * assigned. Otherwise the variable is left for whatever else names it
     * and this entry is retargeted.
     *
     * @param array<int, int|string> $path
     */
    private function diffVariable(SourceNode $node, mixed $old, mixed $new, array $path): void
    {
        $name = $node->variable ?? '';

        if ($this->document->isBuiltInCode($node)) {
            throw new SourcePreservationRefusal(sprintf(
                'The value at %s is $%s, which the file\'s own code builds or uses, so the editor will not replace it with a copy of its value. Change it in the file, or write it as plain data.',
                PhpArraySourceDocument::describePath($path),
                $name,
            ));
        }

        $written = $this->document->resolveNode($node);

        if ($written !== $node) {
            $this->hosts[] = $name;

            try {
                $this->diff($written, $old, $new, $path);
            } finally {
                array_pop($this->hosts);
            }

            return;
        }

        $variable = $this->document->variable($name);

        if ($variable?->value !== null && ! $this->isPlainData($variable->value)) {
            throw new SourcePreservationRefusal(sprintf(
                'The value at %s is $%s, which %d values share and which holds comments, variables or expressions a copy of it would lose. Change it in the file.',
                PhpArraySourceDocument::describePath($path),
                $name,
                $this->document->referenceCount($name),
            ));
        }

        $this->retargetVariable($node, $path, $new);
    }

    /**
     * Retargets a value written as a variable reference to data.
     *
     * A string edited under a nowdoc named nowhere else is edited between
     * the nowdoc's markers. Anything else leaves the variable alone -- other
     * entries name it -- and this entry gets a variable of its own for
     * text, or the literal itself for a number, flag or array.
     *
     * @param array<int, int|string> $path
     */
    private function retargetVariable(SourceNode $node, array $path, mixed $new): void
    {
        $name = $node->variable ?? '';
        $variable = $this->document->variable($name);

        if (is_string($new)) {
            if ($variable !== null
                && $variable->kind === SourceVariable::NOWDOC
                && $this->document->referenceCount($name) === 1
                && PhpArraySourceDocument::labelIsSafe($variable->label ?? '', $new)
            ) {
                $this->edits[] = $this->document->replaceVariableContentEdit($name, $new);

                return;
            }

            $fresh = $this->claimVariable($name);
            $this->edits[] = $this->document->defineVariableEdit($fresh, $new, $this->findHost());
            $this->edits[] = $this->document->replaceValueEdit($path, '$' . $fresh);

            return;
        }

        $this->edits[] = $this->replacementFor($node, $path, $new);
    }

    /**
     * Returns valid PHP for a value, allocating nowdoc variables for any
     * text with a line break so that art stays art.
     *
     * @param array<int, int|string> $path
     * @param bool $inline Whether to write it on one line when it fits: because its neighbours are, or as a child.
     * @param bool $child Whether it is a child of a value being written, which keeps to one line only within the width.
     */
    private function literalFor(mixed $value, array $path, bool $inline = false, bool $child = false): string
    {
        if (is_string($value) && str_contains($value, "\n")) {
            $fresh = $this->claimVariable(self::stemFor($path));
            $this->edits[] = $this->document->defineVariableEdit($fresh, $value, $this->findHost());

            return '$' . $fresh;
        }

        if (! is_array($value) || $value === []) {
            return PhpValueExporter::export($value);
        }

        $isList = array_is_list($value);
        // Each child is written once: a child that fits on its line stays there, as authors write a condition or a point.
        $parts = [];

        foreach ($value as $key => $item) {
            $literal = $this->literalFor($item, [...$path, $key], true, true);
            $parts[] = $isList ? $literal : var_export($key, true) . ' => ' . $literal;
        }

        if ($inline && $this->fitsOnOneLine($value)) {
            $literal = '[' . implode(', ', $parts) . ']';

            // An entry matches its neighbours' lines; a child's small values in a long list still make a line nobody
            // reads at once, so past the width it is written one per line.
            if (! $child || strlen($literal) <= self::INLINE_WIDTH) {
                return $literal;
            }
        }

        $lines = ['['];

        foreach ($parts as $part) {
            $lines[] = '  ' . self::indentContinuation($part) . ',';
        }

        $lines[] = ']';

        return implode("\n", $lines);
    }

    /**
     * Returns whether a value is small enough to write on one line: scalars,
     * short strings, and lists or maps of those, nested one level.
     */
    private function fitsOnOneLine(mixed $value, int $depth = 0): bool
    {
        if (is_string($value)) {
            // Text with line breaks becomes a variable reference, which fits.
            return str_contains($value, "\n") || strlen($value) <= 60;
        }

        if (! is_array($value)) {
            return true;
        }

        if ($depth > 1 || count($value) > 12) {
            return false;
        }

        foreach ($value as $item) {
            if (! $this->fitsOnOneLine($item, $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Indents every line but the first of a nested literal by one level.
     */
    private static function indentContinuation(string $literal): string
    {
        if (! str_contains($literal, "\n")) {
            return $literal;
        }

        $lines = explode("\n", $literal);
        $first = array_shift($lines);

        return $first . "\n" . implode("\n", array_map(
            static fn(string $line): string => $line === '' ? $line : '  ' . $line,
            $lines,
        ));
    }

    /**
     * Returns the variable written as data the diff is inside, which any
     * variable it defines must precede.
     */
    private function findHost(): ?string
    {
        return $this->hosts === [] ? null : $this->hosts[count($this->hosts) - 1];
    }

    /**
     * Returns a variable name no other assignment or planned variable uses.
     */
    private function claimVariable(string $preferred): string
    {
        $name = $this->document->freeVariableName($preferred);
        $stem = $name;
        $suffix = 2;

        while (isset($this->claimedVariables[$name])) {
            $name = $stem . '_' . $suffix;
            $suffix++;
        }

        $this->claimedVariables[$name] = true;

        return $name;
    }

    /**
     * Returns a readable variable stem for text at a path: the last named
     * key with the list positions around it, so `tracks.2.keyframes.0.content`
     * becomes `keyframes_0_content` rather than a bare number.
     *
     * @param array<int, int|string> $path
     */
    private static function stemFor(array $path): string
    {
        $parts = [];

        foreach (array_slice($path, -3) as $step) {
            $parts[] = is_int($step) ? strval($step) : $step;
        }

        $stem = implode('_', $parts);

        return $stem === '' ? 'block' : $stem;
    }
}
