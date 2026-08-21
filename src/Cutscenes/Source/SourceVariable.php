<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Source;

/**
 * A variable assigned once at the top level of an authored file, before the
 * `return`: the ASCII block a keyframe shows, the shared value several
 * entries name.
 *
 * @package Ichiloto\Editor\Cutscenes\Source
 */
final readonly class SourceVariable
{
    public const string NOWDOC = 'nowdoc';
    public const string HEREDOC = 'heredoc';
    public const string STRING = 'string';
    public const string OTHER = 'other';

    /**
     * @param string $name The variable name, without `$`.
     * @param string $kind One of the kind constants.
     * @param int $start The offset of the statement's first byte.
     * @param int $end The offset just past the statement's `;`.
     * @param int|null $contentStart The offset of the first content byte of a nowdoc or heredoc.
     * @param int|null $contentEnd The offset just past the last content byte (before the newline that precedes the closing label).
     * @param string $closingIndent The whitespace before the closing label, which PHP strips from every content line.
     * @param string|null $label The nowdoc or heredoc label.
     */
    public function __construct(
        public string $name,
        public string $kind,
        public int $start,
        public int $end,
        public ?int $contentStart = null,
        public ?int $contentEnd = null,
        public string $closingIndent = '',
        public ?string $label = null,
    ) {
    }
}
