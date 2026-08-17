<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

use RuntimeException;

/**
 * A PHP data file edited in place, one authored value at a time.
 *
 * Some project files are not data dressed as PHP; they are PHP. Last Legend's
 * `assets/Data/items.php` returns a list of `new Item(...)` and
 * `new Weapon(...)` calls with named arguments, imported and fully qualified
 * class names side by side, enum expressions, nested constructors and
 * comments. Regenerating that list from loaded objects answers a different
 * question than the author asked: it reorders arguments, spells out defaults
 * nobody wrote, and rewrites twenty-nine records to change one price.
 *
 * So this does not regenerate anything. It finds the bytes of one named
 * argument's value and replaces exactly those bytes, or inserts one argument
 * where the call ends, or removes one and its separator. Everything else --
 * comments, blank lines, argument order, how a class was spelled, the other
 * entries -- is the same bytes afterwards.
 *
 * It is deliberately narrow about what it will touch. An entry it cannot
 * read as a constructor call with named arguments, or a value it is asked to
 * change inside something other than a nested constructor, is refused with a
 * reason. A caller that cannot express an edit here must leave the file
 * alone rather than fall back to rewriting it.
 *
 * @package Ichiloto\Editor\Database
 */
final class PhpSourceDocument
{
    /**
     * @param string $source The file's bytes.
     * @param array<int, array{class: string, open: int, close: int, arguments: array<string, array{start: int, end: int, nameStart?: int}>, positional: bool, start: int}> $entries
     *   One entry per top-level element of the returned array, in order.
     */
    private function __construct(
        public readonly string $source,
        private readonly array $entries,
        private readonly ?int $arrayClose = null,
    ) {
    }

    /**
     * Reads a PHP file that returns a list of constructor calls.
     *
     * @param string $source The file's bytes.
     * @return self The document.
     */
    public static function parse(string $source): self
    {
        $tokens = self::tokenize($source);
        $entries = [];
        $index = self::findReturnedArray($tokens);

        if ($index === null) {
            return new self($source, []);
        }


        [$arrayOpen, $arrayClose] = $index;
        $position = $arrayOpen + 1;

        while ($position < $arrayClose) {
            $token = $tokens[$position];

            if (self::isSkippable($token) || $token['text'] === ',') {
                $position++;
                continue;
            }

            $entry = self::readEntry($tokens, $position, $arrayClose);

            if ($entry === null) {
                // Something other than a constructor call. It still occupies
                // a position in the returned array, so a placeholder keeps
                // entry indexes and payload indexes the same number, and its
                // bytes stay untouched.
                $entries[] = [
                    'class' => '',
                    'open' => $tokens[$position]['offset'],
                    'close' => $tokens[$position]['offset'],
                    'arguments' => [],
                    'positional' => true,
                    'start' => $tokens[$position]['offset'],
                ];
                $position = self::skipToNextEntry($tokens, $position, $arrayClose);
                continue;
            }

            $entries[] = $entry['entry'];
            $position = $entry['next'];
        }

        return new self($source, $entries, $tokens[$arrayClose]['offset']);
    }

    /**
     * Returns how many top-level constructor entries the file has.
     *
     * @return int The count.
     */
    public function entryCount(): int
    {
        return count($this->entries);
    }

    /**
     * Returns the class each entry constructs, as it was spelled.
     *
     * @return string[] The class names, in file order.
     */
    public function entryClasses(): array
    {
        return array_map(static fn(array $entry): string => $entry['class'], $this->entries);
    }

    /**
     * Determines whether an entry can be edited by argument name.
     *
     * @param int $entryIndex The entry.
     * @return bool True when it is a constructor call using named arguments.
     */
    public function isEditable(int $entryIndex): bool
    {
        return isset($this->entries[$entryIndex]) && ! $this->entries[$entryIndex]['positional'];
    }

    /**
     * Returns the source of one argument's value exactly as authored, or null
     * when the entry does not declare it.
     *
     * @param int $entryIndex The entry.
     * @param string $path The argument name, or a dotted path into a nested constructor.
     * @return string|null The value's source.
     */
    public function argumentSource(int $entryIndex, string $path): ?string
    {
        $span = $this->resolveArgument($entryIndex, $path);

        return $span === null ? null : substr($this->source, $span['start'], $span['end'] - $span['start']);
    }

