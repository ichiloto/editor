<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Source;

use JsonException;

/** Rewrites the authored values of a JSON timeline, retaining its other bytes. */
final readonly class JsonTimelineDocument
{
    private const int ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private function __construct(public string $source, private JsonSourceValue $root)
    {
    }

    public static function parse(string $source): self
    {
        try {
            $value = json_decode($source, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($value)) {
                throw new SourceUnreadable('the timeline must be an object or array.');
            }

            // The decoder has already checked syntax. The token offsets let
            // the writer keep original keys, values and whitespace.
            if (false === preg_match_all('/"(?:[^"\\\\]++|\\\\.)*+"|[{}\[\],:]|[^\s{}\[\],:"]++/s', $source, $matches, PREG_OFFSET_CAPTURE)) {
                throw new SourceUnreadable(preg_last_error_msg());
            }
            $index = 0;

            return new self($source, self::readValue($matches[0], $index));
        } catch (JsonException $failure) {
            throw new SourceUnreadable($failure->getMessage(), previous: $failure);
        }
    }

    /** @param list<array{0: string, 1: int}> $tokens */
    private static function readValue(array $tokens, int &$index): JsonSourceValue
    {
        [$token, $start] = $tokens[$index++];
        if ($token !== '[' && $token !== '{') {
            return new JsonSourceValue($start, $start + strlen($token), json_decode($token, true, flags: JSON_THROW_ON_ERROR));
        }

        $object = $token === '{';
        $close = $object ? '}' : ']';
        $children = [];
        $memberStarts = [];
        $values = [];
        while ($tokens[$index][0] !== $close) {
            $memberStart = $tokens[$index][1];
            if ($object) {
                $key = json_decode($tokens[$index][0], true, flags: JSON_THROW_ON_ERROR);
                if (array_key_exists($key, $children)) {
                    throw new SourceUnreadable('duplicate JSON object key ' . json_encode($key, self::ENCODE_FLAGS) . '.');
                }
                $index += 2;
            } else {
                $key = count($children);
            }

            $child = self::readValue($tokens, $index);
            $children[$key] = $child;
            $values[$key] = $child->value;
            $memberStarts[$key] = $memberStart;
            if ($tokens[$index][0] === ',') {
                ++$index;
            }
        }

        return new JsonSourceValue($start, $tokens[$index++][1] + 1, $values, $children, $memberStarts, $object);
    }

    /** @return array<array-key, mixed> */
    public function getValues(): array
    {
        return $this->root->value;
    }

    /** @param array<array-key, mixed> $values */
    public function rewrite(array $values): string
    {
        try {
            $source = substr($this->source, 0, $this->root->start)
                . $this->renderValue($this->root, $values)
                . substr($this->source, $this->root->end);
            if (json_decode($source, true, flags: JSON_THROW_ON_ERROR) !== $values) {
                throw new SourcePreservationRefusal('the edited timeline would decode to different values.');
            }

            return $source;
        } catch (JsonException $failure) {
            throw new SourcePreservationRefusal($failure->getMessage(), previous: $failure);
        }
    }

    private function renderValue(JsonSourceValue $node, mixed $value): string
    {
        if ($value === $node->value) {
            return substr($this->source, $node->start, $node->end - $node->start);
        }
        if (! is_array($node->value) || ! is_array($value) || (! $node->object && ! array_is_list($value))) {
            return json_encode($value, self::ENCODE_FLAGS);
        }

        $oldChildren = array_values($node->children);
        $starts = array_values($node->memberStarts);
        $close = $node->end - 1;
        $prefix = substr($this->source, $node->start, ($starts[0] ?? $close) - $node->start);
        if ($value === []) {
            return $prefix . $this->source[$close];
        }

        $separators = [];
        for ($i = 1, $count = count($starts); $i < $count; ++$i) {
            $end = $oldChildren[$i - 1]->end;
            $separators[] = substr($this->source, $end, $starts[$i] - $end);
        }

        $children = $node->object ? $node->children : $this->matchListChildren($node->children, $value);
        $position = 0;
        $output = $prefix;
        foreach ($value as $key => $member) {
            if ($position > 0) {
                $output .= $separators[$position - 1] ?? $separators[0] ?? (',' . substr($prefix, 1));
            }
            $child = $children[$key] ?? null;
            if ($node->object) {
                $output .= $child === null
                    ? json_encode((string) $key, self::ENCODE_FLAGS) . ': '
                    : substr($this->source, $node->memberStarts[$key], $child->start - $node->memberStarts[$key]);
            }
            $output .= $child === null ? json_encode($member, self::ENCODE_FLAGS) : $this->renderValue($child, $member);
            ++$position;
        }

        $last = end($oldChildren);

        return $output . substr($this->source, $last === false ? $close : $last->end, $node->end - ($last === false ? $close : $last->end));
    }

    /**
     * Retain the authored spelling of unchanged list members across insertions,
     * removals and moves, then rewrite edited members using remaining positions.
     *
     * @param list<JsonSourceValue> $children
     * @param list<mixed> $values
     * @return array<int, JsonSourceValue>
     */
    private function matchListChildren(array $children, array $values): array
    {
        $positions = [];
        foreach ($children as $position => $child) {
            $positions[serialize($child->value)][] = $position;
        }

        $matched = [];
        $used = [];
        $next = [];
        foreach ($values as $position => $value) {
            $signature = serialize($value);
            $offset = $next[$signature] ?? 0;
            if (isset($positions[$signature][$offset])) {
                $original = $positions[$signature][$offset];
                $next[$signature] = $offset + 1;
                $matched[$position] = $children[$original];
                $used[$original] = true;
            }
        }

        foreach (array_keys($values) as $position) {
            if (! isset($matched[$position]) && ! isset($used[$position]) && isset($children[$position])) {
                $matched[$position] = $children[$position];
            }
        }

        return $matched;
    }
}
