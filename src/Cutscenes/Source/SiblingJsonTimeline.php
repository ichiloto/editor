<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Cutscenes\Source;

use PhpToken;

/** A runtime PHP adapter to a timeline owned by the same asset folder. */
final readonly class SiblingJsonTimeline
{
    private function __construct(
        public string $source,
        private PhpToken $file,
    ) {
    }

    public static function detect(string $source, string $id): ?self
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn(PhpToken $token): bool => ! in_array($token->id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $literal = "'/" . $id . ".json'";
        if (array_map(static fn(PhpToken $token): string => $token->text, $tokens) !== [
            'return', 'json_decode', '(', 'file_get_contents', '(', '__DIR__', '.', $literal, ')',
            ',', 'true', ',', 'flags', ':', 'JSON_THROW_ON_ERROR', ')', ';',
        ]) {
            return null;
        }

        return new self($source, $tokens[7]);
    }

    public function copyFor(string $id): self
    {
        $literal = var_export('/' . $id . '.json', true);
        $source = substr_replace($this->source, $literal, $this->file->pos, strlen($this->file->text));

        return new self($source, new PhpToken(T_CONSTANT_ENCAPSED_STRING, $literal, $this->file->line, $this->file->pos));
    }
}
