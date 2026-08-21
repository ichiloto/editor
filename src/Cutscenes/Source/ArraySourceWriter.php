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
 * keyed entry that appeared is added on lines of its own, one that vanished
 * is cut with its heading, and a list is aligned so that an entry moved
 * keeps its bytes and an entry edited keeps everything but the changed
 * field. A value written as a variable is retargeted rather than expanded:
 * a nowdoc referenced once is edited between its markers, and one shared by
 * several entries is left alone while the edited entry gets a new, safely
 * named variable of its own. Text with line breaks is always written as a
 * nowdoc, so ASCII art stays readable and exact -- trailing spaces,
 * backslashes, Unicode and all.
 *
 * What it cannot express -- a change under an expression, under a key it
 * could not read, a list turned into a map -- it refuses by path, so nothing
 * is written that a reader would not have written by hand.
 *
 * @package Ichiloto\Editor\Cutscenes\Source
 */
final class ArraySourceWriter
{
    /** @var array<int, array{0: int, 1: int, 2: string}> */
    private array $edits = [];

    /** @var array<string, true> Variable names claimed by this plan. */
    private array $claimedVariables = [];

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
    public static function rewrite(PhpArraySourceDocument $document, array $old, array $new): PhpArraySourceDocument
    {
        if ($old === $new) {
            return $document;
        }

        $writer = new self($document);
        $writer->diff($document->root(), $old, $new, []);

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

        if (is_array($new)) {
            $this->diffArray($node, is_array($old) ? $old : null, $new, $path);

            return;
        }

        // A scalar or null now sits here.
        match ($node->kind) {
            SourceNode::SCALAR => $this->edits[] = $this->replacementFor($node, $path, $new),
            SourceNode::VARIABLE => $this->retargetVariable($node, $path, $new),
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

            // A scalar or a variable holding what is now an array: the
            // reference is replaced by the literal, and any variable stays
            // for whatever else names it.
            $this->edits[] = $this->replacementFor($node, $path, $new);

            return;
        }

        if ($node->hasOpaqueKey) {
            throw new SourcePreservationRefusal(sprintf(
                'The array at %s has a key the editor cannot read, so it will not rewrite the array.',
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

        // Keyed: recurse into keys both have, add the new ones, cut the gone.
        foreach ($new as $key => $value) {
            $entry = $node->entryFor($key);

            if ($entry !== null && array_key_exists($key, $old)) {
                $this->diff($entry->value, $old[$key], $value, [...$path, $key]);

                continue;
            }

            $this->edits[] = $this->document->insertEntryEdit(
                $path,
                count($node->entries),
                $key,
                $this->literalFor($value, [...$path, $key]),
            );
        }

        foreach ($node->entries as $entry) {
            if ($entry->key !== null && ! array_key_exists($entry->key, $new)) {
                $this->edits[] = $this->document->removeEntryEdit([...$path, $entry->key]);
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

        // 3. Equal but elsewhere: one entry, moved.
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

        // 4. Between consecutive anchors, what is left pairs up by rank: an
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

        // 5. Which matched entries stay in place: the longest run of them
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

        // 6. Emit. Patches for kept entries; removals for entries that are
        //    gone; then, in new order, insertions and moves ahead of the
        //    next kept entry, or after everything.
        foreach ($kept as $j => $i) {
            $this->diff($node->entries[$i]->value, $old[$i], $new[$j], [...$path, $i]);
        }

        foreach ($old as $i => $entry) {
            if (! isset($matchedOld[$i])) {
                $this->edits[] = $this->document->removeEntryEdit([...$path, $i]);
            }
        }

        foreach ($new as $j => $item) {
            if (isset($kept[$j])) {
                continue;
            }

            $anchor = null;

            for ($next = $j + 1; $next < $newCount; $next++) {
                if (isset($kept[$next])) {
                    $anchor = $kept[$next];

                    break;
                }
            }

            $position = $anchor ?? count($node->entries);

            if (isset($moved[$j])) {
                $this->emitMove($node, $moved[$j], $old[$moved[$j]], $item, $path, $position);

                continue;
            }

            $this->edits[] = $this->document->insertEntryEdit(
                $path,
                $position,
                null,
                $this->literalFor($item, [...$path, $j], self::prefersInline($node)),
            );
        }
    }

    /**
     * Returns whether a list writes each entry on one line -- a keyframe
     * list in the game's own timelines does -- so a new entry reads like its
     * neighbours.
     */
    private static function prefersInline(SourceNode $list): bool
    {
        if ($list->entries === []) {
            return false;
        }

        foreach ($list->entries as $entry) {
            if ($entry->value->kind !== SourceNode::ARRAY) {
                return false;
            }
        }

        return true;
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

        // Patches for the entry, planned against its current place ...
        $before = count($this->edits);
        $this->diff($entry->value, $oldValue, $newValue, [...$path, $from]);
        $inner = [];
        $outer = [];

        foreach (array_splice($this->edits, $before) as $edit) {
            if ($edit[0] >= $start && $edit[1] <= $end) {
                $inner[] = $edit;
            } else {
                // A variable defined for it lives in the prelude and stays global.
                $outer[] = $edit;
            }
        }

        $this->edits = [...$this->edits, ...$outer];

        // ... applied to its own bytes, from the end forward.
        $block = substr($this->document->source, $start, $end - $start);
        usort($inner, static fn(array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);

        foreach ($inner as [$editStart, $editEnd, $text]) {
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
     * Plans replacing a node with the literal for a value.
     *
     * @param array<int, int|string> $path
     * @return array{0: int, 1: int, 2: string}
     */
    private function replacementFor(SourceNode $node, array $path, mixed $value): array
    {
        return $this->document->replaceValueEdit($path, $this->literalFor($value, $path));
    }

    /**
     * Retargets a value written as a variable reference.
     *
     * A string edited under a nowdoc named nowhere else is edited between
     * the nowdoc's markers. Anything else leaves the variable alone -- other
     * entries name it -- and this entry gets a variable of its own for
     * text, or the literal itself for a number or flag.
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
            $this->edits[] = $this->document->defineVariableEdit($fresh, $new);
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
     */
    private function literalFor(mixed $value, array $path, bool $inline = false): string
    {
        if (is_string($value) && str_contains($value, "\n")) {
            $fresh = $this->claimVariable(self::stemFor($path));
            $this->edits[] = $this->document->defineVariableEdit($fresh, $value);

            return '$' . $fresh;
        }

        if (! is_array($value) || $value === []) {
            return PhpValueExporter::export($value);
        }

        $isList = array_is_list($value);

        if ($inline && $this->fitsOnOneLine($value)) {
            $parts = [];

            foreach ($value as $key => $item) {
                $literal = $this->literalFor($item, [...$path, $key], true);
                $parts[] = $isList ? $literal : var_export($key, true) . ' => ' . $literal;
            }

            return '[' . implode(', ', $parts) . ']';
        }

        $lines = ['['];

        foreach ($value as $key => $item) {
            $literal = self::indentContinuation($this->literalFor($item, [...$path, $key]));
            $lines[] = $isList
                ? "  {$literal},"
                : '  ' . var_export($key, true) . " => {$literal},";
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