    /**
     * Returns a document with one argument's value replaced, or the argument
     * added when the entry does not declare it yet.
     *
     * @param int $entryIndex The entry.
     * @param string $path The argument name, or a dotted path into a nested constructor.
     * @param string $literal The replacement value, already valid PHP.
     * @return self The rewritten document.
     */
    public function withArgument(int $entryIndex, string $path, string $literal): self
    {
        $span = $this->resolveArgument($entryIndex, $path);

        if ($span !== null) {
            return self::parse(
                substr($this->source, 0, $span['start']) . $literal . substr($this->source, $span['end']),
            );
        }

        return self::parse($this->insertedArgument($entryIndex, $path, $literal));
    }

    /**
     * Returns a document with one argument removed, or this document when the
     * entry never declared it.
     *
     * @param int $entryIndex The entry.
     * @param string $path The argument name, or a dotted path.
     * @return self The rewritten document.
     */
    public function withoutArgument(int $entryIndex, string $path): self
    {
        $span = $this->resolveArgument($entryIndex, $path, withName: true);

        if ($span === null) {
            return $this;
        }

        $start = $span['start'];
        $end = $span['end'];
        $source = $this->source;

        // Take the separator and the blank line the argument occupied with
        // it, so removing one never leaves a stray comma or an empty line.
        while ($end < strlen($source) && ($source[$end] === ' ' || $source[$end] === "\t")) {
            $end++;
        }

        if ($end < strlen($source) && $source[$end] === ',') {
            $end++;

            while ($end < strlen($source) && ($source[$end] === ' ' || $source[$end] === "\t")) {
                $end++;
            }

            if ($end < strlen($source) && $source[$end] === "\n") {
                $end++;

                while ($start > 0 && ($source[$start - 1] === ' ' || $source[$start - 1] === "\t")) {
                    $start--;
                }
            }
        }

        return self::parse(substr($source, 0, $start) . substr($source, $end));
    }

    /**
     * Returns a document with one entry appended to the returned array.
     *
     * The entry is written in the file's own indentation, with one named
     * argument per line, so a record the editor creates reads like the ones
     * an author wrote by hand.
     *
     * @param string $class The class to construct, spelled as it should appear.
     * @param array<string, string> $arguments The named arguments, values already valid PHP.
     * @return self The rewritten document.
     */
    public function withNewEntry(string $class, array $arguments): self
    {
        if ($this->arrayClose === null) {
            throw new RuntimeException('This file does not return an array to add an entry to.');
        }

        $indent = $this->entryIndent();
        $inner = $indent . '  ';
        $lines = [$indent . 'new ' . $class . '('];

        foreach ($arguments as $name => $literal) {
            $lines[] = sprintf('%s%s: %s,', $inner, $name, $literal);
        }

        $lines[] = $indent . '),';
        $block = implode("\n", $lines) . "\n";
        $before = substr($this->source, 0, $this->arrayClose);
        $after = substr($this->source, $this->arrayClose);
        $trimmed = rtrim($before);
        $needsComma = ! str_ends_with($trimmed, ',') && ! str_ends_with($trimmed, '[');
        $closingIndent = substr($before, strrpos($before, "\n") === false ? 0 : strrpos($before, "\n") + 1);

        return self::parse(
            $trimmed . ($needsComma ? ',' : '') . "\n" . $block . (trim($closingIndent) === '' ? $closingIndent : '') . $after,
        );
    }

    /**
     * Returns a document with one entry removed, bytes and separator.
     *
     * @param int $entryIndex The entry.
     * @return self The rewritten document.
     */
    public function withoutEntry(int $entryIndex): self
    {
        if (! isset($this->entries[$entryIndex])) {
            return $this;
        }

        $entry = $this->entries[$entryIndex];
        $start = $entry['start'];
        $end = $entry['close'] + 1;
        $source = $this->source;

        while ($end < strlen($source) && ($source[$end] === ' ' || $source[$end] === "\t")) {
            $end++;
        }

        if ($end < strlen($source) && $source[$end] === ',') {
            $end++;
        }

        if ($end < strlen($source) && $source[$end] === "\n") {
            $end++;

            while ($start > 0 && ($source[$start - 1] === ' ' || $source[$start - 1] === "\t")) {
                $start--;
            }
        }

        return self::parse(substr($source, 0, $start) . substr($source, $end));
    }

    /**
     * Returns the indentation the array's entries are written at.
     *
     * @return string The indentation.
     */
    private function entryIndent(): string
    {
        foreach ($this->entries as $entry) {
            $lineStart = strrpos(substr($this->source, 0, $entry['start']), "\n");

            if ($lineStart === false) {
                continue;
            }

            $line = substr($this->source, $lineStart + 1, $entry['start'] - $lineStart - 1);

            if (trim($line) === '') {
                return $line;
            }
        }

        return '  ';
    }

