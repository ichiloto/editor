<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Dialogue;

use Ichiloto\Engine\Messaging\Dialogue\ConditionalDialogue;

/**
 * Conditional dialogue as the editor edits it: always variants.
 *
 * The Engine's ConditionalDialogue speaks either shape, plain pages or
 * variants. Plain pages are folded into one variant with no conditions so
 * the editor edits one shape, and one unconditioned, write-less,
 * script-less variant unfolds back into plain pages, so dialogue authored
 * as pages is saved as pages. Adding conditions, writes or a second variant
 * is what turns pages into variants; no line is lost either way.
 */
final class DialogueVariants
{
    private function __construct()
    {
    }

    /**
     * The dialogue as variants, plain pages folded into one.
     *
     * @param array<int, mixed> $dialogue The dialogue as authored.
     * @return array<int, array<string, mixed>> The variants.
     */
    public static function fold(array $dialogue): array
    {
        $dialogue = array_values(array_filter($dialogue, is_array(...)));

        if ($dialogue === [] || ConditionalDialogue::isVariantList($dialogue)) {
            return $dialogue;
        }

        return [['lines' => $dialogue]];
    }

    /**
     * The stored form of a variant list: plain pages when the list is one
     * unconditioned, write-less, script-less variant.
     *
     * @param array<int, mixed> $variants The variants.
     * @return array<int, mixed> The dialogue to store.
     */
    public static function unfold(array $variants): array
    {
        $variants = array_values(array_filter($variants, is_array(...)));

        if (count($variants) === 1 && array_keys($variants[0]) === ['lines'] && is_array($variants[0]['lines'])) {
            return array_values($variants[0]['lines']);
        }

        return $variants;
    }
}
