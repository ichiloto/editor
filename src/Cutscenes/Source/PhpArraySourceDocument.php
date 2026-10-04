<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Source;

use PhpToken;
use RuntimeException;

/**
 * An authored PHP data file edited in place, one value at a time.
 *
 * A cutscene's files are the author's own PHP: a header, comments before and
 * inside the returned data, local variables holding ASCII blocks as nowdocs
 * and heredocs, entries that name those variables, and one returned array
 * literal. Regenerating such a file from the values it evaluates to would
 * lose all of that. So this document does not regenerate. It knows the
 * exact bytes of every entry, every value and every variable assignment,
 * and a change replaces only those bytes: a number where the number was, a
 * new entry on lines of its own in the array's indentation, a nowdoc's
 * content between its markers, a new variable before the `return`.
 *
 * It is deliberately narrow about what it reads. A file that is not a
 * header, top-level assignments and one returned array literal is refused
 * as unreadable, and a value it cannot classify -- a call, an arithmetic
 * expression -- is kept as an opaque span it will show but never rewrite.
 * Edits are applied together, from the end of the file forward, so every
 * span means what it meant when the edits were planned; the result is
 * parsed again, and a caller compares what it evaluates to against what
 * was intended before a byte reaches disk.
 *
 * @package Ichiloto\Editor\Cutscenes\Source
 */
final class PhpArraySourceDocument
{
    /**
     * @param string $source The file's bytes.
     * @param SourceNode $root The returned array literal.
     * @param array<string, SourceVariable> $variables Top-level assignments by name.
     * @param int $returnStart The offset of the `return` keyword.
     * @param int $statementEnd The offset just past the returned statement's `;`.
     */
    private function __construct(
        public readonly string $source,
        private readonly SourceNode $root,
        private readonly array $variables,
        private readonly int $returnStart,
        private readonly int $statementEnd,
    ) {
    }