    /**
     * Returns the source with a new named argument added to an entry's call.
     *
     * The argument is written last, in the indentation the call's other
     * arguments use, so an inserted value reads like the ones already there.
     *
     * @param int $entryIndex The entry.
     * @param string $path The argument name, or a dotted path.
     * @param string $literal The value, already valid PHP.
     * @return string The rewritten source.
     */
    private function insertedArgument(int $entryIndex, string $path, string $literal): string
    {
        $segments = explode('.', $path);
        $name = array_shift($segments);

        if ($segments !== []) {
            // The nested constructor has to exist before a value inside it
            // can be written; the caller authors the whole nested value.
            throw new RuntimeException(sprintf(
                'Cannot add "%s": %s does not declare %s.',
                $path,
                $this->describeEntry($entryIndex),
                $name,
            ));
        }

        if (! $this->isEditable($entryIndex)) {
            throw new RuntimeException(sprintf(
                'Cannot add "%s": %s is not a constructor call with named arguments.',
                $path,
                $this->describeEntry($entryIndex),
            ));
        }

        $entry = $this->entries[$entryIndex];
        $close = $entry['close'];
        $indent = $this->argumentIndent($entry);
        $before = substr($this->source, 0, $close);
        $after = substr($this->source, $close);
        $trimmed = rtrim($before);
        $isMultiline = str_contains(substr($this->source, $entry['open'], $close - $entry['open']), "\n");

        if ($entry['arguments'] === [] && ! $isMultiline) {
            // An empty single-line call: `new Thing()`.
            return $trimmed . sprintf('%s: %s', $name, $literal) . $after;
        }

        if (! $isMultiline) {
            return $trimmed . sprintf(', %s: %s', $name, $literal) . $after;
        }

        $needsComma = ! str_ends_with($trimmed, ',') && ! str_ends_with($trimmed, '(');

        return $trimmed
            . ($needsComma ? ',' : '')
            . "\n" . $indent . sprintf('%s: %s,', $name, $literal) . "\n"
            . $this->closingIndent($entry)
            . $after;
    }

    /**
     * Returns the byte span of an argument's value, following a dotted path
     * into nested constructors, or null when it is not declared.
     *
     * @param int $entryIndex The entry.
     * @param string $path The argument name or dotted path.
     * @param bool $withName Whether the span should include the `name:` label.
     * @return array{start: int, end: int}|null The span.
     */
    private function resolveArgument(int $entryIndex, string $path, bool $withName = false): ?array
    {
        if (! isset($this->entries[$entryIndex])) {
            return null;
        }

        $entry = $this->entries[$entryIndex];

        if ($entry['positional']) {
            return null;
        }

        $segments = explode('.', $path);
        $name = array_shift($segments);
        $span = $entry['arguments'][$name] ?? null;

        if ($span === null) {
            return null;
        }

        while ($segments !== []) {
            // Descend into the nested constructor the argument holds.
            $nested = self::parseCall($this->source, $span['start'], $span['end']);

            if ($nested === null) {
                return null;
            }

            $name = array_shift($segments);
            $span = $nested['arguments'][$name] ?? null;

            if ($span === null) {
                return null;
            }
        }

        if (! $withName) {
            return ['start' => $span['start'], 'end' => $span['end']];
        }

        return ['start' => $span['nameStart'] ?? $span['start'], 'end' => $span['end']];
    }

    /**
     * Describes an entry for a diagnostic.
     *
     * @param int $entryIndex The entry.
     * @return string The description.
     */
    public function describeEntry(int $entryIndex): string
    {
        $class = $this->entries[$entryIndex]['class'] ?? '';

        return $class === ''
            ? sprintf('entry %d', $entryIndex + 1)
            : sprintf('entry %d (%s)', $entryIndex + 1, $class);
    }

    /**
     * Returns the indentation the entry's arguments are written at.
     *
     * @param array{open: int, close: int, arguments: array<string, array{start: int, end: int, nameStart?: int}>} $entry The entry.
     * @return string The indentation.
     */
    private function argumentIndent(array $entry): string
    {
        foreach ($entry['arguments'] as $span) {
            $lineStart = strrpos(substr($this->source, 0, $span['nameStart'] ?? $span['start']), "\n");

            if ($lineStart === false) {
                continue;
            }

            $line = substr($this->source, $lineStart + 1, ($span['nameStart'] ?? $span['start']) - $lineStart - 1);

            if (trim($line) === '') {
                return $line;
            }
        }

        return $this->closingIndent($entry) . '  ';
    }

