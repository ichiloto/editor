<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Database;

/**
 * Reads the source envelope around a PHP data file's top-level return.
 *
 * This deliberately does not rewrite PHP syntax. It only identifies the
 * verbatim prefix that may be retained when a database regenerates the value
 * returned by the file, and reports whether comments occur inside that value.
 */
final class PhpDataSource
{
    /**
     * @return array{header: string, hasInteriorComment: bool}
     */
    public static function inspect(string $source): array
    {
        $tokens = token_get_all($source);
        $header = '';
        $depth = 0;
        $sawReturn = false;
        $hasInteriorComment = false;

        foreach ($tokens as $token) {
            if (is_string($token)) {
                if (! $sawReturn) {
                    $header .= $token;
                }

                if ($token === '(' || $token === '[' || $token === '{') {
                    $depth++;
                } elseif ($token === ')' || $token === ']' || $token === '}') {
                    $depth--;
                }

                continue;
            }

            [$id, $text] = $token;

            if ($id === T_RETURN && $depth === 0 && ! $sawReturn) {
                $sawReturn = true;
                continue;
            }

            if (($id === T_COMMENT || $id === T_DOC_COMMENT) && $sawReturn) {
                $hasInteriorComment = true;
            }

            if (! $sawReturn) {
                $header .= $text;
            }
        }

        if (! $sawReturn) {
            return [
                'header' => "<?php\n\n",
                'hasInteriorComment' => false,
            ];
        }

        return [
            'header' => $header,
            'hasInteriorComment' => $hasInteriorComment,
        ];
    }
}
