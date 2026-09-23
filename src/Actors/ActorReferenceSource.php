<?php

declare(strict_types=1);

namespace Ichiloto\Editor\Actors;

use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourceNode;
use Ichiloto\Editor\Cutscenes\Source\SourcePreservationRefusal;
use Ichiloto\Editor\Cutscenes\Source\SourceUnreadable;
use Ichiloto\Editor\Database\PhpSourceDocument;

/** Surgical literal edits; never a text-wide replacement of character names. */
final class ActorReferenceSource
{
    public static function rewrite(string $source, array $changes): string
    {
        try { $document = PhpArraySourceDocument::parse($source); }
        catch (SourceUnreadable) { return self::rewriteReturnedConstructor($source, $changes); }
        $edits = [];
        foreach ($changes as $change) {
            $path = $change['path'];
            $entry = $document->entryAt($path);
            if ($entry === null || $entry->keyIsOpaque) {
                throw new SourcePreservationRefusal('Actor reference at ' . implode('.', $path) . ' is not an editable literal.');
            }
            if ($change['kind'] === 'key' || $change['kind'] === 'speaker') {
                $tokens = \PhpToken::tokenize('<?php ' . substr($source, $entry->start, $entry->value->start - $entry->start));
                $keyToken = null;
                foreach ($tokens as $token) {
                    if ($token->is([T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) { continue; }
                    $keyToken = $token;
                    break;
                }
                if ($keyToken === null || ! $keyToken->is(T_CONSTANT_ENCAPSED_STRING)) {
                    throw new SourcePreservationRefusal('Actor reference key is not a string literal.');
                }
                $start = $entry->start + $keyToken->pos - strlen('<?php ');
                $edits[] = [$start, $start + strlen($keyToken->text), var_export($change['kind'] === 'speaker' ? 'actor' : $change['target'], true)];
            }
            if ($change['kind'] !== 'key') {
                if ($entry->value->kind !== SourceNode::SCALAR) {
                    throw new SourcePreservationRefusal('Actor reference at ' . implode('.', $path) . ' is an expression or variable; refusing to flatten it.');
                }
                $edits[] = [$entry->value->start, $entry->value->end, var_export($change['target'], true)];
            }
        }
        return $document->withEdits($edits)->source;
    }

    /** Presentation constructors retain imports, comments, argument order and unrelated expressions. */
    private static function rewriteReturnedConstructor(string $source, array $changes): string
    {
        $depth = 0;
        $start = $end = null;
        foreach (\PhpToken::tokenize($source) as $token) {
            if ($token->is(T_RETURN) && $depth === 0) {
                $start = $token->pos + strlen($token->text);
                continue;
            }
            if ($token->text === ';' && $depth === 0 && $start !== null) { $end = $token->pos; break; }
            if (in_array($token->text, ['(', '[', '{'], true)) { $depth++; }
            if (in_array($token->text, [')', ']', '}'], true)) { $depth--; }
        }
        if ($start === null || $end === null) { throw new SourceUnreadable('Cannot locate the returned presentation constructor.'); }
        $expression = substr($source, $start, $end - $start);
        $rewritten = self::rewriteConstructor($expression, $changes);
        // Preserve whitespace/comments surrounding the constructor itself.
        $original = PhpSourceDocument::parse('<?php return [' . $expression . '];')->entrySource(0);
        if ($original === null) { throw new SourceUnreadable('Presentation must return a supported constructor literal.'); }
        $offset = strpos($expression, $original);
        return substr($source, 0, $start) . substr_replace($expression, $rewritten, $offset, strlen($original)) . substr($source, $end);
    }

    private static function rewriteConstructor(string $expression, array $changes): string
    {
        $document = PhpSourceDocument::parse('<?php return [' . $expression . '];');
        $class = basename(str_replace('\\', '/', $document->entryClasses()[0] ?? ''));
        $parameters = match ($class) {
            'DialoguePresentationCatalog' => ['actors'],
            'BattlePresentationCatalog' => ['arenas', 'actors', 'enemies', 'ui', 'results', 'pause'],
            'BattleResultsSkin' => ['textures', 'colors', 'portraits', 'icons', 'assetRoot'],
            'MenuPresentationCatalog' => ['assetRoot', 'data'],
            default => throw new SourcePreservationRefusal('Unsupported presentation constructor; refusing to guess its arguments.'),
        };
        $groups = [];
        foreach ($changes as $change) {
            if ($class === 'MenuPresentationCatalog') { array_unshift($change['path'], 'data'); }
            $argument = array_shift($change['path']);
            $groups[$argument][] = $change;
        }
        foreach ($groups as $argument => $edits) {
            $literal = $document->getConstructorArgumentSource(0, $argument, $parameters);
            if ($literal === null) { throw new SourcePreservationRefusal('Missing actor reference argument ' . $argument . '.'); }
            if ($argument === 'results') {
                $updated = self::rewriteConstructor($literal, $edits);
            } else {
                $wrapper = '<?php return ';
                $updated = self::rewrite($wrapper . $literal . ';', $edits);
                $updated = substr($updated, strlen($wrapper), -1);
            }
            $document = $document->getWithConstructorArgument(0, $argument, $updated, $parameters);
        }
        return $document->entrySource(0) ?? throw new SourcePreservationRefusal('Cannot preserve the presentation constructor.');
    }

    /** Expected value after the same bounded reference edits, for staged readback. */
    public static function getUpdatedValue(mixed $payload, array $changes): mixed
    {
        $value = ActorReferenceInventory::getComparableValue($payload);
        foreach ($changes as $change) {
            $path = $change['path'];
            $key = array_pop($path);
            $parent =& $value;
            foreach ($path as $part) { $parent =& $parent[$part]; }
            if ($change['kind'] === 'key' || $change['kind'] === 'speaker') {
                $newKey = $change['kind'] === 'speaker' ? 'actor' : $change['target'];
                if ($newKey !== $key && array_key_exists($newKey, $parent)) {
                    throw new SourcePreservationRefusal('Actor reference migration would overwrite key ' . $newKey . '.');
                }
                $copy = [];
                foreach ($parent as $oldKey => $oldValue) {
                    $copy[$oldKey === $key ? $newKey : $oldKey] = $oldKey === $key && $change['kind'] === 'speaker' ? $change['target'] : $oldValue;
                }
                $parent = $copy;
            } else { $parent[$key] = $change['target']; }
            unset($parent);
        }
        return $value;
    }
}