    /**
     * Returns the indentation of the entry's closing parenthesis.
     *
     * @param array{close: int} $entry The entry.
     * @return string The indentation.
     */
    private function closingIndent(array $entry): string
    {
        $lineStart = strrpos(substr($this->source, 0, $entry['close']), "\n");

        if ($lineStart === false) {
            return '';
        }

        $line = substr($this->source, $lineStart + 1, $entry['close'] - $lineStart - 1);

        return trim($line) === '' ? $line : '';
    }

    /**
     * Tokenizes source, carrying each token's byte offset and length.
     *
     * @param string $source The source.
     * @return array<int, array{id: int, text: string, offset: int}> The tokens.
     */
    private static function tokenize(string $source): array
    {
        $tokens = [];
        $offset = 0;

        foreach (token_get_all($source) as $token) {
            $text = is_string($token) ? $token : $token[1];
            $tokens[] = [
                'id' => is_string($token) ? -1 : $token[0],
                'text' => $text,
                'offset' => $offset,
            ];
            $offset += strlen($text);
        }

        return $tokens;
    }

    /**
     * Finds the array the file returns.
     *
     * @param array<int, array{id: int, text: string, offset: int}> $tokens The tokens.
     * @return array{0: int, 1: int}|null The opening and closing token indexes.
     */
    private static function findReturnedArray(array $tokens): ?array
    {
        $count = count($tokens);
        $depth = 0;

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (in_array($token['text'], ['(', '[', '{'], true)) {
                $depth++;
                continue;
            }

            if (in_array($token['text'], [')', ']', '}'], true)) {
                $depth--;
                continue;
            }

            if ($token['id'] !== T_RETURN || $depth !== 0) {
                continue;
            }

            for ($open = $index + 1; $open < $count; $open++) {
                if (self::isSkippable($tokens[$open])) {
                    continue;
                }

                if ($tokens[$open]['text'] !== '[' && $tokens[$open]['id'] !== T_ARRAY) {
                    return null;
                }

                if ($tokens[$open]['id'] === T_ARRAY) {
                    // `return array( ... );`
                    while ($open < $count && $tokens[$open]['text'] !== '(') {
                        $open++;
                    }
                }

                $close = self::matchBracket($tokens, $open);

                return $close === null ? null : [$open, $close];
            }
        }

