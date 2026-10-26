<?php

declare(strict_types=1);

namespace Ichiloto\Editor\History;

use Ichiloto\Editor\Storage\SourceSetPlan;
use RuntimeException;

/**
 * An edit that is only whole as a set of files written at once, rather than
 * one document changed and saved later: an actor's identity freeze that
 * also repairs every file naming that actor, say.
 *
 * A document cannot replace the workspace it belongs to, so it says what it
 * needs instead. Whoever owns the workspace asks the author, then writes the
 * set as one {@see SourceSetCommand}, which reloads the workspace and undoes
 * as one step.
 */
final class SourceSetRequired extends RuntimeException
{
    /**
     * @param SourceSetPlan $plan The files to write, with what they held.
     * @param string $label The history label (Migrate actor identities and references).
     * @param string $subject What the set does, for refusals (this actor migration).
     * @param string $question What the author is asked before anything is written, in a line.
     * @param list<string> $paths The files the set writes, relative to the project root.
     */
    public function __construct(
        public readonly SourceSetPlan $plan,
        public readonly string $label,
        public readonly string $subject,
        public readonly string $question,
        public readonly array $paths,
    ) {
        parent::__construct($question);
    }
}