    /**
     * Reads a file that returns one array literal.
     *
     * @param string $source The file's bytes.
     * @return self The document.
     * @throws SourceUnreadable When the file is not that shape.
     */
    public static function parse(string $source): self
    {
        $tokens = PhpToken::tokenize($source);
        $count = count($tokens);
        $variables = [];
        $assigned = [];
        $depth = 0;
        $index = 0;
        $returnAt = null;

        while ($index < $count) {
            $token = $tokens[$index];

            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML])) {
                $index++;
                continue;
            }

            if ($token->is(T_RETURN) && $depth === 0) {
                $returnAt = $index;
                break;
            }

            if ($token->is(T_VARIABLE) && $depth === 0) {
                $assignment = self::readAssignment($tokens, $index, $source);

                if ($assignment !== null) {
                    [$variable, $index] = $assignment;
                    $name = $variable->name;

                    if (isset($assigned[$name])) {
                        // Assigned twice: no single place to change it.
                        $variables[$name] = new SourceVariable($name, SourceVariable::OTHER, $variables[$name]->start, $variable->end);
                    } else {
                        $variables[$name] = $variable;
                        $assigned[$name] = true;
                    }

                    continue;
                }
            }

            $text = $token->text;

            if ($text === '(' || $text === '[' || $text === '{' || $token->is(T_CURLY_OPEN) || $token->is(T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
            } elseif ($text === ')' || $text === ']' || $text === '}') {
                $depth--;
            }

            $index++;
        }

        if ($returnAt === null) {
            throw new SourceUnreadable('The file has no top-level return.');
        }

        $valueAt = self::nextSignificant($tokens, $returnAt + 1);

        if ($valueAt === null) {
            throw new SourceUnreadable('The file returns nothing.');
        }

        $rootParse = self::readValue($tokens, $valueAt, $source, [',', ';']);

        if ($rootParse === null || $rootParse['node']->kind !== SourceNode::ARRAY) {
            throw new SourceUnreadable('The file does not return an array literal.');
        }

        $after = self::nextSignificant($tokens, $rootParse['next']);

        if ($after === null || $tokens[$after]->text !== ';') {
            throw new SourceUnreadable('The returned array is not followed by a semicolon.');
        }

        for ($rest = $after + 1; $rest < $count; $rest++) {
            if (! $tokens[$rest]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_CLOSE_TAG, T_INLINE_HTML])) {
                throw new SourceUnreadable('The file continues after the returned array.');
            }
        }

        return new self(
            $source,
            $rootParse['node'],
            $variables,
            $tokens[$returnAt]->pos,
            $tokens[$after]->pos + 1,
        );
    }

    /**
     * Returns the returned array.
     */
    public function root(): SourceNode
    {
        return $this->root;
    }

    /**
     * Returns every top-level variable assignment, by name.
     *
     * @return array<string, SourceVariable>
     */
    public function variables(): array
    {
        return $this->variables;
    }

    /**
     * Returns one variable assignment.
     */
    public function variable(string $name): ?SourceVariable
    {
        return $this->variables[$name] ?? null;
    }

    /**
     * Returns the entry a path addresses: keys for keyed arrays, positions
     * for lists, from the returned array down.
     *
     * @param array<int, int|string> $path The path.
     * @return SourceEntry|null The entry, or null when the path does not resolve.
     */
    public function entryAt(array $path): ?SourceEntry
    {
        if ($path === []) {
            return null;
        }

        $node = $this->root;
        $entry = null;

        foreach ($path as $step) {
            if ($node->kind !== SourceNode::ARRAY) {
                return null;
            }

            $entry = $node->entryFor($step);

            if ($entry === null) {
                return null;
            }

            $node = $entry->value;
        }

        return $entry;
    }

    /**
     * Returns the node a path addresses; the root for an empty path.
     *
     * @param array<int, int|string> $path The path.
     * @return SourceNode|null The node.
     */
    public function nodeAt(array $path): ?SourceNode
    {
        if ($path === []) {
            return $this->root;
        }

        return $this->entryAt($path)?->value;
    }

    /**
     * Returns how many values in the returned array name a variable.
     */
    public function referenceCount(string $name): int
    {
        return $this->countReferences($this->root, $name);
    }

    private function countReferences(SourceNode $node, string $name): int
    {
        if ($node->kind === SourceNode::VARIABLE) {
            return $node->variable === $name ? 1 : 0;
        }

        $count = 0;

        foreach ($node->entries as $entry) {
            $count += $this->countReferences($entry->value, $name);
        }

        return $count;
    }

    /**
     * Returns the content a nowdoc, heredoc or plain string variable holds,
     * as PHP reads it, or null for anything else.
     */
    public function variableContent(string $name): ?string
    {
        $variable = $this->variables[$name] ?? null;

        if ($variable === null || $variable->contentStart === null || $variable->contentEnd === null) {
            return null;
        }

        $raw = substr($this->source, $variable->contentStart, $variable->contentEnd - $variable->contentStart);

        if ($variable->kind === SourceVariable::STRING) {
            return self::decodeQuoted($raw);
        }

        // Flexible heredoc: the closing indentation is removed from every line.
        $lines = explode("\n", $raw);

        if ($variable->closingIndent !== '') {
            $lines = array_map(
                static fn(string $line): string => str_starts_with($line, $variable->closingIndent)
                    ? substr($line, strlen($variable->closingIndent))
                    : ltrim($line, " \t"),
                $lines,
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Returns the indentation the entries of an array are written at, or
     * what they would be written at if it has none yet: one indentation
     * step deeper than the line the array opens on.
     */
    public function entryIndent(SourceNode $array): string
    {
        foreach ($array->entries as $entry) {
            $indent = $this->lineIndentBefore($entry->start);

            if ($indent !== null) {
                return $indent;
            }
        }

        return $this->getLineIndent($array->start) . $this->getIndentStep($array);
    }

    /**
     * Returns the whitespace that starts the line holding an offset, whatever
     * else precedes the offset on that line: the indentation of `'key' => [`
     * for the `[` of that line.
     */
    private function getLineIndent(int $offset): string
    {
        $lineStart = $this->lineStartOf($offset);
        $lead = substr($this->source, $lineStart, $offset - $lineStart);

        return substr($lead, 0, strlen($lead) - strlen(ltrim($lead, " \t")));
    }

    /**
     * Returns the indentation step the file uses around an array: how much
     * deeper the nearest enclosing array writes its entries than the line it
     * opens on. Two spaces when no enclosing array shows one.
     */
    private function getIndentStep(SourceNode $array): string
    {
        foreach (array_reverse($this->findAncestors($this->root, $array) ?? []) as $ancestor) {
            $opening = $this->getLineIndent($ancestor->start);

            foreach ($ancestor->entries as $entry) {
                $indent = $this->lineIndentBefore($entry->start);

                if ($indent === null) {
                    continue;
                }

                if (strlen($indent) > strlen($opening) && str_starts_with($indent, $opening)) {
                    return substr($indent, strlen($opening));
                }

                break;
            }
        }

        return '  ';
    }

    /**
     * Returns the arrays enclosing a node, outermost first, or null when the
     * node is not below the one searched.
     *
     * @return array<int, SourceNode>|null
     */
    private function findAncestors(SourceNode $node, SourceNode $target): ?array
    {
        foreach ($node->entries as $entry) {
            if ($entry->value === $target) {
                return [$node];
            }

            $below = $this->findAncestors($entry->value, $target);

            if ($below !== null) {
                return [$node, ...$below];
            }
        }

        return null;
    }

    /**
     * Returns the whitespace between the start of a line and an offset when
     * nothing but whitespace precedes the offset on its line; null otherwise.
     */
    public function lineIndentBefore(int $offset): ?string
    {
        $lineStart = $this->lineStartOf($offset);
        $lead = substr($this->source, $lineStart, $offset - $lineStart);

        return trim($lead, " \t") === '' ? $lead : null;
    }

    /**
     * Returns the offset of the first byte of the line holding an offset.
     */
    public function lineStartOf(int $offset): int
    {
        $break = strrpos(substr($this->source, 0, $offset), "\n");

        return $break === false ? 0 : $break + 1;
    }

    /**
     * Returns the offset just past the newline ending the line holding an
     * offset, or the source length.
     */
    public function lineEndAfter(int $offset): int
    {
        $break = strpos($this->source, "\n", $offset);

        return $break === false ? strlen($this->source) : $break + 1;
    }

    /**
     * Returns the span of an entry's lines including the comment-only lines
     * immediately above it at the same indentation -- the heading an author
     * wrote for it -- so that removing or moving the entry takes its heading
     * along, and inserting ahead of the entry does not split them.
     *
     * @return array{0: int, 1: int} Start and end offsets (end exclusive).
     */
    public function blockSpan(SourceEntry $entry): array
    {
        $indent = $this->lineIndentBefore($entry->start);

        if ($indent === null) {
            // The entry shares its line with something before it: only its
            // own bytes and separator are its block.
            return [$entry->start, $entry->separatorEnd];
        }

        $start = $this->lineStartOf($entry->start);

        // Comment-only lines directly above, at the same indentation.
        while ($start > 0) {
            $previousStart = $this->lineStartOf($start - 1);
            $line = substr($this->source, $previousStart, $start - $previousStart);
            $trimmed = trim($line, " \t\n");

            if ($trimmed === '' || ! (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#') || (str_starts_with($trimmed, '/*') && str_ends_with($trimmed, '*/')))) {
                break;
            }

            if ($this->lineIndentBefore($previousStart + strlen($line) - strlen(ltrim($line, " \t"))) !== $indent) {
                break;
            }

            $start = $previousStart;
        }

        $end = $this->lineEndAfter(max($entry->end, $entry->separatorEnd) - 1);
        // An indented first entry may still share its line with siblings or
        // closing syntax. Its heading belongs to it; those later bytes do not.
        if (! $this->holdsOnlyTrivia($entry->separatorEnd, $end)) {
            return [$start, $entry->separatorEnd];
        }
        return [$start, $end];
    }

    /**
     * Returns whether the bytes between two offsets are only whitespace and
     * comments.
     */
    private function holdsOnlyTrivia(int $start, int $end): bool
    {
        foreach (PhpToken::tokenize('<?php ' . substr($this->source, $start, $end - $start)) as $token) {
            if (! $token->is([T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return false;
            }
        }

        return true;
    }

    // -- Planning edits ------------------------------------------------------

    /**
     * Plans replacing one value's bytes with a literal.
     *
     * @param array<int, int|string> $path The value's path.
     * @param string $literal Valid PHP for the new value.
     * @return array{0: int, 1: int, 2: string} The edit.
     */
    public function replaceValueEdit(array $path, string $literal): array
    {
        $entry = $this->entryAt($path);

        if ($entry === null) {
            throw new RuntimeException(sprintf('No entry at %s to replace.', self::describePath($path)));
        }

        return [$entry->value->start, $entry->value->end, self::reindent($literal, $this->lineIndentBefore($entry->start) ?? '')];
    }

    /** Renames only a literal key; its value, comments and position survive. */
    public function renameKeyEdit(array $path, string $newKey): array
    {
        $entry = $this->entryAt($path);
        $parent = $this->nodeAt(array_slice($path, 0, -1));
        if ($entry === null || $entry->keyIsOpaque || $parent === null || $parent->hasOpaqueKey
            || $parent->entryFor($newKey) !== null) {
            throw new SourcePreservationRefusal('The requested key rename cannot be preserved at ' . self::describePath($path));
        }
        $end = $entry->start;
        foreach (PhpToken::tokenize($this->source) as $token) {
            if ($token->pos < $entry->start) {
                continue;
            }
            if ($token->is(T_DOUBLE_ARROW)) {
                return [$entry->start, $end, var_export($newKey, true)];
            }
            if (! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                $end = $token->pos + strlen($token->text);
            }
            if ($end > $entry->value->start) {
                break;
            }
        }
        throw new SourcePreservationRefusal('No literal key at ' . self::describePath($path));
    }

    /**
     * Plans inserting entries, in order, ahead of an entry the array holds:
     * on lines of their own above its block, or ahead of it on its line when
     * it shares one.
     *
     * @param list<array{key: int|string|null, literal: string}> $entries
     * @return array{0: int, 1: int, 2: string} The edit.
     */
    public function insertEntriesBeforeEdit(SourceNode $array, SourceEntry $anchor, array $entries): array
    {
        if ($this->lineIndentBefore($anchor->start) === null) {
            $inline = array_map(fn(array $entry): string => $this->renderInlineEntry($entry['key'], $entry['literal'], $anchor->start), $entries);

            return [$anchor->start, $anchor->start, implode(', ', $inline) . ', '];
        }

        [$blockStart] = $this->blockSpan($anchor);
        $lines = array_map(fn(array $entry): string => $this->renderEntryLine($array, $entry['key'], $entry['literal']), $entries);

        return [$blockStart, $blockStart, implode('', $lines)];
    }

    /**
     * Returns whether every entry of an array shares its line with what
     * precedes it, as `['type' => 'text', 'name' => '']` does.
     */
    public function sharesLines(SourceNode $array): bool
    {
        if ($array->entries === []) {
            return false;
        }

        foreach ($array->entries as $entry) {
            if ($this->lineIndentBefore($entry->start) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Plans removing entries of an array whose entries share their lines,
     * each run of removed entries with the separators that joined it, so the
     * survivors read as if written without them: a run cut ahead of the
     * entry after it, a run at the end cut after the entry before it, and an
     * array left empty written `[]`.
     *
     * @param list<int> $removed The indexes of the entries removed, ascending.
     * @return array{edits: list<array{0: int, 1: int, 2: string}>, separatorCut: bool} The edits, and
     *   whether the last surviving entry's comma went with a run after it.
     */
    public function removeSharedLineEntriesEdits(SourceNode $array, array $removed): array
    {
        $entries = $array->entries;
        $count = count($entries);
        $isRemoved = array_fill_keys($removed, true);

        if (count($isRemoved) === $count && $array->bodyStart !== null && $array->bodyEnd !== null) {
            return ['edits' => [[$array->bodyStart, $array->bodyEnd, '']], 'separatorCut' => false];
        }

        $edits = [];
        $separatorCut = false;

        for ($i = 0; $i < $count; $i++) {
            if (! isset($isRemoved[$i])) {
                continue;
            }

            $j = $i;

            while (isset($isRemoved[$j + 1])) {
                $j++;
            }

            if ($j + 1 < $count) {
                $edits[] = [$entries[$i]->start, $entries[$j + 1]->start, ''];
            } else {
                $edits[] = [$entries[$i - 1]->end, $entries[$j]->end, ''];
                $separatorCut = true;
            }

            $i = $j;
        }

        return ['edits' => $edits, 'separatorCut' => $separatorCut];
    }

    /**
     * Plans inserting an entry into an array: ahead of the entry now at a
     * position, or after the last one.
     *
     * @param array<int, int|string> $parentPath The array's path.
     * @param int $position The position the new entry takes among the entries.
     * @param int|string|null $key The explicit key, or null for a list entry.
     * @param string $literal Valid PHP for the value.
     * @return array{0: int, 1: int, 2: string} The edit.
     */
    public function insertEntryEdit(array $parentPath, int $position, int|string|null $key, string $literal): array
    {
        $array = $this->nodeAt($parentPath);

        if ($array === null || $array->kind !== SourceNode::ARRAY || $array->bodyStart === null || $array->bodyEnd === null) {
            throw new RuntimeException(sprintf('No array at %s to insert into.', self::describePath($parentPath)));
        }

        $line = $this->renderEntryLine($array, $key, $literal);
        $entries = $array->entries;

        if ($entries === []) {
            $expansion = $this->planEmptyArrayExpansion($array, $line);

            if ($expansion !== null) {
                return $expansion;
            }

            // Only comments inside: the entry follows them, on a line of its
            // own when the closing bracket has one.
            if ($this->lineIndentBefore($array->bodyEnd) !== null) {
                $at = $this->lineStartOf($array->bodyEnd);

                return [$at, $at, $line];
            }

            $gap = ctype_space($this->source[$array->bodyEnd - 1]) ? '' : ' ';

            return [$array->bodyEnd, $array->bodyEnd, $gap . $this->renderInlineEntry($key, $literal, $array->bodyEnd)];
        }

        if ($position < count($entries)) {
            $neighbour = $entries[$position];

            if ($this->lineIndentBefore($neighbour->start) === null) {
                // The neighbour shares its line: go ahead of it on that line.
                return [$neighbour->start, $neighbour->start, $this->renderInlineEntry($key, $literal, $neighbour->start) . ', '];
            }

            [$blockStart] = $this->blockSpan($neighbour);

            return [$blockStart, $blockStart, $line];
        }

        // After the last entry, which must end with a comma first.
        $last = $entries[count($entries) - 1];
        $needsComma = $last->separatorEnd === $last->end;
        $afterLast = $this->lineEndAfter(max($last->end, $last->separatorEnd) - 1);

        if ($this->lineIndentBefore($last->start) === null
            || $afterLast <= $last->separatorEnd
            || ! $this->holdsOnlyTrivia($last->separatorEnd, $afterLast)
        ) {
            // The last entry shares its line with a sibling or the closing
            // bracket: append inline after it.
            return $this->planInlineAppend($array, [$this->renderInlineEntry($key, $literal, $last->start)], $last);
        }

        // The rest of the last entry's line -- its comma, a comment, the
        // newline -- stays; the new entry follows on lines of its own.
        $rest = substr($this->source, $last->end, $afterLast - $last->end);

        return [$last->end, $afterLast, ($needsComma ? ',' : '') . $rest . $line];
    }

    /**
     * Renders one entry as the line (or lines) it would occupy in an array,
     * at that array's own entry indentation.
     */
    public function renderEntryLine(SourceNode $array, int|string|null $key, string $literal): string
    {
        $indent = $this->entryIndent($array);

        return $indent . self::renderEntry($key, self::reindent($literal, $indent)) . ",\n";
    }

    /**
     * Renders one entry for a line it shares with others: no indentation of
     * its own and no separator, continuation lines under the line it joins.
     */
    private function renderInlineEntry(int|string|null $key, string $literal, int $at): string
    {
        return self::renderEntry($key, self::reindent($literal, $this->getLineIndent($at)));
    }

    /**
     * Renders an entry's key and value, `key => value` or the value alone.
     */
    private static function renderEntry(int|string|null $key, string $value): string
    {
        return ($key === null ? '' : var_export($key, true) . ' => ') . $value;
    }

    /**
     * Plans appending entries to an array whose entries share their lines,
     * joined the way such an array joins its own: after the entry they
     * follow, one space after each comma, and a trailing comma only when the
     * array's last entry is written with one.
     *
     * @param SourceNode $array The array node.
     * @param array<int, string> $entries The entries, each `key => value` or a value.
     * @param SourceEntry|null $lastSurviving The entry that will precede the
     *   appended ones, or null when none survives and they start the body.
     * @param bool $separatorCut Whether the entries after it are cut with its
     *   comma ({@see removeSharedLineEntriesEdits}): the appended ones then
     *   join right after its value, ahead of any trailing comma left behind.
     * @return array{0: int, 1: int, 2: string} The edit.
     */
    public function planInlineAppend(SourceNode $array, array $entries, ?SourceEntry $lastSurviving, bool $separatorCut = false): array
    {
        if ($array->bodyStart === null) {
            throw new RuntimeException('Only an array node takes appended entries.');
        }

        if ($lastSurviving !== null && $separatorCut) {
            return [$lastSurviving->end, $lastSurviving->end, ', ' . implode(', ', $entries)];
        }

        $last = $array->entries === [] ? null : $array->entries[count($array->entries) - 1];
        $trailingComma = $last !== null && $last->separatorEnd !== $last->end;
        $text = implode(', ', $entries) . ($trailingComma ? ',' : '');

        if ($lastSurviving === null) {
            return [$array->bodyStart, $array->bodyStart, $text];
        }

        if ($lastSurviving->separatorEnd === $lastSurviving->end) {
            // It had no comma because it was last; it needs one now.
            return [$lastSurviving->end, $lastSurviving->end, ', ' . $text];
        }

        return [$lastSurviving->separatorEnd, $lastSurviving->separatorEnd, ' ' . $text];
    }

    /**
     * Plans writing entry lines into an array whose body is only whitespace:
     * `[]` opens onto lines of their own and closes at the indentation of
     * the line the array starts on. Null when the body holds anything else.
     *
     * @return array{0: int, 1: int, 2: string}|null The edit.
     */
    private function planEmptyArrayExpansion(SourceNode $array, string $lines): ?array
    {
        if ($array->entries !== [] || $array->bodyStart === null || $array->bodyEnd === null) {
            return null;
        }

        if (trim(substr($this->source, $array->bodyStart, $array->bodyEnd - $array->bodyStart)) !== '') {
            return null;
        }

        return [$array->bodyStart, $array->bodyEnd, "\n" . $lines . $this->getLineIndent($array->start)];
    }

    /**
     * Plans appending ready lines at the end of an array, composably.
     *
     * Unlike a tail replacement, these edits touch nothing an unrelated
     * edit may also touch: the lines are a pure insertion at the start of
     * the closing bracket's line -- after every entry, every removal span
     * and any trailing comment -- and the comma the previous last entry may
     * need is its own one-character insertion. Several appends into one
     * array are therefore one combined edit, and appends compose with
     * removals of any entry, the last included.
     *
     * @param SourceNode $array The array node.
     * @param string $lines The rendered entry lines, each ending in a newline.
     * @param SourceEntry|null $lastSurviving The entry that will precede the
     *   appended lines, when one survives whatever else this rewrite does.
     * @return array<int, array{0: int, 1: int, 2: string}> One or two edits.
     */
    public function appendEntriesEdit(SourceNode $array, string $lines, ?SourceEntry $lastSurviving): array
    {
        if ($array->bodyStart === null || $array->bodyEnd === null) {
            throw new RuntimeException('Only an array node takes appended entries.');
        }

        $expansion = $this->planEmptyArrayExpansion($array, $lines);

        if ($expansion !== null) {
            return [$expansion];
        }

        $edits = [];

        if ($lastSurviving !== null && $lastSurviving->separatorEnd === $lastSurviving->end) {
            // It had no comma because it was last; it needs one now.
            $edits[] = [$lastSurviving->end, $lastSurviving->end, ','];
        }

        $edits[] = [$this->lineStartOf($array->bodyEnd), $this->lineStartOf($array->bodyEnd), $lines];

        return $edits;
    }

    /**
     * Plans removing an entry with its lines and heading.
     *
     * @param array<int, int|string> $path The entry's path.
     * @return array{0: int, 1: int, 2: string} The edit.
     */
    public function removeEntryEdit(array $path): array
    {
        $entry = $this->entryAt($path);

        if ($entry === null) {
            throw new RuntimeException(sprintf('No entry at %s to remove.', self::describePath($path)));
        }

        [$start, $end] = $this->blockSpan($entry);

        return [$start, $end, ''];
    }

    /**
     * Plans a nowdoc assignment of a variable placed before the `return`,
     * after the last existing assignment when there is one.
     *
     * @param string $name The variable name, without `$`.
     * @param string $content The exact content.
     * @return array{0: int, 1: int, 2: string} The edit.
     */
    public function defineVariableEdit(string $name, string $content): array
    {
        $at = $this->lineStartOf($this->returnStart);
        $lastVariable = null;

        foreach ($this->variables as $variable) {
            if ($lastVariable === null || $variable->end > $lastVariable->end) {
                $lastVariable = $variable;
            }
        }

        if ($lastVariable !== null) {
            $at = $this->lineEndAfter($lastVariable->end);
        }

        return [$at, $at, self::nowdocAssignment($name, $content) . "\n"];
    }

    /**
     * Plans replacing the content of a nowdoc or heredoc variable.
     *
     * @param string $name The variable name.
     * @param string $content The exact new content.
     * @return array{0: int, 1: int, 2: string} The edit.
     */
    public function replaceVariableContentEdit(string $name, string $content): array
    {
        $variable = $this->variables[$name] ?? null;

        if ($variable === null || $variable->contentStart === null || $variable->contentEnd === null || $variable->kind !== SourceVariable::NOWDOC) {
            throw new RuntimeException(sprintf('Variable $%s is not a nowdoc this editor rewrites in place.', $name));
        }

        if (! self::labelIsSafe($variable->label ?? '', $content)) {
            throw new RuntimeException(sprintf('The new content of $%s would end its nowdoc early.', $name));
        }

        $lines = explode("\n", $content);

        if ($variable->closingIndent !== '') {
            $lines = array_map(static fn(string $line): string => $line === '' ? $line : $variable->closingIndent . $line, $lines);
        }

        return [$variable->contentStart, $variable->contentEnd, implode("\n", $lines)];
    }

    /**
     * Applies planned edits together, from the end of the file forward, and
     * reads the result again.
     *
     * @param array<int, array{0: int, 1: int, 2: string}> $edits The edits.
     * @return self The rewritten document.
     */
    public function withEdits(array $edits): self
    {
        if ($edits === []) {
            return $this;
        }

        usort($edits, static fn(array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
        $source = $this->source;
        $previousStart = null;

        foreach ($edits as [$start, $end, $text]) {
            if ($previousStart !== null && $end > $previousStart) {
                throw new RuntimeException('Two edits overlap; the file was left unchanged.');
            }

            $source = substr($source, 0, $start) . $text . substr($source, $end);
            $previousStart = $start;
        }

        return self::parse($source);
    }

    /**
     * Returns a variable name not yet assigned in the file, from a preferred
     * stem.
     */
    public function freeVariableName(string $preferred): string
    {
        $stem = preg_replace('/[^A-Za-z0-9_]+/', '_', $preferred) ?? 'art';
        $stem = trim($stem, '_');

        if ($stem === '' || preg_match('/^[0-9]/', $stem) === 1) {
            $stem = 'art_' . $stem;
        }

        $candidate = $stem;
        $suffix = 2;

        while (isset($this->variables[$candidate])) {
            $candidate = $stem . '_' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Returns a nowdoc assignment statement for content.
     */
    public static function nowdocAssignment(string $name, string $content): string
    {
        $label = 'ART';
        $suffix = 2;

        while (! self::labelIsSafe($label, $content)) {
            $label = 'ART' . $suffix;
            $suffix++;
        }

        return sprintf("\$%s = <<<'%s'\n%s\n%s;", $name, $label, $content, $label);
    }

    /**
     * Returns whether no content line could close a nowdoc with a label.
     */
    public static function labelIsSafe(string $label, string $content): bool
    {
        if ($label === '') {
            return false;
        }

        foreach (explode("\n", $content) as $line) {
            $trimmed = ltrim($line, " \t");

            if (str_starts_with($trimmed, $label)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Describes a path for a diagnostic.
     *
     * @param array<int, int|string> $path The path.
     */
    public static function describePath(array $path): string
    {
        return $path === [] ? 'the returned array' : implode('.', array_map('strval', $path));
    }

    // -- Parsing -------------------------------------------------------------

    /**
     * @param PhpToken[] $tokens
     * @return array{0: SourceVariable, 1: int}|null The variable and the index after its `;`.
     */
    private static function readAssignment(array $tokens, int $index, string $source): ?array
    {
        $count = count($tokens);
        $name = ltrim($tokens[$index]->text, '$');
        $equals = self::nextSignificant($tokens, $index + 1);

        if ($equals === null || $tokens[$equals]->text !== '=') {
            return null;
        }

        $valueAt = self::nextSignificant($tokens, $equals + 1);

        if ($valueAt === null) {
            return null;
        }

        $token = $tokens[$valueAt];
        $start = $tokens[$index]->pos;

        if ($token->is(T_START_HEREDOC)) {
            $opener = $token->text;
            $isNowdoc = str_contains($opener, "'");
            $label = trim(trim(substr($opener, 3)), "'\"\n\r ");
            $contentStart = $token->pos + strlen($opener);
            $cursor = $valueAt + 1;
            $interpolates = false;

            while ($cursor < $count && ! $tokens[$cursor]->is(T_END_HEREDOC)) {
                if (! $tokens[$cursor]->is(T_ENCAPSED_AND_WHITESPACE)) {
                    $interpolates = true;
                }

                $cursor++;
            }

            if ($cursor >= $count) {
                return null;
            }

            $closing = $tokens[$cursor];
            $closingIndent = substr($closing->text, 0, strlen($closing->text) - strlen(ltrim($closing->text, " \t")));
            // The content ends before the newline that precedes the closing label.
            $contentEnd = $closing->pos;

            if ($contentEnd > $contentStart && $source[$contentEnd - 1] === "\n") {
                $contentEnd--;
            }

            if ($contentEnd < $contentStart) {
                $contentEnd = $contentStart;
            }

            $semicolon = self::nextSignificant($tokens, $cursor + 1);

            if ($semicolon === null || $tokens[$semicolon]->text !== ';') {
                return null;
            }

            $kind = $isNowdoc ? SourceVariable::NOWDOC : ($interpolates ? SourceVariable::OTHER : SourceVariable::HEREDOC);

            return [
                new SourceVariable($name, $kind, $start, $tokens[$semicolon]->pos + 1, $contentStart, $contentEnd, $closingIndent, $label),
                $semicolon + 1,
            ];
        }

        // Anything else up to the statement's `;` at depth zero.
        $depth = 0;
        $cursor = $valueAt;
        $kind = SourceVariable::OTHER;
        $contentStart = null;
        $contentEnd = null;
        $significant = 0;

        while ($cursor < $count) {
            $current = $tokens[$cursor];
            $text = $current->text;

            if ($depth === 0 && $text === ';') {
                break;
            }

            if ($text === '(' || $text === '[' || $text === '{' || $current->is(T_CURLY_OPEN) || $current->is(T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
            } elseif ($text === ')' || $text === ']' || $text === '}') {
                $depth--;
            }

            if (! $current->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                $significant++;
            }

            $cursor++;
        }

        if ($cursor >= $count) {
            return null;
        }

        if ($significant === 1 && $token->is(T_CONSTANT_ENCAPSED_STRING)) {
            $kind = SourceVariable::STRING;
            $contentStart = $token->pos;
            $contentEnd = $token->pos + strlen($token->text);
        }

        return [
            new SourceVariable($name, $kind, $start, $tokens[$cursor]->pos + 1, $contentStart, $contentEnd),
            $cursor + 1,
        ];
    }

    /**
     * Reads one value starting at a token: an array literal, a scalar, a
     * variable, or an opaque expression running to a top-level terminator.
     *
     * @param PhpToken[] $tokens
     * @param string[] $terminators The tokens that end an expression at depth zero.
     * @return array{node: SourceNode, next: int}|null The node and the index after it.
     */
    private static function readValue(array $tokens, int $index, string $source, array $terminators): ?array
    {
        $count = count($tokens);

        if ($index >= $count) {
            return null;
        }

        $token = $tokens[$index];

        if ($token->text === '[' || ($token->is(T_ARRAY) && ($open = self::nextSignificant($tokens, $index + 1)) !== null && $tokens[$open]->text === '(')) {
            return self::readArray($tokens, $index, $source);
        }

        // Scan the expression to its terminator, classifying as we go.
        $depth = 0;
        $cursor = $index;
        $significant = [];

        while ($cursor < $count) {
            $current = $tokens[$cursor];
            $text = $current->text;

            if ($depth === 0 && in_array($text, $terminators, true)) {
                break;
            }

            if ($depth === 0 && ($text === ']' || $text === ')')) {
                break;
            }

            if ($text === '(' || $text === '[' || $text === '{' || $current->is(T_CURLY_OPEN) || $current->is(T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
            } elseif ($text === ')' || $text === ']' || $text === '}') {
                $depth--;
            }

            if (! $current->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                $significant[] = $cursor;
            }

            $cursor++;
        }

        if ($significant === []) {
            return null;
        }

        $first = $tokens[$significant[0]];
        $last = $tokens[$significant[count($significant) - 1]];
        $start = $first->pos;
        $end = $last->pos + strlen($last->text);
        $kind = SourceNode::EXPRESSION;
        $variable = null;

        if (count($significant) === 1) {
            if ($first->is(T_VARIABLE)) {
                $kind = SourceNode::VARIABLE;
                $variable = ltrim($first->text, '$');
            } elseif ($first->is([T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER])
                || ($first->is(T_STRING) && in_array(strtolower($first->text), ['true', 'false', 'null'], true))
            ) {
                $kind = SourceNode::SCALAR;
            }
        } elseif (count($significant) === 2 && $first->text === '-' && $last->is([T_LNUMBER, T_DNUMBER])) {
            $kind = SourceNode::SCALAR;
        }

        return ['node' => new SourceNode($kind, $start, $end, variable: $variable), 'next' => $cursor];
    }

    /**
     * Reads an array literal starting at `[` or `array`.
     *
     * @param PhpToken[] $tokens
     * @return array{node: SourceNode, next: int}|null
     */
    private static function readArray(array $tokens, int $index, string $source): ?array
    {
        $count = count($tokens);
        $start = $tokens[$index]->pos;

        if ($tokens[$index]->is(T_ARRAY)) {
            $openAt = self::nextSignificant($tokens, $index + 1);
            $close = ')';
        } else {
            $openAt = $index;
            $close = ']';
        }

        if ($openAt === null) {
            return null;
        }

        $bodyStart = $tokens[$openAt]->pos + 1;
        $cursor = $openAt + 1;
        $entries = [];
        $isList = true;
        $hasOpaqueKey = false;
        $sawImplicit = false;
        $sawExplicit = false;

        while (true) {
            $cursor = self::nextSignificant($tokens, $cursor);

            if ($cursor === null) {
                return null;
            }

            if ($tokens[$cursor]->text === $close) {
                break;
            }

            // Look ahead for a top-level `=>` before the entry's terminator.
            $arrowAt = self::topLevelArrow($tokens, $cursor, $close);
            $key = null;
            $keyIsOpaque = false;
            $entryStart = $tokens[$cursor]->pos;
            $valueAt = $cursor;

            if ($arrowAt !== null) {
                $keyTokens = [];

                for ($k = $cursor; $k < $arrowAt; $k++) {
                    if (! $tokens[$k]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                        $keyTokens[] = $tokens[$k];
                    }
                }

                $key = self::literalKey($keyTokens);

                if ($key === null) {
                    $keyIsOpaque = true;
                    $hasOpaqueKey = true;
                }

                $isList = false;
                $sawExplicit = true;
                $valueAt = self::nextSignificant($tokens, $arrowAt + 1);

                if ($valueAt === null) {
                    return null;
                }
            } else {
                $sawImplicit = true;

                if ($tokens[$cursor]->is(T_ELLIPSIS)) {
                    // A spread adds entries only PHP can count, so no later
                    // position or key in this array is the file's to address.
                    $keyIsOpaque = true;
                    $hasOpaqueKey = true;
                }
            }

            $value = self::readValue($tokens, $valueAt, $source, [',']);

            if ($value === null) {
                return null;
            }

            $node = $value['node'];
            $after = self::nextSignificant($tokens, $value['next']);

            if ($after === null) {
                return null;
            }

            $separatorEnd = $node->end;

            if ($tokens[$after]->text === ',') {
                $separatorEnd = $tokens[$after]->pos + 1;
                $cursor = $after + 1;
            } elseif ($tokens[$after]->text === $close) {
                $cursor = $after;
            } else {
                return null;
            }

            $entries[] = new SourceEntry($key, $node, $entryStart, $node->end, $separatorEnd, $keyIsOpaque);
        }

        if ($sawExplicit && $sawImplicit) {
            // Positions of implicit entries among explicit keys are PHP's to
            // work out; not something to write against.
            $hasOpaqueKey = true;
        }

        $bodyEnd = $tokens[$cursor]->pos;

        return [
            'node' => new SourceNode(SourceNode::ARRAY, $start, $bodyEnd + 1, $entries, $isList, $hasOpaqueKey, null, $bodyStart, $bodyEnd),
            'next' => $cursor + 1,
        ];
    }

    /**
     * Returns the index of a `=>` at depth zero before the entry ends, or null.
     *
     * @param PhpToken[] $tokens
     */
    private static function topLevelArrow(array $tokens, int $index, string $close): ?int
    {
        $count = count($tokens);
        $depth = 0;

        for ($cursor = $index; $cursor < $count; $cursor++) {
            $token = $tokens[$cursor];
            $text = $token->text;

            if ($depth === 0 && ($text === ',' || $text === $close || $text === ';')) {
                return null;
            }

            if ($depth === 0 && $token->is(T_DOUBLE_ARROW)) {
                return $cursor;
            }

            if ($text === '(' || $text === '[' || $text === '{' || $token->is(T_CURLY_OPEN) || $token->is(T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
            } elseif ($text === ')' || $text === ']' || $text === '}') {
                if ($depth === 0) {
                    return null;
                }

                $depth--;
            }
        }

        return null;
    }

    /**
     * Reads a key written as one literal, or null when it is anything else.
     *
     * @param PhpToken[] $keyTokens
     */
    private static function literalKey(array $keyTokens): int|string|null
    {
        if (count($keyTokens) === 1) {
            $token = $keyTokens[0];

            if ($token->is(T_LNUMBER)) {
                return intval($token->text, 0);
            }

            if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
                $decoded = self::decodeQuoted($token->text);

                if ($decoded === null) {
                    return null;
                }

                // PHP normalises integer-like string keys.
                return preg_match('/^(0|-?[1-9][0-9]*)$/', $decoded) === 1 && strlen($decoded) < 19 ? intval($decoded) : $decoded;
            }

            return null;
        }

        if (count($keyTokens) === 2 && $keyTokens[0]->text === '-' && $keyTokens[1]->is(T_LNUMBER)) {
            return -intval($keyTokens[1]->text, 0);
        }

        return null;
    }

    /**
     * Decodes a single- or double-quoted PHP string literal, or returns null
     * when it interpolates.
     */
    public static function decodeQuoted(string $literal): ?string
    {
        if (strlen($literal) < 2) {
            return null;
        }

        $quote = $literal[0];
        $body = substr($literal, 1, -1);

        if ($quote === "'") {
            return strtr($body, ['\\\\' => '\\', "\\'" => "'"]);
        }

        if ($quote !== '"') {
            return null;
        }

        if (preg_match('/(?<!\\\\)\$[A-Za-z_{]/', $body) === 1) {
            return null;
        }

        $decoded = preg_replace_callback(
            '/\\\\(u\{[0-9A-Fa-f]+\}|x[0-9A-Fa-f]{1,2}|[0-7]{1,3}|.)/s',
            static function (array $match): string {
                $escape = $match[1];

                return match ($escape[0]) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    'v' => "\v",
                    'e' => "\e",
                    'f' => "\f",
                    '\\' => '\\',
                    '$' => '$',
                    '"' => '"',
                    'u' => mb_chr(intval(substr($escape, 2, -1), 16)) ?: '',
                    'x' => chr(intval(substr($escape, 1), 16)),
                    default => preg_match('/^[0-7]+$/', $escape) === 1 ? chr(intval($escape, 8)) : '\\' . $escape,
                };
            },
            $body,
        );

        return $decoded ?? null;
    }

    /**
     * Returns the index of the next token that is not whitespace or a comment.
     *
     * @param PhpToken[] $tokens
     */
    private static function nextSignificant(array $tokens, int $index): ?int
    {
        $count = count($tokens);

        while ($index < $count && $tokens[$index]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            $index++;
        }

        return $index < $count ? $index : null;
    }

    /**
     * Re-indents the continuation lines of a multi-line literal to sit under
     * an entry written at an indentation.
     */
    private static function reindent(string $literal, string $indent): string
    {
        if (! str_contains($literal, "\n")) {
            return $literal;
        }

        $lines = explode("\n", $literal);
        $first = array_shift($lines);

        return $first . "\n" . implode("\n", array_map(
            static fn(string $line): string => $line === '' ? $line : $indent . $line,
            $lines,
        ));
    }
}