        return null;
    }

    /**
     * Reads one `new Class(...)` entry.
     *
     * @param array<int, array{id: int, text: string, offset: int}> $tokens The tokens.
     * @param int $position The first token of the entry.
     * @param int $limit The array's closing token.
     * @return array{entry: array{class: string, open: int, close: int, arguments: array<string, array{start: int, end: int, nameStart: int}>, positional: bool}, next: int}|null
     */
    private static function readEntry(array $tokens, int $position, int $limit): ?array
    {
        if ($tokens[$position]['id'] !== T_NEW) {
            return null;
        }

        $index = $position + 1;
        $class = '';

        while ($index < $limit) {
            $token = $tokens[$index];

            if (self::isSkippable($token)) {
                $index++;
                continue;
            }

            if (
                $token['id'] === T_STRING
                || $token['id'] === T_NAME_QUALIFIED
                || $token['id'] === T_NAME_FULLY_QUALIFIED
                || $token['id'] === T_NS_SEPARATOR
            ) {
                $class .= $token['text'];
                $index++;
                continue;
            }

            break;
        }

        if ($class === '' || $index >= $limit || $tokens[$index]['text'] !== '(') {
            return null;
        }

        $close = self::matchBracket($tokens, $index);

        if ($close === null) {
            return null;
        }

        $arguments = self::readArguments($tokens, $index, $close);

        return [
            'entry' => [
                'class' => $class,
                'open' => $tokens[$index]['offset'],
                'close' => $tokens[$close]['offset'],
                'arguments' => $arguments['named'],
                'positional' => $arguments['positional'],
                'start' => $tokens[$position]['offset'],
            ],
            'next' => $close + 1,
        ];
    }

    /**
     * Reads a call's named arguments and their value spans.
     *
     * @param array<int, array{id: int, text: string, offset: int}> $tokens The tokens.
     * @param int $open The opening parenthesis token index.
     * @param int $close The closing parenthesis token index.
     * @return array{named: array<string, array{start: int, end: int, nameStart: int}>, positional: bool}
     */
    private static function readArguments(array $tokens, int $open, int $close): array
    {
        $named = [];
        $positional = false;
        $index = $open + 1;

        while ($index < $close) {
            $token = $tokens[$index];

            if (self::isSkippable($token) || $token['text'] === ',') {
                $index++;
                continue;
            }

            $nameIndex = $index;
            $name = null;

            if ($token['id'] === T_STRING) {
                $peek = $index + 1;

                while ($peek < $close && self::isSkippable($tokens[$peek])) {
                    $peek++;
                }

                if ($peek < $close && $tokens[$peek]['text'] === ':') {
                    $name = $token['text'];
                    $index = $peek + 1;
                }
            }

            if ($name === null) {
                $positional = true;
            }

            while ($index < $close && self::isSkippable($tokens[$index])) {
                $index++;
            }

            $valueStart = $tokens[$index]['offset'] ?? $tokens[$close]['offset'];
            $end = self::skipValue($tokens, $index, $close);
            $valueEnd = $tokens[$end]['offset'] ?? $tokens[$close]['offset'];

            if ($name !== null) {
                $named[$name] = [
                    'start' => $valueStart,
                    'end' => self::trimmedEnd($tokens, $index, $end, $valueEnd),
                    'nameStart' => $tokens[$nameIndex]['offset'],
                ];
            }

            $index = $end;
        }

        return ['named' => $named, 'positional' => $positional];
    }

    /**
     * Returns the byte offset just past a value's last non-space token.
     *
     * @param array<int, array{id: int, text: string, offset: int}> $tokens The tokens.
     * @param int $start The value's first token.
     * @param int $end The token after the value.
     * @param int $fallback The offset to use when the value is empty.
     * @return int The offset.
     */
    private static function trimmedEnd(array $tokens, int $start, int $end, int $fallback): int
    {
        for ($index = $end - 1; $index >= $start; $index--) {
            if (self::isSkippable($tokens[$index])) {
                continue;
            }

            return $tokens[$index]['offset'] + strlen($tokens[$index]['text']);
        }

        return $fallback;
    }

    /**
     * Skips one argument value, honouring nesting.
     *
     * @param array<int, array{id: int, text: string, offset: int}> $tokens The tokens.
     * @param int $index The value's first token.
     * @param int $limit The call's closing token.
     * @return int The token index of the separator or closing token.
     */
    private static function skipValue(array $tokens, int $index, int $limit): int
    {
        $depth = 0;

        while ($index < $limit) {
            $text = $tokens[$index]['text'];

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($text === ',' && $depth === 0) {
                return $index;
            }

            $index++;
        }

        return $limit;
    }

    /**
     * Skips an entry this cannot read, to the next top-level comma.
     *
     * @param array<int, array{id: int, text: string, offset: int}> $tokens The tokens.
     * @param int $index The entry's first token.
     * @param int $limit The array's closing token.
     * @return int The next entry's first token index.
     */
    private static function skipToNextEntry(array $tokens, int $index, int $limit): int
    {
        return self::skipValue($tokens, $index, $limit) + 1;
    }

    /**
     * Returns a nested call's named arguments, parsed from a value's bytes.
     *
     * @param string $source The whole source.
     * @param int $start The value's first byte.
     * @param int $end The byte past the value.
     * @return array{arguments: array<string, array{start: int, end: int, nameStart: int}>}|null The call.
     */
    private static function parseCall(string $source, int $start, int $end): ?array
    {
        $fragment = substr($source, $start, $end - $start);
        $tokens = self::tokenize('<?php ' . $fragment . ';');
        $offset = $start - strlen('<?php ');
        $open = null;

        foreach ($tokens as $index => $token) {
            if ($token['text'] === '(') {
                $open = $index;
                break;
            }
        }

        if ($open === null) {
            return null;
        }

        $close = self::matchBracket($tokens, $open);

        if ($close === null) {
            return null;
        }

        $arguments = self::readArguments($tokens, $open, $close);
        $shifted = [];

        foreach ($arguments['named'] as $name => $span) {
            $shifted[$name] = [
                'start' => $span['start'] + $offset,
                'end' => $span['end'] + $offset,
                'nameStart' => $span['nameStart'] + $offset,
            ];
        }

        return ['arguments' => $shifted];
    }

    /**
     * Returns the token index closing the bracket opened at a token.
     *
     * @param array<int, array{id: int, text: string, offset: int}> $tokens The tokens.
     * @param int $open The opening token index.
     * @return int|null The closing token index.
     */
    private static function matchBracket(array $tokens, int $open): ?int
    {
        $count = count($tokens);
        $depth = 0;

        for ($index = $open; $index < $count; $index++) {
            $text = $tokens[$index]['text'];

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
                continue;
            }

            if (in_array($text, [')', ']', '}'], true)) {
                $depth--;

                if ($depth === 0) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * Determines whether a token carries no syntax.
     *
     * @param array{id: int, text: string, offset: int} $token The token.
     * @return bool True for whitespace and comments.
     */
    private static function isSkippable(array $token): bool
    {
        return in_array($token['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }
}
